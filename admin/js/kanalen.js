import { getKanalen, postKanaal, putKanaal, postPanel, putPanel, downloadPanel } from './api.js';
import { verwerkFout } from './toegang.js';
import { escapeHtml } from 'cdn/html.js';
import { APP_URL } from './config.php';

// the kanalen of a gesprek, in its tab on the page of the gesprek (views/gesprek.html), for its
// gespreksbeheerders: a link for taking part per promotion channel, with how many deelnemers and antwoorden
// came through it. Meetellen can be turned off and on; withdrawing (intrekken) stops the link for good.
// And the panels: a link per panellid, with an export (CSV) for the party that knows the members.

let lijst, fout, nieuw, panelLijst, panelLeeg, panelNieuw;
let gekoppeld = false;

function koppel() {
    if (gekoppeld) {
        return;
    }
    lijst = document.getElementById('kanalen-lijst');
    fout = document.getElementById('kanalen-fout');
    nieuw = document.getElementById('kanaal-nieuw');
    nieuw.addEventListener('submit', maak);
    lijst.addEventListener('click', klik);
    lijst.addEventListener('submit', trekIn);
    panelLijst = document.getElementById('panels-lijst');
    panelLeeg = document.getElementById('panels-leeg');
    panelNieuw = document.getElementById('panel-nieuw');
    panelNieuw.addEventListener('submit', maakPanel);
    panelLijst.addEventListener('click', klikPanel);
    panelLijst.addEventListener('submit', bevestigPanel);
    gekoppeld = true;
}

let gesprek = null;
let kanalen = [];
let panels = [];
let zonderKanaal = { deelnemers: 0, antwoorden: 0 };

// loads and shows the kanalen of a gesprek { id, zonder_kanaal }; errors are for the caller
export async function toonKanalen(huidig) {
    koppel();
    gesprek = huidig;
    fout.hidden = true;
    const geladen = await getKanalen(huidig.id);
    if (gesprek !== huidig) {
        return;
    }
    kanalen = geladen.kanalen;
    panels = geladen.panels;
    zonderKanaal = geladen.zonder_kanaal;
    tekenLijst();
    tekenPanels();
}

function tekenLijst() {
    const zonder = `
        <li class="kanaal-zonder">
            <p class="rij-kop"><strong>Zonder kanaal</strong>
                ${gesprek.zonder_kanaal ? '' : '<span class="label opgeschort">Uit</span>'}
                <span class="rij-noot">${aantallen(zonderKanaal)}</span></p>
            <p class="rij-noot">${gesprek.zonder_kanaal
                ? 'Wie zonder link komt, kan ook meedoen. Uitzetten kan in de tab Gegevens.'
                : 'Alleen wie via een link van een kanaal komt, kan meedoen. Aanzetten kan in de tab Gegevens.'}</p>
        </li>`;
    lijst.innerHTML = zonder + kanalen.map(kanaalRegel).join('');
}

function aantallen({ deelnemers, antwoorden }) {
    return `${deelnemers} ${deelnemers === 1 ? 'deelnemer' : 'deelnemers'} · ${antwoorden} ${antwoorden === 1 ? 'antwoord' : 'antwoorden'}`;
}

function link(kanaal) {
    return `${APP_URL}/#/gesprekken/${encodeURIComponent(gesprek.id)}?kanaal=${encodeURIComponent(kanaal.token)}`;
}

function kanaalRegel(k) {
    const actief = k.status === 'actief';
    return `
        <li data-id="${escapeHtml(k.kanaal_id)}">
            <p class="rij-kop"><strong>${escapeHtml(k.naam)}</strong>
                ${actief ? '' : '<span class="label verwijderd">Ingetrokken</span>'}
                ${k.meetellen ? '' : '<span class="label opgeschort">Telt niet mee</span>'}
                <span class="rij-noot">${aantallen(k)}</span></p>
            ${actief ? `
                <div class="uitnodiging-regel kanaal-link">
                    <input readonly value="${escapeHtml(link(k))}" aria-label="De link van ${escapeHtml(k.naam)}">
                    <button type="button" class="knop-klein" data-actie="kopieer">Kopiëren</button>
                </div>` : ''}
            <div class="acties">
                <button type="button" class="knop-klein" data-actie="meetellen">${k.meetellen ? 'Niet meetellen' : 'Wel meetellen'}</button>
                ${actief ? '<button type="button" class="knop-klein" data-actie="intrekken">Intrekken</button>' : ''}
            </div>
            <form class="reden-formulier intrekken-formulier" hidden>
                <label class="vinkje"><input type="checkbox" name="meetellen" ${k.meetellen ? 'checked' : ''}> De antwoorden die er al zijn, blijven meetellen</label>
                <button type="submit" class="knop-klein">Intrekken</button>
                <button type="button" class="link-knop" data-actie="annuleren">Annuleren</button>
            </form>
        </li>
    `;
}

