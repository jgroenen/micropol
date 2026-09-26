import { getGesprek, putGesprek } from './api.js';
import { verwerkFout } from './toegang.js';
import { toonStellingen, verbergStellingen, bijBeoordelingVanStelling } from './stellingen.js';
import { toonLogboek, verbergLogboek } from './logboek.js';
import { gesprekVelden } from './util.js';
import { APP_URL } from './config.php';
import { toonView } from './views.js';

// elements of views/gesprek.html, set by koppel() once the view is in the page
let titel, melding, info, formulier, fout, knop;
let gekoppeld = false;

function koppel() {
    if (gekoppeld) {
        return;
    }
    titel = document.getElementById('gesprek-titel');
    melding = document.getElementById('gesprek-melding');
    info = document.getElementById('gesprek-info');
    formulier = document.getElementById('gesprek-formulier');
    fout = document.getElementById('gesprek-fout');
    knop = document.getElementById('gesprek-knop');
    formulier.addEventListener('submit', slaOp);
    bijBeoordelingVanStelling(async stellingen => {
        try {
            await toonLogboek(huidigId, stellingen);
        } catch (error) {
            verwerkFout(error);
        }
    });
    gekoppeld = true;
}

let huidigId = null;

// one gesprek: its details to change, its stellingen to approve or reject, and its logboek
export async function toonGesprek(id) {
    huidigId = id;
    try {
        await toonView('gesprek');
        koppel();
        melding.hidden = true;
        fout.hidden = true;
        formulier.hidden = true;
        verbergStellingen();
        verbergLogboek();
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
        formulier.hidden = false;
        await vernieuw(id);
    } catch (error) {
        verwerkFout(error);
    }
}

// the stellingen, and the logboek with their texts; errors are for the caller
async function vernieuw(id) {
    const stellingen = await toonStellingen(id);
    if (stellingen) {
        await toonLogboek(id, stellingen);
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
        await vernieuw(huidigId);
    } catch (error) {
        fout.textContent = 'De wijzigingen konden niet worden opgeslagen. Probeer het opnieuw.';
        verwerkFout(error, fout);
    } finally {
        knop.disabled = false;
    }
}
