import { gelogdeFetch } from 'cdn/verzoek.js';
import { AUTH_URL, CLIENT_ID } from './config.js';

// Logging in with the auth service (OAuth 2: authorization code with PKCE, refresh tokens, revocation).
// The endpoints come from its /.well-known/openid-configuration, so another OAuth server like Keycloak
// only needs another AUTH_URL (and CLIENT_ID) in config.js.
//
// The tokens are kept in localStorage, so a reload or a new tab stays logged in. The access token is
// short-lived; it is refreshed when (almost) expired, and each refresh gives a new refresh token.

const REDIRECT_URI = `${location.origin}/`;
const TOKENS = 'minipol_admin_tokens'; // { access_token, refresh_token, verloopt (ms) }
const INLOG = 'minipol_admin_inlog';   // sessionStorage during the login: { state, verifier, hash }
const VOORSPRONG = 30 * 1000;          // refresh when the access token expires within this

let configuratie = null;

// start logging in: to the login page of the auth service, which comes back to REDIRECT_URI
export async function inloggen() {
    const verifier = willekeurig();
    const state = willekeurig();
    bewaar(sessionStorage, INLOG, { state, verifier, hash: location.hash });
    const { authorization_endpoint } = await discovery();
    const url = new URL(authorization_endpoint);
    url.search = new URLSearchParams({
        response_type: 'code',
        client_id: CLIENT_ID,
        redirect_uri: REDIRECT_URI,
        scope: 'openid',
        state,
        code_challenge: await challenge(verifier),
        code_challenge_method: 'S256',
    });
    location.assign(url);
}

// back from the login page (?code=...&state=... or ?error=...): exchange the code for tokens,
// and go back to the page where logging in started; does nothing on a normal page load
export async function verwerkTerugkeer() {
    const params = new URLSearchParams(location.search);
    if (!params.has('code') && !params.has('error')) {
        return;
    }
    const inlog = lees(sessionStorage, INLOG);
    sessionStorage.removeItem(INLOG);
    history.replaceState(null, '', `/${inlog?.hash ?? ''}`);
    if (params.has('error')) {
        throw new Error(`Login failed: ${params.get('error')}`);
    }
    // the state proves that this answer belongs to a login started here
    if (!inlog || params.get('state') !== inlog.state) {
        throw new Error('Login failed: unknown state.');
    }
    bewaarTokens(await tokenVerzoek({
        grant_type: 'authorization_code',
        code: params.get('code'),
        redirect_uri: REDIRECT_URI,
        client_id: CLIENT_ID,
        code_verifier: inlog.verifier,
    }));
}

// the access token, refreshed first when (almost) expired; null when not logged in
export async function accessToken() {
    let tokens = lees(localStorage, TOKENS);
    if (tokens && tokens.verloopt - Date.now() < VOORSPRONG) {
        tokens = await vernieuw(tokens);
    }
    return tokens?.access_token ?? null;
}

// after a 401 of the api: try a new access token once; null when the login has ended
export async function vernieuwNa401() {
    const tokens = lees(localStorage, TOKENS);
    return tokens ? (await vernieuw(tokens))?.access_token ?? null : null;
}

// { id, gebruikersnaam, email } of the logged in beheerder, or null
export async function ingelogdeBeheerder() {
    const token = await accessToken();
    if (!token) {
        return null;
    }
    const { userinfo_endpoint } = await discovery();
    const response = await registreerFetch(userinfo_endpoint, { headers: { Authorization: `Bearer ${token}` } });
    if (!response.ok) {
        return null;
    }
    const info = await response.json();
    return { id: info.sub, gebruikersnaam: info.preferred_username ?? info.email ?? info.sub, email: info.email ?? '' };
}

// ends the login at the auth service, so all its tokens stop working
export async function uitloggen() {
    const tokens = lees(localStorage, TOKENS);
    localStorage.removeItem(TOKENS);
    if (tokens) {
        const { revocation_endpoint } = await discovery();
        await registreerFetch(revocation_endpoint, {
            method: 'POST',
            body: new URLSearchParams({ token: tokens.refresh_token, client_id: CLIENT_ID }),
        });
    }
}

// A refresh token works once. Tabs share the tokens, so only one tab at a time refreshes (Web Locks);
// a tab that waited uses the tokens the other one got, instead of reusing the old refresh token,
// which the auth service would take as stolen.
async function vernieuw(oud) {
    const doe = async () => {
        const tokens = lees(localStorage, TOKENS);
        if (!tokens) {
            return null;
        }
        if (tokens.refresh_token !== oud.refresh_token) {
            return tokens; // another tab already refreshed
        }
        try {
            bewaarTokens(await tokenVerzoek({ grant_type: 'refresh_token', refresh_token: tokens.refresh_token, client_id: CLIENT_ID }));
            return lees(localStorage, TOKENS);
        } catch (error) {
            if (error.status === 400 || error.status === 401) {
                localStorage.removeItem(TOKENS); // expired or ended: logged out
                return null;
            }
            throw error;
        }
    };
    return navigator.locks ? navigator.locks.request('minipol-admin-tokens', doe) : doe();
}

async function tokenVerzoek(velden) {
    const { token_endpoint } = await discovery();
    const response = await registreerFetch(token_endpoint, { method: 'POST', body: new URLSearchParams(velden) });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const error = new Error(`Token request failed: ${data.error ?? response.status}`);
        error.status = response.status;
        throw error;
    }
    return data;
}

function bewaarTokens(data) {
    bewaar(localStorage, TOKENS, {
        access_token: data.access_token,
        refresh_token: data.refresh_token,
        verloopt: Date.now() + data.expires_in * 1000,
    });
}

async function discovery() {
    if (!configuratie) {
        const response = await registreerFetch(`${AUTH_URL}/.well-known/openid-configuration`);
        if (!response.ok) {
            throw new Error(`HTTP ${response.status} for the configuration of the auth service`);
        }
        configuratie = await response.json();
    }
    return configuratie;
}

// fetch, registered in the API popover (verzoek.js on the cdn), with tokens and codes hidden there
const GEHEIM = ['code', 'code_verifier', 'token', 'access_token', 'refresh_token'];

function registreerFetch(url, options = {}) {
    return gelogdeFetch(url, options, GEHEIM);
}

// PKCE S256: base64url(sha256(verifier))
async function challenge(verifier) {
    const hash = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier));
    return base64url(new Uint8Array(hash));
}

function willekeurig() {
    return base64url(crypto.getRandomValues(new Uint8Array(32)));
}

function base64url(bytes) {
    return btoa(String.fromCharCode(...bytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function lees(opslag, sleutel) {
    try {
        return JSON.parse(opslag.getItem(sleutel));
    } catch (error) {
        return null;
    }
}

function bewaar(opslag, sleutel, waarde) {
    try {
        opslag.setItem(sleutel, JSON.stringify(waarde));
    } catch (error) {}
}
