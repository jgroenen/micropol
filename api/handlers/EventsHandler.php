<?php

// What happened in a gesprek, for the logboek in the admin: the events of its three streams (see Data),
// newest first. Deelnemers stay anonymous: door has their nummer, like in the matrix, never their id.
class EventsHandler {
    const LIMIET = 100;
    const MAX_LIMIET = 1000;

    // GET /events?gesprek_id=<id>[&voor=<event id>][&limiet=<n>]   beheerders only
    // { events: [Event], meer } with at most limiet events, newest first; with voor only the events before
    // that one, to load older ones; meer says whether there are older events than these
    public function GET($id = null) {
        Toegang::vereisBeheerder();
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
        if (!Data::gesprekBestaat($gesprekId)) {
            throw new HttpFout(404, 'Gesprek not found.');
        }

        // from each stream the last limiet + 1 events before voor; together enough to know whether there are more
        $nummers = [];
        $events = array_merge(
            $this->laatste(Data::events('gesprekken'), (int) $limiet + 1, $voor, $gesprekId),
            $this->laatste(Data::events('stellingen', $gesprekId), (int) $limiet + 1, $voor),
            $this->laatste($this->nummer(Data::events('antwoorden', $gesprekId), $nummers), (int) $limiet + 1, $voor)
        );
        usort($events, function ($a, $b) {
            return strcmp($b['id'], $a['id']);
        });
        $meer = count($events) > (int) $limiet;
        $events = array_slice($events, 0, (int) $limiet);

        // deelnemers who only added stellingen get a nummer after those who answered
        foreach (Data::events('stellingen', $gesprekId) as $event) {
            $this->nummerVan($event['door'], $nummers);
        }
        $beheerders = Data::beheerders();
        Http::json([
            'events' => array_map(function ($event) use ($nummers, $beheerders) {
                return $this->openbaar($event, $nummers, $beheerders);
            }, $events),
            'meer' => $meer,
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

    // numbers the deelnemers in order of their first antwoord, like Data::deelnemers(), while passing on the events
    private function nummer($events, array &$nummers) {
        foreach ($events as $event) {
            $this->nummerVan($event['door'], $nummers);
            yield $event;
        }
    }

    private function nummerVan(?array $door, array &$nummers) {
        if (($door['soort'] ?? null) === Data::DOOR_DEELNEMER && !isset($nummers[$door['id']])) {
            $nummers[$door['id']] = count($nummers) + 1;
        }
    }

    // the event as the api gives it: tijdstip in ISO 8601, and door without the id of a deelnemer
    private function openbaar(array $event, array $nummers, array $beheerders) {
        $event['tijdstip'] = $event['tijdstip'] === null ? null : date('c', $event['tijdstip']);
        $door = $event['door'];
        if (($door['soort'] ?? null) === Data::DOOR_DEELNEMER) {
            $event['door'] = ['soort' => Data::DOOR_DEELNEMER, 'nummer' => $nummers[$door['id']]];
        } elseif (($door['soort'] ?? null) === Data::DOOR_BEHEERDER) {
            $event['door']['gebruikersnaam'] = $beheerders[$door['id']]['gebruikersnaam'] ?? '';
        }
        return $event;
    }
}
