<?php

// Who has access to the beheer endpoints. Logging in (POST /sessie) gives a random token, which the admin
// sends as "Authorization: Bearer <token>". Only its sha256 is stored (sessies.csv, append only).
// A token stays valid while it is used: it expires SESSIE_IDLE seconds after it was last used, and
// SESSIE_MAX seconds after logging in at the latest. Logging out ends it at once.
class Toegang {
    // a use extends the token only when this much of its idle time has passed, so the file gets at most
    // one row per this many seconds per login
    const VERLENG_NA = 5 * 60;

    // logs in: a new token for the beheerder => { token, verloopt (ISO 8601) }
    public static function login(array $beheerder) {
        $token = bin2hex(random_bytes(32));
        $verloopt = time() + SESSIE_IDLE;
        Data::voegToe('sessies', Data::SESSIES, [hash('sha256', $token), $beheerder['id'], time(), $verloopt]);
        return ['token' => $token, 'verloopt' => date('c', $verloopt)];
    }

    // logs out: the token of this request stops working
    public static function logout() {
        $sessie = self::sessie();
        if ($sessie !== null) {
            Data::voegToe('sessies', Data::SESSIES, [$sessie['token_hash'], $sessie['beheerder_id'], $sessie['begonnen'], 0]);
        }
    }

    // the logged in beheerder { id, gebruikersnaam, email }, or null; a valid token is extended
    public static function beheerder() {
        $sessie = self::sessie();
        if ($sessie === null) {
            return null;
        }
        $beheerder = Data::beheerderMetId($sessie['beheerder_id']);
        if ($beheerder === null) {
            return null;
        }
        self::verleng($sessie);
        return ['id' => $beheerder['id'], 'gebruikersnaam' => $beheerder['gebruikersnaam'], 'email' => $beheerder['email']];
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

    // the valid sessie of the token in this request, or null
    private static function sessie() {
        $token = Http::bearer();
        if ($token === null) {
            return null;
        }
        $sessie = Data::laatste('sessies', 'token_hash', hash('sha256', $token));
        if ($sessie === null || (int) $sessie['verloopt'] < time() || (int) $sessie['begonnen'] + SESSIE_MAX < time()) {
            return null;
        }
        return $sessie;
    }

    private static function verleng(array $sessie) {
        $nieuw = min(time() + SESSIE_IDLE, (int) $sessie['begonnen'] + SESSIE_MAX);
        if ($nieuw - (int) $sessie['verloopt'] >= self::VERLENG_NA) {
            Data::voegToe('sessies', Data::SESSIES, [$sessie['token_hash'], $sessie['beheerder_id'], $sessie['begonnen'], $nieuw]);
        }
    }
}
