<?php

// The page of the app: views/pagina.html, with the url of the cdn filled in (from instellingen.php).
// The views inside the page are loaded by js/views.js.
$cdn = (require __DIR__ . '/instellingen.php')['CDN_URL'];

// only the characters of a plain url, so it can go into the attribute and the import map as it is
if (preg_match('#^https?://[A-Za-z0-9.:-]+(/[A-Za-z0-9._/-]*)?$#', $cdn) !== 1) {
    http_response_code(500);
    exit('MINIPOL_CDN_URL is not a plain url.');
}

header('Content-Type: text/html; charset=utf-8');
echo str_replace('{{CDN_URL}}', rtrim($cdn, '/'), file_get_contents(__DIR__ . '/views/pagina.html'));
