import { registreer, huidigePagina } from 'cdn/apilog.js';
import { API_URL } from './config.js';
import { accessToken, vernieuwNa401 } from './auth.js';

// calls to the PHP api for the admin environment, see api/index.php

// returns the parsed json (null for an empty response); throws with the status on errors;
// sends the access token of the auth service (see auth.js), and tries a new one once after a 401;
// every call is registered for the API popover (apilog.js on the cdn), with verzonden as the request shown there
async function request(url, options = {}, verzonden = options.body) {
    try {
        return await verstuur(url, options, verzonden, await accessToken());
    } catch (error) {
        const nieuw = error.status === 401 ? await vernieuwNa401() : null;
        if (!nieuw) {
            throw error;
        }
        return verstuur(url, options, verzonden, nieuw);
    }
}

async function verstuur(url, options, verzonden, token) {
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
