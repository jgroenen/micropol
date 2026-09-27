<?php

// What happened in a gesprek, for the logboek in the admin: the events of its streams (see Data), with
// its team, newest first. Deelnemers stay anonymous: door has their nummer, like in the matrix, never their id.
// Events about a stelling have its tekst, so the logboek can show it.
class EventsHandler {
    const LIMIET = 100;
    const MAX_LIMIET = 1000;

    // GET /events?gesprek_id=<id>[&voor=<event id>][&limiet=<n>]   the team of the gesprek only
    // { events: [Event], meer } with at most limiet events, newest first; with voor only the events before
    // that one, to load older ones; meer says whether there are older events than these
    public function GET($id = null) {
        Toegang::vereisAccount();
        $gesprekId = Http::field($_GET, 'gesprek_id');
        $voor = Http::field($_GET, 'voor');
        $limiet = Http::field($_GET, 'limiet');
        if ($gesprekId === '') {
            throw new HttpFout(400, 'gesprek_id is required.');
        }
        if ($limiet === '') {
            $limiet = self::LIMIET;
        } elseif (preg_match('/^[1-9][0-9]*$/', $limiet) !== 1 || (int) $limiet > self::MAX_LIMIET) {
            throw new HttpFout(400, 'limiet must be a number from 1 to ' . self::MAX_LIMIET . '.');
        }
        $limiet = (int) $limiet;
        if (!Data::gesprekBestaat($gesprekId)) {
            throw new HttpFout(404, 'Gesprek not found.');
        }
        Toegang::vereisRol($gesprekId, Beheer::TEAMROLLEN);

        // from each stream the last limiet + 1 events before voor; together enough to know whether there are more.
        // The antwoorden first: deelnemers who only added stellingen get a nummer after those who answered
        // the deelnemers in the matrix have its nummers; the others (only through a kanaal that does not count,
        // or only stellingen) get one after them
        $nummers = [];
        foreach (array_keys(Data::matrix($gesprekId)) as $deelnemerId) {
            $nummers[$deelnemerId] = count($nummers) + 1;
        }
        $teksten = [];
        $kanalen = Data::kanalen($gesprekId);
        $events = array_merge(
            $this->laatste(Data::events('gesprekken'), $limiet + 1, $voor, $gesprekId),
            $this->laatste($this->nummer(Data::events('antwoorden', $gesprekId), $nummers), $limiet + 1, $voor),
            $this->laatste($this->stellingen(Data::events('stellingen', $gesprekId), $nummers, $teksten), $limiet + 1, $voor),
            $this->laatste(Data::events('team', $gesprekId), $limiet + 1, $voor),
            $this->laatste(Data::events('kanalen', $gesprekId), $limiet + 1, $voor)
        );
        usort($events, function ($a, $b) {
            return strcmp($b['id'], $a['id']);
        });

        $accounts = Beheer::accounts();
        Http::json([
            'events' => array_map(function ($event) use ($nummers, $teksten, $accounts, $kanalen) {
                return $this->openbaar($event, $nummers, $teksten, $accounts, $kanalen);
            }, array_slice($events, 0, $limiet)),
            'meer' => count($events) > $limiet,
        ]);
    }

    // the last $aantal events of a stream with an id below $voor (if given), of gesprek $gesprekId (if given)
    private function laatste($events, $aantal, $voor, $gesprekId = null) {
        $laatste = [];
        foreach ($events as $event) {
            if (($voor === '' || strcmp($event['id'], $voor) < 0) && ($gesprekId === null || $event['gesprek_id'] === $gesprekId)) {
                $laatste[] = $event;
                // keeps the memory small for a long stream
                if (count($laatste) > 2 * $aantal) {
                    $laatste = array_slice($laatste, -$aantal);
                }
            }
        }
        return array_slice($laatste, -$aantal);
    }

    // numbers the deelnemers in order of their first event, like Data::deelnemers() does for antwoorden,
    // while passing on the events
    private function nummer($events, array &$nummers) {
        foreach ($events as $event) {
            $door = $event['door'];
            if (($door['soort'] ?? null) === Data::DOOR_DEELNEMER && !isset($nummers[$door['id']])) {
                $nummers[$door['id']] = count($nummers) + 1;
            }
            yield $event;
        }
    }

    // like nummer(), and keeps the tekst of every stelling: [stelling_id => tekst]
    private function stellingen($events, array &$nummers, array &$teksten) {
        foreach ($this->nummer($events, $nummers) as $event) {
            if ($event['type'] === Data::STELLING_TOEGEVOEGD) {
                $teksten[$event['stelling_id']] = $event['tekst'];
            }
            yield $event;
        }
    }

    // the event as the api gives it: tijdstip in ISO 8601, door without the id of a deelnemer, the tekst of
    // its stelling, the gebruikersnaam of the account it is about (an event of the team), and the naam of its kanaal
    private function openbaar(array $event, array $nummers, array $teksten, array $accounts, array $kanalen) {
        $event['tijdstip'] = $event['tijdstip'] === null ? null : date('c', $event['tijdstip']);
        $door = $event['door'];
        if (($door['soort'] ?? null) === Data::DOOR_DEELNEMER) {
            $event['door'] = ['soort' => Data::DOOR_DEELNEMER, 'nummer' => $nummers[$door['id']]];
        } elseif (($door['soort'] ?? null) === Data::DOOR_BEHEERDER) {
            $event['door']['gebruikersnaam'] = $accounts[$door['id']]['gebruikersnaam'] ?? '';
        }
        if (isset($event['account_id'])) {
            $event['gebruikersnaam'] = $accounts[$event['account_id']]['gebruikersnaam'] ?? '';
        }
        if (isset($event['stelling_id']) && !isset($event['tekst'])) {
            $event['tekst'] = $teksten[$event['stelling_id']] ?? '';
        }
        if (isset($event['kanaal_id'])) {
            $event['kanaal'] = $kanalen[$event['kanaal_id']]['naam'] ?? '';
        }
        return $event;
    }
}
