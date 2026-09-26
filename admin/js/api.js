import { jsonVerzoek, ofNull } from 'cdn/verzoek.js';
import { API_URL } from './config.js';
import { accessToken, vernieuwNa401 } from './auth.js';

// all calls of the admin to the api (api/index.php), with the access token of the auth service (see auth.js);
// registered in the API popover (verzoek.js on the cdn). A call that fails throws an Error with its status;
// lookups give null when there is nothing (404). What is sent is one object in the shape of the api schema;
// an id in the url is a parameter of its own.

// after a 401 a new access token is tried once
async function verzoek(url, options = {}) {
    try {
        return await metToken(url, options, await accessToken());
    } catch (error) {
        const nieuw = error.status === 401 ? await vernieuwNa401() : null;
        if (!nieuw) {
            throw error;
        }
        return metToken(url, options, nieuw);
    }
}

function metToken(url, options, token) {
    const headers = { ...options.headers };
    if (token) {
        headers.Authorization = `Bearer ${token}`;
    }
    return jsonVerzoek(API_URL + url, { ...options, headers });
}

function stuur(methode, url, data) {
    return verzoek(url, {
        method: methode,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
    });
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
