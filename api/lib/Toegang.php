<?php

// Who has access to the beheer endpoints. Logging in (POST /sessie) gives a random token, which the admin
// sends as "Authorization: Bearer <token>". Only its sha256 is stored (sessies.csv, append only).
// A token stays valid while it is used: it expires SESSIE_IDLE seconds after it was last used, and
// SESSIE_MAX seconds after logging in at the latest. Logging out ends it at once.
// What an account may do follows from its roles, see Beheer.
class Toegang {
    // a use extends the token only when this much of its idle time has passed, so the file gets at most
    // one row per this many seconds per login
    const VERLENG_NA = 5 * 60;

    // logs in: a new token for the account id (or Beheer::INSTALLATIE) => { token, verloopt (ISO 8601) }
    public static function login($accountId) {
        $token = bin2hex(random_bytes(32));
        $verloopt = time() + SESSIE_IDLE;
        Data::voegToe('sessies', Data::SESSIES, [hash('sha256', $token), $accountId, time(), $verloopt]);
        return ['token' => $token, 'verloopt' => date('c', $verloopt)];
    }

    // logs out: the token of this request stops working
    public static function logout() {
        $sessie = self::sessie();
        if ($sessie !== null) {
            Data::voegToe('sessies', Data::SESSIES, [$sessie['token_hash'], $sessie['account_id'], $sessie['begonnen'], 0]);
        }
    }

    // the logged in account (see Beheer::accounts()), or null; a valid token is extended
    public static function account() {
        $sessie = self::sessie();
        $account = $sessie === null ? null : Beheer::account($sessie['account_id']);
        if ($account !== null) {
            self::verleng($sessie);
        }
        return $account;
    }

    // whether this request has the login of admin/admin, and there is still no superbeheerder
    public static function installatie() {
        $sessie = self::sessie();
        return $sessie !== null && $sessie['account_id'] === Beheer::INSTALLATIE && Beheer::installatieNodig();
    }

    // for the beheer endpoints: the logged in account; without one a 401
    public static function vereisAccount() {
        $account = self::account();
        if ($account === null) {
            header('WWW-Authenticate: Bearer');
            throw new HttpFout(401, 'Not logged in.');
        }
        return $account;
    }

    // the logged in account if it is an actief superbeheerder; otherwise a 401 or 403
    public static function vereisSuperbeheerder() {
        $account = self::vereisAccount();
        if (!Beheer::isSuperbeheerder($account)) {
            throw new HttpFout(403, 'Only for superbeheerders.');
        }
        return $account;
    }

    // the logged in account if it has one of the roles in the gesprek (and it is not paused), or it is
    // superbeheerder and that is one of the roles; otherwise a 401 or 403. The gesprek must exist.
    public static function vereisRol($gesprekId, array $rollen) {
        $account = self::vereisAccount();
        if (in_array(Beheer::ROL_SUPERBEHEERDER, $rollen, true) && Beheer::isSuperbeheerder($account)) {
            return $account;
        }
        if (Data::gesprekActief($gesprekId) && in_array(Beheer::rolIn($gesprekId, $account), $rollen, true)) {
            return $account;
        }
        throw new HttpFout(403, 'Not allowed for this gesprek.');
    }

    // the deelnemer_id of this request, or '' without one. A deelnemer sends it as "Authorization: Bearer
    // <deelnemer_id>": it is his key, like a token, and so never in a url or a body. A HttpFout 400 when it is
    // no valid deelnemer_id. (The endpoints of a deelnemer never take the token of a beheerder, and the other
    // way round, so the two never mix.)
    public static function deelnemerId() {
        $deelnemerId = Http::bearer() ?? '';
        if ($deelnemerId !== '' && !Data::deelnemerIdGeldig($deelnemerId)) {
            throw new HttpFout(400, 'The deelnemer_id may only have letters, digits and dashes, at most ' . Data::MAX_DEELNEMER_ID . '.');
        }
        return $deelnemerId;
    }

    // for the calls of a deelnemer: the deelnemer_id; without one a 401
    public static function vereisDeelnemer() {
        $deelnemerId = self::deelnemerId();
        if ($deelnemerId === '') {
            header('WWW-Authenticate: Bearer');
            throw new HttpFout(401, 'The deelnemer_id is required: Authorization: Bearer <deelnemer_id>.');
        }
        return $deelnemerId;
    }

    // for POST /installatie: only the login of admin/admin, while there is no superbeheerder
    public static function vereisInstallatie() {
        if (!self::installatie()) {
            header('WWW-Authenticate: Bearer');
            throw new HttpFout(401, 'Only right after installing, logged in as admin.');
        }
    }

    // the valid sessie of the token in this request, or null
    private static function sessie() {
        $token = Http::bearer();
        if ($token === null) {
            return null;
        }
        $sessie = Data::laatste('sessies', 'token_hash', hash('sha256', $token));
        if ($sessie === null || !isset($sessie['account_id']) || (int) $sessie['verloopt'] < time() || (int) $sessie['begonnen'] + SESSIE_MAX < time()) {
            return null;
        }
        return $sessie;
    }

    private static function verleng(array $sessie) {
        $nieuw = min(time() + SESSIE_IDLE, (int) $sessie['begonnen'] + SESSIE_MAX);
        if ($nieuw - (int) $sessie['verloopt'] >= self::VERLENG_NA) {
            Data::voegToe('sessies', Data::SESSIES, [$sessie['token_hash'], $sessie['account_id'], $sessie['begonnen'], $nieuw]);
        }
    }
}
