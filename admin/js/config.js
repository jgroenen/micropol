// Where the other servers are. These are the values for local development (dev/start.sh);
// deploy/deploy.sh writes this file with the production values when deploying.
export const API_URL = 'http://localhost:8001';
export const APP_URL = 'http://localhost:8000';

// The OAuth server for logging in, and the id of the admin there (see auth.js).
export const AUTH_URL = 'http://localhost:8005';
export const CLIENT_ID = 'minipol-admin';
