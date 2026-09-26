<?php

// Adds a user to the admin environment (api/data/users.csv).
// Usage: php api/bin/user-toevoegen.php <username> <email>
// The password is asked for, so it does not end up in the shell history.

if (PHP_SAPI !== 'cli') {
    exit;
}

define('DATA_DIR', __DIR__ . '/../data');
foreach (['Csv', 'Data', 'Wachtwoord'] as $class) {
    require __DIR__ . "/../lib/$class.php";
}

[, $username, $email] = $argv + [null, '', ''];
$username = trim($username);
$email = trim($email);
if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php api/bin/user-toevoegen.php <username> <email>\n");
    exit(1);
}
if (Data::user($username) !== null) {
    fwrite(STDERR, "User $username already exists.\n");
    exit(1);
}

$password = vraagWachtwoord('Password: ');
if (strlen($password) < 12) {
    fwrite(STDERR, "The password needs at least 12 characters.\n");
    exit(1);
}
if (vraagWachtwoord('Password again: ') !== $password) {
    fwrite(STDERR, "The passwords are not the same.\n");
    exit(1);
}

Csv::append(Data::usersFile(), Data::USERS, array_merge([Data::uuid(), $username, $email], Wachtwoord::versleutel($password)));
echo "User $username added.\n";

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
