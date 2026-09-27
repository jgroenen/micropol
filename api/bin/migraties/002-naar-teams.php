<?php

// Converts the beheerders of before the teams (beheerders.csv) into accounts (beheer.jsonl); see lib/Beheer.php.
// Every beheerder becomes an account with the same gebruikersnaam, email and wachtwoord: a superbeheerder,
// and gespreksbeheerder of every gesprek there is, since beheerders could do everything before the teams.
// beheerders.csv and sessies.csv (with the old logins) move to data/csv-voor-teams/, so nothing is lost.
// Run by bin/migreer.php, after 001: the gesprekken must be events already.
return function () {
    $oud = DATA_DIR . '/csv-voor-teams';
    if (!is_file(DATA_DIR . '/beheerders.csv')) {
        return 'niets om te zetten';
    }
    if (is_file(Data::stroom('beheer')) || file_exists($oud)) {
        throw new RuntimeException('there is a beheerders.csv, but also beheer.jsonl or csv-voor-teams/: check the data by hand.');
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

    mkdir($oud, 0775, true);
    foreach (['beheerders.csv', 'sessies.csv'] as $naam) {
        if (file_exists(DATA_DIR . "/$naam")) {
            rename(DATA_DIR . "/$naam", "$oud/$naam");
        }
    }

    return sprintf('%d beheerders omgezet: nu superbeheerder en gespreksbeheerder van %d gesprekken; de csv-bestanden staan in data/csv-voor-teams/', $aantal, count($gesprekken));
};
