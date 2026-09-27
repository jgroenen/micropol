<?php

// Where the data lives and the lookups the handlers share. Handlers read and write through here.
//
// What happens is stored as events, one JSON object per line (Jsonl), append only. There are these streams:
//   gesprekken.jsonl                  gesprek.aangemaakt, .aangepast, .opgeschort, .hersteld, .beeindigd
//   gesprekken/<id>/stellingen.jsonl  stelling.toegevoegd, stelling.goedgekeurd, stelling.afgekeurd
//   gesprekken/<id>/antwoorden.jsonl  antwoord.gegeven
//   gesprekken/<id>/team.jsonl        lid.toegevoegd, .opgeschort, .hersteld, .verwijderd (see Beheer)
//   gesprekken/<id>/kanalen.jsonl     kanaal.aangemaakt, .aangepast, .ingetrokken; panel.aangemaakt, .uitgebreid,
//                                     .aangepast, .ingetrokken
// and one file that is no stream: gesprekken/<id>/paneltellingen.json, see telPanellink()
//   beheer.jsonl                      accounts, wachtwoorden, superbeheerders and uitnodigingen (see Beheer)
// Every event has id (UUID v7: sorting by id is sorting by time), tijdstip (unix time; null in data
// from before the events), type, door ({ soort, id } of who did it; null when unknown) and gesprek_id
// (null in beheer.jsonl when it is about no gesprek), plus the fields of its type, see event(). The state
// follows from reading the events in order: a change is a new event, and the last one counts.
//
// Logins are no events: those are csv, where a change is a new row with the same key and the last row
// counts (Csv::lastPer).
class Data {
    // logins, see Toegang; the token is stored as its sha256; verloopt 0 once logged out
    const SESSIES = ['token_hash', 'account_id', 'begonnen', 'verloopt'];

