<?php

// Where the data lives and the lookups the handlers share. Every file is append only: a change is a
// new row with the same key, and the last row counts (Csv::lastPer). Handlers read and write through here.
class Data {
    // the columns of each file
    const GESPREKKEN = ['id', 'titel', 'omschrijving', 'moderatie'];
    const STELLINGEN = ['id', 'gesprek_id', 'tekst', 'deelnemer_id'];
    const ANTWOORDEN = ['deelnemer_id', 'stelling_id', 'waarde'];
    // tijdstip is unix time, like every time in the data
    const BEOORDELINGEN = ['stelling_id', 'beoordeling', 'reden', 'beheerder_id', 'tijdstip'];
    // who may log in to the admin; see Wachtwoord for salt, versleuteld_wachtwoord and wachtwoord_methode
    const BEHEERDERS = ['id', 'gebruikersnaam', 'email', 'salt', 'versleuteld_wachtwoord', 'wachtwoord_methode'];
    // logins, see Toegang; the token is stored as its sha256; verloopt 0 once logged out
    const SESSIES = ['token_hash', 'beheerder_id', 'begonnen', 'verloopt'];

    // the values the data allows
    const WAARDEN = ['eens', 'neutraal', 'oneens'];
    // stellingen are shown unless afgekeurd (blacklist), or only once goedgekeurd (whitelist), see zichtbaar()
    const MODERATIE_ACHTERAF = 'achteraf';
    const MODERATIE_VOORAF = 'vooraf';
    const MODERATIES = [self::MODERATIE_ACHTERAF, self::MODERATIE_VOORAF];
    const BEOORDELING_GOEDGEKEURD = 'goedgekeurd';
    const BEOORDELING_AFGEKEURD = 'afgekeurd';
    const BEOORDELING_WAARDEN = [self::BEOORDELING_GOEDGEKEURD, self::BEOORDELING_AFGEKEURD];

    // the file with this name, like 'gesprekken' or 'antwoorden/<gesprek_id>';
    // a gesprek id in the name must have passed gesprekBestaat()
    public static function bestand($naam) {
        return DATA_DIR . "/$naam.csv";
    }

    // adds a row (values in the order of $kolommen) and returns it as an associative array
    public static function voegToe($naam, array $kolommen, array $rij) {
        return Csv::append(self::bestand($naam), $kolommen, $rij);
    }

    // the last row with $waarde in column $kolom, or null
    public static function laatste($naam, $kolom, $waarde) {
        return Csv::lastPer(self::bestand($naam), $kolom)[$waarde] ?? null;
    }

    // all gesprekken, in the order they were created
    public static function gesprekken() {
        return array_values(Csv::lastPer(self::bestand('gesprekken'), 'id'));
    }

    public static function gesprek($id) {
        return self::laatste('gesprekken', 'id', $id);
    }

    // also guards the file names, since a gesprek id ends up in a path
    public static function gesprekBestaat($id) {
        return preg_match('/^[A-Za-z0-9-]+$/', $id) === 1 && self::gesprek($id) !== null;
    }

    // stellingen of one gesprek, in the order they were added
    public static function stellingen($gesprekId) {
        return array_values(array_filter(
            Csv::read(self::bestand('stellingen')),
            function ($stelling) use ($gesprekId) {
                return $stelling['gesprek_id'] === $gesprekId;
            }
        ));
    }

    // [stelling_id => the last beoordeling]
    public static function beoordelingen($gesprekId) {
        return Csv::lastPer(self::bestand("beoordelingen/$gesprekId"), 'stelling_id');
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
        return in_array($stellingId, array_column(self::zichtbareStellingen($gesprekId), 'id'), true);
    }

    // all antwoorden of a gesprek, oldest first
    public static function antwoorden($gesprekId) {
        return Csv::read(self::bestand("antwoorden/$gesprekId"));
    }

    // [deelnemer_id => [stelling_id => waarde]], deelnemers in order of their first antwoord;
    // the last antwoord of a deelnemer on a stelling counts
    public static function matrix($gesprekId) {
        $rows = [];
        foreach (self::antwoorden($gesprekId) as $antwoord) {
            $rows[$antwoord['deelnemer_id']][$antwoord['stelling_id']] = $antwoord['waarde'];
        }
        return $rows;
    }

    // the deelnemers of a gesprek, anonymous: [{ nummer, antwoorden: { stelling_id: waarde } }], numbered in
    // order of their first antwoord; with $stellingIds only the antwoorden on those (a deelnemer without any stays,
    // so the numbers are the same everywhere)
    public static function deelnemers($gesprekId, ?array $stellingIds = null) {
        $alleen = $stellingIds === null ? null : array_flip($stellingIds);
        $deelnemers = [];
        foreach (array_values(self::matrix($gesprekId)) as $index => $antwoorden) {
            $deelnemers[] = [
                'nummer' => $index + 1,
                // an object, also when empty or with numeric stelling ids
                'antwoorden' => (object) ($alleen === null ? $antwoorden : array_intersect_key($antwoorden, $alleen)),
            ];
        }
        return $deelnemers;
    }

    // [stelling_id => { eens, neutraal, oneens }]: how many deelnemers gave each antwoord,
    // the last antwoord of each deelnemer counts
    public static function tellingen($gesprekId) {
        $tellingen = [];
        foreach (self::matrix($gesprekId) as $antwoorden) {
            foreach ($antwoorden as $stellingId => $waarde) {
                $tellingen[$stellingId] ??= array_fill_keys(self::WAARDEN, 0);
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
            $stelling['antwoorden'] = $tellingen[$stelling['id']] ?? array_fill_keys(self::WAARDEN, 0);
            return $stelling;
        }, $stellingen);
    }

    // beheerder by gebruikersnaam (not case sensitive), or null
    public static function beheerder($gebruikersnaam) {
        foreach (Csv::lastPer(self::bestand('beheerders'), 'id') as $beheerder) {
            if (strcasecmp($beheerder['gebruikersnaam'], $gebruikersnaam) === 0) {
                return $beheerder;
            }
        }
        return null;
    }

    public static function beheerderMetId($id) {
        return self::laatste('beheerders', 'id', $id);
    }

    // random UUID v4
    public static function uuid() {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
