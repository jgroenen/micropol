<?php

// Converts the data of before the events (csv) into the three event streams, once; see lib/Data.php.
// Usage: php api/bin/naar-events.php
//
// Reads gesprekken.csv, stellingen.csv, antwoorden/<id>.csv and beoordelingen/<id>.csv, writes the events,
// and moves the csv files to data/csv-voor-events/, so nothing is lost. Beheerders and sessies stay as they are.
// The csv files did not keep when something happened, except for beoordelingen: the other events get
// tijdstip null. Who made or changed a gesprek is also unknown: door null. Their ids keep the order of the
// csv files, so the logboek shows them in that order.

if (PHP_SAPI !== 'cli') {
    exit;
}

define('DATA_DIR', __DIR__ . '/../data');
foreach (['Csv', 'Jsonl', 'Data'] as $class) {
    require __DIR__ . "/../lib/$class.php";
}

const OUD = DATA_DIR . '/csv-voor-events';

if (is_file(Data::stroom('gesprekken'))) {
    fwrite(STDERR, "Er zijn al events (data/gesprekken.jsonl); er is niets omgezet.\n");
    exit(1);
}
if (file_exists(OUD)) {
    fwrite(STDERR, "data/csv-voor-events/ bestaat al; er is niets omgezet.\n");
    exit(1);
}
if (!is_file(DATA_DIR . '/gesprekken.csv')) {
    echo "Geen gesprekken.csv: er is niets om te zetten.\n";
    exit;
}

// [bestand => [event]], written at the end, so a failure halfway leaves no half data
$stromen = [];
function voegToe(array &$stromen, $stroom, $type, $door, $gesprekId, array $velden, $tijdstip = null) {
    $event = Data::event($type, $door, $gesprekId, $velden);
    $event['tijdstip'] = $tijdstip;
    $stromen[$stroom][] = $event;
}

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
        voegToe($stromen, Data::stroom('gesprekken'), Data::GESPREK_AANGEMAAKT, null, $id, $velden);
    } elseif ($gewijzigd = array_diff_assoc($velden, $gesprekken[$id])) {
        voegToe($stromen, Data::stroom('gesprekken'), Data::GESPREK_AANGEPAST, null, $id, $gewijzigd);
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
            voegToe($stromen, Data::stroom('stellingen', $gesprekId), Data::STELLING_TOEGEVOEGD, $door, $gesprekId, ['stelling_id' => $rij['id'], 'tekst' => $rij['tekst']]);
            $bekend[$rij['id']] = true;
            $aantallen['stellingen']++;
        }
    }
    foreach (Csv::read(DATA_DIR . "/beoordelingen/$gesprekId.csv") as $rij) {
        if (isset($bekend[$rij['stelling_id']], Data::BEOORDELING_EVENTS[$rij['beoordeling']])) {
            $velden = ['stelling_id' => $rij['stelling_id']] + ($rij['reden'] === '' ? [] : ['reden' => $rij['reden']]);
            $door = Data::doorBeheerder(['id' => $rij['beheerder_id']]);
            voegToe($stromen, Data::stroom('stellingen', $gesprekId), Data::BEOORDELING_EVENTS[$rij['beoordeling']], $door, $gesprekId, $velden, (int) $rij['tijdstip']);
            $aantallen['beoordelingen']++;
        }
    }
    foreach (Csv::read(DATA_DIR . "/antwoorden/$gesprekId.csv") as $rij) {
        $velden = ['stelling_id' => $rij['stelling_id'], 'waarde' => $rij['waarde']];
        voegToe($stromen, Data::stroom('antwoorden', $gesprekId), Data::ANTWOORD_GEGEVEN, Data::doorDeelnemer($rij['deelnemer_id']), $gesprekId, $velden);
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
mkdir(OUD, 0775, true);
foreach (['gesprekken.csv', 'stellingen.csv', 'antwoorden', 'beoordelingen'] as $naam) {
    if (file_exists(DATA_DIR . "/$naam")) {
        rename(DATA_DIR . "/$naam", OUD . "/$naam");
    }
}

printf(
    "Omgezet: %d gesprekken, %d stellingen, %d beoordelingen en %d antwoorden.\nDe csv-bestanden staan nu in data/csv-voor-events/; weggooien mag als alles werkt.\n",
    count($gesprekken), $aantallen['stellingen'], $aantallen['beoordelingen'], $aantallen['antwoorden']
);
