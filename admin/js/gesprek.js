import { getGesprek, putGesprek } from './api.js';
import { verwerkFout } from './toegang.js';
import { toonStellingen, verbergStellingen } from './stellingen.js';
import { maakLogboek } from './logboek.js';
import { maakTabs } from 'cdn/tabs.js';
import { gesprekVelden } from './util.js';
import { APP_URL } from './config.php';
import { toonView } from './views.js';

// elements of views/gesprek.html, set by koppel() once the view is in the page
let titel, melding, info, tabs, tabBladen, formulier, fout, knop;
let gekoppeld = false;
const logboek = maakLogboek('gesprek-logboek');
// the tab shown, kept when opening another gesprek
let actieveTab = 'stellingen';

function koppel() {
    if (gekoppeld) {
        return;
    }
    titel = document.getElementById('gesprek-titel');
    melding = document.getElementById('gesprek-melding');
    info = document.getElementById('gesprek-info');
    tabs = document.getElementById('gesprek-tabs');
    tabBladen = maakTabs(tabs.querySelector('[role="tablist"]'), bijTab);
    formulier = document.getElementById('gesprek-formulier');
    fout = document.getElementById('gesprek-fout');
    knop = document.getElementById('gesprek-knop');
    formulier.addEventListener('submit', slaOp);
    gekoppeld = true;
}

let huidigId = null;

// one gesprek, with tabs: its stellingen to approve or reject, its logboek, and its details to change
export async function toonGesprek(id) {
    huidigId = id;
    try {
        await toonView('gesprek');
        koppel();
        melding.hidden = true;
        fout.hidden = true;
        tabs.hidden = true;
        verbergStellingen();
        logboek.verberg();
        titel.textContent = '';
        info.textContent = '';

        const gesprek = await getGesprek(id);
        if (huidigId !== id) {
            return; // another gesprek was opened in the meantime
        }
        if (!gesprek) {
            titel.textContent = 'Gesprek niet gevonden';
            return;
        }
        titel.textContent = gesprek.titel;
        const aantal = gesprek.stellingen.length;
        info.innerHTML = `${aantal} ${aantal === 1 ? 'zichtbare stelling' : 'zichtbare stellingen'} · <a target="_blank" rel="noopener" href="${APP_URL}/#/gesprekken/${encodeURIComponent(id)}">Bekijken in de app →</a>`;
        vulFormulier(gesprek);
        tabs.hidden = false;
        tabBladen.toon(actieveTab);
        await toonStellingen(id);
    } catch (error) {
        verwerkFout(error);
    }
}

// the logboek is loaded whenever its tab is shown, so it is up to date after beoordelingen and changes
async function bijTab(naam) {
    actieveTab = naam;
    if (naam !== 'logboek' || huidigId === null) {
        return;
    }
    try {
        await logboek.toon(huidigId);
    } catch (error) {
        verwerkFout(error);
    }
}

function vulFormulier(gesprek) {
    formulier.titel.value = gesprek.titel;
    formulier.omschrijving.value = gesprek.omschrijving;
    formulier.moderatie.value = gesprek.moderatie || 'achteraf';
}

async function slaOp(event) {
    event.preventDefault();
    fout.hidden = true;
    melding.hidden = true;
    knop.disabled = true;
    try {
        const gesprek = await putGesprek(huidigId, gesprekVelden(formulier));
        titel.textContent = gesprek.titel;
        vulFormulier(gesprek);
        melding.textContent = 'De wijzigingen zijn opgeslagen.';
        melding.hidden = false;
        // the moderatie decides which stellingen are zichtbaar
        await toonStellingen(huidigId);
    } catch (error) {
        fout.textContent = 'De wijzigingen konden niet worden opgeslagen. Probeer het opnieuw.';
        verwerkFout(error, fout);
    } finally {
        knop.disabled = false;
    }
}
