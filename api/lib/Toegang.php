<?php

// Who has access to the beheer endpoints: the admin sends an access token of the auth service as
// "Authorization: Bearer <token>".
// The api asks the auth service whether it is valid (introspection, RFC 7662), with its own id and secret,
// see config.php. That works with any OAuth server with introspection, like Keycloak.
// The answer is kept INTROSPECTIE_CACHE seconds, so logging out takes at most that long to reach the api.
class Toegang {
    // the logged in beheerder { id, gebruikersnaam, email }, or null
    public static function beheerder() {
        $token = Http::bearer();
        if ($token === null) {
            return null;
        }
        $info = self::introspecteer($token);
        if (empty($info['active']) || !isset($info['sub'])) {
            return null;
        }
        return [
            'id' => $info['sub'],
            'gebruikersnaam' => $info['username'] ?? $info['preferred_username'] ?? '',
            'email' => $info['email'] ?? '',
        ];
    }

    // for the beheer endpoints: the logged in beheerder; without one a 401
    public static function vereisBeheerder() {
        $beheerder = self::beheerder();
        if ($beheerder === null) {
            header('WWW-Authenticate: Bearer');
            throw new HttpFout(401, 'Not logged in.');
        }
        return $beheerder;
    }

    // the answer of the auth service for a token, from the cache when recent enough
    private static function introspecteer($token) {
        $cache = DATA_DIR . '/cache/introspectie-' . hash('sha256', $token) . '.json';
        $bewaard = is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;
        if (is_array($bewaard) && $bewaard['tot'] > time()) {
            return $bewaard['info'];
        }

        $info = self::vraagAuth($token);
        if ($info === null) {
            return ['active' => false]; // the auth service could not be reached: nobody is logged in
        }
        // not longer than the token itself is valid
        $tot = min(time() + INTROSPECTIE_CACHE, (int) ($info['exp'] ?? PHP_INT_MAX));
        if (!is_dir(dirname($cache))) {
            @mkdir(dirname($cache), 0775, true);
        }
        @file_put_contents($cache, json_encode(['tot' => $tot, 'info' => $info]), LOCK_EX);
        return $info;
    }

    private static function vraagAuth($token) {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'timeout' => 5,
            'ignore_errors' => true,
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n"
                . 'Authorization: Basic ' . base64_encode(rawurlencode(AUTH_CLIENT_ID) . ':' . rawurlencode(AUTH_CLIENT_SECRET)) . "\r\n",
            'content' => http_build_query(['token' => $token]),
        ]]);
        $tekst = @file_get_contents(AUTH_INTROSPECTIE_URL, false, $context);
        $info = $tekst === false ? null : json_decode($tekst, true);
        if (!is_array($info) || !array_key_exists('active', $info)) {
            error_log('Introspection at the auth service failed.');
            return null;
        }
        return $info;
    }
}
