import { getBeoordelingen, postBeoordeling } from './api.js';
import { verwerkFout } from './sessie.js';
import { escapeHtml } from './util.js';

// the stellingen of a gesprek on its page (views/gesprek.html), newest first, to approve or reject

// elements, set by koppel() once the view is in the page
let sectie, lijst, filter, fout, leeg;
let gekoppeld = false;

function koppel() {
    if (gekoppeld) {
        return;
    }
    sectie = document.getElementById('beheer-stellingen');
    lijst = document.getElementById('stellingen-lijst');
    filter = document.getElementById('stellingen-filter');
    fout = document.getElementById('stellingen-fout');
    leeg = document.getElementById('stellingen-leeg');
    filter.addEventListener('change', tekenLijst);
    lijst.addEventListener('click', klik);
    lijst.addEventListener('submit', keurAf);
    gekoppeld = true;
}

const FILTERS = {
    'alle': () => true,
    'te-beoordelen': s => s.beoordeling === null,
    'zichtbaar': s => s.zichtbaar,
    'afgekeurd': s => s.beoordeling === 'afgekeurd',
};

let gesprekId = null;
let stellingen = [];

// loads and shows the stellingen of a gesprek; errors are for the caller
export async function toonStellingen(id) {
    koppel();
    gesprekId = id;
    const geladen = await getBeoordelingen(id);
    if (gesprekId !== id) {
        return; // another gesprek was opened in the meantime
    }
    stellingen = geladen.reverse();
    fout.hidden = true;
    sectie.hidden = false;
    tekenLijst();
}

export function verbergStellingen() {
    gesprekId = null;
    document.getElementById('beheer-stellingen').hidden = true;
}

function tekenLijst() {
    // the number of stellingen per filter in the options
    [...filter.options].forEach(optie => {
        const aantal = stellingen.filter(FILTERS[optie.value]).length;
        optie.textContent = `${optie.value.replace('-', ' ')} (${aantal})`;
    });
    const getoond = stellingen.filter(FILTERS[filter.value]);
    lijst.innerHTML = getoond.map(stellingRegel).join('');
    lijst.hidden = getoond.length === 0;
    leeg.hidden = getoond.length > 0;
}

function status(s) {
    if (s.beoordeling === 'goedgekeurd') {
        return '<span class="beoordeling-label goedgekeurd">Goedgekeurd</span>';
    }
    if (s.beoordeling === 'afgekeurd') {
        return `<span class="beoordeling-label afgekeurd">Afgekeurd</span> ${escapeHtml(s.reden)}`;
    }
    return s.zichtbaar
        ? '<span class="beoordeling-label">Niet beoordeeld</span> zichtbaar voor deelnemers'
        : '<span class="beoordeling-label wacht">Wacht op goedkeuring</span> nog niet zichtbaar';
}

function stellingRegel(s) {
    const aantal = s.antwoorden.eens + s.antwoorden.neutraal + s.antwoorden.oneens;
    return `
        <li data-id="${escapeHtml(s.id)}">
            <p class="stelling-tekst">${escapeHtml(s.tekst)}</p>
            <p class="stelling-meta">${status(s)} · ${aantal} ${aantal === 1 ? 'antwoord' : 'antwoorden'}</p>
            <div class="stelling-acties">
                ${s.beoordeling !== 'goedgekeurd' ? '<button type="button" class="knop-klein" data-actie="goedkeuren">Goedkeuren</button>' : ''}
                ${s.beoordeling !== 'afgekeurd' ? '<button type="button" class="knop-klein" data-actie="afkeuren">Afkeuren</button>' : ''}
            </div>
            <form class="afkeur-formulier" hidden>
                <label>Reden <span>(ziet de indiener)</span>
                    <input name="reden" maxlength="500" required>
                </label>
                <button type="submit" class="knop-klein">Afkeuren</button>
                <button type="button" class="link-button" data-actie="annuleren">Annuleren</button>
            </form>
        </li>
    `;
}

function klik(event) {
    const knop = event.target.closest('[data-actie]');
    if (!knop) {
        return;
    }
    const li = knop.closest('li');
    const afkeurFormulier = li.querySelector('.afkeur-formulier');
    if (knop.dataset.actie === 'goedkeuren') {
        beoordeel(li, 'goedgekeurd');
    } else if (knop.dataset.actie === 'afkeuren') {
        afkeurFormulier.hidden = false;
        afkeurFormulier.reden.focus();
    } else if (knop.dataset.actie === 'annuleren') {
        afkeurFormulier.hidden = true;
    }
}

function keurAf(event) {
    event.preventDefault();
    const form = event.target;
    beoordeel(form.closest('li'), 'afgekeurd', form.reden.value.trim());
}

async function beoordeel(li, beoordeling, reden = '') {
    fout.hidden = true;
    li.querySelectorAll('button').forEach(b => b.disabled = true);
    try {
        const nieuw = await postBeoordeling(gesprekId, li.dataset.id, beoordeling, reden);
        stellingen = stellingen.map(s => s.id === nieuw.id ? nieuw : s);
        tekenLijst();
    } catch (error) {
        li.querySelectorAll('button').forEach(b => b.disabled = false);
        fout.textContent = 'De beoordeling kon niet worden opgeslagen. Probeer het opnieuw.';
        verwerkFout(error, fout);
    }
}
