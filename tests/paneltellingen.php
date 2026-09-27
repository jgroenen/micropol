<?php

// The counts of the links of a panel (Data::telPanellink()) per window of the clock: what comes in counts
// only from the next window on, so nobody sees when one link went up by one. Tested with a window of a day,
// in a data map of its own, by moving the window of the file back.
define('DATA_DIR', sys_get_temp_dir() . '/minipol-paneltellingen-' . getmypid());
define('PANEL_TELVENSTER', 86400);
foreach (['Csv', 'Jsonl', 'Data', 'HttpFout'] as $class) {
    require __DIR__ . "/../api/lib/$class.php";
}

$fouten = 0;
function proef($naam, $goed) {
    global $fouten;
    $fouten += $goed ? 0 : 1;
    echo ($goed ? 'ok   ' : 'FOUT ') . "paneltellingen: $naam\n";
}

$gesprek = 'g1';
$link = ['kanaal_id' => 'k1', 'panel_id' => 'p1'];
$bestand = DATA_DIR . "/gesprekken/$gesprek/paneltellingen.json";

Data::telPanellink($gesprek, $link, 'antwoorden');
Data::telPanellink($gesprek, $link, 'antwoorden');
Data::telPanellink($gesprek, $link, 'stellingen');
Data::telPanellink($gesprek, ['kanaal_id' => 'k2', 'panel_id' => null], 'antwoorden');
Data::telPanellink($gesprek, null, 'antwoorden');
proef('vandaag telt nog niet mee', Data::paneltellingen($gesprek) === []);

$opgeslagen = json_decode(file_get_contents($bestand), true);
proef('in het bestand alleen getallen per link, zonder tijd of volgorde', array_keys($opgeslagen) === ['venster', 'stand', 'lopend']
    && $opgeslagen['lopend'] === ['k1' => ['antwoorden' => 2, 'stellingen' => 1]]);

// a day later: yesterday counts
$opgeslagen['venster'] -= 86400;
file_put_contents($bestand, json_encode($opgeslagen));
proef('na het venster telt het mee', Data::paneltellingen($gesprek) === ['k1' => ['antwoorden' => 2, 'stellingen' => 1]]);
Data::telPanellink($gesprek, $link, 'antwoorden');
proef('en wat daarna komt weer pas het venster daarna', Data::paneltellingen($gesprek)['k1']['antwoorden'] === 2);

array_map('unlink', glob(DATA_DIR . "/gesprekken/$gesprek/*"));
rmdir(DATA_DIR . "/gesprekken/$gesprek");
rmdir(DATA_DIR . '/gesprekken');
rmdir(DATA_DIR);

echo "paneltellingen.php: $fouten fouten\n";
exit($fouten ? 1 : 0);
