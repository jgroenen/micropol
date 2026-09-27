<?php

// Adds a superbeheerder (an account in api/data/beheer.jsonl, see lib/Beheer.php), who can then log in to
// the admin. Normally the first one is made in the admin, after logging in with admin/admin; this is for
// when that is not possible, like when nobody can log in anymore.
// Usage: php api/bin/superbeheerder-toevoegen.php <gebruikersnaam> <email>
// The wachtwoord is asked for, so it does not end up in the shell history.

if (PHP_SAPI !== 'cli') {
    exit;
}

define('DATA_DIR', __DIR__ . '/../data');
foreach (['Csv', 'Jsonl', 'Data', 'HttpFout', 'Beheer', 'Wachtwoord'] as $class) {
    require __DIR__ . "/../lib/$class.php";
}

[, $gebruikersnaam, $email] = $argv + [null, '', ''];
$gebruikersnaam = trim($gebruikersnaam);
$email = trim($email);
if ($gebruikersnaam === '' || $email === '') {
    fwrite(STDERR, "Gebruik: php api/bin/superbeheerder-toevoegen.php <gebruikersnaam> <email>\n");
    exit(1);
}

$wachtwoord = vraagWachtwoord('Wachtwoord: ');
if (vraagWachtwoord('Wachtwoord nogmaals: ') !== $wachtwoord) {
    fwrite(STDERR, "De wachtwoorden zijn niet gelijk.\n");
    exit(1);
}
try {
    Beheer::controleerNieuwAccount($gebruikersnaam, $email, $wachtwoord);
} catch (HttpFout $fout) {
    fwrite(STDERR, $fout->getMessage() . "\n");
    exit(1);
}

$account = Beheer::maakAccount($gebruikersnaam, $email, $wachtwoord);
Data::voegEventToe(Beheer::SUPERBEHEERDER_BENOEMD, null, null, ['account_id' => $account['id']]);
echo "Superbeheerder $gebruikersnaam toegevoegd.\n";

// reads a line without showing it, when the terminal allows that
function vraagWachtwoord($vraag) {
    echo $vraag;
    $verborgen = stream_isatty(STDIN) && shell_exec('stty -echo 2>/dev/null') !== false;
    $regel = rtrim((string) fgets(STDIN), "\r\n");
    if ($verborgen) {
        shell_exec('stty echo 2>/dev/null');
        echo "\n";
    }
    return $regel;
}
