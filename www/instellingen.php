<?php

// Settings of the product page: where the other parts are, to link to them. In production they come from
// environment variables (env in the Caddyfile); without them the defaults are for local development (dev/start.sh).
// Used by index.php.

function instelling($naam, $standaard) {
    $waarde = $_SERVER[$naam] ?? getenv($naam);
    return is_string($waarde) && $waarde !== '' ? $waarde : $standaard;
}

return [
    'APP_URL' => instelling('MINIPOL_APP_URL', 'http://localhost:8005'),
    'ADMIN_URL' => instelling('MINIPOL_ADMIN_URL', 'http://localhost:8002'),
    'API_URL' => instelling('MINIPOL_API_URL', 'http://localhost:8001'),
    'MATH_URL' => instelling('MINIPOL_MATH_URL', 'http://localhost:8004'),
    'CDN_URL' => instelling('MINIPOL_CDN_URL', 'http://localhost:8003'),
];
