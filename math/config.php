<?php

// Settings of the math server. In production they come from environment variables (env in the Caddyfile);
// without them the defaults are for local development (dev/start.sh).

function instelling($naam, $standaard) {
    $waarde = $_SERVER[$naam] ?? getenv($naam);
    return is_string($waarde) && $waarde !== '' ? $waarde : $standaard;
}

// The documentation on /docs is shown by this viewer (Swagger UI), which gets the url of the spec as ?url=.
// Its origin may call this server too, for "Try it out".
const DOCS_VIEWER = 'https://petstore.swagger.io/';

// Origins (scheme://host[:port]) of the pages that may call the math server from the browser (CORS):
// the app, and the viewer of the docs.
define('TOEGESTANE_ORIGINS', [instelling('MINIPOL_APP_URL', 'http://localhost:8005'), rtrim(DOCS_VIEWER, '/')]);

// Base urls of the api servers whose export the math server may fetch (GET <api>/export?gesprek_id=...).
// Only these: the math server must not fetch any url a caller gives it.
define('TOEGESTANE_APIS', [instelling('MINIPOL_API_URL', 'http://localhost:8001')]);
