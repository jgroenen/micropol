<?php

// Where the data lives and the lookups the handlers share.
class Data {
    // moderatie: MODERATIE_ACHTERAF or MODERATIE_VOORAF, see zichtbaar()
    const GESPREKKEN = ['id', 'titel', 'omschrijving', 'moderatie'];
    const STELLINGEN = ['id', 'gesprek_id', 'content', 'user_id'];
    const ANTWOORDEN = ['user_id', 'stelling_id', 'waarde'];
    // beoordeling of a stelling by an admin (user_id): BEOORDELING_GOEDGEKEURD or BEOORDELING_AFGEKEURD
    const BEOORDELINGEN = ['stelling_id', 'beoordeling', 'reden', 'user_id', 'tijdstip'];

    // stellingen are shown unless afgekeurd (blacklist), or only once goedgekeurd (whitelist)
    const MODERATIE_ACHTERAF = 'achteraf';
    const MODERATIE_VOORAF = 'vooraf';
    const BEOORDELING_GOEDGEKEURD = 'goedgekeurd';
    const BEOORDELING_AFGEKEURD = 'afgekeurd';
    // admin users, see Wachtwoord for salt, encrypted_password and password_method
    const USERS = ['id', 'username', 'email', 'salt', 'encrypted_password', 'password_method'];
    // login tokens of admin users, see Sessie; verloopt is a unix timestamp
    const SESSIES = ['token_hash', 'user_id', 'verloopt'];

    public static function gesprekkenFile() {
        return DATA_DIR . '/gesprekken.csv';
    }

    public static function stellingenFile() {
        return DATA_DIR . '/stellingen.csv';
    }

    public static function usersFile() {
        return DATA_DIR . '/users.csv';
    }

    public static function sessiesFile() {
        return DATA_DIR . '/sessies.csv';
    }

    // one file per gesprek; only call with an id that passed gesprekExists()
    public static function antwoordenFile($gesprekId) {
        return DATA_DIR . '/antwoorden/' . $gesprekId . '.csv';
    }

    // one file per gesprek; only call with an id that passed gesprekExists()
    public static function beoordelingenFile($gesprekId) {
        return DATA_DIR . '/beoordelingen/' . $gesprekId . '.csv';
    }

    // the file is append only: a changed gesprek is a new row with the same id, the last row counts;
    // gesprekken stay in the order they were created
    public static function gesprekken() {
        $gesprekken = [];
        foreach (Csv::read(self::gesprekkenFile()) as $gesprek) {
            $gesprekken[$gesprek['id']] = $gesprek;
        }
        return array_values($gesprekken);
    }

    public static function gesprek($id) {
        foreach (self::gesprekken() as $gesprek) {
            if ($gesprek['id'] === $id) {
                return $gesprek;
            }
        }
        return null;
    }

    // also guards the file name, since a gesprek id ends up in a path
    public static function gesprekExists($id) {
        return preg_match('/^[A-Za-z0-9-]+$/', $id) === 1 && self::gesprek($id) !== null;
    }

    // stellingen of one gesprek, in the order they were added
    public static function stellingen($gesprekId) {
        return array_values(array_filter(
            Csv::read(self::stellingenFile()),
            function ($stelling) use ($gesprekId) {
                return $stelling['gesprek_id'] === $gesprekId;
            }
        ));
    }

    // [stelling_id => beoordeling row]; the file is append only, the last beoordeling counts
    public static function beoordelingen($gesprekId) {
        $beoordelingen = [];
        foreach (Csv::read(self::beoordelingenFile($gesprekId)) as $beoordeling) {
            $beoordelingen[$beoordeling['stelling_id']] = $beoordeling;
        }
        return $beoordelingen;
    }

    // whether a stelling is shown to deelnemers, given the moderatie of its gesprek and its beoordeling (or null)
    public static function zichtbaar($moderatie, $beoordeling) {
        return $moderatie === self::MODERATIE_VOORAF
            ? $beoordeling === self::BEOORDELING_GOEDGEKEURD
            : $beoordeling !== self::BEOORDELING_AFGEKEURD;
    }

