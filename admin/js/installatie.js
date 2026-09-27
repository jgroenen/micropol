import { postInstallatie } from './api.js';
import { zetSessie } from './toegang.js';
import { accountVeldenHtml, accountVelden, toonWachtwoordHints, accountFout } from './util.js';
import { toonView } from './views.js';

// right after installing, logged in as admin/admin: making the first superbeheerder (views/installatie.html)

let formulier, fout, knop;
let gekoppeld = false;

function koppel() {
    if (gekoppeld) {
        return;
    }
    formulier = document.getElementById('installatie-formulier');
    fout = document.getElementById('installatie-fout');
    knop = document.getElementById('installatie-knop');
    document.getElementById('installatie-velden').innerHTML = accountVeldenHtml('installatie');
    toonWachtwoordHints(formulier);
    formulier.addEventListener('submit', maak);
    gekoppeld = true;
}

export async function toonInstallatie() {
    try {
        await toonView('installatie');
        koppel();
        fout.hidden = true;
        formulier.gebruikersnaam.focus();
    } catch (error) {
        console.error('Error showing installatie:', error);
    }
}

// then main.js shows the overview, logged in as the new superbeheerder
async function maak(event) {
    event.preventDefault();
    fout.hidden = true;
    const velden = accountVelden(formulier, fout);
    if (!velden) {
        return;
    }
    knop.disabled = true;
    try {
        const sessie = await postInstallatie(velden);
        formulier.reset();
        location.hash = '#/';
        zetSessie(sessie);
    } catch (error) {
        fout.textContent = accountFout(error);
        fout.hidden = false;
    } finally {
        knop.disabled = false;
    }
}
