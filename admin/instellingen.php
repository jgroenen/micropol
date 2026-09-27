<?php

// Settings of the admin: where the other servers are. In production they come from environment variables
// (env in the Caddyfile); without them the defaults are for local development (dev/start.sh).
// Used by index.php and js/config.php.

function instelling($naam, $standaard) {
    $waarde = $_SERVER[$naam] ?? getenv($naam);
    return is_string($waarde) && $waarde !== '' ? $waarde : $standaard;
}

return [
    'WWW_URL' => instelling('MINIPOL_WWW_URL', 'http://localhost:8000'),
    'API_URL' => instelling('MINIPOL_API_URL', 'http://localhost:8001'),
    'APP_URL' => instelling('MINIPOL_APP_URL', 'http://localhost:8005'),
    'CDN_URL' => instelling('MINIPOL_CDN_URL', 'http://localhost:8003'),
];