    // the types of events, with their fields besides the ones every event has
    const GESPREK_AANGEMAAKT = 'gesprek.aangemaakt';       // titel, omschrijving, moderatie
    const GESPREK_AANGEPAST = 'gesprek.aangepast';         // only the fields that changed
    const GESPREK_OPGESCHORT = 'gesprek.opgeschort';       // reden; paused: deelnemers see a notice
    const GESPREK_BEEINDIGD = 'gesprek.beeindigd';         // reden; over: deelnemers see that it is over
    const GESPREK_HERSTELD = 'gesprek.hersteld';           // open again, after opgeschort or beeindigd
    const STELLING_TOEGEVOEGD = 'stelling.toegevoegd';     // stelling_id, tekst, kanaal_id or panel_id (see deelnameVelden())
    const STELLING_GOEDGEKEURD = 'stelling.goedgekeurd';   // stelling_id, reden (optional)
    const STELLING_AFGEKEURD = 'stelling.afgekeurd';       // stelling_id, reden
    const ANTWOORD_GEGEVEN = 'antwoord.gegeven';           // stelling_id, waarde, kanaal_id or panel_id (see deelnameVelden())
    // a kanaal: a link for taking part, handed to one promotion channel (like a newsletter); see kanalen()
    const KANAAL_AANGEMAAKT = 'kanaal.aangemaakt';         // kanaal_id, naam, token
    const KANAAL_AANGEPAST = 'kanaal.aangepast';           // kanaal_id, meetellen
    const KANAAL_INGETROKKEN = 'kanaal.ingetrokken';       // kanaal_id, meetellen (not for a link of a panel)
    // a panel: a set of kanalen, one link per panellid; the members are known elsewhere, see panels()
    const PANEL_AANGEMAAKT = 'panel.aangemaakt';           // panel_id, naam, links: [{ kanaal_id, token, nummer }]
    const PANEL_UITGEBREID = 'panel.uitgebreid';           // panel_id, links: more links, numbered on
    const PANEL_AANGEPAST = 'panel.aangepast';             // panel_id, meetellen
    const PANEL_INGETROKKEN = 'panel.ingetrokken';         // panel_id, meetellen: all its links work no more
    // the stream of each kind of event, by the part of the type before the dot; the events of Beheer too
    const STROMEN = [
        'gesprek' => 'gesprekken', 'stelling' => 'stellingen', 'antwoord' => 'antwoorden', 'lid' => 'team', 'kanaal' => 'kanalen', 'panel' => 'kanalen',
        'account' => 'beheer', 'wachtwoord' => 'beheer', 'superbeheerder' => 'beheer', 'uitnodiging' => 'beheer',
    ];
    // the streams of the whole server; the others are per gesprek
    const ALGEMENE_STROMEN = ['gesprekken', 'beheer'];
    // the fields of a gesprek besides its id
    // zonder_kanaal: whether deelnemers may take part without a kanaal (true), or only through the link of one
    const GESPREK_VELDEN = ['titel', 'omschrijving', 'moderatie', 'zonder_kanaal'];
    // who does something: door.soort; a beheerder is anyone with an account (see Beheer)
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
    // the status of a superbeheerder or a lid of a team (see Beheer): opgeschort is for a while, with a
    // reden; verwijderd takes the access away for good (the events stay)
    const STATUS_ACTIEF = 'actief';
    const STATUS_OPGESCHORT = 'opgeschort';
    const STATUS_VERWIJDERD = 'verwijderd';
    const STATUSSEN = [self::STATUS_ACTIEF, self::STATUS_OPGESCHORT, self::STATUS_VERWIJDERD];
    // the status of a gesprek: opgeschort (paused) or beeindigd (over); both with a reden, both take no
    // antwoorden or stellingen and shut out the team, and both can be undone. A gesprek is never removed.
    const STATUS_BEEINDIGD = 'beeindigd';
    // the status of a kanaal
    const KANAAL_ACTIEF = 'actief';
    const KANAAL_INGETROKKEN_STATUS = 'ingetrokken';
    const GESPREK_STATUSSEN = [self::STATUS_ACTIEF, self::STATUS_OPGESCHORT, self::STATUS_BEEINDIGD];
    // the event that gives a gesprek each status, and back
    const GESPREK_STATUS_EVENTS = [
        self::STATUS_ACTIEF => self::GESPREK_HERSTELD,
        self::STATUS_OPGESCHORT => self::GESPREK_OPGESCHORT,
        self::STATUS_BEEINDIGD => self::GESPREK_BEEINDIGD,
    ];
    // the event of each beoordeling, and back
    const BEOORDELING_EVENTS = [
        self::BEOORDELING_GOEDGEKEURD => self::STELLING_GOEDGEKEURD,
        self::BEOORDELING_AFGEKEURD => self::STELLING_AFGEKEURD,
    ];

    // ---- csv: sessies

    // the csv file with this name, like 'sessies'
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

    // the file of a stream: 'gesprekken' or 'beheer', or 'stellingen', 'antwoorden' or 'team' of one gesprek;
    // the gesprek id must have passed gesprekBestaat(), since it ends up in the path
    public static function stroom($naam, $gesprekId = null) {
        return DATA_DIR . (in_array($naam, self::ALGEMENE_STROMEN, true) ? "/$naam.jsonl" : "/gesprekken/$gesprekId/$naam.jsonl");
    }

    // the events of a stream, oldest first (a generator)
    public static function events($naam, $gesprekId = null) {
        return Jsonl::read(self::stroom($naam, $gesprekId));
    }

    // a new event, not yet stored; $door from doorBeheerder() or doorDeelnemer(); $gesprekId is null when
    // it is about no gesprek
    public static function event($type, ?array $door, $gesprekId, array $velden) {
        return ['id' => self::uuid7(), 'tijdstip' => time(), 'type' => $type, 'door' => $door, 'gesprek_id' => $gesprekId] + $velden;
    }

    // stores a new event in its stream and returns it
    public static function voegEventToe($type, ?array $door, $gesprekId, array $velden) {
        $stroom = self::STROMEN[strstr($type, '.', true)];
        return Jsonl::append(self::stroom($stroom, $gesprekId), self::event($type, $door, $gesprekId, $velden));
    }

    // $account from Beheer, or anything with an id
    public static function doorBeheerder(array $account) {
        return ['soort' => self::DOOR_BEHEERDER, 'id' => $account['id']];
    }

