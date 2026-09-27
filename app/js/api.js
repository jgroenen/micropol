import { jsonVerzoek, ofNull } from 'cdn/verzoek.js';
import { API_URL, MATH_URL } from './config.php';

// all calls of the app: to the api (api/index.php), and to the math server for the analyse (math/index.php);
// registered in the API popover (verzoek.js on the cdn). A call that fails throws an Error with its status;
// lookups give null when there is nothing (404). What is sent is one object in the shape of the api schema;
// an id in the url is a parameter of its own.

function get(url) {
    return jsonVerzoek(API_URL + url);
}

function post(url, data) {
    return jsonVerzoek(API_URL + url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
    });
}

// [{ id, titel, omschrijving, moderatie }]
export async function getGesprekken() {
    return (await get('/gesprekken')).gesprekken;
}

// { id, titel, omschrijving, moderatie, status, zonder_kanaal, stellingen: [{ id, tekst }] } or null;
// only the zichtbare stellingen, in random order. With the token of a kanaal also kanaal_geldig: whether its
// link still works
export function getGesprek(gesprekId, kanaal = null) {
    const zoek = kanaal ? `?kanaal=${encodeURIComponent(kanaal)}` : '';
    return ofNull(get(`/gesprekken/${encodeURIComponent(gesprekId)}${zoek}`));
}

// { stelling_id: waarde } for one deelnemer, or null if the gesprek does not exist
export async function getMijnAntwoorden(gesprekId, deelnemerId) {
    const data = await ofNull(get(`/antwoorden?gesprek_id=${encodeURIComponent(gesprekId)}&deelnemer_id=${encodeURIComponent(deelnemerId)}`));
    if (!data) {
        return null;
    }
    const antwoorden = {};
    data.antwoorden.forEach(antwoord => {
        antwoorden[antwoord.stelling_id] = antwoord.waarde;
    });
    return antwoorden;
}

// [{ nummer, antwoorden: { stelling_id: waarde } }], one per deelnemer (anonymous, like the export), or null
export async function getMatrix(gesprekId) {
    const data = await ofNull(get(`/antwoorden?gesprek_id=${encodeURIComponent(gesprekId)}`));
    return data ? data.deelnemers : null;
}

// PCA + K-means groups: { k, verklaarde_variantie, deelnemers: [{ deelnemer, x, y, groep, antwoorden }], groepen, ... }
// from the math server, which reads the export of this gesprek on our api; null if the gesprek does not exist
export function getAnalyse(gesprekId) {
    return ofNull(jsonVerzoek(`${MATH_URL}/analyse?api=${encodeURIComponent(API_URL)}&gesprek_id=${encodeURIComponent(gesprekId)}`));
}

// [{ id, gesprek_id, tekst, deelnemer_id, beoordeling, reden, zichtbaar, antwoorden: { eens, neutraal, oneens } }]
// added by one deelnemer, or null if the gesprek does not exist; beoordeling is goedgekeurd, afgekeurd or null
export async function getMijnStellingen(gesprekId, deelnemerId) {
    const data = await ofNull(get(`/stellingen?gesprek_id=${encodeURIComponent(gesprekId)}&deelnemer_id=${encodeURIComponent(deelnemerId)}`));
    return data ? data.stellingen : null;
}

// { gesprek_id, deelnemer_id, stelling_id, waarde, kanaal } => the saved antwoord; a 403 when the kanaal works
// no more, or when the gesprek only takes part through a kanaal and there is none
export function postAntwoord(antwoord) {
    return post('/antwoorden', antwoord);
}

// { gesprek_id, deelnemer_id, tekst, kanaal } => the new stelling { id, gesprek_id, tekst, deelnemer_id, beoordeling, reden, zichtbaar }
export function postStelling(stelling) {
    return post('/stellingen', stelling);
}
