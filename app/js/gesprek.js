import { getGesprek, kanaalGeldig, getMijnAntwoorden, getMijnStellingen, getAnalyse, postAntwoord, postStelling } from './api.js';
import { deelnemerId } from './deelnemer.js';
import { escapeHtml } from 'cdn/html.js';
import { maakTabs } from 'cdn/tabs.js';
import { zetGesprekTitel } from './kop.js';
import { isGesloten, toonGesloten } from './gesloten.js';
import { kanaalVan, vergeetKanaal } from './kanaal.js';
import { labels } from './labels.js';
import { toonView } from './views.js';
import { koppelTooltip } from './tooltip.js';
import { plaats, groepMarker, groepNaam, tekenPlot, tekenLegenda } from './analyse.js';

// elements of views/gesprek.html, set by koppel() once the view is in the page
let beantwoorden, toevoegen, melding, teller, huidigeStelling, knoppen, antwoordKnoppen, tabs, tabBladen, textarea, indienen;
let gekoppeld = false;

// the tab shown below the stelling, kept when switching gesprekken
let actieveTab = 'antwoorden';

function koppel() {
    if (gekoppeld) {
        return;
    }
    beantwoorden = document.getElementById('beantwoorden');
    toevoegen = document.getElementById('toevoegen');
    melding = document.getElementById('melding');
    teller = document.getElementById('stelling-teller');
    huidigeStelling = document.getElementById('huidige-stelling');
    knoppen = document.getElementById('antwoord-knoppen');
    antwoordKnoppen = knoppen.querySelectorAll('button');
    tabs = document.getElementById('gesprek-tabs');
    textarea = document.getElementById('stelling-tekst');
    indienen = document.getElementById('stelling-indienen');

    antwoordKnoppen.forEach(button => {
        button.addEventListener('click', () => beantwoord(button.dataset.antwoord));
    });

    tabBladen = maakTabs(tabs.querySelector('[role="tablist"]'), naam => actieveTab = naam);

    // deelnemer info on the plot points
    koppelTooltip(document.getElementById('groepen-plot'));

    document.getElementById('open-toevoegen').addEventListener('click', () => {
        melding.hidden = true;
        beantwoorden.hidden = true;
        tabs.hidden = true;
        toevoegen.hidden = false;
        textarea.focus();
    });

    document.getElementById('sluit-toevoegen').addEventListener('click', toonBeantwoorden);

    toevoegen.addEventListener('submit', dienStellingIn);
    // no looking for new stellingen while the page is out of view; back in view, it goes on
    document.addEventListener('visibilitychange', wachtOpNieuweStellingen);
    gekoppeld = true;
}

// state of the opened gesprek
let huidig = null; // { gesprek, antwoorden: { stelling_id: waarde }, mijnStellingen: [stelling], stelling, analyse, analyseMislukt }

// open one gesprek: load stellingen and this deelnemer's antwoorden and stellingen
export async function toonGesprek(id) {
    huidig = null;
    try {
        await toonView('gesprek');
        koppel();
        // the matrix is under the tab of the groups
        document.getElementById('groepen-link').href = `#/gesprekken/${encodeURIComponent(id)}/matrix`;
        melding.hidden = true;
        beantwoorden.hidden = true;
        tabs.hidden = true;
        toevoegen.hidden = true;

        const kanaal = kanaalVan(id);
        const [gesprek, geldig, antwoorden, eigenStellingen, analyse] = await Promise.all([
            getGesprek(id),
            kanaal === null ? null : kanaalGeldig(id, kanaal).catch(() => false),
            getMijnAntwoorden(id, deelnemerId),
            getMijnStellingen(id, deelnemerId),
            // the groups are extra: answering works without them (e.g. when the math server is down)
            getAnalyse(id).catch(error => {
                console.error('Error fetching analyse:', error);
                return undefined;
            })
        ]);
        if (!gesprek) {
            toonNietGevonden();
            return;
        }
        if (isGesloten(gesprek)) {
            await toonGesloten(gesprek);
            return;
        }
        // a kanaal whose link works no more is forgotten; without a kanaal, only when the gesprek allows it
        const kanaalVervallen = kanaal !== null && !geldig;
        if (kanaalVervallen) {
            vergeetKanaal(id);
        }
        if (!gesprek.zonder_kanaal && (kanaal === null || kanaalVervallen)) {
            await toonGesloten(gesprek, 'alleen-via-link');
            return;
        }
        if (kanaalVervallen) {
            melding.textContent = 'De link waarmee je kwam, werkt niet meer. Je kunt gewoon verder meedoen.';
            melding.hidden = false;
        }
        laatstGekeken = Date.now();
        huidig = { gesprek, antwoorden: antwoorden ?? {}, mijnStellingen: eigenStellingen ?? [], stelling: null, analyse: analyse ?? null, analyseMislukt: analyse === undefined };
        zetGesprekTitel(gesprek.titel);
        document.getElementById('moderatie-hint').hidden = gesprek.moderatie !== 'vooraf';
        toonBeantwoorden();
    } catch (error) {
        console.error('Error fetching gesprek:', error);
    }
}

