import { jsonVerzoek, ofNull } from 'cdn/verzoek.js';
import { API_URL } from './config.php';

// all calls of the admin to the api (api/index.php); registered in the API popover (verzoek.js on the cdn).
// A call that fails throws an Error with its status; lookups give null when there is nothing (404).
// What is sent is one object in the shape of the api schema; an id in the url is a parameter of its own.

// Logging in gives a token, sent as "Authorization: Bearer <token>" and kept in localStorage, so a reload
// or a new tab stays logged in. The api extends it while it is used; a 401 means it has ended.
const TOKEN = 'minipol_admin_token';
// not shown in the API popover
const GEHEIM = ['wachtwoord', 'token'];

function leesToken() {
    try {
        return localStorage.getItem(TOKEN);
    } catch (error) {
        return null;
    }
}

function bewaarToken(token) {
    try {
        if (token) {
            localStorage.setItem(TOKEN, token);
        } else {
            localStorage.removeItem(TOKEN);
        }
    } catch (error) {}
}

async function verzoek(url, options = {}) {
    const token = leesToken();
    const headers = { ...options.headers };
    if (token) {
        headers.Authorization = `Bearer ${token}`;
    }
    try {
        return await jsonVerzoek(API_URL + url, { ...options, headers }, GEHEIM);
    } catch (error) {
        if (error.status === 401 && token) {
            bewaarToken(null); // expired or logged out elsewhere
        }
        throw error;
    }
}

function stuur(methode, url, data) {
    return verzoek(url, {
        method: methode,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
    });
}

// { id, gebruikersnaam, email } of the logged in beheerder, or null
export async function getBeheerder() {
    if (!leesToken()) {
        return null;
    }
    const { beheerder } = await verzoek('/sessie');
    if (!beheerder) {
        bewaarToken(null); // expired or logged out elsewhere
    }
    return beheerder;
}

// the beheerder; throws with status 401 for a wrong gebruikersnaam or wachtwoord
export async function login(gebruikersnaam, wachtwoord) {
    const { beheerder, token } = await stuur('POST', '/sessie', { gebruikersnaam, wachtwoord });
    bewaarToken(token);
    return beheerder;
}

export async function logout() {
    try {
        await verzoek('/sessie', { method: 'DELETE' });
    } finally {
        bewaarToken(null);
    }
}

// [{ id, titel, omschrijving, moderatie }]
export async function getGesprekken() {
    return (await verzoek('/gesprekken')).gesprekken;
}

// { id, titel, omschrijving, moderatie, stellingen: [{ id, tekst }] } or null
export function getGesprek(id) {
    return ofNull(verzoek(`/gesprekken/${encodeURIComponent(id)}`));
}

// { titel, omschrijving, moderatie } => the new gesprek { id, titel, omschrijving, moderatie }
export function postGesprek(gesprek) {
    return stuur('POST', '/gesprekken', gesprek);
}

// { titel, omschrijving, moderatie } => the changed gesprek { id, titel, omschrijving, moderatie }
export function putGesprek(id, gesprek) {
    return stuur('PUT', `/gesprekken/${encodeURIComponent(id)}`, gesprek);
}

// all stellingen of a gesprek: [{ id, gesprek_id, tekst, beoordeling, reden, zichtbaar, antwoorden: { eens, neutraal, oneens } }]
export async function getBeoordelingen(gesprekId) {
    return (await verzoek(`/beoordelingen?gesprek_id=${encodeURIComponent(gesprekId)}`)).stellingen;
}

// { gesprek_id, stelling_id, beoordeling, reden } => the stelling with its new state;
// beoordeling is goedgekeurd or afgekeurd (then reden is required)
export function postBeoordeling(beoordeling) {
    return stuur('POST', '/beoordelingen', beoordeling);
}

// what happened in a gesprek, newest first: { events: [{ id, tijdstip, type, door, gesprek_id, ... }], meer };
// with voor (the id of the oldest event so far) the ones before it
export function getEvents(gesprekId, voor = null) {
    const parameters = new URLSearchParams({ gesprek_id: gesprekId });
    if (voor) {
        parameters.set('voor', voor);
    }
    return verzoek(`/events?${parameters}`);
}
