<?php

// Settings of the api. In production they come from environment variables (SetEnv in the Apache
// virtual host, see deploy/); without them the defaults are for local development (dev/start.sh).

function instelling($naam, $standaard) {
    $waarde = $_SERVER[$naam] ?? getenv($naam);
    return is_string($waarde) && $waarde !== '' ? $waarde : $standaard;
}

define('APP_URL', instelling('MINIPOL_APP_URL', 'http://localhost:8000'));
define('ADMIN_URL', instelling('MINIPOL_ADMIN_URL', 'http://localhost:8002'));

// Origins (scheme://host[:port]) of the app and the admin: only pages from these may call the api
// from the browser (CORS).
define('TOEGESTANE_ORIGINS', [APP_URL, ADMIN_URL]);

// a login of a beheerder ends after this many seconds without using it, and after SESSIE_MAX at the latest
const SESSIE_IDLE = 60 * 60;
const SESSIE_MAX = 12 * 3600;
