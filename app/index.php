<?php

// The page of the app: views/pagina.html, with the urls of the cdn and www filled in (from instellingen.php).
// The views inside the page are loaded by js/views.js.
$instellingen = require __DIR__ . '/instellingen.php';

// only the characters of a plain url, so they can go into the page and the Content-Security-Policy as they are
foreach ($instellingen as $naam => $url) {
    if (preg_match('#^https?://[A-Za-z0-9.:-]+(/[A-Za-z0-9._/-]*)?$#', $url) !== 1) {
        http_response_code(500);
        exit("MINIPOL_$naam is not a plain url.");
    }
}
['WWW_URL' => $www, 'API_URL' => $api, 'MATH_URL' => $math, 'CDN_URL' => $cdn] = array_map(function ($url) {
    return rtrim($url, '/');
}, $instellingen);

$pagina = str_replace(['{{CDN_URL}}', '{{WWW_URL}}'], [$cdn, $www], file_get_contents(__DIR__ . '/views/pagina.html'));

// Content-Security-Policy: scripts, styles and fonts only from here and the cdn, calls only to the api and
// the math server; the import map is the only inline script, allowed by its hash. A defence for when
// something slips through escapeHtml: injected html can then not run scripts or send data elsewhere.
preg_match('#<script type="importmap">(.*?)</script>#s', $pagina, $importmap);
$hash = base64_encode(hash('sha256', $importmap[1], true));
header("Content-Security-Policy: default-src 'none'; script-src 'self' $cdn 'sha256-$hash'; style-src 'self' $cdn; "
    . "font-src $cdn; img-src 'self'; connect-src 'self' $api $math; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Content-Type: text/html; charset=utf-8');
echo $pagina;
