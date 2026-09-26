import { registreer, huidigePagina } from './apilog.js';

// Calls to the servers of MiniPol, shared by the app and the admin. Every call is registered in the
// API popover (apilog.js), with the values of the fields in verberg hidden (tokens, codes, passwords).

// fetch, registered in the API popover; returns the Response (its body can still be read)
export async function gelogdeFetch(url, options = {}, verberg = []) {
    const verzoek = {
        pagina: huidigePagina(),
        methode: options.method ?? 'GET',
        url,
        verzonden: options.body === undefined ? undefined : verborgen(options.body, verberg),
        status: 0,
        duur: 0,
        tekst: '',
    };
    const start = performance.now();
    try {
        const response = await fetch(url, options);
        verzoek.status = response.status;
        verzoek.tekst = verborgen(await response.clone().text(), verberg);
        return response;
    } catch (error) {
        verzoek.tekst = String(error);
        throw error;
    } finally {
        verzoek.duur = performance.now() - start;
        registreer(verzoek);
    }
}

// the JSON of a call (null for an empty response); throws an Error with the status for anything but 2xx
export async function jsonVerzoek(url, options = {}, verberg = []) {
    const response = await gelogdeFetch(url, options, verberg);
    const tekst = await response.text();
    if (!response.ok) {
        const error = new Error(`HTTP ${response.status} for ${url}`);
        error.status = response.status;
        throw error;
    }
    return tekst ? JSON.parse(tekst) : null;
}

// for lookups: null when it is not found (404), other errors go on
export async function ofNull(belofte) {
    try {
        return await belofte;
    } catch (error) {
        if (error.status === 404) {
            return null;
        }
        throw error;
    }
}

// the body (JSON or form data) as text, with the values of the fields in verberg replaced
function verborgen(body, verberg) {
    const tekst = String(body);
    if (verberg.length === 0) {
        return tekst;
    }
    try {
        const data = JSON.parse(tekst);
        verberg.filter(veld => veld in data).forEach(veld => data[veld] = '••••••••');
        return JSON.stringify(data);
    } catch (error) {
        const velden = new URLSearchParams(tekst);
        verberg.filter(veld => velden.has(veld)).forEach(veld => velden.set(veld, '••••••••'));
        return velden.toString();
    }
}
