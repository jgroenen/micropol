<?php

// Brings the data up to date with this version of the code: runs the migraties in bin/migraties/ that have
// not run on this data yet, in the order of their names, and keeps which ran in data/migraties.jsonl.
// deploy/uppen.sh runs it on every update, and dev/start.sh when starting; on new or empty data the
// migraties have nothing to do, and are only marked as done.
// Usage: php api/bin/migreer.php [data map]   (default: api/data)
//
// A migratie is a file NNN-naam.php that returns a function. The function converts the data, or does
// nothing when there is nothing to convert, and returns what it did as a line of text. It throws when
// the data is not as expected: then this script stops with exit code 1, and that migratie and the ones
// after it stay to do.

if (PHP_SAPI !== 'cli') {
    exit;
}

define('DATA_DIR', rtrim($argv[1] ?? __DIR__ . '/../data', '/'));
foreach (['Csv', 'Jsonl', 'Data', 'HttpFout', 'Beheer', 'Wachtwoord'] as $class) {
    require __DIR__ . "/../lib/$class.php";
}

$logboek = DATA_DIR . '/migraties.jsonl';
if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0775, true)) {
    fwrite(STDERR, 'Kan ' . DATA_DIR . " niet maken.\n");
    exit(1);
}
$gedaan = [];
foreach (Jsonl::read($logboek) as $regel) {
    $gedaan[$regel['migratie']] = true;
}

$bestanden = glob(__DIR__ . '/migraties/*.php');
sort($bestanden);
$aantal = 0;
foreach ($bestanden as $bestand) {
    $naam = basename($bestand, '.php');
    if (isset($gedaan[$naam])) {
        continue;
    }
    try {
        $uitkomst = (require $bestand)();
    } catch (Throwable $fout) {
        fwrite(STDERR, "Migratie $naam mislukt: {$fout->getMessage()}\n");
        exit(1);
    }
    Jsonl::append($logboek, ['migratie' => $naam, 'tijdstip' => time(), 'uitkomst' => $uitkomst]);
    echo "Migratie $naam: $uitkomst\n";
    $aantal++;
}
if ($aantal === 0) {
    echo "De data is bij: geen migraties te doen.\n";
}
