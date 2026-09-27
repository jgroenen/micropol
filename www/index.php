<?php

// The product page of MiniPol (views/pagina.html), with links to all the parts: the urls from instellingen.php
// are filled in for {{APP_URL}}, {{API_URL}} and the others. There is no JavaScript.
$instellingen = require __DIR__ . '/instellingen.php';

// only the characters of a plain url, so they can go into the page and the Content-Security-Policy as they are
foreach ($instellingen as $naam => $url) {
    if (preg_match('#^https?://[A-Za-z0-9.:-]+(/[A-Za-z0-9._/-]*)?$#', $url) !== 1) {
        http_response_code(500);
        exit("MINIPOL_$naam is not a plain url.");
    }
}
$urls = array_map(function ($url) {
    return rtrim($url, '/');
}, $instellingen);

$pagina = file_get_contents(__DIR__ . '/views/pagina.html');
foreach ($urls as $naam => $url) {
    $pagina = str_replace('{{' . $naam . '}}', $url, $pagina);
}

// Content-Security-Policy: no scripts at all; styles and fonts only from here and the cdn
$cdn = $urls['CDN_URL'];
header("Content-Security-Policy: default-src 'none'; style-src 'self' $cdn; font-src $cdn; img-src 'self'; "
    . "form-action 'none'; base-uri 'none'; frame-ancestors 'none'");
header('Content-Type: text/html; charset=utf-8');
echo $pagina;
