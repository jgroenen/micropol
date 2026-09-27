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

// who is logged in: { account, installatie }, see Sessie in the api schema; account null when not logged in
export async function getSessie() {
    if (!leesToken()) {
        return { account: null, installatie: false };
    }
    const sessie = await verzoek('/sessie');
    if (!sessie.account && !sessie.installatie) {
        bewaarToken(null); // expired or logged out elsewhere
    }
    return sessie;
}

// { account, installatie }; throws with status 401 for a wrong gebruikersnaam or wachtwoord
export async function login(gebruikersnaam, wachtwoord) {
    return bewaarSessie(await stuur('POST', '/sessie', { gebruikersnaam, wachtwoord }));
}

export async function logout() {
    try {
        await verzoek('/sessie', { method: 'DELETE' });
    } finally {
        bewaarToken(null);
    }
}

// keeps the token of a new login, if the response has one; returns { account, installatie }
function bewaarSessie({ token, verloopt, ...sessie }) {
    if (token) {
        bewaarToken(token);
    }
    return sessie;
}

// right after installing, logged in as admin: { gebruikersnaam, email, wachtwoord } of the first superbeheerder
// => { account, installatie }, logged in as that superbeheerder
export async function postInstallatie(account) {
    return bewaarSessie(await stuur('POST', '/installatie', account));
}

// { rol, gesprek_id } => { token, rol, gesprek_id, verloopt }: the token is for the link
export function postUitnodiging(uitnodiging) {
    return stuur('POST', '/uitnodigingen', uitnodiging);
}

// what an uitnodiging is for: { rol, gesprek: { id, titel } or null, verloopt }, or null when used or expired
export function getUitnodiging(token) {
    return ofNull(verzoek(`/uitnodigingen/${encodeURIComponent(token)}`));
}

// uses an uitnodiging: logged in for that account (nieuwAccount null), otherwise for a new account
// { gebruikersnaam, email, wachtwoord }, which is logged in right away => { account, installatie }
export async function neemUitnodigingAan(token, nieuwAccount = null) {
    return bewaarSessie(await stuur('POST', `/uitnodigingen/${encodeURIComponent(token)}`, nieuwAccount ?? {}));
}

// the team of a gesprek: [{ account_id, gebruikersnaam, email, rol, status }]
export async function getTeam(gesprekId) {
    return (await verzoek(`/team?gesprek_id=${encodeURIComponent(gesprekId)}`)).leden;
}

// { gesprek_id, account_id, status, reden } => the lid
export function postTeamstatus(wijziging) {
    return stuur('POST', '/team', wijziging);
}

// [{ id, gebruikersnaam, email, status }]
export async function getSuperbeheerders() {
    return (await verzoek('/superbeheerders')).superbeheerders;
}

// { account_id, status, reden } => the superbeheerder
export function postSuperbeheerderstatus(wijziging) {
    return stuur('POST', '/superbeheerders', wijziging);
}

// the kanalen of a gesprek: { kanalen: [{ kanaal_id, naam, token, status, meetellen, deelnemers, antwoorden }],
// zonder_kanaal: { deelnemers, antwoorden } }
export function getKanalen(gesprekId) {
    return verzoek(`/kanalen?gesprek_id=${encodeURIComponent(gesprekId)}`);
}

// { gesprek_id, naam } => the new kanaal
export function postKanaal(kanaal) {
    return stuur('POST', '/kanalen', kanaal);
}

// { gesprek_id, meetellen, status } => the kanaal; status ingetrokken stops its link for good
export function putKanaal(kanaalId, wijziging) {
    return stuur('PUT', `/kanalen/${encodeURIComponent(kanaalId)}`, wijziging);
}

// { gesprek_id, status, reden } => the gesprek
export function postGespreksstatus(wijziging) {
    return stuur('POST', '/gespreksstatus', wijziging);
}

// the gesprekken of the logged in account: [{ id, titel, omschrijving, moderatie, status, rol }]
export async function getGesprekken() {
    return (await verzoek('/gesprekken')).gesprekken;
}

// { id, titel, omschrijving, moderatie, status, stellingen: [{ id, tekst }] } or null
export function getGesprek(id) {
    return ofNull(verzoek(`/gesprekken/${encodeURIComponent(id)}`));
}

// { titel, omschrijving, moderatie } => the new gesprek { id, titel, omschrijving, moderatie, status }
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