    // stellingen of one gesprek with their moderatie state: beoordeling (or null), reden, zichtbaar
    public static function stellingenMetBeoordeling($gesprekId) {
        $moderatie = self::gesprek($gesprekId)['moderatie'] ?? self::MODERATIE_ACHTERAF;
        $beoordelingen = self::beoordelingen($gesprekId);
        return array_map(function ($stelling) use ($moderatie, $beoordelingen) {
            $beoordeling = $beoordelingen[$stelling['id']] ?? null;
            $stelling['beoordeling'] = $beoordeling['beoordeling'] ?? null;
            $stelling['reden'] = $beoordeling['reden'] ?? '';
            $stelling['zichtbaar'] = self::zichtbaar($moderatie, $stelling['beoordeling']);
            return $stelling;
        }, self::stellingen($gesprekId));
    }

    // the stellingen deelnemers see, answer and that count in the analyse
    public static function zichtbareStellingen($gesprekId) {
        return array_values(array_filter(self::stellingenMetBeoordeling($gesprekId), function ($stelling) {
            return $stelling['zichtbaar'];
        }));
    }

    public static function stellingZichtbaar($stellingId, $gesprekId) {
        foreach (self::zichtbareStellingen($gesprekId) as $stelling) {
            if ($stelling['id'] === $stellingId) {
                return true;
            }
        }
        return false;
    }

    // all antwoorden of a gesprek, oldest first
    public static function antwoorden($gesprekId) {
        return Csv::read(self::antwoordenFile($gesprekId));
    }

    // [user_id => [stelling_id => waarde]], deelnemers in order of their first antwoord;
    // the file is append only, so a later antwoord overrides an earlier one
    public static function matrix($gesprekId) {
        $rows = [];
        foreach (self::antwoorden($gesprekId) as $antwoord) {
            $rows[$antwoord['user_id']][$antwoord['stelling_id']] = $antwoord['waarde'];
        }
        return $rows;
    }

    // admin user by username (not case sensitive), or null
    public static function user($username) {
        foreach (Csv::read(self::usersFile()) as $user) {
            if (strcasecmp($user['username'], $username) === 0) {
                return $user;
            }
        }
        return null;
    }

    // [stelling_id => { eens, neutraal, oneens }]: how many deelnemers gave each antwoord,
    // the last antwoord of each deelnemer counts
    public static function tellingen($gesprekId) {
        $tellingen = [];
        foreach (self::matrix($gesprekId) as $antwoorden) {
            foreach ($antwoorden as $stellingId => $waarde) {
                $tellingen[$stellingId] ??= ['eens' => 0, 'neutraal' => 0, 'oneens' => 0];
                if (isset($tellingen[$stellingId][$waarde])) {
                    $tellingen[$stellingId][$waarde]++;
                }
            }
        }
        return $tellingen;
    }

    // the stellingen with antwoorden { eens, neutraal, oneens } added
    public static function metTellingen($gesprekId, array $stellingen) {
        $tellingen = self::tellingen($gesprekId);
        return array_map(function ($stelling) use ($tellingen) {
            $stelling['antwoorden'] = $tellingen[$stelling['id']] ?? ['eens' => 0, 'neutraal' => 0, 'oneens' => 0];
            return $stelling;
        }, $stellingen);
    }

    public static function userById($id) {
        foreach (Csv::read(self::usersFile()) as $user) {
            if ($user['id'] === $id) {
                return $user;
            }
        }
        return null;
    }

    // session row by the hash of its token, or null; the file is append only, the last row counts
    public static function sessie($tokenHash) {
        $gevonden = null;
        foreach (Csv::read(self::sessiesFile()) as $sessie) {
            if (hash_equals($sessie['token_hash'], $tokenHash)) {
                $gevonden = $sessie;
            }
        }
        return $gevonden;
    }

    // random UUID v4
    public static function uuid() {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
