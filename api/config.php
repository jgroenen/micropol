<?php

// Settings of the api. In production they come from environment variables (env in the Caddyfile);
// without them the defaults are for local development (dev/start.sh).

function instelling($naam, $standaard) {
    $waarde = $_SERVER[$naam] ?? getenv($naam);
    return is_string($waarde) && $waarde !== '' ? $waarde : $standaard;
}

define('APP_URL', instelling('MINIPOL_APP_URL', 'http://localhost:8005'));
define('ADMIN_URL', instelling('MINIPOL_ADMIN_URL', 'http://localhost:8002'));

// The documentation on /docs is shown by this viewer (Swagger UI), which gets the url of the spec as ?url=.
// Its origin may call this server too, for "Try it out".
const DOCS_VIEWER = 'https://petstore.swagger.io/';

// Origins (scheme://host[:port]) of the app, the admin and the viewer of the docs: only pages from these may
// call the api from the browser (CORS).
define('TOEGESTANE_ORIGINS', [APP_URL, ADMIN_URL, rtrim(DOCS_VIEWER, '/')]);

// a login of a beheerder ends after this many seconds without using it, and after SESSIE_MAX at the latest
const SESSIE_IDLE = 60 * 60;
const SESSIE_MAX = 12 * 3600;
