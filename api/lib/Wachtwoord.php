<?php

// Storing and checking passwords of admin users (users.csv).
// password_method says how encrypted_password was made, so another method can be added later
// without breaking existing users:
//   password_hash   PHP's password_hash(); the hash holds its own algorithm and salt, the salt column stays empty
class Wachtwoord {
    const METHODE = 'password_hash';

    // [salt, encrypted_password, password_method] for a new password
    public static function versleutel($wachtwoord) {
        return ['', password_hash($wachtwoord, PASSWORD_DEFAULT), self::METHODE];
    }

    public static function klopt($wachtwoord, array $user) {
        switch ($user['password_method']) {
            case 'password_hash':
                return password_verify($wachtwoord, $user['encrypted_password']);
            default:
                return false;
        }
    }

    // takes as long as klopt(), for a username that does not exist,
    // so the response time does not tell which usernames exist
    public static function doeAlsOf($wachtwoord) {
        static $dummy = null;
        $dummy ??= password_hash('dummy', PASSWORD_DEFAULT);
        password_verify($wachtwoord, $dummy);
    }
}
