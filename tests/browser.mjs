// The app and the admin in Chrome (headless, over the DevTools protocol, without dependencies):
// answering, adding a stelling, the tabs, the matrix, and in the admin logging in,
// changing a gesprek, rejecting a stelling, the tabs with the logboek, and logging in and out.
// Uses the data that tests/api.py made (TEST_UITVOER); run it with tests/run.sh.
import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const APP = process.env.TEST_APP_URL ?? 'http://localhost:8000';
const ADMIN = process.env.TEST_ADMIN_URL ?? 'http://localhost:8002';
const API = process.env.TEST_API_URL ?? 'http://localhost:8001';
const GEBRUIKER = process.env.TEST_GEBRUIKER ?? 'minipol-test';
const WACHTWOORD = process.env.TEST_WACHTWOORD ?? 'testwachtwoord123';
const data = JSON.parse(readFileSync(process.env.TEST_UITVOER, 'utf8'));

let fouten = 0;
function check(naam, goed, info = '') {
    fouten += goed ? 0 : 1;
    console.log(`${goed ? 'ok  ' : 'FOUT'} ${naam}${goed ? '' : ` ${info}`}`);
}

// ---- Chrome

function chromePad() {
    const kandidaten = [
        process.env.CHROME,
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
    ];
    return kandidaten.find(pad => pad && existsSync(pad));
}

const profiel = mkdtempSync(join(tmpdir(), 'minipol-chrome-'));
// TEST_ONVEILIG_TLS=1: accept a certificate of a local CA (Caddy's local_certs)
const onveilig = process.env.TEST_ONVEILIG_TLS ? ['--ignore-certificate-errors'] : [];
const chrome = spawn(chromePad(), ['--headless=new', ...onveilig, '--remote-debugging-port=9333', `--user-data-dir=${profiel}`, '--window-size=1000,900', 'about:blank'], { stdio: 'ignore' });
const slaap = ms => new Promise(r => setTimeout(r, ms));
let pagina;
for (let i = 0; i < 50 && !pagina; i++) {
    await slaap(200);
    pagina = await fetch('http://localhost:9333/json').then(r => r.json()).then(l => l.find(t => t.type === 'page')).catch(() => null);
}
const ws = new WebSocket(pagina.webSocketDebuggerUrl);
await new Promise(r => ws.onopen = r);

let volgnummer = 0;
const wacht = new Map();
ws.onmessage = e => {
    const m = JSON.parse(e.data);
    if (m.id && wacht.has(m.id)) {
        wacht.get(m.id)(m);
        wacht.delete(m.id);
    }
    if (m.method === 'Runtime.exceptionThrown') {
        check('geen JavaScript-fout op de pagina', false, JSON.stringify(m.params.exceptionDetails).slice(0, 300));
    }
};
const cmd = (method, params = {}) => new Promise(r => {
    const id = ++volgnummer;
    wacht.set(id, r);
    ws.send(JSON.stringify({ id, method, params }));
});
// runs a function body in the page, returns its value
async function doe(body) {
    const r = await cmd('Runtime.evaluate', { expression: `(async () => { ${body} })()`, awaitPromise: true, returnByValue: true });
    return r.result.result?.value;
}
const waarde = expr => doe(`return ${expr};`);
async function wachtOp(expr, max = 8000) {
    const start = Date.now();
    while (Date.now() - start < max) {
        if (await waarde(`(() => { try { return ${expr}; } catch { return false; } })()`)) {
            return true;
        }
        await slaap(100);
    }
    return false;
}
const zichtbaar = id => `document.getElementById('${id}') && !document.getElementById('${id}').hidden`;
async function naar(url) {
    await cmd('Page.navigate', { url });
    await slaap(300);
}

await cmd('Runtime.enable');
await cmd('Page.enable');
await cmd('Network.enable');
await cmd('Network.setCacheDisabled', { cacheDisabled: true });

