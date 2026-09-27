import { escapeHtml } from './html.js';

// Developer tool: a "{ } API" button with a popover listing the API calls of the current page
// (method, url, status, duration, size) with the request and response as formatted JSON.
// Calls are registered by verzoek.js; the list starts over on every page change (nieuwePagina(), called by the
// main.js of the interface). A library on the cdn: it brings its own stylesheet (apilog.css, next to it).

const MAX_VERZOEKEN = 50;

// the Popover API is from 2023 (Safari 17, iOS 17); without it there is no button, and the page just works.
// Before, ':popover-open' threw there, so the app and the admin showed no more than their header.
const heeftPopover = Object.hasOwn(HTMLElement.prototype, 'popover');

const stijl = document.createElement('link');
stijl.rel = 'stylesheet';
stijl.href = new URL('apilog.css', import.meta.url);
document.head.append(stijl);

let pagina = 0;       // increases on every page change
let verzoeken = [];   // the calls of the current page

const knop = document.createElement('button');
knop.className = 'api-log-knop';
knop.type = 'button';
knop.setAttribute('popovertarget', 'api-log');
knop.hidden = !heeftPopover;
knop.innerHTML = '<span aria-hidden="true">{ }</span> API <span class="api-log-teller">0</span>';

const paneel = document.createElement('div');
paneel.id = 'api-log';
paneel.className = 'api-log';
paneel.popover = 'auto';
paneel.innerHTML = `
    <header>
        <h2>API-verzoeken op deze pagina</h2>
        <button type="button" class="sluit-knop" popovertarget="api-log" popovertargetaction="hide" aria-label="Sluiten">
            <svg width="16" height="16" viewBox="0 0 20 20" aria-hidden="true"><path d="M3 3l14 14M17 3L3 17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        </button>
    </header>
    <p class="api-log-leeg">Nog geen verzoeken.</p>
    <ol class="api-log-lijst"></ol>
`;
document.body.append(knop, paneel);

const teller = knop.querySelector('.api-log-teller');
const lijst = paneel.querySelector('.api-log-lijst');
const leeg = paneel.querySelector('.api-log-leeg');

// the page a call belongs to; take it when the call starts
export function huidigePagina() {
    return pagina;
}

// forget the calls of the previous page
export function nieuwePagina() {
    pagina++;
    verzoeken = [];
    teken();
}

// { pagina, methode, url, verzonden, status, duur, tekst } — tekst is the raw response body
export function registreer(verzoek) {
    if (verzoek.pagina !== pagina) {
        return; // finished after the page changed
    }
    verzoeken.push(verzoek);
    if (verzoeken.length > MAX_VERZOEKEN) {
        verzoeken.shift();
    }
    teken();
}

function teken() {
    teller.textContent = verzoeken.length;
    leeg.hidden = verzoeken.length > 0;
    // only draw the list when it can be seen; it is drawn again when the popover opens
    if (heeftPopover && paneel.matches(':popover-open')) {
        lijst.innerHTML = '';
        verzoeken.forEach(verzoek => lijst.append(maakRegel(verzoek)));
    }
}

paneel.addEventListener('toggle', event => {
    if (event.newState === 'open') {
        teken();
    }
});

function maakRegel(verzoek) {
    const li = document.createElement('li');
    const url = decodeURIComponent(verzoek.url);
    const statusKlasse = verzoek.status >= 400 || verzoek.status === 0 ? 'fout' : 'ok';
    li.innerHTML = `
        <details>
            <summary>
                <span class="methode">${escapeHtml(verzoek.methode)}</span>
                <span class="url">${escapeHtml(url)}</span>
                <span class="status ${statusKlasse}">${verzoek.status || 'mislukt'}</span>
                <span class="meta">${Math.round(verzoek.duur)} ms · ${grootte(verzoek.tekst)}</span>
            </summary>
            <div class="inhoud"></div>
        </details>
    `;
    // format only when opened: some responses are large
    const details = li.querySelector('details');
    details.addEventListener('toggle', () => {
        const inhoud = li.querySelector('.inhoud');
        if (!details.open || inhoud.childElementCount > 0) {
            return;
        }
        if (verzoek.verzonden !== undefined) {
            inhoud.insertAdjacentHTML('beforeend', `<h3>Verzonden</h3><pre>${kleur(verzoek.verzonden)}</pre>`);
        }
        inhoud.insertAdjacentHTML('beforeend', `
            <h3>Antwoord</h3>
            <pre>${kleur(verzoek.tekst)}</pre>
            <button type="button" class="kopieer">Kopieer antwoord</button>
        `);
        inhoud.querySelector('.kopieer').addEventListener('click', async event => {
            try {
                await navigator.clipboard.writeText(opgemaakt(verzoek.tekst));
                event.target.textContent = 'Gekopieerd';
            } catch (error) {
                event.target.textContent = 'Kopiëren lukte niet';
            }
        });
    });
    return li;
}

// indented JSON, or the text as it is when it is not JSON
function opgemaakt(tekst) {
    try {
        return JSON.stringify(JSON.parse(tekst), null, 2);
    } catch (error) {
        return tekst ?? '';
    }
}

// formatted JSON with spans for keys, strings, numbers and true/false/null
function kleur(tekst) {
    return escapeHtml(opgemaakt(tekst)).replace(
        /("(?:\\u[a-fA-F0-9]{4}|\\[^u]|[^\\"])*")(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/g,
        (match, string, dubbelepunt, woord) => {
            if (string) {
                return dubbelepunt
                    ? `<span class="sleutel">${string}</span>${dubbelepunt}`
                    : `<span class="tekst">${string}</span>`;
            }
            return `<span class="${woord ? 'woord' : 'getal'}">${match}</span>`;
        }
    );
}

function grootte(tekst) {
    const bytes = new Blob([tekst ?? '']).size;
    return bytes < 1024 ? `${bytes} B` : `${(bytes / 1024).toLocaleString('nl-NL', { maximumFractionDigits: 1 })} kB`;
}
