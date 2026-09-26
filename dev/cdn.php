<?php

// Router for the cdn in local development (see start.sh): serves the files of cdn/ with a CORS
// header, which browsers need to import JS modules from another origin. In production the
// webserver of the cdn sends that header.

if (PHP_SAPI !== 'cli-server') {
    exit;
}

$types = [
    'css' => 'text/css; charset=utf-8',
    'js' => 'text/javascript; charset=utf-8',
];
$root = realpath(__DIR__ . '/../cdn');
$file = realpath($root . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$extensie = $file ? pathinfo($file, PATHINFO_EXTENSION) : '';

// only css and js files inside cdn/
if ($file === false || !str_starts_with($file, $root . '/') || !isset($types[$extensie]) || !is_file($file)) {
    http_response_code(404);
    return;
}

header('Access-Control-Allow-Origin: *');
header('Content-Type: ' . $types[$extensie]);
readfile($file);
