<?php

// A gesprek id ends up in the path of its files (Data::stroom()). Data checks it there itself, so a handler
// that forgets Data::gesprekBestaat() gets an error, never a file outside the data map.
define('DATA_DIR', sys_get_temp_dir() . '/minipol-paden-' . getmypid());
foreach (['Csv', 'Jsonl', 'Data', 'HttpFout'] as $class) {
    require __DIR__ . "/../api/lib/$class.php";
}

$fouten = 0;
function proef($naam, $goed) {
    global $fouten;
    $fouten += $goed ? 0 : 1;
    echo ($goed ? 'ok   ' : 'FOUT ') . "paden: $naam\n";
}

function geweigerd(callable $doe) {
    try {
        $doe();
        return false;
    } catch (RuntimeException $e) {
        return true;
    }
}

proef('een gewoon id', Data::stroom('antwoorden', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b') === DATA_DIR . '/gesprekken/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b/antwoorden.jsonl');
proef('de algemene stromen zonder id', Data::stroom('beheer') === DATA_DIR . '/beheer.jsonl');
foreach (['../beheer', '../../etc/passwd', 'a/b', '..', '', "abc\n", 'abc.', null, ['a']] as $id) {
    proef('geweigerd: ' . json_encode($id), geweigerd(function () use ($id) {
        Data::stroom('antwoorden', $id);
    }));
    proef('bestaat niet: ' . json_encode($id), Data::gesprekBestaat($id) === false);
}
proef('schrijven met ../ lukt niet', geweigerd(function () {
    Data::voegEventToe(Data::STELLING_TOEGEVOEGD, null, '../x', []);
}));
proef('tellen met ../ lukt niet', geweigerd(function () {
    Data::telPanellink('../x', ['kanaal_id' => 'k1', 'panel_id' => 'p1'], 'antwoorden');
}));
proef('niets buiten de data map', !file_exists(dirname(DATA_DIR) . '/x') && !file_exists(DATA_DIR . '/x'));

@rmdir(DATA_DIR);

echo "paden.php: $fouten fouten\n";
exit($fouten ? 1 : 0);
