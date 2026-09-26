<?php

// Settings of the auth service; change these for each environment.

// the url of this service, as clients reach it (the "issuer")
define('ISSUER', 'http://localhost:8005');

// the cdn with the shared styles, for the login page
define('CDN_URL', 'http://localhost:8003');

// Origins (scheme://host[:port]) of the pages that may call /token, /revoke and /userinfo from the browser (CORS).
const TOEGESTANE_ORIGINS = [
    'http://localhost:8002', // admin
];

// The apps that may send users here to log in (public clients, with PKCE), and where the user may be
// sent back to after logging in; redirect_uri must be exactly one of these.
const CLIENTS = [
    'minipol-admin' => ['redirect_uris' => ['http://localhost:8002/']],
];

// The servers that may check tokens with /introspect, with their secret (HTTP Basic).
// Set the secrets with environment variables in production; the defaults are for local development only.
define('RESOURCE_SERVERS', [
    'minipol-api' => getenv('MINIPOL_API_SECRET') ?: 'dev-api-secret',
]);

// lifetimes, in seconds
const ACCESS_TOKEN_GELDIG = 15 * 60;   // an access token
const REFRESH_TOKEN_GELDIG = 30 * 60;  // a refresh token: logged out after this long without refreshing
const SESSIE_MAX = 12 * 3600;          // a login, however often it is refreshed
const CODE_GELDIG = 60;                // an authorization code, until it is exchanged at /token
