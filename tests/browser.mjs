// The product page, the app and the admin in Chrome (headless, over the DevTools protocol, without dependencies):
// answering, adding a stelling, the tabs, the matrix, a paused gesprek, and in the admin the roles:
// a gespreksbeheerder moderates and sees the logboek and the team, a superbeheerder makes a gesprek with
// an uitnodiging, which a new account accepts, and a kanaal a new deelnemer comes through; and logging in and out.
// Uses the data that tests/api.php made (TEST_UITVOER); run it with tests/run.sh.
import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const WWW = process.env.TEST_WWW_URL ?? 'http://localhost:8000';
const APP = process.env.TEST_APP_URL ?? 'http://localhost:8005';
const ADMIN = process.env.TEST_ADMIN_URL ?? 'http://localhost:8002';
const API = process.env.TEST_API_URL ?? 'http://localhost:8001';
const MATH = process.env.TEST_MATH_URL ?? 'http://localhost:8004';
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
    // everything the pages need must fit the Content-Security-Policy of app/index.php and admin/index.php
    if (m.method === 'Log.entryAdded' && /Content.Security.Policy/i.test(m.params.entry.text)) {
        check('niets geblokkeerd door de Content-Security-Policy', false, m.params.entry.text.slice(0, 300));
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
await cmd('Log.enable');
await cmd('Page.enable');
await cmd('Network.enable');
await cmd('Network.setCacheDisabled', { cacheDisabled: true });

try {
    // ---- www: the product page, with links to all the parts
    await naar(`${WWW}/`);
    check('www: productpagina', await wachtOp(`document.querySelector('h1')?.textContent.includes('Samen in gesprek')`));
    check('www: links naar app, beheer en de docs', await waarde(`(() => {
        const links = [...document.querySelectorAll('a')].map(a => a.href);
        return ['${APP}/', '${ADMIN}/', '${API}/docs', '${API}/docs/openapi.json', '${API}/docs/schema.json', '${MATH}/docs', '${MATH}/docs/openapi.json']
            .every(url => links.includes(url));
    })()`));
    const knop = await waarde(`(() => { const r = document.querySelector('.held .knop').getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; })()`);
    await cmd('Input.dispatchMouseEvent', { type: 'mouseMoved', x: knop.x, y: knop.y });
    check('www: knop houdt witte tekst bij hover', await wachtOp(`getComputedStyle(document.querySelector('.held .knop')).color === 'oklch(1 0 0)'`, 2000));
    await cmd('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 0, y: 0 });
    check('www: stijl van de cdn', (await waarde(`getComputedStyle(document.body).fontFamily`)).includes('IBM Plex'));
    check('www: zonder scripts, met Content-Security-Policy', await waarde(`document.scripts.length === 0`)
        && (await fetch(`${WWW}/`)).headers.get('content-security-policy')?.includes("default-src 'none'"));

    // ---- app: a deelnemer with the old localStorage key keeps its antwoorden
    await naar(`${APP}/`);
    await doe(`localStorage.clear(); localStorage.setItem('user_id', '${data.deelnemer}');`);
    await naar(`${APP}/#/gesprekken/${data.gesprek}`);
    await cmd('Page.reload');
    check('app: gesprek geladen', await wachtOp(`document.getElementById('mijn-antwoorden-lijst')?.children.length > 0`));
    check('app: titel van het gesprek in de kop en in de tabtitel', await wachtOp(`document.getElementById('site-gesprek').textContent.startsWith('Testgesprek') && document.title.startsWith('MiniPol | Testgesprek')`));
    check('app: oude user_id overgenomen als deelnemer_id', await waarde(`localStorage.getItem('deelnemer_id') === '${data.deelnemer}' && localStorage.getItem('user_id') === null`));
    check('app: eigen antwoorden (alleen zichtbare stellingen)', await waarde(`document.getElementById('mijn-antwoorden-lijst').children.length`) === data.zichtbaar);
    check('app: stijl van de cdn', (await waarde(`getComputedStyle(document.querySelector('.knop') ?? document.body).fontFamily`)).includes('IBM Plex'));
    // loaded, and (below) nothing from Google: so from the cdn, the only other source
    check('app: met Content-Security-Policy', (await fetch(`${APP}/`)).headers.get('content-security-policy')?.includes("default-src 'none'"));
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
    check('app: matrix met de titel van het gesprek in de kop', await wachtOp(`document.title.startsWith('MiniPol | Testgesprek') && !document.getElementById('site-gesprek').hidden`));
    check('app: matrix met een rij per deelnemer', await wachtOp(`document.querySelectorAll('.matrix tbody tr').length === ${data.deelnemers}`));
    check('app: matrixkolommen met stellingtekst', await waarde(`[...document.querySelectorAll('.matrix thead th[data-stelling]')].some(th => th.dataset.stelling.startsWith('Teststelling'))`));
    check('app: aanhalingstekens en tags blijven tekst', await waarde(`[...document.querySelectorAll('.matrix thead th[data-stelling]')].some(th => th.dataset.stelling === 'Een "stelling" <b>uit</b> de browsertest.') && !document.querySelector('.matrix b')`));

    // ---- app: a paused or an ended gesprek shows a notice instead
    await naar(`${APP}/#/gesprekken/${data.gepauzeerd}`);
    check('app: gepauzeerd gesprek met een melding over de hele pagina', await wachtOp(`${zichtbaar('gesloten')} && document.getElementById('gesloten-titel').textContent.includes('tijdelijk gepauzeerd')`));
    await naar(`${APP}/#/gesprekken/${data.beeindigd}/matrix`);
    check('app: beëindigd gesprek, ook de matrix: dit gesprek is voorbij', await wachtOp(`${zichtbaar('gesloten')} && document.getElementById('gesloten-titel').textContent === 'Dit gesprek is voorbij' && document.querySelector('#gesloten .icoon-gepauzeerd').getBoundingClientRect().width === 0`));

    // ---- admin: logging in, as the gespreksbeheerder of the test gesprek
    const inloggen = (naam, wachtwoord) => doe(`const f = document.getElementById('inlog-formulier'); f.gebruikersnaam.value = '${naam}'; f.wachtwoord.value = '${wachtwoord}'; document.getElementById('inlog-knop').click();`);
    const uitloggen = async () => {
        await doe(`document.getElementById('uitloggen').click();`);
        await wachtOp(`${zichtbaar('inloggen')} && localStorage.getItem('minipol_admin_token') === null`);
    };
    await naar(`${ADMIN}/#/gesprekken/${data.gesprek}`);
    check('admin: zonder login het inlogformulier', await wachtOp(zichtbaar('inloggen')));
    check('admin: met Content-Security-Policy', (await fetch(`${ADMIN}/`)).headers.get('content-security-policy')?.includes("frame-ancestors 'none'"));
    await inloggen(data.gespreksbeheerder, 'fout');
    check('admin: fout wachtwoord gemeld', await wachtOp(zichtbaar('inlog-fout')));
    await inloggen(data.gespreksbeheerder, WACHTWOORD);
    check('admin: ingelogd, op het gesprek', await wachtOp(`document.getElementById('stellingen-lijst')?.children.length > 0`));
    check('admin: naam in de kop', await waarde(`document.getElementById('ingelogd-als').textContent`) === data.gespreksbeheerder);
    check('admin: geen wachtwoord of token in de API-popup', await doe(`
        const token = localStorage.getItem('minipol_admin_token');
        document.querySelector('.api-log-knop').click();
        document.querySelectorAll('.api-log-lijst details').forEach(d => d.open = true);
        await new Promise(r => setTimeout(r, 200));
        const tekst = document.querySelector('.api-log-lijst').textContent;
        document.getElementById('api-log').hidePopover();
        return !tekst.includes(token) && !tekst.includes('${WACHTWOORD}');`));

    // ---- admin: the gespreksbeheerder changes the gesprek, moderates, and sees the logboek and the team
    check('admin: tabs van een gespreksbeheerder, met stellingen eerst', await waarde(`${zichtbaar('paneel-stellingen')} && !document.getElementById('tab-team').hidden && document.getElementById('tab-status').hidden`));
    await doe(`document.getElementById('tab-gegevens').click(); document.getElementById('gesprek-titel-veld').value = 'Testgesprek (browser)'; document.getElementById('gesprek-knop').click();`);
    check('admin: gesprek opgeslagen', await wachtOp(`document.getElementById('gesprek-titel').textContent === 'Testgesprek (browser)' && document.title === 'MiniPol beheer | Testgesprek (browser)'`));
    await doe(`document.getElementById('tab-stellingen').click(); document.querySelector('#stellingen-lijst [data-actie=afkeuren]').click();`);
    await doe(`const f = document.querySelector('#stellingen-lijst .reden-formulier:not([hidden])'); f.reden.value = 'Browsertest'; f.querySelector('button[type=submit]').click();`);
    check('admin: stelling afgekeurd', await wachtOp(`[...document.querySelectorAll('.stelling-meta')].some(m => m.textContent.includes('Browsertest'))`));
    await doe(`document.getElementById('tab-logboek').click();`);
    check('admin: afkeuren in het logboek', await wachtOp(`document.querySelector('#gesprek-logboek li')?.textContent.includes('keurde af') && document.querySelector('#gesprek-logboek li').textContent.includes('Browsertest')`));
    check('admin: logboek met aanpassing, team, antwoorden per deelnemer samen, zonder deelnemer-ids', await waarde(`(() => {
        const tekst = document.querySelector('#gesprek-logboek .logboek-lijst').textContent;
        return tekst.includes('paste het gesprek aan') && tekst.includes('kwam in het team als moderator') && /Deelnemer \\d+\\s+gaf \\d+ antwoorden/.test(tekst) && !tekst.includes('${data.deelnemer}');
    })()`));
    await doe(`document.getElementById('tab-team').click();`);
    check('admin: team met de moderator', await wachtOp(`document.getElementById('team-lijst').textContent.includes('${data.moderator}')`));
    await doe(`document.querySelector('#team-uitnodigen [type=submit]').click();`);
    check('admin: uitnodigingslink voor het team', await wachtOp(`document.querySelector('#team-uitnodigen .uitnodiging-link input')?.value.includes('#/uitnodiging/')`));

    // ---- kanalen: a link per promotion channel; a new deelnemer answers through it, and the kanaal counts him
    await doe(`document.getElementById('tab-kanalen').click();`);
    await wachtOp(`document.querySelector('#kanalen-lijst .kanaal-zonder')`);
    await doe(`const f = document.getElementById('kanaal-nieuw'); f.naam.value = 'Browserkanaal'; f.querySelector('[type=submit]').click();`);
    check('admin: kanaal gemaakt, met een link', await wachtOp(`document.querySelector('#kanalen-lijst .kanaal-link input')?.value.includes('?kanaal=')`));
    const kanaalLink = await waarde(`document.querySelector('#kanalen-lijst .kanaal-link input').value`);
    await naar(`${APP}/`);
    await naar(kanaalLink);
    check('app: kanaal uit de link bewaard, en uit de adresbalk', await wachtOp(`localStorage.getItem('kanaal:${data.gesprek}') && !location.hash.includes('kanaal')`));
    // a new deelnemer: without his id the app makes a new one when the page loads again
    await doe(`localStorage.removeItem('deelnemer_id');`);
    await cmd('Page.reload');
    check('app: nieuwe deelnemer via het kanaal kan antwoorden', await wachtOp(zichtbaar('antwoord-knoppen')));
    await doe(`document.querySelector('#antwoord-knoppen [data-antwoord=eens]').click();`);
    await wachtOp(`document.getElementById('mijn-antwoorden-lijst')?.children.length === 1`);
    await naar(`${ADMIN}/#/gesprekken/${data.gesprek}`);
    await wachtOp(zichtbaar('gesprek-tabs'));
    await doe(`document.getElementById('tab-kanalen').click();`);
    check('admin: kanaal telt de nieuwe deelnemer', await wachtOp(`[...document.querySelectorAll('#kanalen-lijst li')].some(li => li.textContent.includes('Browserkanaal') && li.textContent.includes('1 deelnemer ·'))`));
    await doe(`[...document.querySelectorAll('#kanalen-lijst li')].find(li => li.textContent.includes('Browserkanaal')).querySelector('[data-actie=intrekken]').click();`);
    await doe(`document.querySelector('#kanalen-lijst .intrekken-formulier:not([hidden]) [type=submit]').click();`);
    check('admin: kanaal ingetrokken', await wachtOp(`document.getElementById('kanalen-lijst').textContent.includes('Ingetrokken')`));
    await naar(`${ADMIN}/#/`);
    check('admin: gespreksbeheerder ziet zijn gesprekken, zonder knop voor een nieuw', await wachtOp(`document.getElementById('gesprekken-lijst').textContent.includes('Testgesprek (browser)') && document.getElementById('open-nieuw').hidden`));
    await naar(`${ADMIN}/#/gesprekken/bestaatniet`);
    check('admin: onbekend gesprek', await wachtOp(`document.getElementById('gesprek-titel').textContent === 'Gesprek niet gevonden'`));

    // ---- admin: a reload stays logged in; a token that ended elsewhere means logging in again
    await cmd('Page.reload');
    check('admin: na herladen nog ingelogd', await wachtOp(`document.getElementById('ingelogd-als')?.textContent === '${data.gespreksbeheerder}'`));
    await doe(`await fetch('${API}/sessie', { method: 'DELETE', headers: { Authorization: 'Bearer ' + localStorage.getItem('minipol_admin_token') } });`);
    await naar(`${ADMIN}/#/gesprekken/${data.gesprek}`);
    check('admin: token elders beëindigd, dan het inlogformulier', await wachtOp(`${zichtbaar('inloggen')} && localStorage.getItem('minipol_admin_token') === null`));

    // ---- admin: the superbeheerder makes a gesprek, with the link for its first gespreksbeheerder
    await naar(`${ADMIN}/#/`);
    await inloggen(GEBRUIKER, WACHTWOORD);
    check('admin: superbeheerder ziet de knop voor een nieuw gesprek', await wachtOp(`${zichtbaar('gesprekken')} && !document.getElementById('open-nieuw').hidden && !document.getElementById('menu-superbeheerders').hidden`));
    await doe(`document.getElementById('open-nieuw').click(); const f = document.getElementById('nieuw-formulier'); f.titel.value = 'Uit de browsertest'; document.getElementById('nieuw-knop').click();`);
    check('admin: nieuw gesprek, met de link voor de eerste gespreksbeheerder', await wachtOp(`document.getElementById('gesprekken-lijst').textContent.includes('Uit de browsertest') && document.querySelector('#nieuw-uitnodigen .uitnodiging-link input')?.value.includes('#/uitnodiging/')`));
    const link = await waarde(`document.querySelector('#nieuw-uitnodigen .uitnodiging-link input').value`);
    await naar(`${ADMIN}/#/gesprekken/${data.gesprek}`);
    check('admin: superbeheerder ziet bij een gesprek alleen team en status', await wachtOp(`${zichtbaar('gesprek-tabs')} && document.getElementById('tab-stellingen').hidden && !document.getElementById('tab-team').hidden && !document.getElementById('tab-status').hidden`));
    // the reden form: clicking in it keeps the button as it is
    await doe(`document.getElementById('tab-status').click(); document.querySelector('#status-rij button[data-status=beeindigd]').click();`);
    await doe(`const f = document.querySelector('#status-rij .reden-formulier'); f.reden.click(); f.reden.click(); f.querySelector('label').click();`);
    check('admin: redenformulier blijft gelijk bij klikken erin', await waarde(`document.querySelector('#status-rij .reden-formulier [type=submit]').textContent === 'Beëindigen'`));
    await doe(`document.querySelector('#status-rij [data-annuleren]').click();`);
    await naar(`${ADMIN}/#/superbeheerders`);
    check('admin: superbeheerders', await wachtOp(`document.getElementById('superbeheerders-lijst').textContent.includes('${GEBRUIKER}')`));
    check('admin: menu met gesprekken en superbeheerders, de huidige gemarkeerd', await waarde(`!document.getElementById('beheer-menu').hidden && document.getElementById('menu-superbeheerders').hasAttribute('aria-current') && !document.getElementById('menu-gesprekken').hasAttribute('aria-current')`));
    await doe(`document.getElementById('menu-gesprekken').click();`);
    check('admin: menu-item gesprekken naar het overzicht', await wachtOp(`${zichtbaar('gesprekken')} && document.getElementById('menu-gesprekken').hasAttribute('aria-current')`));
    await uitloggen();

    // ---- admin: the link: a new account, then on the gesprek as its gespreksbeheerder
    await naar(link);
    check('admin: uitnodiging zonder login', await wachtOp(`${zichtbaar('uitnodiging')} && document.getElementById('uitnodiging-tekst').textContent.includes('gespreksbeheerder')`));
    await doe(`const f = document.getElementById('uitnodiging-nieuw'); f.gebruikersnaam.value = '${GEBRUIKER}-browser'; f.email.value = 'browser@example.org'; f.wachtwoord.value = '${WACHTWOORD}'; f.herhaal.value = '${WACHTWOORD}'; document.getElementById('uitnodiging-nieuw-knop').click();`);
    check('admin: uitnodiging aangenomen, op het nieuwe gesprek', await wachtOp(`document.getElementById('gesprek-titel')?.textContent === 'Uit de browsertest' && !document.getElementById('tab-gegevens').hidden`));
    await naar(link);
    check('admin: een gebruikte link werkt niet meer', await wachtOp(`document.getElementById('uitnodiging-tekst').textContent.includes('al gebruikt of verlopen')`));
    await naar(`${ADMIN}/#/`);
    await uitloggen();
    check('admin: uitgelogd', await waarde(zichtbaar('inloggen')));
} finally {
    ws.close();
    // wait until Chrome has really stopped (at most 5 seconds): until then it may still write in its profile
    const gestopt = new Promise(r => chrome.once('exit', r));
    chrome.kill();
    await Promise.race([gestopt, slaap(5000)]);
    // cleaning up is no test: when the map cannot be removed yet, it stays in the temp directory
    try {
        rmSync(profiel, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 });
    } catch (error) {
        console.log(`let op: het Chrome-profiel ${profiel} is niet opgeruimd (${error.code})`);
    }
}

console.log(`browser.mjs: ${fouten} fouten`);
process.exit(fouten ? 1 : 0);