async function maak(event) {
    event.preventDefault();
    fout.hidden = true;
    const knop = nieuw.querySelector('[type=submit]');
    knop.disabled = true;
    try {
        const kanaal = await postKanaal({ gesprek_id: gesprek.id, naam: nieuw.naam.value.trim() });
        kanalen.push(kanaal);
        nieuw.reset();
        tekenLijst();
    } catch (error) {
        fout.textContent = 'Het kanaal kon niet worden gemaakt. Probeer het opnieuw.';
        verwerkFout(error, fout);
    } finally {
        knop.disabled = false;
    }
}

async function klik(event) {
    const knop = event.target.closest('button[data-actie]');
    if (!knop) {
        return;
    }
    const rij = knop.closest('li');
    const kanaal = kanalen.find(k => k.kanaal_id === rij.dataset.id);
    const formulier = rij.querySelector('.intrekken-formulier');
    switch (knop.dataset.actie) {
        case 'kopieer':
            try {
                await navigator.clipboard.writeText(link(kanaal));
                knop.textContent = 'Gekopieerd';
            } catch (error) {
                rij.querySelector('input[readonly]').select();
            }
            break;
        case 'meetellen':
            await wijzig(rij, { meetellen: !kanaal.meetellen });
            break;
        case 'intrekken':
            formulier.hidden = false;
            break;
        case 'annuleren':
            formulier.hidden = true;
            break;
    }
}

function trekIn(event) {
    event.preventDefault();
    const formulier = event.target;
    wijzig(formulier.closest('li'), { status: 'ingetrokken', meetellen: formulier.meetellen.checked });
}

async function wijzig(rij, wijziging) {
    fout.hidden = true;
    rij.querySelectorAll('button').forEach(knop => knop.disabled = true);
    try {
        const nieuwKanaal = await putKanaal(rij.dataset.id, { gesprek_id: gesprek.id, ...wijziging });
        kanalen = kanalen.map(k => k.kanaal_id === nieuwKanaal.kanaal_id ? nieuwKanaal : k);
        tekenLijst();
    } catch (error) {
        rij.querySelectorAll('button').forEach(knop => knop.disabled = false);
        fout.textContent = 'Het kanaal kon niet worden aangepast. Probeer het opnieuw.';
        verwerkFout(error, fout);
    }
}

// ---- panels

function tekenPanels() {
    panelLijst.innerHTML = panels.map(panelRegel).join('');
    panelLijst.hidden = panels.length === 0;
    panelLeeg.hidden = panels.length > 0;
}

function meervoud(aantal, een, meer) {
    return `${aantal} ${aantal === 1 ? een : meer}`;
}

