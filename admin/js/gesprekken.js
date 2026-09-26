import { getGesprekken, postGesprek } from './api.js';
import { verwerkFout } from './toegang.js';
import { escapeHtml } from 'cdn/html.js';
import { gesprekVelden } from './util.js';
import { APP_URL } from './config.php';
import { toonView } from './views.js';

// elements of views/gesprekken.html, set by koppel() once the view is in the page
let overzicht, lijst, melding, formulier, fout, knop;
let gekoppeld = false;

function koppel() {
    if (gekoppeld) {
        return;
    }
    overzicht = document.getElementById('gesprekken-overzicht');
    lijst = document.getElementById('gesprekken-lijst');
    melding = document.getElementById('gesprekken-melding');
    formulier = document.getElementById('nieuw-formulier');
    fout = document.getElementById('nieuw-fout');
    knop = document.getElementById('nieuw-knop');
    formulier.addEventListener('submit', maakGesprek);
    document.getElementById('open-nieuw').addEventListener('click', toonFormulier);
    document.getElementById('sluit-nieuw').addEventListener('click', toonLijst);
    gekoppeld = true;
}

// the form for a new gesprek takes the place of the list, like adding a stelling in the app
function toonFormulier() {
    melding.hidden = true;
    fout.hidden = true;
    overzicht.hidden = true;
    formulier.hidden = false;
    formulier.titel.focus();
}

function toonLijst() {
    formulier.hidden = true;
    overzicht.hidden = false;
}

// all gesprekken, with a button for a new one
export async function toonGesprekken() {
    try {
        await toonView('gesprekken');
        koppel();
        melding.hidden = true;
        toonLijst();
        const gesprekken = await getGesprekken();
        lijst.innerHTML = gesprekken.map(gesprekRegel).join('');
    } catch (error) {
        verwerkFout(error);
    }
}

function gesprekRegel(g) {
    return `
        <li>
            <a class="beheer-titel" href="#/gesprekken/${encodeURIComponent(g.id)}">${escapeHtml(g.titel)}</a>
            <span>${escapeHtml(g.omschrijving)}</span>
            <a target="_blank" rel="noopener" href="${APP_URL}/#/gesprekken/${encodeURIComponent(g.id)}">Bekijken →</a>
        </li>
    `;
}

// a new gesprek is added at the end of the list, like in gesprekken.csv
async function maakGesprek(event) {
    event.preventDefault();
    fout.hidden = true;
    melding.hidden = true;
    knop.disabled = true;
    try {
        const gesprek = await postGesprek(gesprekVelden(formulier));
        formulier.reset();
        lijst.insertAdjacentHTML('beforeend', gesprekRegel(gesprek));
        toonLijst();
        melding.textContent = `Gesprek „${gesprek.titel}” is aangemaakt.`;
        melding.hidden = false;
    } catch (error) {
        fout.textContent = 'Het gesprek kon niet worden aangemaakt. Probeer het opnieuw.';
        verwerkFout(error, fout);
    } finally {
        knop.disabled = false;
    }
}
