import { registreer, huidigePagina } from 'cdn/apilog.js';
import { API_URL, MATH_URL } from './config.js';

// all calls to the PHP api (api/index.php), and to the math server for the analyse (math/index.php)

// returns the parsed json, or null on 404; throws on other errors
// every call is registered for the API popover (apilog.js on the cdn)
async function request(url, options = {}, basis = API_URL) {
    const verzoek = {
        pagina: huidigePagina(),
        methode: options.method ?? 'GET',
        url: basis + url,
        verzonden: options.body,
        status: 0,
        duur: 0,
        tekst: '',
    };
    const start = performance.now();
    try {
        const response = await fetch(basis + url, options);
        verzoek.status = response.status;
        verzoek.tekst = await response.text();
        verzoek.duur = performance.now() - start;
        if (response.status === 404) {
            return null;
        }
        if (!response.ok) {
            throw new Error(`HTTP ${response.status} for ${url}`);
        }
        return JSON.parse(verzoek.tekst);
    } catch (error) {
        verzoek.duur = verzoek.duur || performance.now() - start;
        verzoek.tekst = verzoek.tekst || String(error);
        throw error;
    } finally {
        registreer(verzoek);
    }
}

function post(url, data) {
    return request(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    });
}

// [{ id, titel, omschrijving }]
export async function getGesprekken() {
    const data = await request('/gesprekken');
    return data.gesprekken;
}

// { id, titel, omschrijving, moderatie, stellingen: [{ id, content }] } or null;
// only the zichtbare stellingen, in random order
export function getGesprek(gesprekId) {
    return request(`/gesprekken/${encodeURIComponent(gesprekId)}`);
}

// { stelling_id: waarde } for one user, or null if the gesprek does not exist
export async function getMijnAntwoorden(gesprekId, userId) {
    const data = await request(`/antwoorden?gesprek_id=${encodeURIComponent(gesprekId)}&user_id=${encodeURIComponent(userId)}`);
    if (!data) {
        return null;
    }
    const antwoorden = {};
    data.antwoorden.forEach(antwoord => {
        antwoorden[antwoord.stelling_id] = antwoord.waarde;
    });
    return antwoorden;
}

// [{ stelling_id: waarde }], one object per deelnemer, or null
export async function getMatrix(gesprekId) {
    const data = await request(`/antwoorden?gesprek_id=${encodeURIComponent(gesprekId)}`);
    return data ? data.antwoorden : null;
}

// PCA + K-means groups: { k, verklaarde_variantie, deelnemers: [{ deelnemer, x, y, groep, antwoorden }], groepen, ... }
// from the math server, which reads the export of this gesprek on our api
export function getAnalyse(gesprekId) {
    return request(`/analyse?api=${encodeURIComponent(API_URL)}&gesprek_id=${encodeURIComponent(gesprekId)}`, {}, MATH_URL);
}

export function postAntwoord(gesprekId, userId, stellingId, waarde) {
    return post('/antwoorden', {
        gesprek_id: gesprekId,
        user_id: userId,
        stelling_id: stellingId,
        waarde: waarde
    });
}

// [{ id, gesprek_id, content, user_id, beoordeling, reden, zichtbaar, antwoorden: { eens, neutraal, oneens } }]
// added by one user, or null if the gesprek does not exist; beoordeling is goedgekeurd, afgekeurd or null
export async function getMijnStellingen(gesprekId, userId) {
    const data = await request(`/stellingen?gesprek_id=${encodeURIComponent(gesprekId)}&user_id=${encodeURIComponent(userId)}`);
    return data ? data.stellingen : null;
}

// returns the new stelling { id, gesprek_id, content, user_id, beoordeling, reden, zichtbaar }
export function postStelling(gesprekId, userId, content) {
    return post('/stellingen', { gesprek_id: gesprekId, user_id: userId, content: content });
}
