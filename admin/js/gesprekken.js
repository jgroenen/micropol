import { getGesprekken, postGesprek } from './api.js';
import { isSuperbeheerder, verwerkFout } from './toegang.js';
import { statusLabel } from './statusacties.js';
import { koppelUitnodigen, uitnodigenHtml, wisUitnodiging } from './uitnodigen.js';
import { escapeHtml } from 'cdn/html.js';
import { gesprekVelden } from './util.js';
import { APP_URL } from './config.php';
import { toonView } from './views.js';

// elements of views/gesprekken.html, set by koppel() once the view is in the page
let overzicht, lijst, leeg, melding, formulier, fout, knop, openNieuw, uitnodigen;
let gekoppeld = false;

function koppel() {
    if (gekoppeld) {
        return;
    }
    overzicht = document.getElementById('gesprekken-overzicht');
    lijst = document.getElementById('gesprekken-lijst');
    leeg = document.getElementById('gesprekken-leeg');
    openNieuw = document.getElementById('open-nieuw');
    uitnodigen = document.getElementById('nieuw-uitnodigen');
    uitnodigen.innerHTML = uitnodigenHtml({ knoptekst: 'Nieuwe link' });
    koppelUitnodigen(uitnodigen, () => ({ rol: 'gespreksbeheerder', gesprek_id: nieuwId }));
    melding = document.getElementById('gesprekken-melding');
    formulier = document.getElementById('nieuw-formulier');
    fout = document.getElementById('nieuw-fout');
    knop = document.getElementById('nieuw-knop');
    formulier.addEventListener('submit', maakGesprek);
    openNieuw.addEventListener('click', toonFormulier);
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

// the id of the gesprek made last, for the link of its first gespreksbeheerder
let nieuwId = null;

// the gesprekken of the account; superbeheerders see all of them, with a button for a new one
export async function toonGesprekken() {
    try {
        await toonView('gesprekken');
        koppel();
        melding.hidden = true;
        uitnodigen.hidden = true;
        wisUitnodiging(uitnodigen);
        openNieuw.hidden = !isSuperbeheerder();
        toonLijst();
        const gesprekken = await getGesprekken();
        lijst.innerHTML = gesprekken.map(gesprekRegel).join('');
        lijst.hidden = gesprekken.length === 0;
        leeg.hidden = gesprekken.length > 0;
    } catch (error) {
        verwerkFout(error);
    }
}

// with the rol of the account in the gesprek, and its status when that is not actief
function gesprekRegel(g) {
    const labels = [
        g.rol ? `<span class="label">${escapeHtml(g.rol)}</span>` : '',
        g.status !== 'actief' ? statusLabel(g.status, { opgeschort: 'Gepauzeerd' }) : '',
    ].join('');
    return `
        <li>
            <a class="beheer-titel" href="#/gesprekken/${encodeURIComponent(g.id)}">${escapeHtml(g.titel)}</a>
            <span>${escapeHtml(g.omschrijving)}</span>
            <span class="gesprek-labels">${labels}</span>
            ${g.status === 'actief' ? `<a target="_blank" rel="noopener" href="${APP_URL}/#/gesprekken/${encodeURIComponent(g.id)}">Bekijken →</a>` : ''}
        </li>
    `;
}

// a new gesprek (superbeheerders) is added at the end of the list, like in GET /gesprekken
async function maakGesprek(event) {
    event.preventDefault();
    fout.hidden = true;
    melding.hidden = true;
    knop.disabled = true;
    try {
        const gesprek = await postGesprek(gesprekVelden(formulier));
        formulier.reset();
        lijst.insertAdjacentHTML('beforeend', gesprekRegel({ ...gesprek, rol: null }));
        lijst.hidden = false;
        leeg.hidden = true;
        toonLijst();
        melding.textContent = `Gesprek „${gesprek.titel}” is aangemaakt. Nodig nu de eerste gespreksbeheerder uit met deze link:`;
        melding.hidden = false;
        // a new gesprek has no team yet: the link for its first gespreksbeheerder right away
        nieuwId = gesprek.id;
        uitnodigen.hidden = false;
        uitnodigen.requestSubmit();
    } catch (error) {
        fout.textContent = 'Het gesprek kon niet worden aangemaakt. Probeer het opnieuw.';
        verwerkFout(error, fout);
    } finally {
        knop.disabled = false;
    }
}