    // whether a deelnemer_id is at most MAX_DEELNEMER_ID letters, digits and dashes
    public static function deelnemerIdGeldig($deelnemerId) {
        return preg_match('/^[A-Za-z0-9-]{1,' . self::MAX_DEELNEMER_ID . '}$/', $deelnemerId) === 1;
    }

    public static function doorDeelnemer($deelnemerId) {
        return ['soort' => self::DOOR_DEELNEMER, 'id' => $deelnemerId];
    }

    // ---- gesprekken

    // all gesprekken { id, titel, omschrijving, moderatie, status }, in the order they were created
    public static function gesprekken() {
        return array_values(self::alleGesprekken());
    }

    // the gesprek, or null
    public static function gesprek($id) {
        return self::alleGesprekken()[$id] ?? null;
    }

    // also guards the file names, since a gesprek id ends up in a path
    public static function gesprekBestaat($id) {
        return preg_match('/^[A-Za-z0-9-]+$/', $id) === 1 && self::gesprek($id) !== null;
    }

    // whether deelnemers can take part: it exists and is not paused (opgeschort) or over (beeindigd)
    public static function gesprekActief($id) {
        return self::gesprekBestaat($id) && self::gesprek($id)['status'] === self::STATUS_ACTIEF;
    }

    // [id => gesprek]
    private static function alleGesprekken() {
        $velden = array_flip(self::GESPREK_VELDEN);
        $statussen = array_flip(self::GESPREK_STATUS_EVENTS);
        $gesprekken = [];
        foreach (self::events('gesprekken') as $event) {
            $id = $event['gesprek_id'];
            if ($event['type'] === self::GESPREK_AANGEMAAKT) {
                $gesprekken[$id] = ['id' => $id] + array_fill_keys(self::GESPREK_VELDEN, '') + ['status' => self::STATUS_ACTIEF];
                // gesprekken of before the kanalen: everyone may take part
                $gesprekken[$id]['zonder_kanaal'] = true;
            }
            if (!isset($gesprekken[$id])) {
                continue;
            }
            $gesprekken[$id] = array_merge($gesprekken[$id], array_intersect_key($event, $velden));
            if (isset($statussen[$event['type']])) {
                $gesprekken[$id]['status'] = $statussen[$event['type']];
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
    // the last antwoord of a deelnemer on a stelling counts. Antwoorden through a kanaal or a panel that does
    // not count (meetellen false) are left out, unless $alles
    public static function matrix($gesprekId, $alles = false) {
        [$kanalen, $panels] = $alles ? [[], []] : self::kanalenEnPanels($gesprekId);
        $telNiet = function ($ding) {
            return !$ding['meetellen'];
        };
        $uit = array_filter($kanalen, $telNiet);
        $panelsUit = array_filter($panels, $telNiet);
        $rows = [];
        foreach (self::events('antwoorden', $gesprekId) as $event) {
            if (!isset($uit[$event['kanaal_id'] ?? '']) && !isset($panelsUit[$event['panel_id'] ?? ''])) {
                $rows[$event['door']['id']][$event['stelling_id']] = $event['waarde'];
            }
        }
        return $rows;
    }

    // ---- kanalen

    // the kanalen of a gesprek: [kanaal_id => { kanaal_id, naam, token, status (actief or ingetrokken), meetellen,
    // panel_id, nummer }]. A kanaal is a link for taking part (the app with ?kanaal=<token>), for one promotion
    // channel. The token is no secret: the channel shares the link openly. Ingetrokken, the link works no more;
    // whether the antwoorden through it count (meetellen) can change any time.
    // The links of a panel are kanalen too, with their panel_id and nummer; their status and meetellen follow
    // their panel, and one link can be withdrawn on its own.
    public static function kanalen($gesprekId) {
        return self::kanalenEnPanels($gesprekId)[0];
    }

    // the panels of a gesprek: [panel_id => { panel_id, naam, status, meetellen, links }] (links: how many).
    // A panel is a set of links, one per panellid, whose members are known only elsewhere (like a research
    // agency): MiniPol keeps no name, only a nummer per link. An antwoord through a link keeps only the panel,
    // not the link, so no deelnemer can be linked to his link; how often each link was used is counted apart,
    // see telPanellink().
    public static function panels($gesprekId) {
        return self::kanalenEnPanels($gesprekId)[1];
    }

    private static function kanalenEnPanels($gesprekId) {
        $kanalen = [];
        $panels = [];
        foreach (self::events('kanalen', $gesprekId) as $event) {
            $id = $event['kanaal_id'] ?? null;
            $panelId = $event['panel_id'] ?? null;
            switch ($event['type']) {
                case self::KANAAL_AANGEMAAKT:
                    $kanalen[$id] = ['kanaal_id' => $id, 'naam' => $event['naam'], 'token' => $event['token'], 'status' => self::KANAAL_ACTIEF, 'meetellen' => true, 'panel_id' => null, 'nummer' => null];
                    break;
                case self::KANAAL_INGETROKKEN:
                case self::KANAAL_AANGEPAST:
                    if (isset($kanalen[$id])) {
                        if ($event['type'] === self::KANAAL_INGETROKKEN) {
                            $kanalen[$id]['status'] = self::KANAAL_INGETROKKEN_STATUS;
                        }
                        if (array_key_exists('meetellen', $event)) {
                            $kanalen[$id]['meetellen'] = (bool) $event['meetellen'];
                        }
                    }
                    break;
                case self::PANEL_AANGEMAAKT:
                    $panels[$panelId] = ['panel_id' => $panelId, 'naam' => $event['naam'], 'status' => self::KANAAL_ACTIEF, 'meetellen' => true, 'links' => 0];
                    // no break: its links
                case self::PANEL_UITGEBREID:
                    if (isset($panels[$panelId])) {
                        foreach ($event['links'] as $link) {
                            $kanalen[$link['kanaal_id']] = [
                                'kanaal_id' => $link['kanaal_id'],
                                'naam' => $panels[$panelId]['naam'] . ' #' . $link['nummer'],
                                'token' => $link['token'],
                                'status' => self::KANAAL_ACTIEF,
                                'meetellen' => true,
                                'panel_id' => $panelId,
                                'nummer' => $link['nummer'],
                            ];
                        }
                        $panels[$panelId]['links'] += count($event['links']);
                    }
                    break;
                case self::PANEL_INGETROKKEN:
                case self::PANEL_AANGEPAST:
                    if (isset($panels[$panelId])) {
                        if ($event['type'] === self::PANEL_INGETROKKEN) {
                            $panels[$panelId]['status'] = self::KANAAL_INGETROKKEN_STATUS;
                        }
                        if (array_key_exists('meetellen', $event)) {
                            $panels[$panelId]['meetellen'] = (bool) $event['meetellen'];
                        }
                    }
                    break;
            }
        }
        // the links of a panel follow it
        foreach ($kanalen as &$kanaal) {
            $panel = $panels[$kanaal['panel_id']] ?? null;
            if ($panel !== null) {
                $kanaal['meetellen'] = $panel['meetellen'];
                if ($panel['status'] !== self::KANAAL_ACTIEF) {
                    $kanaal['status'] = self::KANAAL_INGETROKKEN_STATUS;
                }
            }
        }
        unset($kanaal);
        return [$kanalen, $panels];
    }

    // the kanaal an antwoord or a stelling comes through: the actief kanaal of the token, or null without a
    // token. A HttpFout 403 when the token is no actief kanaal of the gesprek (anymore), or when there is no
    // token while the gesprek only takes part through kanalen (zonder_kanaal false)
    public static function kanaalVoorDeelname($gesprekId, $token) {
        if ($token === '') {
            if (!self::gesprek($gesprekId)['zonder_kanaal']) {
                throw new HttpFout(403, 'This gesprek only takes part through the link of a kanaal.');
            }
            return null;
        }
        $kanaal = self::kanaalMetToken($gesprekId, $token);
        if ($kanaal === null) {
            throw new HttpFout(403, 'This kanaal does not work (anymore).');
        }
        return $kanaal;
    }

    // what the event of an antwoord or stelling keeps of its kanaal: its kanaal_id; for a link of a panel only
    // the panel_id, never the link (see panels()); nothing without a kanaal
    public static function deelnameVelden(?array $kanaal) {
        if ($kanaal === null) {
            return [];
        }
        return $kanaal['panel_id'] !== null ? ['panel_id' => $kanaal['panel_id']] : ['kanaal_id' => $kanaal['kanaal_id']];
    }

    // counts one antwoord or stelling ($wat) through a link of a panel; nothing for another kanaal or none.
    // gesprekken/<id>/paneltellingen.json holds per link only these two numbers: no deelnemer, no stelling, no
    // time and no order, so it can not be linked to the antwoorden. That is why it is no stream, but a file
    // that changes. What comes in counts only from the next day on (PANEL_TELVENSTER, see config.php), so
    // nobody sees when one link went up by one, however often he looks:
    // { venster, stand: { kanaal_id: { antwoorden, stellingen } }, lopend: {...} }
    public static function telPanellink($gesprekId, ?array $kanaal, $wat) {
        if ($kanaal === null || $kanaal['panel_id'] === null) {
            return;
        }
        self::metPaneltellingen($gesprekId, function (array $tellingen) use ($kanaal, $wat) {
            $tellingen['lopend'][$kanaal['kanaal_id']] ??= ['antwoorden' => 0, 'stellingen' => 0];
            $tellingen['lopend'][$kanaal['kanaal_id']][$wat]++;
            return $tellingen;
        });
    }

    // [kanaal_id => { antwoorden, stellingen }] of the links of the panels of a gesprek, up to the end of
    // yesterday, see telPanellink()
    public static function paneltellingen($gesprekId) {
        return self::metPaneltellingen($gesprekId, function (array $tellingen) {
            return $tellingen;
        })['stand'];
    }

    // reads the paneltellingen, moves what came in before this window to the stand, lets $wijzig change them,
    // and writes them back; returns them
    private static function metPaneltellingen($gesprekId, callable $wijzig) {
        $handle = fopen(self::paneltellingenBestand($gesprekId), 'c+');
        if ($handle === false) {
            throw new RuntimeException('Failed to open the paneltellingen.');
        }
        flock($handle, LOCK_EX);
        $tellingen = json_decode(stream_get_contents($handle), true);
        if (!is_array($tellingen) || !isset($tellingen['venster'])) {
            $tellingen = ['venster' => 0, 'stand' => [], 'lopend' => []];
        }
        $lengte = defined('PANEL_TELVENSTER') ? PANEL_TELVENSTER : 86400;
        $venster = intdiv(time(), $lengte) * $lengte;
        if ($tellingen['venster'] < $venster) {
            foreach ($tellingen['lopend'] as $kanaalId => $telling) {
                $tellingen['stand'][$kanaalId] ??= ['antwoorden' => 0, 'stellingen' => 0];
                $tellingen['stand'][$kanaalId]['antwoorden'] += $telling['antwoorden'];
                $tellingen['stand'][$kanaalId]['stellingen'] += $telling['stellingen'];
            }
            $tellingen['lopend'] = [];
            $tellingen['venster'] = $venster;
        }
        $tellingen = $wijzig($tellingen);
        ftruncate($handle, 0);
        rewind($handle);
        // an empty map stays an object in JSON
        fwrite($handle, json_encode(['venster' => $tellingen['venster'], 'stand' => (object) $tellingen['stand'], 'lopend' => (object) $tellingen['lopend']]));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        return $tellingen;
    }

    private static function paneltellingenBestand($gesprekId) {
        $dir = DATA_DIR . "/gesprekken/$gesprekId";
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return "$dir/paneltellingen.json";
    }

    // the actief kanaal of this token in the gesprek, or null
    public static function kanaalMetToken($gesprekId, $token) {
        foreach (self::kanalen($gesprekId) as $kanaal) {
            if ($kanaal['status'] === self::KANAAL_ACTIEF && hash_equals($kanaal['token'], $token)) {
                return $kanaal;
            }
        }
        return null;
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
