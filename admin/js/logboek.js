import { getEvents } from './api.js';
import { verwerkFout } from './toegang.js';
import { escapeHtml } from 'cdn/html.js';

// The logboek of a gesprek: what happened, newest first, a line per event with when, who and what
// (GET /events). Antwoorden of a deelnemer one after another are one line, otherwise they would hide
// everything else. The html is in views/gesprek.html:
//   <section class="logboek" id="...">  with .logboek-lijst, .logboek-fout, .logboek-leeg and .logboek-meer

// the logboek in the section with this id => { toon, verberg }
export function maakLogboek(id) {
    let sectie, lijst, fout, leeg, meer;
    let gesprekId = null;
    let events = [];
    // changes with every toon(), so a response for an earlier one is ignored
    let versie = 0;

    function koppel() {
        if (sectie) {
            return;
        }
        sectie = document.getElementById(id);
        lijst = sectie.querySelector('.logboek-lijst');
        fout = sectie.querySelector('.logboek-fout');
        leeg = sectie.querySelector('.logboek-leeg');
        meer = sectie.querySelector('.logboek-meer');
        meer.addEventListener('click', laadMeer);
    }

    // loads and shows the newest events of a gesprek; errors are for the caller
    async function toon(gesprek) {
        koppel();
        gesprekId = gesprek;
        const deze = ++versie;
        const geladen = await getEvents(gesprekId);
        if (deze !== versie) {
            return; // shown again in the meantime
        }
        events = geladen.events;
        meer.hidden = !geladen.meer;
        fout.hidden = true;
        sectie.hidden = false;
        tekenLijst();
    }

    function verberg() {
        versie++;
        document.getElementById(id).hidden = true;
    }

    // the events before the oldest one shown
    async function laadMeer() {
        const deze = versie;
        fout.hidden = true;
        meer.disabled = true;
        try {
            const geladen = await getEvents(gesprekId, events.at(-1).id);
            if (deze !== versie) {
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

    return { toon, verberg };
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
            return `keurde goed: ${citaat(event.tekst)}`;
        case 'stelling.afgekeurd':
            return `keurde af: ${citaat(event.tekst)}, met als reden ${citaat(event.reden ?? '')}`;
        case 'antwoord.gegeven':
            return groep.length === 1
                ? `antwoordde ${escapeHtml(event.waarde)} op ${citaat(event.tekst)}`
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

function citaat(tekst) {
    return `„${escapeHtml(tekst)}”`;
}
