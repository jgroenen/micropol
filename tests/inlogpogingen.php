<?php

// The limit on logging in (api/lib/Inlogpogingen.php), in a data map of its own: after too many failed
// attempts a 429, per IP address and per gebruikersnaam; the file holds no IP address and no gebruikersnaam,
// and a new hour starts over with a new key.
define('DATA_DIR', sys_get_temp_dir() . '/minipol-inlogpogingen-' . getmypid());
foreach (['HttpFout', 'Inlogpogingen'] as $class) {
    require __DIR__ . "/../api/lib/$class.php";
}
// the output waits, so Inlogpogingen can still set its Retry-After header
ob_start();

$fouten = 0;
function proef($naam, $goed) {
    global $fouten;
    $fouten += $goed ? 0 : 1;
    echo ($goed ? 'ok   ' : 'FOUT ') . "inlogpogingen: $naam\n";
}
function geweigerd($gebruikersnaam) {
    try {
        Inlogpogingen::controleer($gebruikersnaam);
        return false;
    } catch (HttpFout $fout) {
        return $fout->getCode() === 429;
    }
}
$bestand = DATA_DIR . '/inlogpogingen.json';

// per IP address: 10 failed attempts, then no more, also not for another name
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
for ($i = 0; $i < Inlogpogingen::MAX_PER_IP; $i++) {
    proef("poging " . ($i + 1) . " mag", !geweigerd("naam$i"));
    Inlogpogingen::mislukt("naam$i");
}
proef('na 10 mislukte pogingen van één IP-adres: 429', geweigerd('iemand-anders'));
$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
proef('een ander IP-adres mag nog', !geweigerd('iemand-anders'));

// per gebruikersnaam: 20, from any IP address
for ($i = 0; $i < Inlogpogingen::MAX_PER_NAAM; $i++) {
    $_SERVER['REMOTE_ADDR'] = "192.0.2.$i";
    Inlogpogingen::mislukt('Beheerder');
}
$_SERVER['REMOTE_ADDR'] = '192.0.2.200';
proef('na 20 mislukte pogingen voor één gebruikersnaam: 429, ook van een nieuw IP-adres (en hoofdletters tellen niet)', geweigerd('beheerder'));

// IPv6: one /64 counts as one
for ($i = 0; $i < Inlogpogingen::MAX_PER_IP; $i++) {
    $_SERVER['REMOTE_ADDR'] = "2001:db8:1:2::$i";
    Inlogpogingen::mislukt("v6-$i");
}
$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2::ffff';
proef('IPv6: hetzelfde /64 telt als één', geweigerd('nog-iemand'));

$inhoud = file_get_contents($bestand);
proef('geen IP-adres en geen gebruikersnaam in het bestand', !str_contains($inhoud, '203.0.113') && !str_contains($inhoud, '2001:db8') && !str_contains($inhoud, 'beheerder') && !str_contains($inhoud, 'naam0'));

// a new hour: a new key, and it starts over
$opgeslagen = json_decode($inhoud, true);
$sleutel = $opgeslagen['sleutel'];
$opgeslagen['venster'] -= Inlogpogingen::VENSTER;
file_put_contents($bestand, json_encode($opgeslagen));
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
proef('een nieuw uur begint opnieuw', !geweigerd('beheerder'));
proef('met een nieuwe sleutel', json_decode(file_get_contents($bestand), true)['sleutel'] !== $sleutel);

unlink($bestand);
rmdir(DATA_DIR);
echo "inlogpogingen.php: $fouten fouten\n";
exit($fouten ? 1 : 0);
