<?php

// Settings of the api; change these for each environment.

// Origins (scheme://host[:port]) of the app and the admin: only pages from these may call the api
// from the browser (CORS).
const TOEGESTANE_ORIGINS = [
    'http://localhost:8000', // app
    'http://localhost:8002', // admin
];
