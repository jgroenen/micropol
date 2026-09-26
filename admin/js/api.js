import { registreer, huidigePagina } from 'cdn/apilog.js';
import { API_URL } from './config.js';

// calls to the PHP api for the admin environment, see api/index.php

// The api is on another domain, so logging in gives a token instead of a cookie; it is sent as
// "Authorization: Bearer <token>" and kept in localStorage, so a reload or a new tab stays logged in.
const TOKEN_SLEUTEL = 'minipol_admin_token';

function leesToken() {
    try {
        return localStorage.getItem(TOKEN_SLEUTEL);
    } catch (e) {
        return null;
    }
}

function bewaarToken(token) {
    try {
        if (token) {
            localStorage.setItem(TOKEN_SLEUTEL, token);
        } else {
            localStorage.removeItem(TOKEN_SLEUTEL);
        }
    } catch (e) {}
}

// returns the parsed json (null for an empty response); throws with the status on errors;
// every call is registered for the API popover (apilog.js on the cdn), with verzonden as the request shown there
async function request(url, options = {}, verzonden = options.body) {
    const token = leesToken();
    const headers = { ...options.headers };
    if (token) {
        headers.Authorization = `Bearer ${token}`;
    }
    const verzoek = {
        pagina: huidigePagina(),
        methode: options.method ?? 'GET',
        url: API_URL + url,
        verzonden,
        status: 0,
        duur: 0,
        tekst: '',
    };
    const start = performance.now();
    try {
        const response = await fetch(API_URL + url, { ...options, headers });
        verzoek.status = response.status;
        verzoek.tekst = await response.text();
        verzoek.duur = performance.now() - start;
        if (response.status === 401 && token) {
            bewaarToken(null); // expired or logged out elsewhere
        }
        if (!response.ok) {
            const error = new Error(`HTTP ${response.status} for ${url}`);
            error.status = response.status;
            throw error;
        }
        return verzoek.tekst ? JSON.parse(verzoek.tekst) : null;
    } catch (error) {
        verzoek.duur = verzoek.duur || performance.now() - start;
        verzoek.tekst = verzoek.tekst || String(error);
        throw error;
    } finally {
        registreer(verzoek);
    }
}

function post(url, data, verzonden) {
    const body = JSON.stringify(data);
    return request(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body
    }, verzonden ?? body);
}

// { id, username, email } of the logged in user, or null
export async function getUser() {
    if (!leesToken()) {
        return null;
    }
    const user = (await request('/sessie')).user;
    if (!user) {
        bewaarToken(null); // expired or logged out elsewhere
    }
    return user;
}

// the user; throws with status 401 for a wrong username or password
export async function login(username, password) {
    // the password is not shown in the API popover
    const data = await post('/sessie', { username, password }, JSON.stringify({ username, password: '••••••••' }));
    bewaarToken(data.token);
    return data.user;
}

export async function logout() {
    try {
        await request('/sessie', { method: 'DELETE' });
    } finally {
        bewaarToken(null);
    }
}

// [{ id, titel, omschrijving }]
export async function getGesprekken() {
    return (await request('/gesprekken')).gesprekken;
}

// { id, titel, omschrijving, stellingen: [{ id, content }] } or throws with status 404
export function getGesprek(id) {
    return request(`/gesprekken/${encodeURIComponent(id)}`);
}

// { titel, omschrijving, moderatie } => the changed gesprek { id, titel, omschrijving, moderatie }
export function putGesprek(id, velden) {
    return request(`/gesprekken/${encodeURIComponent(id)}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(velden)
    });
}

// { titel, omschrijving, moderatie } => the new gesprek { id, titel, omschrijving, moderatie }
export function postGesprek(velden) {
    return post('/gesprekken', velden);
}

// all stellingen of a gesprek: [{ id, content, beoordeling, reden, zichtbaar, antwoorden: { eens, neutraal, oneens } }]
export async function getBeoordelingen(gesprekId) {
    return (await request(`/beoordelingen?gesprek_id=${encodeURIComponent(gesprekId)}`)).stellingen;
}

// beoordeling is goedgekeurd or afgekeurd (reden required); returns the stelling with its new state
export function postBeoordeling(gesprekId, stellingId, beoordeling, reden = '') {
    return post('/beoordelingen', { gesprek_id: gesprekId, stelling_id: stellingId, beoordeling, reden });
}
