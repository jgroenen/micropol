import { getGesprek, putGesprek, postGespreksstatus } from './api.js';
import { isSuperbeheerder, rolIn, verwerkFout } from './toegang.js';
import { toonStellingen, verbergStellingen } from './stellingen.js';
import { maakLogboek } from './logboek.js';
import { toonTeam } from './team.js';
import { statusLabel, statusActiesHtml, koppelStatusacties } from './statusacties.js';
import { maakTabs } from 'cdn/tabs.js';
import { gesprekVelden } from './util.js';
import { APP_URL } from './config.php';
import { toonView } from './views.js';

// elements of views/gesprek.html, set by koppel() once the view is in the page
let titel, melding, info, tabs, tabBladen, formulier, fout, knop, geenToegang, statusRij, statusFout;
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
    geenToegang = document.getElementById('gesprek-geen-toegang');
    statusRij = document.getElementById('status-rij');
    statusFout = document.getElementById('status-fout');
    formulier.addEventListener('submit', slaOp);
    koppelStatusacties(statusRij, wijzigStatus);
    gekoppeld = true;
}

let huidigId = null;
// which tabs the account may see in the current gesprek
let zichtbaar = {};

// the tabs each account may see: the team of the gesprek works in it while it is not paused;
// superbeheerders manage its team and its status, but read no content
function tabsVoor(gesprek) {
    const rol = rolIn(gesprek.id);
    const actief = gesprek.status === 'actief';
    const gespreksbeheerder = actief && rol === 'gespreksbeheerder';
    return {
        stellingen: actief && rol !== null,
        logboek: actief && rol !== null,
        gegevens: gespreksbeheerder,
        team: gespreksbeheerder || isSuperbeheerder(),
        status: isSuperbeheerder(),
    };
}

// one gesprek, with tabs for what the account may do in it: stellingen to approve or reject, the logboek,
// the gegevens, the team, and the status
export async function toonGesprek(id) {
    huidigId = id;
    try {
        await toonView('gesprek');
        koppel();
        melding.hidden = true;
        fout.hidden = true;
        tabs.hidden = true;
        geenToegang.hidden = true;
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
        document.title = `MiniPol beheer | ${gesprek.titel}`;
        const aantal = gesprek.stellingen.length;
        info.innerHTML = gesprek.status === 'actief'
            ? `${aantal} ${aantal === 1 ? 'zichtbare stelling' : 'zichtbare stellingen'} · <a target="_blank" rel="noopener" href="${APP_URL}/#/gesprekken/${encodeURIComponent(id)}">Bekijken in de app →</a>`
            : `${statusLabel(gesprek.status, { opgeschort: 'Gepauzeerd' })} deelnemers zien een melding in plaats van het gesprek`;
        vulFormulier(gesprek);
        toonStatus(gesprek.status);

        zichtbaar = tabsVoor(gesprek);
        const namen = Object.keys(zichtbaar).filter(naam => zichtbaar[naam]);
        if (namen.length === 0) {
            geenToegang.textContent = {
                actief: 'Je zit niet (meer) in het team van dit gesprek.',
                opgeschort: 'Dit gesprek is gepauzeerd door een superbeheerder. Je kunt er tijdelijk niet bij.',
                beeindigd: 'Dit gesprek is beëindigd door een superbeheerder.',
            }[gesprek.status];
            geenToegang.hidden = false;
            return;
        }
        for (const naam of Object.keys(zichtbaar)) {
            document.getElementById(`tab-${naam}`).hidden = !zichtbaar[naam];
        }
        tabs.hidden = false;
        tabBladen.toon(zichtbaar[actieveTab] ? actieveTab : namen[0]);
        if (zichtbaar.stellingen) {
            await toonStellingen(id);
        }
    } catch (error) {
        verwerkFout(error);
    }
}

// the logboek and the team are loaded whenever their tab is shown, so they are up to date after changes
async function bijTab(naam) {
    actieveTab = naam;
    if (huidigId === null || !zichtbaar[naam]) {
        return;
    }
    try {
        if (naam === 'logboek') {
            await logboek.toon(huidigId);
        } else if (naam === 'team') {
            await toonTeam(huidigId);
        }
    } catch (error) {
        verwerkFout(error);
    }
}

// the status of the gesprek, with buttons to pause, resume or remove it (superbeheerders)
function toonStatus(status) {
    statusFout.hidden = true;
    statusRij.innerHTML = `
        <p class="rij-kop">${statusLabel(status, { opgeschort: 'Gepauzeerd' })} ${UITLEG[status]}</p>
        ${statusActiesHtml(status, true, 'gesprek')}
    `;
}

const UITLEG = {
    actief: 'Deelnemers kunnen meedoen.',
    opgeschort: 'Deelnemers zien een melding dat het gesprek tijdelijk gepauzeerd is, en het team kan er tijdelijk niet bij.',
    beeindigd: 'Deelnemers zien dat het gesprek voorbij is, en het team kan er niet meer bij. Heropenen kan altijd.',
};

async function wijzigStatus(rij, status, reden) {
    statusFout.hidden = true;
    try {
        await postGespreksstatus({ gesprek_id: huidigId, status, reden });
        await toonGesprek(huidigId);
    } catch (error) {
        statusFout.textContent = 'De status kon niet worden veranderd. Probeer het opnieuw.';
        verwerkFout(error, statusFout);
        throw error;
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
        document.title = `MiniPol beheer | ${gesprek.titel}`;
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
