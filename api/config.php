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
const DOCS_VIEWER = 'https://petstore.swagger.io/';

// The sites whose pages may call the api from the browser (CORS). For now every site ('*'): the api is open,
// a base for open innovation, like a page in JSFiddle or a dashboard of someone else. That is safe for the
// beheer: without cookies a page only sends a token it has itself. Only the app, the admin and the viewer of
// the docs would be: [APP_URL, ADMIN_URL, rtrim(DOCS_VIEWER, '/')]
define('TOEGESTANE_ORIGINS', ['*']);

// a login of a beheerder ends after this many seconds without using it, and after SESSIE_MAX at the latest
const SESSIE_IDLE = 60 * 60;
const SESSIE_MAX = 12 * 3600;
