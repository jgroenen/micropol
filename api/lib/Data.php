<?php

// Where the data lives and the lookups the handlers share. Handlers read and write through here.
//
// What happens in the gesprekken is stored as events, one JSON object per line (Jsonl), append only.
// There are three streams:
//   gesprekken.jsonl                  gesprek.aangemaakt, gesprek.aangepast (of all gesprekken)
//   gesprekken/<id>/stellingen.jsonl  stelling.toegevoegd, stelling.goedgekeurd, stelling.afgekeurd
//   gesprekken/<id>/antwoorden.jsonl  antwoord.gegeven
// Every event has id (UUID v7: sorting by id is sorting by time), tijdstip (unix time; null in data
// from before the events), type, door ({ soort, id } of who did it; null when unknown) and gesprek_id,
// plus the fields of its type, see event(). A gesprek, its stellingen and the antwoorden follow from
// reading the events in order: a change is a new event, and the last one counts.
//
// Beheerders and logins are no events of a gesprek: those are csv, where a change is a new row with
// the same key and the last row counts (Csv::lastPer).
class Data {
    // the columns of the csv files
    // who may log in to the admin; see Wachtwoord for salt, versleuteld_wachtwoord and wachtwoord_methode
    const BEHEERDERS = ['id', 'gebruikersnaam', 'email', 'salt', 'versleuteld_wachtwoord', 'wachtwoord_methode'];
    // logins, see Toegang; the token is stored as its sha256; verloopt 0 once logged out
    const SESSIES = ['token_hash', 'beheerder_id', 'begonnen', 'verloopt'];

    // the types of events, with their fields besides the ones every event has
    const GESPREK_AANGEMAAKT = 'gesprek.aangemaakt';       // titel, omschrijving, moderatie
    const GESPREK_AANGEPAST = 'gesprek.aangepast';         // only the fields that changed
    const STELLING_TOEGEVOEGD = 'stelling.toegevoegd';     // stelling_id, tekst
    const STELLING_GOEDGEKEURD = 'stelling.goedgekeurd';   // stelling_id, reden (optional)
    const STELLING_AFGEKEURD = 'stelling.afgekeurd';       // stelling_id, reden
    const ANTWOORD_GEGEVEN = 'antwoord.gegeven';           // stelling_id, waarde
    // the stream of each kind of event, by the part of the type before the dot
    const STROMEN = ['gesprek' => 'gesprekken', 'stelling' => 'stellingen', 'antwoord' => 'antwoorden'];
    // the fields of a gesprek besides its id
    const GESPREK_VELDEN = ['titel', 'omschrijving', 'moderatie'];
    // who does something: door.soort
    const DOOR_BEHEERDER = 'beheerder';
    const DOOR_DEELNEMER = 'deelnemer';

    // the values the data allows
    const WAARDEN = ['eens', 'neutraal', 'oneens'];
    // a deelnemer_id is made by the browser (a UUID); its length is limited, so nobody can fill the data with long ids
    const MAX_DEELNEMER_ID = 64;
    // stellingen are shown unless afgekeurd (blacklist), or only once goedgekeurd (whitelist), see zichtbaar()
    const MODERATIE_ACHTERAF = 'achteraf';
    const MODERATIE_VOORAF = 'vooraf';
    const MODERATIES = [self::MODERATIE_ACHTERAF, self::MODERATIE_VOORAF];
    const BEOORDELING_GOEDGEKEURD = 'goedgekeurd';
    const BEOORDELING_AFGEKEURD = 'afgekeurd';
    const BEOORDELING_WAARDEN = [self::BEOORDELING_GOEDGEKEURD, self::BEOORDELING_AFGEKEURD];
    // the event of each beoordeling, and back
    const BEOORDELING_EVENTS = [
        self::BEOORDELING_GOEDGEKEURD => self::STELLING_GOEDGEKEURD,
        self::BEOORDELING_AFGEKEURD => self::STELLING_AFGEKEURD,
    ];

    // ---- csv: beheerders and sessies

    // the csv file with this name, like 'beheerders'
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

    // ---- events

    // the file of a stream: 'gesprekken', or 'stellingen' or 'antwoorden' of one gesprek;
    // the gesprek id must have passed gesprekBestaat(), since it ends up in the path
    public static function stroom($naam, $gesprekId = null) {
        return DATA_DIR . ($naam === 'gesprekken' ? '/gesprekken.jsonl' : "/gesprekken/$gesprekId/$naam.jsonl");
    }

    // the events of a stream, oldest first (a generator)
    public static function events($naam, $gesprekId = null) {
        return Jsonl::read(self::stroom($naam, $gesprekId));
    }

    // a new event, not yet stored; $door from doorBeheerder() or doorDeelnemer()
    public static function event($type, ?array $door, $gesprekId, array $velden) {
        return ['id' => self::uuid7(), 'tijdstip' => time(), 'type' => $type, 'door' => $door, 'gesprek_id' => $gesprekId] + $velden;
    }

    // stores a new event in its stream and returns it
    public static function voegEventToe($type, ?array $door, $gesprekId, array $velden) {
        $stroom = self::STROMEN[strstr($type, '.', true)];
        return Jsonl::append(self::stroom($stroom, $gesprekId), self::event($type, $door, $gesprekId, $velden));
    }

    public static function doorBeheerder(array $beheerder) {
        return ['soort' => self::DOOR_BEHEERDER, 'id' => $beheerder['id']];
    }

    // whether a deelnemer_id is at most MAX_DEELNEMER_ID letters, digits and dashes
    public static function deelnemerIdGeldig($deelnemerId) {
        return preg_match('/^[A-Za-z0-9-]{1,' . self::MAX_DEELNEMER_ID . '}$/', $deelnemerId) === 1;
    }