function toonNietGevonden() {
    beantwoorden.hidden = false;
    teller.textContent = '';
    huidigeStelling.textContent = 'Gesprek niet gevonden.';
    knoppen.hidden = true;
    tabs.hidden = true;
}

function toonTab(naam) {
    tabBladen.toon(naam);
}

// fills a list of the tabs, or shows its empty text
function vulLijst(id, items) {
    const lijst = document.getElementById(`${id}-lijst`);
    lijst.innerHTML = items.join('');
    lijst.hidden = items.length === 0;
    document.getElementById(`${id}-leeg`).hidden = items.length > 0;
}

// the stellingen this deelnemer answered, in the order they were answered
// (the stellingen of the gesprek come in random order)
function toonMijnAntwoorden() {
    const { gesprek, antwoorden } = huidig;
    const stellingVan = new Map(gesprek.stellingen.map(s => [s.id, s]));
    vulLijst('mijn-antwoorden', Object.keys(antwoorden)
        .filter(id => stellingVan.has(id))
        .map(id => {
            const s = stellingVan.get(id);
            const waarde = antwoorden[s.id];
            const klasse = waarde in labels ? waarde : 'leeg';
            return `<li><span>${escapeHtml(s.tekst)}</span><strong><span class="bolletje ${klasse}"></span> ${escapeHtml(labels[waarde] ?? waarde)}</strong></li>`;
        }));
}

// the stellingen this deelnemer added, newest first, each with how it was answered,
// or why others don't see it
function toonMijnStellingen() {
    vulLijst('mijn-stellingen', [...huidig.mijnStellingen].reverse()
        .map(s => `<li class="mijn-stelling"><span>${escapeHtml(s.tekst)}</span>${s.zichtbaar ? verdeling(s.antwoorden) : moderatieStatus(s)}</li>`));
    // the width of each part of a bar; set here, since the Content-Security-Policy allows no style attributes
    document.querySelectorAll('#mijn-stellingen-lijst .verdeling [data-aantal]').forEach(deel => {
        deel.style.flexGrow = deel.dataset.aantal;
    });
}

function moderatieStatus(stelling) {
    if (stelling.beoordeling === 'afgekeurd') {
        return `<p class="moderatie-status afgekeurd"><strong>Afgekeurd</strong>${stelling.reden ? `: ${escapeHtml(stelling.reden)}` : ''}</p>`;
    }
    return '<p class="moderatie-status"><strong>Nog niet goedgekeurd.</strong> Andere deelnemers zien je stelling pas na goedkeuring.</p>';
}

