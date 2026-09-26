<?php

// The auth service: a small OAuth 2 authorization server (authorization code with PKCE, refresh tokens,
// introspection and revocation), so it can later be replaced by one like Keycloak. See lib/Tokens.php.
// /<resource>[/<id>] is handled by handlers/<Resource>Handler-><METHOD>($id), like the api.
// Locally: php -S localhost:8005 auth/index.php

require __DIR__ . '/config.php';

// gebruikers, codes, logins and tokens, see lib/Data.php
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
    '.well-known' => 'WellKnownHandler',
    'authorize' => 'AuthorizeHandler',
    'token' => 'TokenHandler',
    'introspect' => 'IntrospectHandler',
    'revoke' => 'RevokeHandler',
    'userinfo' => 'UserinfoHandler',
    'docs' => 'DocsHandler',
];

// the admin is on another origin: allow it (CORS), and answer the preflight
// that browsers send before a request with an Authorization header
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
} catch (OAuthFout $fout) {
    Http::json(['error' => $fout->error, 'error_description' => $fout->getMessage()], $fout->getCode());
} catch (HttpFout $fout) {
    Http::error($fout->getCode(), $fout->getMessage());
} catch (Throwable $e) {
    error_log($e);
    Http::error(500, 'Internal server error.');
}
