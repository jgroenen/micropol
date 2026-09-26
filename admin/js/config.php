<?php

// The settings for the browser, as a JS module (imported as ./config.php): the urls from ../instellingen.php.
$instellingen = require __DIR__ . '/../instellingen.php';
header('Content-Type: text/javascript; charset=utf-8');
header('Cache-Control: no-cache');
foreach ($instellingen as $naam => $waarde) {
    echo "export const $naam = " . json_encode($waarde, JSON_UNESCAPED_SLASHES) . ";\n";
}