// 100% bar of oneens, neutraal and eens, with the numbers in it
function verdeling(telling) {
    const volgorde = ['oneens', 'neutraal', 'eens'];
    const totaal = volgorde.reduce((som, w) => som + telling[w], 0);
    if (totaal === 0) {
        return '<p class="verdeling-leeg">Nog niemand heeft deze stelling beantwoord.</p>';
    }
    const beschrijving = volgorde.map(w => `${telling[w]} ${labels[w].toLowerCase()}`).join(', ');
    const delen = volgorde
        .filter(w => telling[w] > 0)
        .map(w => `<span class="${w}" data-aantal="${telling[w]}" title="${labels[w]}: ${telling[w]}">${telling[w]}</span>`)
        .join('');
    return `<div class="verdeling" role="img" aria-label="${beschrijving}">${delen}</div>`;
}

// which group the deelnemer belongs to, placed in the browser with the model from the analyse
function toonGroepen() {
    const analyse = huidig.analyse;
    const status = document.getElementById('groep-status');
    const inhoud = document.getElementById('groepen-inhoud');
    let jij = analyse ? plaats(analyse.model, huidig.antwoorden) : null;
    const nodig = analyse ? analyse.min_antwoorden - (jij ? jij.antwoorden : 0) : 0;
    // with too few antwoorden the deelnemer is in the plot, but not yet in a group
    if (jij && nodig > 0) {
        jij = { ...jij, groep: null };
    }

    inhoud.hidden = !(analyse && tekenPlot(document.getElementById('groepen-plot'), analyse, jij));
    if (!inhoud.hidden) {
        tekenLegenda(document.getElementById('groepen-legenda'), analyse, jij);
    }

    if (huidig.analyseMislukt) {
        status.textContent = 'De groepen zijn nu niet beschikbaar. Probeer het later opnieuw.';
        return;
    }
    if (!analyse || analyse.k === 0) {
        status.textContent = 'Er zijn nog te weinig deelnemers voor groepen.';
        return;
    }
    if (nodig > 0) {
        status.textContent = `Nog ${nodig} ${nodig === 1 ? 'antwoord' : 'antwoorden'}, dan zie je bij welke groep je hoort.`;
    } else {
        status.innerHTML = `Met je huidige antwoorden hoor je bij ${groepMarker(jij.groep)} <strong>groep ${groepNaam(jij.groep)}</strong>.`;
    }
}

// show the next unanswered stelling and the tabs
function toonBeantwoorden() {
    beantwoorden.hidden = false;
    toevoegen.hidden = true;

    const { gesprek, antwoorden } = huidig;
    const stellingen = gesprek.stellingen;
    toonMijnAntwoorden();
    toonMijnStellingen();
    toonGroepen();
    toonTab(actieveTab);
    tabs.hidden = false;

    const stelling = stellingen.find(s => !(s.id in antwoorden));
    huidig.stelling = stelling || null;
    wachtOpNieuweStellingen();

    if (!stelling) {
        teller.textContent = '';
        huidigeStelling.textContent = 'Dit waren alle stellingen voor nu. Dien zelf stellingen in. Nieuwe stellingen van andere deelnemers verschijnen hier vanzelf.';
        knoppen.hidden = true;
        return;
    }

    const aantalBeantwoord = stellingen.filter(s => s.id in antwoorden).length;
    teller.textContent = `Stelling ${aantalBeantwoord + 1} van ${stellingen.length}`;
    huidigeStelling.textContent = stelling.tekst;
    knoppen.hidden = false;
    antwoordKnoppen.forEach(b => b.disabled = false);
}

// once all stellingen are answered: every 2 minutes a look for new ones, only while the page is in view
const NIEUWE_STELLINGEN_NA = 2 * 60 * 1000;
let wachtTimer = null;
let laatstGekeken = 0;

function wachtOpNieuweStellingen() {
    clearTimeout(wachtTimer);
    wachtTimer = null;
    if (!huidig || huidig.stelling || document.hidden || !opDezePagina(huidig.gesprek.id)) {
        return;
    }
    wachtTimer = setTimeout(kijkNaarNieuweStellingen, Math.max(0, laatstGekeken + NIEUWE_STELLINGEN_NA - Date.now()));
}

// whether the page of this gesprek is shown (not the list, nor its matrix)
function opDezePagina(gesprekId) {
    return location.hash.replace(/\?.*$/, '') === `#/gesprekken/${encodeURIComponent(gesprekId)}`;
}

