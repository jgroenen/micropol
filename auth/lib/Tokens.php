<?php

// The OAuth 2 flows of the auth service, on the append only files of Data:
//
//   login (/authorize)       a sessie and an authorization code, bound to the PKCE code_challenge
//   code (/token)            the code, once, for an access token and a refresh token
//   refresh (/token)         a refresh token, once, for a new pair (rotation); a refresh token used
//                            twice is probably stolen, so that revokes the whole sessie
//   introspect / userinfo    whether an access token is valid, and whose it is
//   revoke                   ends the sessie of a token: all its tokens stop working at once
//
// Tokens are random strings, meaningless outside this service; only their sha256 is stored.
// A token is valid while its own verloopt and that of its sessie are in the future.
class Tokens {
    // an authorization code for a gebruiker who just logged in at /authorize
    public static function maakCode($clientId, $redirectUri, $codeChallenge, array $gebruiker) {
        $code = self::willekeurig();
        Data::append('codes', Data::CODES, [
            self::hash($code), $clientId, $redirectUri, $codeChallenge, $gebruiker['id'], time() + CODE_GELDIG,
        ]);
        return $code;
    }

    // grant_type=authorization_code: the tokens for a code, checked against the PKCE code_verifier
    public static function wisselCode($code, $clientId, $redirectUri, $codeVerifier) {
        $rij = Data::laatste('codes', 'code_hash', self::hash($code));
        if ($rij === null || (int) $rij['verloopt'] < time()) {
            throw new OAuthFout('invalid_grant', 'The code is invalid, expired or already used.');
        }
        // once only, also when the rest is wrong
        Data::append('codes', Data::CODES, array_merge(array_values(array_slice($rij, 0, 5)), [0]));

        if (!hash_equals($rij['client_id'], $clientId) || !hash_equals($rij['redirect_uri'], $redirectUri)) {
            throw new OAuthFout('invalid_grant', 'The code was issued for another client or redirect_uri.');
        }
        if (!hash_equals($rij['code_challenge'], self::challenge($codeVerifier))) {
            throw new OAuthFout('invalid_grant', 'The code_verifier does not match the code_challenge.');
        }

        $sessieId = Data::uuid();
        Data::append('sessies', Data::SESSIES, [$sessieId, $rij['gebruiker_id'], $clientId, time(), time() + SESSIE_MAX]);
        return self::geefUit($sessieId);
    }

    // grant_type=refresh_token: a new access token and refresh token; the old refresh token stops working
    public static function vernieuw($refreshToken, $clientId) {
        $rij = Data::laatste('tokens', 'token_hash', self::hash($refreshToken));
        if ($rij === null || $rij['soort'] !== 'refresh') {
            throw new OAuthFout('invalid_grant', 'Unknown refresh token.');
        }
        if ((int) $rij['verloopt'] === 0) {
            // used before: someone else has (had) it too, so nobody keeps this login
            self::beeindig($rij['sessie_id']);
            throw new OAuthFout('invalid_grant', 'The refresh token was already used; the login has been ended.');
        }
        $sessie = self::actieveSessie($rij['sessie_id']);
        if ((int) $rij['verloopt'] < time() || $sessie === null) {
            throw new OAuthFout('invalid_grant', 'The refresh token or the login has expired.');
        }
        if (!hash_equals($sessie['client_id'], $clientId)) {
            throw new OAuthFout('invalid_grant', 'The refresh token was issued for another client.');
        }
        Data::append('tokens', Data::TOKENS, [$rij['token_hash'], 'refresh', $rij['sessie_id'], 0]);
        return self::geefUit($rij['sessie_id']);
    }

    // RFC 7662: { active: false }, or { active: true, sub, username, preferred_username, email, client_id, exp, iat }
    public static function introspecteer($token) {
        $rij = Data::laatste('tokens', 'token_hash', self::hash($token));
        // only access tokens: a refresh token is not for calling an api
        if ($rij === null || $rij['soort'] !== 'access' || (int) $rij['verloopt'] < time()) {
            return ['active' => false];
        }
        $sessie = self::actieveSessie($rij['sessie_id']);
        $gebruiker = $sessie ? Data::gebruikerById($sessie['gebruiker_id']) : null;
        if ($gebruiker === null) {
            return ['active' => false];
        }
        return [
            'active' => true,
            // the names of RFC 7662 and OpenID Connect
            'sub' => $gebruiker['id'],
            'username' => $gebruiker['gebruikersnaam'],
            'preferred_username' => $gebruiker['gebruikersnaam'],
            'email' => $gebruiker['email'],
            'client_id' => $sessie['client_id'],
            'token_type' => 'Bearer',
            'exp' => (int) $rij['verloopt'],
            'iat' => (int) $rij['verloopt'] - ACCESS_TOKEN_GELDIG,
        ];
    }

    // RFC 7009: ends the sessie of an access or refresh token; an unknown token is fine too
    public static function trekIn($token) {
        $rij = Data::laatste('tokens', 'token_hash', self::hash($token));
        if ($rij !== null) {
            self::beeindig($rij['sessie_id']);
        }
    }

    // a new access token and refresh token for a sessie, never valid after the sessie ends
    private static function geefUit($sessieId) {
        $sessie = self::actieveSessie($sessieId);
        $einde = (int) $sessie['verloopt'];
        $access = self::willekeurig();
        $refresh = self::willekeurig();
        $accessVerloopt = min(time() + ACCESS_TOKEN_GELDIG, $einde);
        Data::append('tokens', Data::TOKENS, [self::hash($access), 'access', $sessieId, $accessVerloopt]);
        Data::append('tokens', Data::TOKENS, [self::hash($refresh), 'refresh', $sessieId, min(time() + REFRESH_TOKEN_GELDIG, $einde)]);
        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => $accessVerloopt - time(),
            'refresh_token' => $refresh,
            'refresh_expires_in' => min(REFRESH_TOKEN_GELDIG, $einde - time()),
        ];
    }

    private static function actieveSessie($id) {
        $sessie = Data::laatste('sessies', 'id', $id);
        return $sessie !== null && (int) $sessie['verloopt'] > time() ? $sessie : null;
    }

    private static function beeindig($sessieId) {
        $sessie = Data::laatste('sessies', 'id', $sessieId);
        if ($sessie !== null && (int) $sessie['verloopt'] !== 0) {
            Data::append('sessies', Data::SESSIES, [$sessie['id'], $sessie['gebruiker_id'], $sessie['client_id'], $sessie['begonnen'], 0]);
        }
    }

    // PKCE S256: base64url(sha256(verifier)), without padding
    public static function challenge($verifier) {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private static function willekeurig() {
        return bin2hex(random_bytes(32));
    }

    private static function hash($token) {
        return hash('sha256', $token);
    }
}
