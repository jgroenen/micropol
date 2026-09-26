<?php

// The math server: analyses of gesprekken, from the standard export of an api server.
// /<resource>[/<id>] is handled by handlers/<Resource>Handler-><METHOD>($id), like the api.
// Locally: php -S localhost:8004 math/index.php

require __DIR__ . '/config.php';

// the calculated models, see lib/AnalyseModel.php
define('DATA_DIR', __DIR__ . '/data');

// classes live in handlers/ and lib/, one class per file
spl_autoload_register(function ($class) {
    foreach (['handlers', 'lib'] as $dir) {
        $file = __DIR__ . '/' . $dir . '/' . basename($class) . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});

$handlers = [
    'analyse' => 'AnalyseHandler',
    'docs' => 'DocsHandler',
];

// the app is on another origin: allow it (CORS), and answer the preflight
Http::cors(TOEGESTANE_ORIGINS);
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    return;
}

$segments = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
$resource = $segments[0] ?? null;
$id = $segments[1] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

if (!isset($handlers[$resource])) {
    Http::error(404, 'Unknown resource.');
    return;
}

$handler = new $handlers[$resource]();

if (!method_exists($handler, $method)) {
    Http::error(405, 'Method not allowed.');
    return;
}

try {
    $handler->{$method}($id);
} catch (HttpFout $fout) {
    Http::error($fout->getCode(), $fout->getMessage());
} catch (Throwable $e) {
    error_log($e);
    Http::error(500, 'Internal server error.');
}