    public static function doorDeelnemer($deelnemerId) {
        return ['soort' => self::DOOR_DEELNEMER, 'id' => $deelnemerId];
    }

    // ---- gesprekken

    // all gesprekken { id, titel, omschrijving, moderatie }, in the order they were created
    public static function gesprekken() {
        return array_values(self::alleGesprekken());
    }

    public static function gesprek($id) {
        return self::alleGesprekken()[$id] ?? null;
    }

    // also guards the file names, since a gesprek id ends up in a path
    public static function gesprekBestaat($id) {
        return preg_match('/^[A-Za-z0-9-]+$/', $id) === 1 && self::gesprek($id) !== null;
    }

    // [id => gesprek]
    private static function alleGesprekken() {
        $velden = array_flip(self::GESPREK_VELDEN);
        $gesprekken = [];
        foreach (self::events('gesprekken') as $event) {
            $id = $event['gesprek_id'];
            if ($event['type'] === self::GESPREK_AANGEMAAKT) {
                $gesprekken[$id] = ['id' => $id] + array_fill_keys(self::GESPREK_VELDEN, '');
            }
            if (isset($gesprekken[$id])) {
                $gesprekken[$id] = array_merge($gesprekken[$id], array_intersect_key($event, $velden));
            }
        }
        return $gesprekken;
    }

    // ---- stellingen

    // stellingen of one gesprek, in the order they were added:
    // { id, gesprek_id, tekst, deelnemer_id, beoordeling (or null), reden, zichtbaar }
    public static function stellingen($gesprekId) {
        return array_values(self::alleStellingen($gesprekId));
    }

    // one stelling like stellingen(), or null
    public static function stelling($gesprekId, $stellingId) {
        return self::alleStellingen($gesprekId)[$stellingId] ?? null;
    }

    // whether a stelling is shown to deelnemers, given the moderatie of its gesprek and its beoordeling (or null)
    public static function zichtbaar($moderatie, $beoordeling) {
        return $moderatie === self::MODERATIE_VOORAF
            ? $beoordeling === self::BEOORDELING_GOEDGEKEURD
            : $beoordeling !== self::BEOORDELING_AFGEKEURD;
    }

    // the stellingen deelnemers see, answer and that count in the analyse
    public static function zichtbareStellingen($gesprekId) {
        return array_values(array_filter(self::stellingen($gesprekId), function ($stelling) {
            return $stelling['zichtbaar'];
        }));
    }

    public static function stellingZichtbaar($stellingId, $gesprekId) {
        return in_array($stellingId, array_column(self::zichtbareStellingen($gesprekId), 'id'), true);
    }

    // [id => stelling]; the last beoordeling counts
    private static function alleStellingen($gesprekId) {
        $beoordelingen = array_flip(self::BEOORDELING_EVENTS);
        $stellingen = [];
        foreach (self::events('stellingen', $gesprekId) as $event) {
            $id = $event['stelling_id'];
            if ($event['type'] === self::STELLING_TOEGEVOEGD) {
                $stellingen[$id] = [
                    'id' => $id,
                    'gesprek_id' => $gesprekId,
                    'tekst' => $event['tekst'],
                    'deelnemer_id' => $event['door']['id'] ?? '',
                    'beoordeling' => null,
                    'reden' => '',
                ];
            } elseif (isset($stellingen[$id], $beoordelingen[$event['type']])) {
                $stellingen[$id]['beoordeling'] = $beoordelingen[$event['type']];
                $stellingen[$id]['reden'] = $event['reden'] ?? '';
            }
        }
        $moderatie = self::gesprek($gesprekId)['moderatie'] ?? self::MODERATIE_ACHTERAF;
        foreach ($stellingen as &$stelling) {
            $stelling['zichtbaar'] = self::zichtbaar($moderatie, $stelling['beoordeling']);
        }
        unset($stelling);
        return $stellingen;
    }

    // ---- antwoorden

    // [deelnemer_id => [stelling_id => waarde]], deelnemers in order of their first antwoord;
    // the last antwoord of a deelnemer on a stelling counts
    public static function matrix($gesprekId) {
        $rows = [];
        foreach (self::events('antwoorden', $gesprekId) as $event) {
            $rows[$event['door']['id']][$event['stelling_id']] = $event['waarde'];
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

    // ---- beheerders

    // beheerder by gebruikersnaam (not case sensitive), or null
    public static function beheerder($gebruikersnaam) {
        foreach (self::beheerders() as $beheerder) {
            if (strcasecmp($beheerder['gebruikersnaam'], $gebruikersnaam) === 0) {
                return $beheerder;
            }
        }
        return null;
    }

    public static function beheerderMetId($id) {
        return self::beheerders()[$id] ?? null;
    }

    // [id => beheerder]
    public static function beheerders() {
        return Csv::lastPer(self::bestand('beheerders'), 'id');
    }

    // ---- ids

    // random UUID v4, for gesprekken and stellingen
    public static function uuid() {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant RFC 4122
        return self::uuidTekst($bytes);
    }

    // UUID v7, for events: it starts with the time in milliseconds, so ids sort by time;
    // within this process always higher than the one before, also within the same millisecond
    public static function uuid7() {
        static $vorige = 0;
        $vorige = max((int) floor(microtime(true) * 1000), $vorige + 1);
        $bytes = substr(pack('J', $vorige), 2) . random_bytes(10);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x70); // version 7
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant RFC 4122
        return self::uuidTekst($bytes);
    }

    private static function uuidTekst($bytes) {
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
