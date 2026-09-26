<?php

// Adds a gebruiker to the auth service (auth/data/gebruikers.csv), who can then log in to the admin.
// Usage: php auth/bin/gebruiker-toevoegen.php <gebruikersnaam> <email>
// The wachtwoord is asked for, so it does not end up in the shell history.

if (PHP_SAPI !== 'cli') {
    exit;
}

define('DATA_DIR', __DIR__ . '/../data');
foreach (['Csv', 'Data', 'Wachtwoord'] as $class) {
    require __DIR__ . "/../lib/$class.php";
}

[, $gebruikersnaam, $email] = $argv + [null, '', ''];
$gebruikersnaam = trim($gebruikersnaam);
$email = trim($email);
if ($gebruikersnaam === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Gebruik: php auth/bin/gebruiker-toevoegen.php <gebruikersnaam> <email>\n");
    exit(1);
}
if (Data::gebruiker($gebruikersnaam) !== null) {
    fwrite(STDERR, "Gebruiker $gebruikersnaam bestaat al.\n");
    exit(1);
}

$wachtwoord = vraagWachtwoord('Wachtwoord: ');
if (strlen($wachtwoord) < 12) {
    fwrite(STDERR, "Het wachtwoord moet minstens 12 tekens hebben.\n");
    exit(1);
}
if (vraagWachtwoord('Wachtwoord nogmaals: ') !== $wachtwoord) {
    fwrite(STDERR, "De wachtwoorden zijn niet gelijk.\n");
    exit(1);
}

Data::voegToe('gebruikers', Data::GEBRUIKERS, array_merge([Data::uuid(), $gebruikersnaam, $email], Wachtwoord::versleutel($wachtwoord)));
echo "Gebruiker $gebruikersnaam toegevoegd.\n";

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