try {
    // ---- app: a deelnemer with the old localStorage key keeps its antwoorden
    await naar(`${APP}/`);
    await doe(`localStorage.clear(); localStorage.setItem('user_id', '${data.deelnemer}');`);
    await naar(`${APP}/#/gesprekken/${data.gesprek}`);
    await cmd('Page.reload');
    check('app: gesprek geladen', await wachtOp(`document.getElementById('mijn-antwoorden-lijst')?.children.length > 0`));
    check('app: oude user_id overgenomen als deelnemer_id', await waarde(`localStorage.getItem('deelnemer_id') === '${data.deelnemer}' && localStorage.getItem('user_id') === null`));
    check('app: eigen antwoorden (alleen zichtbare stellingen)', await waarde(`document.getElementById('mijn-antwoorden-lijst').children.length`) === data.zichtbaar);
    check('app: stijl van de cdn', (await waarde(`getComputedStyle(document.querySelector('.knop') ?? document.body).fontFamily`)).includes('IBM Plex'));
    // loaded, and (below) nothing from Google: so from the cdn, the only other source
    check('app: font IBM Plex Sans geladen', await doe(`await document.fonts.ready; return [...document.fonts].some(f => f.family.includes('IBM Plex Sans') && f.status === 'loaded');`));
    check('app: geen verzoeken naar Google', await waarde(`!performance.getEntriesByType('resource').some(r => /google|gstatic/.test(r.name))`));

    await doe(`document.getElementById('tab-stellingen').click(); document.getElementById('open-toevoegen').click();`);
    // with quotes and a tag, which must stay text everywhere
    await doe(`document.getElementById('stelling-tekst').value = 'Een "stelling" <b>uit</b> de browsertest.'; document.getElementById('stelling-indienen').click();`);
    check('app: stelling toegevoegd', await wachtOp(`${zichtbaar('melding')} && document.getElementById('mijn-stellingen-lijst').textContent.includes('<b>uit</b> de browsertest')`));
    await doe(`document.getElementById('tab-groepen').click();`);
    check('app: tab groepen', await wachtOp(`document.getElementById('groep-status').textContent.length > 0`));
    check('app: API-popup telt de calls', Number(await waarde(`document.querySelector('.api-log-teller').textContent`)) > 0);
    check('app: API-popup met eigen stylesheet van de cdn', await wachtOp(`getComputedStyle(document.querySelector('.api-log-knop')).position === 'fixed'`));

    // ---- app: the matrix
    await naar(`${APP}/#/gesprekken/${data.gesprek}/matrix`);
    check('app: matrix met een rij per deelnemer', await wachtOp(`document.querySelectorAll('.matrix tbody tr').length === ${data.deelnemers}`));
    check('app: matrixkolommen met stellingtekst', await waarde(`[...document.querySelectorAll('.matrix thead th[data-stelling]')].some(th => th.dataset.stelling.startsWith('Teststelling'))`));
    check('app: aanhalingstekens en tags blijven tekst', await waarde(`[...document.querySelectorAll('.matrix thead th[data-stelling]')].some(th => th.dataset.stelling === 'Een "stelling" <b>uit</b> de browsertest.') && !document.querySelector('.matrix b')`));

    // ---- admin: logging in
    await naar(`${ADMIN}/#/gesprekken/${data.gesprek}`);
    check('admin: zonder login het inlogformulier', await wachtOp(zichtbaar('inloggen')));
    await doe(`const f = document.getElementById('inlog-formulier'); f.gebruikersnaam.value = '${GEBRUIKER}'; f.wachtwoord.value = 'fout'; document.getElementById('inlog-knop').click();`);
    check('admin: fout wachtwoord gemeld', await wachtOp(zichtbaar('inlog-fout')));
    await doe(`const f = document.getElementById('inlog-formulier'); f.wachtwoord.value = '${WACHTWOORD}'; document.getElementById('inlog-knop').click();`);
    check('admin: ingelogd, op het gesprek', await wachtOp(`document.getElementById('stellingen-lijst')?.children.length > 0`));
    check('admin: naam in de kop', await waarde(`document.getElementById('ingelogd-als').textContent`) === GEBRUIKER);
    check('admin: geen wachtwoord of token in de API-popup', await doe(`
        const token = localStorage.getItem('minipol_admin_token');
        document.querySelector('.api-log-knop').click();
        document.querySelectorAll('.api-log-lijst details').forEach(d => d.open = true);
        await new Promise(r => setTimeout(r, 200));
        const tekst = document.querySelector('.api-log-lijst').textContent;
        document.getElementById('api-log').hidePopover();
        return !tekst.includes(token) && !tekst.includes('${WACHTWOORD}');`));

    // ---- admin: changing
    check('admin: tabs, met stellingen eerst', await waarde(`${zichtbaar('paneel-stellingen')} && document.getElementById('paneel-logboek').hidden && document.getElementById('paneel-gegevens').hidden`));
    await doe(`document.getElementById('tab-gegevens').click(); document.getElementById('gesprek-titel-veld').value = 'Testgesprek (browser)'; document.getElementById('gesprek-knop').click();`);
    check('admin: gesprek opgeslagen', await wachtOp(`document.getElementById('gesprek-titel').textContent === 'Testgesprek (browser)'`));
    await doe(`document.getElementById('tab-stellingen').click(); document.querySelector('#stellingen-lijst [data-actie=afkeuren]').click();`);
    await doe(`const f = document.querySelector('#stellingen-lijst .afkeur-formulier:not([hidden])'); f.reden.value = 'Browsertest'; f.querySelector('button[type=submit]').click();`);
    check('admin: stelling afgekeurd', await wachtOp(`[...document.querySelectorAll('.stelling-meta')].some(m => m.textContent.includes('Browsertest'))`));
    await doe(`document.getElementById('tab-logboek').click();`);
    check('admin: afkeuren in het logboek', await wachtOp(`document.querySelector('#gesprek-logboek li')?.textContent.includes('keurde af') && document.querySelector('#gesprek-logboek li').textContent.includes('Browsertest')`));
    check('admin: logboek met aanpassing, antwoorden per deelnemer samen, zonder deelnemer-ids', await waarde(`(() => {
        const tekst = document.querySelector('#gesprek-logboek .logboek-lijst').textContent;
        return tekst.includes('paste het gesprek aan') && /Deelnemer \\d+\\s+gaf \\d+ antwoorden/.test(tekst) && !tekst.includes('${data.deelnemer}');
    })()`));
    await naar(`${ADMIN}/#/`);
    await wachtOp(zichtbaar('gesprekken'));
    await doe(`document.getElementById('open-nieuw').click(); const f = document.getElementById('nieuw-formulier'); f.titel.value = 'Uit de browsertest'; document.getElementById('nieuw-knop').click();`);
    check('admin: nieuw gesprek', await wachtOp(`${zichtbaar('gesprekken-melding')} && document.getElementById('gesprekken-lijst').textContent.includes('Uit de browsertest')`));
    await naar(`${ADMIN}/#/gesprekken/bestaatniet`);
    check('admin: onbekend gesprek', await wachtOp(`document.getElementById('gesprek-titel').textContent === 'Gesprek niet gevonden'`));

    // ---- admin: a reload stays logged in; a token that ended elsewhere means logging in again
    await cmd('Page.reload');
    check('admin: na herladen nog ingelogd', await wachtOp(`document.getElementById('ingelogd-als')?.textContent === '${GEBRUIKER}'`));
    await doe(`await fetch('${API}/sessie', { method: 'DELETE', headers: { Authorization: 'Bearer ' + localStorage.getItem('minipol_admin_token') } });`);
    await naar(`${ADMIN}/#/gesprekken/${data.gesprek}`);
    check('admin: token elders beëindigd, dan het inlogformulier', await wachtOp(`${zichtbaar('inloggen')} && localStorage.getItem('minipol_admin_token') === null`));
    await doe(`const f = document.getElementById('inlog-formulier'); f.gebruikersnaam.value = '${GEBRUIKER}'; f.wachtwoord.value = '${WACHTWOORD}'; document.getElementById('inlog-knop').click();`);
    await wachtOp(`!document.getElementById('ingelogd').hidden`);
    await doe(`document.getElementById('uitloggen').click();`);
    check('admin: uitgelogd', await wachtOp(`${zichtbaar('inloggen')} && localStorage.getItem('minipol_admin_token') === null`));
} finally {
    ws.close();
    chrome.kill();
    await slaap(300);
    rmSync(profiel, { recursive: true, force: true });
}

console.log(`browser.mjs: ${fouten} fouten`);
process.exit(fouten ? 1 : 0);
