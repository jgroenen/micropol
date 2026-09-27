<?php

// Storing and checking the wachtwoorden of accounts (wachtwoord.ingesteld in beheer.jsonl, see Beheer).
// wachtwoord_methode says how versleuteld_wachtwoord was made, so another method can be added later
// without breaking existing accounts:
//   password_hash   PHP's password_hash(); the hash holds its own algorithm and salt
class Wachtwoord {
    const METHODE = 'password_hash';

    // { wachtwoord_methode, versleuteld_wachtwoord } for a new wachtwoord
    public static function versleutel($wachtwoord) {
        return ['wachtwoord_methode' => self::METHODE, 'versleuteld_wachtwoord' => password_hash($wachtwoord, PASSWORD_DEFAULT)];
    }

    public static function klopt($wachtwoord, array $account) {
        switch ($account['wachtwoord_methode']) {
            case 'password_hash':
                return password_verify($wachtwoord, $account['versleuteld_wachtwoord']);
            default:
                return false;
        }
    }

    // takes as long as klopt(), for a gebruikersnaam that does not exist,
    // so the response time does not tell which gebruikersnamen exist
    public static function doeAlsOf($wachtwoord) {
        static $dummy = null;
        $dummy ??= password_hash('dummy', PASSWORD_DEFAULT);
        password_verify($wachtwoord, $dummy);
    }
}
