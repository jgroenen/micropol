<?php

// Settings of the api. In production they come from environment variables (SetEnv in the Apache
// virtual host, see deploy/); without them the defaults are for local development (dev/start.sh).

function instelling($naam, $standaard) {
    $waarde = $_SERVER[$naam] ?? getenv($naam);
    return is_string($waarde) && $waarde !== '' ? $waarde : $standaard;
}

define('APP_URL', instelling('MINIPOL_APP_URL', 'http://localhost:8000'));
define('ADMIN_URL', instelling('MINIPOL_ADMIN_URL', 'http://localhost:8002'));
define('AUTH_URL', instelling('MINIPOL_AUTH_URL', 'http://localhost:8005'));

// Origins (scheme://host[:port]) of the app and the admin: only pages from these may call the api
// from the browser (CORS).
define('TOEGESTANE_ORIGINS', [APP_URL, ADMIN_URL]);

// The auth service that checks the tokens of logged in admins (introspection, RFC 7662), and the id and
// secret of this api there; the same secret is set for the auth service.
define('AUTH_INTROSPECTIE_URL', AUTH_URL . '/introspect');
define('AUTH_CLIENT_ID', 'minipol-api');
define('AUTH_CLIENT_SECRET', instelling('MINIPOL_API_SECRET', 'dev-api-secret'));

// seconds the api keeps the answer of the auth service for a token
const INTROSPECTIE_CACHE = 10;
