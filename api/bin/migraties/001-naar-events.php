<?php

// Converts the data of before the events (csv) into the event streams; see lib/Data.php.
// Reads gesprekken.csv, stellingen.csv, antwoorden/<id>.csv and beoordelingen/<id>.csv, writes the events,
// and moves the csv files to data/csv-voor-events/, so nothing is lost. The csv files did not keep when
// something happened, except for beoordelingen: the other events get tijdstip null. Who made or changed a
// gesprek is also unknown: door null. Their ids keep the order of the csv files, so the logboek shows them
// in that order. Run by bin/migreer.php.
return function () {
    $oud = DATA_DIR . '/csv-voor-events';
    if (!is_file(DATA_DIR . '/gesprekken.csv')) {
        return 'niets om te zetten';
    }
    if (is_file(Data::stroom('gesprekken')) || file_exists($oud)) {
        throw new RuntimeException('there is a gesprekken.csv, but also gesprekken.jsonl or csv-voor-events/: check the data by hand.');
    }

    // [bestand => [event]], written at the end, so a failure halfway leaves no half data
    $stromen = [];
    $voegToe = function ($stroom, $type, $door, $gesprekId, array $velden, $tijdstip = null) use (&$stromen) {
        $event = Data::event($type, $door, $gesprekId, $velden);
        $event['tijdstip'] = $tijdstip;
        $stromen[$stroom][] = $event;
    };

    // gesprekken: the first row of an id is aangemaakt, a later one aangepast with what changed
    $gesprekken = [];
    foreach (Csv::read(DATA_DIR . '/gesprekken.csv') as $rij) {
        $id = $rij['id'];
        if (preg_match('/^[A-Za-z0-9-]+$/', $id) !== 1) {
            continue;
        }
        $velden = [
            'titel' => $rij['titel'],
            'omschrijving' => $rij['omschrijving'],
            'moderatie' => ($rij['moderatie'] ?? '') ?: Data::MODERATIE_ACHTERAF,
        ];
        if (!isset($gesprekken[$id])) {
            $voegToe(Data::stroom('gesprekken'), Data::GESPREK_AANGEMAAKT, null, $id, $velden);
        } elseif ($gewijzigd = array_diff_assoc($velden, $gesprekken[$id])) {
            $voegToe(Data::stroom('gesprekken'), Data::GESPREK_AANGEPAST, null, $id, $gewijzigd);
        }
        $gesprekken[$id] = $velden;
    }

    $stellingen = Csv::read(DATA_DIR . '/stellingen.csv');
    $aantallen = ['stellingen' => 0, 'beoordelingen' => 0, 'antwoorden' => 0];
    foreach (array_keys($gesprekken) as $gesprekId) {
        // stellingen, then their beoordelingen
        $bekend = [];
        foreach ($stellingen as $rij) {
            if ($rij['gesprek_id'] === $gesprekId) {
                $door = ($rij['deelnemer_id'] ?? '') === '' ? null : Data::doorDeelnemer($rij['deelnemer_id']);
                $voegToe(Data::stroom('stellingen', $gesprekId), Data::STELLING_TOEGEVOEGD, $door, $gesprekId, ['stelling_id' => $rij['id'], 'tekst' => $rij['tekst']]);
                $bekend[$rij['id']] = true;
                $aantallen['stellingen']++;
            }
        }
        foreach (Csv::read(DATA_DIR . "/beoordelingen/$gesprekId.csv") as $rij) {
            if (isset($bekend[$rij['stelling_id']], Data::BEOORDELING_EVENTS[$rij['beoordeling']])) {
                $velden = ['stelling_id' => $rij['stelling_id']] + ($rij['reden'] === '' ? [] : ['reden' => $rij['reden']]);
                $door = Data::doorBeheerder(['id' => $rij['beheerder_id']]);
                $voegToe(Data::stroom('stellingen', $gesprekId), Data::BEOORDELING_EVENTS[$rij['beoordeling']], $door, $gesprekId, $velden, (int) $rij['tijdstip']);
                $aantallen['beoordelingen']++;
            }
        }
        foreach (Csv::read(DATA_DIR . "/antwoorden/$gesprekId.csv") as $rij) {
            $velden = ['stelling_id' => $rij['stelling_id'], 'waarde' => $rij['waarde']];
            $voegToe(Data::stroom('antwoorden', $gesprekId), Data::ANTWOORD_GEGEVEN, Data::doorDeelnemer($rij['deelnemer_id']), $gesprekId, $velden);
            $aantallen['antwoorden']++;
        }
    }

    foreach ($stromen as $bestand => $events) {
        if (!is_dir(dirname($bestand))) {
            mkdir(dirname($bestand), 0775, true);
        }
        $regels = array_map(function ($event) {
            return json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        }, $events);
        file_put_contents($bestand, implode('', $regels), LOCK_EX);
    }

    // the csv files out of the way, but kept
    mkdir($oud, 0775, true);
    foreach (['gesprekken.csv', 'stellingen.csv', 'antwoorden', 'beoordelingen'] as $naam) {
        if (file_exists(DATA_DIR . "/$naam")) {
            rename(DATA_DIR . "/$naam", "$oud/$naam");
        }
    }

    return sprintf(
        '%d gesprekken, %d stellingen, %d beoordelingen en %d antwoorden omgezet; de csv-bestanden staan in data/csv-voor-events/',
        count($gesprekken), $aantallen['stellingen'], $aantallen['beoordelingen'], $aantallen['antwoorden']
    );
};
