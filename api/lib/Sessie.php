<?php

// Login of the admin: the admin runs on another domain, where a cookie of the api would be a
// third-party cookie that browsers block. So logging in gives a random token, which the admin sends
// as "Authorization: Bearer <token>". Only the sha256 of the token is stored (sessies.csv),
// so the data files don't hold tokens that can be used.
class Sessie {
    const GELDIG = 12 * 3600; // seconds a token stays valid after logging in

    // the logged in user { id, username, email }, or null
    public static function user() {
        $sessie = self::sessie();
        if ($sessie === null) {
            return null;
        }
        $user = Data::userById($sessie['user_id']);
        if ($user === null) {
            return null;
        }
        return [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
        ];
    }

    // a new token for the user: { token, verloopt } (verloopt as ISO 8601)
    public static function login(array $user) {
        $token = bin2hex(random_bytes(32));
        $verloopt = time() + self::GELDIG;
        Csv::append(Data::sessiesFile(), Data::SESSIES, [hash('sha256', $token), $user['id'], $verloopt]);
        return ['token' => $token, 'verloopt' => date('c', $verloopt)];
    }

    // ends the session of the token in the request; the file is append only, so this adds a row
    // for the same token that expired right away
    public static function logout() {
        $sessie = self::sessie();
        if ($sessie !== null) {
            Csv::append(Data::sessiesFile(), Data::SESSIES, [$sessie['token_hash'], $sessie['user_id'], 0]);
        }
    }

    // for handlers of the admin environment: the logged in user, or false after sending a 401
    public static function vereisUser() {
        $user = self::user();
        if ($user === null) {
            Http::error(401, "Not logged in.");
            return false;
        }
        return $user;
    }

    // the valid session of the token in the request, or null
    private static function sessie() {
        $token = self::token();
        if ($token === null) {
            return null;
        }
        $sessie = Data::sessie(hash('sha256', $token));
        if ($sessie === null || (int) $sessie['verloopt'] < time()) {
            return null;
        }
        return $sessie;
    }

    // the token from "Authorization: Bearer <token>", or null
    // (under Apache the header only reaches PHP with CGIPassAuth On, see README)
    private static function token() {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $match) !== 1) {
            return null;
        }
        return strtolower($match[1]);
    }
}
