import { getSuperbeheerders, postSuperbeheerderstatus } from './api.js';
import { ingelogdAccount, isSuperbeheerder, verwerkFout } from './toegang.js';
import { escapeHtml } from 'cdn/html.js';
import { statusLabel, statusActiesHtml, koppelStatusacties } from './statusacties.js';
import { koppelUitnodigen, uitnodigenHtml, wisUitnodiging } from './uitnodigen.js';
import { toonView } from './views.js';

// the superbeheerders (views/superbeheerders.html), for superbeheerders: pausing, restoring and removing
// them, and inviting new ones

let lijst, fout, uitnodigen;
let gekoppeld = false;

function koppel() {
    if (gekoppeld) {
        return;
    }
    lijst = document.getElementById('superbeheerders-lijst');
    fout = document.getElementById('superbeheerders-fout');
    uitnodigen = document.getElementById('superbeheerders-uitnodigen');
    uitnodigen.innerHTML = uitnodigenHtml({ titel: 'Nieuwe superbeheerder uitnodigen' });
    koppelUitnodigen(uitnodigen, () => ({ rol: 'superbeheerder' }));
    koppelStatusacties(lijst, wijzig);
    gekoppeld = true;
}

let superbeheerders = [];

export async function toonSuperbeheerders() {
    try {
        await toonView('superbeheerders');
        koppel();
        fout.hidden = true;
        wisUitnodiging(uitnodigen);
        if (!isSuperbeheerder()) {
            lijst.innerHTML = '';
            fout.textContent = 'Deze pagina is alleen voor superbeheerders.';
            fout.hidden = false;
            uitnodigen.hidden = true;
            return;
        }
        uitnodigen.hidden = false;
        superbeheerders = await getSuperbeheerders();
        tekenLijst();
    } catch (error) {
        verwerkFout(error);
    }
}

function tekenLijst() {
    const ik = ingelogdAccount()?.id;
    lijst.innerHTML = superbeheerders.map(s => `
        <li data-id="${escapeHtml(s.id)}">
            <p class="rij-kop"><strong>${escapeHtml(s.gebruikersnaam)}</strong> ${escapeHtml(s.email)} ${statusLabel(s.status)}${s.id === ik ? ' <span class="rij-noot">(jij)</span>' : ''}</p>
            ${statusActiesHtml(s.status, s.id !== ik)}
        </li>
    `).join('');
}

async function wijzig(rij, status, reden) {
    fout.hidden = true;
    try {
        const nieuw = await postSuperbeheerderstatus({ account_id: rij.dataset.id, status, reden });
        superbeheerders = superbeheerders.map(s => s.id === nieuw.id ? nieuw : s);
        tekenLijst();
    } catch (error) {
        fout.textContent = 'De status kon niet worden veranderd. Probeer het opnieuw.';
        verwerkFout(error, fout);
        throw error;
    }
}