async function kijkNaarNieuweStellingen() {
    wachtTimer = null;
    const vorig = huidig;
    if (!vorig || !opDezePagina(vorig.gesprek.id)) {
        return;
    }
    laatstGekeken = Date.now();
    let gesprek;
    try {
        gesprek = await getGesprek(vorig.gesprek.id);
    } catch (error) {
        console.error('Error looking for new stellingen:', error);
    }
    if (huidig !== vorig) {
        return;
    }
    if (gesprek === null || (gesprek && isGesloten(gesprek))) {
        // gone, paused or over in the meantime: show that
        await toonGesprek(vorig.gesprek.id);
        return;
    }
    const bekend = new Set(vorig.gesprek.stellingen.map(s => s.id));
    const nieuw = gesprek ? gesprek.stellingen.filter(s => !bekend.has(s.id)) : [];
    vorig.gesprek.stellingen.push(...nieuw);
    // while adding a stelling, the new ones wait until the form closes
    if (nieuw.length > 0 && !beantwoorden.hidden) {
        toonBeantwoorden();
        return;
    }
    wachtOpNieuweStellingen();
}

// save an antwoord, then move on to the next stelling
async function beantwoord(waarde) {
    if (!huidig || !huidig.stelling) {
        return;
    }
    const { gesprek, antwoorden, stelling } = huidig;
    melding.hidden = true;
    antwoordKnoppen.forEach(b => b.disabled = true);
    try {
        await postAntwoord({ gesprek_id: gesprek.id, stelling_id: stelling.id, waarde, kanaal: kanaalVan(gesprek.id) ?? undefined }, deelnemerId);
        antwoorden[stelling.id] = waarde;
        // answering an own stelling changes its bar
        const eigen = huidig.mijnStellingen.find(s => s.id === stelling.id);
        if (eigen) {
            eigen.antwoorden[waarde]++;
        }
        toonBeantwoorden();
    } catch (error) {
        console.error('Error saving antwoord:', error);
        if (await kanaalGewijzigd(error, gesprek.id)) {
            return;
        }
        alert('Je antwoord kon niet worden opgeslagen. Probeer het opnieuw.');
        antwoordKnoppen.forEach(b => b.disabled = false);
    }
}

async function dienStellingIn(event) {
    event.preventDefault();
    if (!huidig) {
        return;
    }
    const tekst = textarea.value.trim();
    if (!tekst) {
        return;
    }
    indienen.disabled = true;
    try {
        const stelling = await postStelling({ gesprek_id: huidig.gesprek.id, tekst, kanaal: kanaalVan(huidig.gesprek.id) ?? undefined }, deelnemerId);
        textarea.value = '';
        // a stelling waiting for goedkeuring can't be answered yet
        if (stelling.zichtbaar) {
            huidig.gesprek.stellingen.push({ id: stelling.id, tekst: stelling.tekst });
        }
        huidig.mijnStellingen.push({ ...stelling, antwoorden: { eens: 0, neutraal: 0, oneens: 0 } });
        melding.textContent = stelling.zichtbaar
            ? 'Je stelling is ingediend!'
            : 'Je stelling is ingediend! Andere deelnemers zien hem zodra hij is goedgekeurd.';
        // show the new stelling in its list
        actieveTab = 'stellingen';
        toonBeantwoorden();
        melding.hidden = false;
    } catch (error) {
        console.error('Error adding stelling:', error);
        if (await kanaalGewijzigd(error, huidig.gesprek.id)) {
            return;
        }
        alert('De stelling kon niet worden toegevoegd. Probeer het opnieuw.');
    } finally {
        indienen.disabled = false;
    }
}

// a 403 while taking part: the kanaal was withdrawn, or the gesprek now only takes part through a link.
// The gesprek is loaded again, which shows what fits; whether that was it
async function kanaalGewijzigd(error, gesprekId) {
    if (error.status !== 403) {
        return false;
    }
    await toonGesprek(gesprekId);
    return true;
}
