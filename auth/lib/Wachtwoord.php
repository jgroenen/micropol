<?php

// Storing and checking the wachtwoorden of gebruikers (gebruikers.csv).
// wachtwoord_methode says how versleuteld_wachtwoord was made, so another method can be added later
// without breaking existing gebruikers:
//   password_hash   PHP's password_hash(); the hash holds its own algorithm and salt, the salt column stays empty
class Wachtwoord {
    const METHODE = 'password_hash';

    // [salt, versleuteld_wachtwoord, wachtwoord_methode] for a new wachtwoord
    public static function versleutel($wachtwoord) {
        return ['', password_hash($wachtwoord, PASSWORD_DEFAULT), self::METHODE];
    }

    public static function klopt($wachtwoord, array $gebruiker) {
        switch ($gebruiker['wachtwoord_methode']) {
            case 'password_hash':
                return password_verify($wachtwoord, $gebruiker['versleuteld_wachtwoord']);
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