function panelRegel(p) {
    const actief = p.status === 'actief';
    const getal = (soort, label, standaard, knop) => `
        <form class="reden-formulier panel-formulier" data-soort="${soort}" hidden>
            <label>${label} <input name="getal" type="number" min="1" max="1000" ${standaard ? `value="${standaard}"` : ''} required></label>
            <button type="submit" class="knop-klein">${knop}</button>
            <button type="button" class="link-knop" data-panel-actie="annuleren">Annuleren</button>
        </form>`;
    return `
        <li data-panel="${escapeHtml(p.panel_id)}">
            <p class="rij-kop"><strong>${escapeHtml(p.naam)}</strong>
                ${actief ? '' : '<span class="label verwijderd">Ingetrokken</span>'}
                ${p.meetellen ? '' : '<span class="label opgeschort">Telt niet mee</span>'}
                <span class="rij-noot">${meervoud(p.links, 'link', 'links')} · ${p.gebruikt} gebruikt · ${meervoud(p.antwoorden, 'antwoord', 'antwoorden')} · ${meervoud(p.stellingen, 'stelling', 'stellingen')}</span></p>
            <div class="acties">
                <button type="button" class="knop-klein" data-panel-actie="export">Export (CSV)</button>
                ${actief ? '<button type="button" class="knop-klein" data-panel-actie="erbij">Links erbij</button>' : ''}
                ${actief ? '<button type="button" class="knop-klein" data-panel-actie="link">Eén link intrekken</button>' : ''}
                <button type="button" class="knop-klein" data-panel-actie="meetellen">${p.meetellen ? 'Niet meetellen' : 'Wel meetellen'}</button>
                ${actief ? '<button type="button" class="knop-klein" data-panel-actie="intrekken">Intrekken</button>' : ''}
            </div>
            ${getal('erbij', 'Aantal', 10, 'Links erbij')}
            ${getal('link', 'Nummer', null, 'Link intrekken')}
            <form class="reden-formulier panel-formulier" data-soort="intrekken" hidden>
                <label class="vinkje"><input type="checkbox" name="meetellen" ${p.meetellen ? 'checked' : ''}> De antwoorden die er al zijn, blijven meetellen</label>
                <button type="submit" class="knop-klein">Panel intrekken</button>
                <button type="button" class="link-knop" data-panel-actie="annuleren">Annuleren</button>
            </form>
        </li>
    `;
}

async function maakPanel(event) {
    event.preventDefault();
    fout.hidden = true;
    const knop = panelNieuw.querySelector('[type=submit]');
    knop.disabled = true;
    try {
        panels.push(await postPanel({ gesprek_id: gesprek.id, naam: panelNieuw.naam.value.trim(), aantal: Number(panelNieuw.aantal.value) }));
        panelNieuw.naam.value = '';
        tekenPanels();
    } catch (error) {
        fout.textContent = 'Het panel kon niet worden gemaakt. Probeer het opnieuw.';
        verwerkFout(error, fout);
    } finally {
        knop.disabled = false;
    }
}

async function klikPanel(event) {
    const knop = event.target.closest('button[data-panel-actie]');
    if (!knop) {
        return;
    }
    const rij = knop.closest('li');
    const panel = panels.find(p => p.panel_id === rij.dataset.panel);
    const actie = knop.dataset.panelActie;
    rij.querySelectorAll('.panel-formulier').forEach(f => f.hidden = f.dataset.soort !== actie);
    if (actie === 'export') {
        fout.hidden = true;
        try {
            await downloadPanel(gesprek.id, panel);
        } catch (error) {
            fout.textContent = 'De export lukte niet. Probeer het opnieuw.';
            verwerkFout(error, fout);
        }
    } else if (actie === 'meetellen') {
        await wijzigPanel(rij, { meetellen: !panel.meetellen });
    }
}

function bevestigPanel(event) {
    event.preventDefault();
    const formulier = event.target;
    const rij = formulier.closest('li');
    const wijziging = {
        erbij: () => ({ erbij: Number(formulier.getal.value) }),
        link: () => ({ link_intrekken: Number(formulier.getal.value) }),
        intrekken: () => ({ status: 'ingetrokken', meetellen: formulier.meetellen.checked }),
    }[formulier.dataset.soort]();
    wijzigPanel(rij, wijziging);
}

async function wijzigPanel(rij, wijziging) {
    fout.hidden = true;
    rij.querySelectorAll('button').forEach(knop => knop.disabled = true);
    try {
        const nieuwPanel = await putPanel(rij.dataset.panel, { gesprek_id: gesprek.id, ...wijziging });
        panels = panels.map(p => p.panel_id === nieuwPanel.panel_id ? nieuwPanel : p);
        tekenPanels();
    } catch (error) {
        rij.querySelectorAll('button').forEach(knop => knop.disabled = false);
        fout.textContent = error.status === 404 ? 'Dat nummer bestaat niet in dit panel.' : 'Het panel kon niet worden aangepast. Probeer het opnieuw.';
        verwerkFout(error, fout);
    }
}
