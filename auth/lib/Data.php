<?php

// Where the data of the auth service lives. Every file is append only: a change is a new row
// with the same key, and the last row counts. Tokens and codes are stored as their sha256 only.
class Data {
    // accounts, see Wachtwoord for salt, versleuteld_wachtwoord and wachtwoord_methode
    const GEBRUIKERS = ['id', 'gebruikersnaam', 'email', 'salt', 'versleuteld_wachtwoord', 'wachtwoord_methode'];
    // authorization codes, valid until verloopt (unix time); 0 once exchanged
    const CODES = ['code_hash', 'client_id', 'redirect_uri', 'code_challenge', 'gebruiker_id', 'verloopt'];
    // logins; verloopt is the end of the login (SESSIE_MAX), 0 once revoked
    const SESSIES = ['id', 'gebruiker_id', 'client_id', 'begonnen', 'verloopt'];
    // access and refresh tokens (soort); verloopt 0 for a refresh token that was used (rotation)
    const TOKENS = ['token_hash', 'soort', 'sessie_id', 'verloopt'];

    public static function file($naam) {
        return DATA_DIR . "/$naam.csv";
    }

    public static function append($naam, array $headers, array $row) {
        return Csv::append(self::file($naam), $headers, $row);
    }

    // the last row with $waarde in column $kolom, or null
    public static function laatste($naam, $kolom, $waarde) {
        $gevonden = null;
        foreach (Csv::read(self::file($naam)) as $row) {
            if (hash_equals($row[$kolom], $waarde)) {
                $gevonden = $row;
            }
        }
        return $gevonden;
    }

    // gebruiker by gebruikersnaam (not case sensitive), or null
    public static function gebruiker($gebruikersnaam) {
        foreach (Csv::read(self::file('gebruikers')) as $gebruiker) {
            if (strcasecmp($gebruiker['gebruikersnaam'], $gebruikersnaam) === 0) {
                return $gebruiker;
            }
        }
        return null;
    }

    public static function gebruikerById($id) {
        return self::laatste('gebruikers', 'id', $id);
    }

    // random UUID v4
    public static function uuid() {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
