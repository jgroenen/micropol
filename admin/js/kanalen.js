import { getKanalen, postKanaal, putKanaal } from './api.js';
import { verwerkFout } from './toegang.js';
import { escapeHtml } from 'cdn/html.js';
import { APP_URL } from './config.php';

// the kanalen of a gesprek, in its tab on the page of the gesprek (views/gesprek.html), for its
// gespreksbeheerders: a link for taking part per promotion channel, with how many deelnemers and antwoorden
// came through it. Meetellen can be turned off and on; withdrawing (intrekken) stops the link for good.

let lijst, fout, nieuw;
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
    gekoppeld = true;
}

let gesprek = null;
let kanalen = [];
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
    zonderKanaal = geladen.zonder_kanaal;
    tekenLijst();
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
