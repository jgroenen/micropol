import { getEvents } from './api.js';
import { verwerkFout } from './toegang.js';
import { escapeHtml } from 'cdn/html.js';

// the logboek of a gesprek on its page (views/gesprek.html): what happened, newest first, a line per event
// with when, who and what (GET /events). Antwoorden of a deelnemer one after another are one line,
// otherwise they would hide everything else.

// elements, set by koppel() once the view is in the page
let sectie, lijst, fout, leeg, meer;
let gekoppeld = false;

function koppel() {
    if (gekoppeld) {
        return;
    }
    sectie = document.getElementById('logboek');
    lijst = document.getElementById('logboek-lijst');
    fout = document.getElementById('logboek-fout');
    leeg = document.getElementById('logboek-leeg');
    meer = document.getElementById('logboek-meer');
    meer.addEventListener('click', laadMeer);
    gekoppeld = true;
}

let gesprekId = null;
let events = [];
// stelling_id => tekst, for the events that only have the id
let teksten = new Map();

// loads and shows the newest events of a gesprek, with the texts of its stellingen; errors are for the caller
export async function toonLogboek(id, stellingen) {
    koppel();
    gesprekId = id;
    teksten = new Map(stellingen.map(s => [s.id, s.tekst]));
    const geladen = await getEvents(id);
    if (gesprekId !== id) {
        return; // another gesprek was opened in the meantime
    }
    events = geladen.events;
    meer.hidden = !geladen.meer;
    fout.hidden = true;
    sectie.hidden = false;
    tekenLijst();
}

export function verbergLogboek() {
    gesprekId = null;
    document.getElementById('logboek').hidden = true;
}

// the events before the oldest one shown
async function laadMeer() {
    const id = gesprekId;
    fout.hidden = true;
    meer.disabled = true;
    try {
        const geladen = await getEvents(id, events.at(-1).id);
        if (gesprekId !== id) {
            return;
        }
        events = events.concat(geladen.events);
        meer.hidden = !geladen.meer;
        tekenLijst();
    } catch (error) {
        fout.textContent = 'Oudere gebeurtenissen konden niet worden geladen. Probeer het opnieuw.';
        verwerkFout(error, fout);
    } finally {
        meer.disabled = false;
    }
}

function tekenLijst() {
    const groepen = groepeer(events);
    lijst.innerHTML = groepen.map(regel).join('');
    lijst.hidden = groepen.length === 0;
    leeg.hidden = groepen.length > 0;
}

// [[event]]: antwoorden of the same deelnemer one after another together, the rest alone
function groepeer(events) {
    const groepen = [];
    for (const event of events) {
        const vorige = groepen.at(-1)?.[0];
        if (event.type === 'antwoord.gegeven' && vorige?.type === 'antwoord.gegeven' && vorige.door?.nummer === event.door?.nummer) {
            groepen.at(-1).push(event);
        } else {
            groepen.push([event]);
        }
    }
    return groepen;
}

// one line: when (of the newest event of the group), who and what
function regel(groep) {
    const event = groep[0];
    const tijd = event.tijdstip
        ? `<time datetime="${escapeHtml(event.tijdstip)}">${new Date(event.tijdstip).toLocaleString('nl-NL', { dateStyle: 'medium', timeStyle: 'short' })}</time>`
        : '<span title="Van vóór het logboek: toen werd het tijdstip niet bewaard">tijdstip onbekend</span>';
    return `
        <li>
            <span class="logboek-tijd">${tijd}</span>
            <span class="logboek-wie">${escapeHtml(wie(event.door))}</span>
            <span class="logboek-wat">${wat(groep)}</span>
        </li>
    `;
}

function wie(door) {
    if (door?.soort === 'beheerder') {
        return door.gebruikersnaam || 'Een beheerder';
    }
    if (door?.soort === 'deelnemer') {
        return `Deelnemer ${door.nummer}`;
    }
    return 'Onbekend';
}

// what happened, as html
function wat(groep) {
    const event = groep[0];
    switch (event.type) {
        case 'gesprek.aangemaakt':
            return `maakte het gesprek aan: ${citaat(event.titel)}`;
        case 'gesprek.aangepast':
            return `paste het gesprek aan: ${wijzigingen(event)}`;
        case 'stelling.toegevoegd':
            return `voegde een stelling toe: ${citaat(event.tekst)}`;
        case 'stelling.goedgekeurd':
            return `keurde goed: ${stelling(event.stelling_id)}`;
        case 'stelling.afgekeurd':
            return `keurde af: ${stelling(event.stelling_id)}, met als reden ${citaat(event.reden ?? '')}`;
        case 'antwoord.gegeven':
            return groep.length === 1
                ? `antwoordde ${escapeHtml(event.waarde)} op ${stelling(event.stelling_id)}`
                : `gaf ${groep.length} antwoorden`;
        default:
            return escapeHtml(event.type);
    }
}

function wijzigingen(event) {
    const delen = [];
    if ('titel' in event) {
        delen.push(`titel ${citaat(event.titel)}`);
    }
    if ('omschrijving' in event) {
        delen.push(event.omschrijving ? `omschrijving ${citaat(event.omschrijving)}` : 'omschrijving leeg');
    }
    if ('moderatie' in event) {
        delen.push(event.moderatie === 'vooraf' ? 'stellingen pas tonen na goedkeuring' : 'stellingen direct tonen');
    }
    return delen.join(', ');
}

function stelling(id) {
    return teksten.has(id) ? citaat(teksten.get(id)) : 'een stelling';
}

function citaat(tekst) {
    return `„${escapeHtml(tekst)}”`;
}
