<?php

// Settings of the auth service. In production they come from environment variables (SetEnv in the Apache
// virtual host, see deploy/); without them the defaults are for local development (dev/start.sh).

function instelling($naam, $standaard) {
    $waarde = $_SERVER[$naam] ?? getenv($naam);
    return is_string($waarde) && $waarde !== '' ? $waarde : $standaard;
}

define('ADMIN_URL', instelling('MINIPOL_ADMIN_URL', 'http://localhost:8002'));

// the url of this service, as clients reach it (the "issuer")
define('ISSUER', instelling('MINIPOL_AUTH_URL', 'http://localhost:8005'));

// the cdn with the shared styles, for the login page
define('CDN_URL', instelling('MINIPOL_CDN_URL', 'http://localhost:8003'));

// Origins (scheme://host[:port]) of the pages that may call /token, /revoke and /userinfo from the browser (CORS).
define('TOEGESTANE_ORIGINS', [ADMIN_URL]);

// The apps that may send gebruikers here to log in (public clients, with PKCE), and where the gebruiker may be
// sent back to after logging in; redirect_uri must be exactly one of these.
define('CLIENTS', [
    'minipol-admin' => ['redirect_uris' => [ADMIN_URL . '/']],
]);

// The servers that may check tokens with /introspect, with their secret (HTTP Basic);
// the same secret is set for the api.
define('RESOURCE_SERVERS', [
    'minipol-api' => instelling('MINIPOL_API_SECRET', 'dev-api-secret'),
]);

// lifetimes, in seconds
const ACCESS_TOKEN_GELDIG = 15 * 60;   // an access token
const REFRESH_TOKEN_GELDIG = 30 * 60;  // a refresh token: logged out after this long without refreshing
const SESSIE_MAX = 12 * 3600;          // a login, however often it is refreshed
const CODE_GELDIG = 60;                // an authorization code, until it is exchanged at /token
