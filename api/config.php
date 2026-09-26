<?php

// Settings of the api; change these for each environment.

// Origins (scheme://host[:port]) of the app and the admin: only pages from these may call the api
// from the browser (CORS).
const TOEGESTANE_ORIGINS = [
    'http://localhost:8000', // app
    'http://localhost:8002', // admin
];

// The auth service that checks the tokens of logged in admins (introspection, RFC 7662), and the id and
// secret of this api there. Set the secret with an environment variable in production.
define('AUTH_INTROSPECTIE_URL', 'http://localhost:8005/introspect');
define('AUTH_CLIENT_ID', 'minipol-api');
define('AUTH_CLIENT_SECRET', getenv('MINIPOL_API_SECRET') ?: 'dev-api-secret');

// seconds the api keeps the answer of the auth service for a token
const INTROSPECTIE_CACHE = 10;
