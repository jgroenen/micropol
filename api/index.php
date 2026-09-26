<?php

// JSON api: /<resource>[/<id>] is handled by handlers/<Resource>Handler-><METHOD>($id).
// Runs on its own server; the app and the admin call it from other origins, see config.php.
// Locally: php -S localhost:8001 api/index.php

require __DIR__ . '/config.php';

// csv storage, see data/
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
    'gesprekken' => 'GesprekkenHandler',
    'stellingen' => 'StellingenHandler',
    'antwoorden' => 'AntwoordenHandler',
    'export' => 'ExportHandler',
    'docs' => 'DocsHandler',
    'sessie' => 'SessieHandler',
    'beoordelingen' => 'BeoordelingenHandler',
];

// the app and the admin are on other origins: allow those (CORS), and answer the preflight
// that browsers send before a request with JSON or an Authorization header
Http::cors(TOEGESTANE_ORIGINS);
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    return;
}

// the path segments; after /api/ when the api is served from a subdirectory of that name
$segments = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
$apiIndex = array_search('api', $segments, true);
if ($apiIndex !== false) {
    $segments = array_slice($segments, $apiIndex + 1);
}

$resource = $segments[0] ?? null;
$id = $segments[1] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

if (!isset($handlers[$resource])) {
    Http::error(404, "Unknown resource.");
    return;
}

$handler = new $handlers[$resource]();

if (!method_exists($handler, $method)) {
    Http::error(405, "Method not allowed.");
    return;
}

try {
    $handler->{$method}($id);
} catch (Throwable $e) {
    error_log($e);
    Http::error(500, "Internal server error.");
}
