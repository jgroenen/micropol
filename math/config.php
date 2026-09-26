<?php

// Settings of the math server; change these for each environment.

// Origins (scheme://host[:port]) of the pages that may call the math server from the browser (CORS).
const TOEGESTANE_ORIGINS = [
    'http://localhost:8000', // app
];

// Base urls of the api servers whose export the math server may fetch (GET <api>/export?gesprek_id=...).
// Only these: the math server must not fetch any url a caller gives it.
const TOEGESTANE_APIS = [
    'http://localhost:8001',
];
