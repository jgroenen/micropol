import { getUitnodiging, neemUitnodigingAan, login } from './api.js';
import { ingelogdAccount, zetSessie } from './toegang.js';
import { accountVeldenHtml, accountVelden, accountFout, ROLNAMEN } from './util.js';
import { toonView } from './views.js';

// an uitnodiging (views/uitnodiging.html), from the link someone sent: accept it logged in, with a new
// account, or by logging in first. Then the gesprek (or the overview) is shown.

let tekst, fout, ingelogd, uitgelogd, nieuw, inloggen;
let gekoppeld = false;

function koppel() {
    if (gekoppeld) {
        return;
    }
    tekst = document.getElementById('uitnodiging-tekst');
    fout = document.getElementById('uitnodiging-fout');
    ingelogd = document.getElementById('uitnodiging-ingelogd');
    uitgelogd = document.getElementById('uitnodiging-uitgelogd');
    nieuw = document.getElementById('uitnodiging-nieuw');
    inloggen = document.getElementById('uitnodiging-inloggen');
    document.getElementById('uitnodiging-velden').innerHTML = accountVeldenHtml('uitnodiging');
    document.getElementById('uitnodiging-aannemen').addEventListener('click', () => aannemen(() => neemUitnodigingAan(huidigToken)));
    nieuw.addEventListener('submit', event => {
        event.preventDefault();
        const velden = accountVelden(nieuw, fout);
        if (velden) {
            aannemen(() => neemUitnodigingAan(huidigToken, velden));
        }
    });
    inloggen.addEventListener('submit', event => {
        event.preventDefault();
        aannemen(async () => {
            await login(inloggen.gebruikersnaam.value.trim(), inloggen.wachtwoord.value);
            return neemUitnodigingAan(huidigToken);
        });
    });
    gekoppeld = true;
}

let huidigToken = null;
let huidig = null;

export async function toonUitnodiging(token) {
    huidigToken = token;
    try {
        await toonView('uitnodiging');
        koppel();
        fout.hidden = true;
        ingelogd.hidden = true;
        uitgelogd.hidden = true;
        tekst.textContent = '';
        huidig = await getUitnodiging(token);
        if (huidigToken !== token) {
            return;
        }
        if (!huidig) {
            tekst.textContent = 'Deze uitnodiging is al gebruikt of verlopen. Vraag om een nieuwe link.';
            return;
        }
        const rol = ROLNAMEN[huidig.rol];
        tekst.textContent = huidig.gesprek
            ? `Je bent uitgenodigd als ${rol} van het gesprek „${huidig.gesprek.titel}”.`
            : `Je bent uitgenodigd als ${rol} van MiniPol.`;
        const account = ingelogdAccount();
        if (account) {
            document.getElementById('uitnodiging-aannemen').textContent = `Aannemen als ${account.gebruikersnaam}`;
            ingelogd.hidden = false;
        } else {
            uitgelogd.hidden = false;
        }
    } catch (error) {
        console.error('Error loading uitnodiging:', error);
        tekst.textContent = 'De uitnodiging kan nu niet worden geladen. Probeer het later opnieuw.';
    }
}

// accepts with the given call, then goes to the gesprek of the uitnodiging
async function aannemen(neemAan) {
    fout.hidden = true;
    document.querySelectorAll('#uitnodiging button').forEach(knop => knop.disabled = true);
    try {
        const sessie = await neemAan();
        location.hash = huidig.gesprek ? `#/gesprekken/${encodeURIComponent(huidig.gesprek.id)}` : '#/';
        zetSessie(sessie);
    } catch (error) {
        fout.textContent = {
            401: 'Onjuiste gebruikersnaam of wachtwoord.',
            429: 'Te veel mislukte pogingen. Probeer het over een uur opnieuw.',
        }[error.status] ?? accountFout(error);
        fout.hidden = false;
    } finally {
        document.querySelectorAll('#uitnodiging button').forEach(knop => knop.disabled = false);
    }
}
