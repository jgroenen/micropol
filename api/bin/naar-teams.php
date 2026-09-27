<?php

// Converts the beheerders of before the teams (beheerders.csv) into accounts (beheer.jsonl), once; see lib/Beheer.php.
// Usage: php api/bin/naar-teams.php
//
// Every beheerder becomes an account with the same gebruikersnaam, email and wachtwoord: a superbeheerder,
// and gespreksbeheerder of every gesprek there is, since beheerders could do everything before the teams.
// beheerders.csv and sessies.csv (with the old logins) move to data/csv-voor-teams/, so nothing is lost.

if (PHP_SAPI !== 'cli') {
    exit;
}

define('DATA_DIR', __DIR__ . '/../data');
foreach (['Csv', 'Jsonl', 'Data', 'HttpFout', 'Beheer', 'Wachtwoord'] as $class) {
    require __DIR__ . "/../lib/$class.php";
}

const OUD = DATA_DIR . '/csv-voor-teams';

if (is_file(Data::stroom('beheer'))) {
    fwrite(STDERR, "Er is al een data/beheer.jsonl; er is niets omgezet.\n");
    exit(1);
}
if (file_exists(OUD)) {
    fwrite(STDERR, "data/csv-voor-teams/ bestaat al; er is niets omgezet.\n");
    exit(1);
}
if (!is_file(DATA_DIR . '/beheerders.csv')) {
    echo "Geen beheerders.csv: er is niets om te zetten.\n";
    exit;
}

$gesprekken = Data::gesprekken();
$aantal = 0;
foreach (Csv::lastPer(DATA_DIR . '/beheerders.csv', 'id') as $beheerder) {
    $id = $beheerder['id'];
    Data::voegEventToe(Beheer::ACCOUNT_AANGEMAAKT, null, null, ['account_id' => $id, 'gebruikersnaam' => $beheerder['gebruikersnaam'], 'email' => $beheerder['email']]);
    Data::voegEventToe(Beheer::WACHTWOORD_INGESTELD, null, null, [
        'account_id' => $id,
        'wachtwoord_methode' => $beheerder['wachtwoord_methode'],
        'versleuteld_wachtwoord' => $beheerder['versleuteld_wachtwoord'],
    ]);
    Data::voegEventToe(Beheer::SUPERBEHEERDER_BENOEMD, null, null, ['account_id' => $id]);
    foreach ($gesprekken as $gesprek) {
        Data::voegEventToe(Beheer::LID_TOEGEVOEGD, null, $gesprek['id'], ['account_id' => $id, 'rol' => Beheer::ROL_GESPREKSBEHEERDER]);
    }
    $aantal++;
}

mkdir(OUD, 0775, true);
foreach (['beheerders.csv', 'sessies.csv'] as $naam) {
    if (file_exists(DATA_DIR . "/$naam")) {
        rename(DATA_DIR . "/$naam", OUD . "/$naam");
    }
}

printf(
    "Omgezet: %d beheerders, nu superbeheerder en gespreksbeheerder van %d gesprekken.\nDe csv-bestanden staan in data/csv-voor-teams/.\n",
    $aantal, count($gesprekken)
);
