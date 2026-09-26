<?php

// Settings of the math server. In production they come from environment variables (SetEnv in the Apache
// virtual host, see deploy/); without them the defaults are for local development (dev/start.sh).

function instelling($naam, $standaard) {
    $waarde = $_SERVER[$naam] ?? getenv($naam);
    return is_string($waarde) && $waarde !== '' ? $waarde : $standaard;
}

// Origins (scheme://host[:port]) of the pages that may call the math server from the browser (CORS).
define('TOEGESTANE_ORIGINS', [instelling('MINIPOL_APP_URL', 'http://localhost:8000')]);

// Base urls of the api servers whose export the math server may fetch (GET <api>/export?gesprek_id=...).
// Only these: the math server must not fetch any url a caller gives it.
define('TOEGESTANE_APIS', [instelling('MINIPOL_API_URL', 'http://localhost:8001')]);
