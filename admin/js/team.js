import { getTeam, postTeamstatus } from './api.js';
import { ingelogdAccount, isSuperbeheerder, verwerkFout } from './toegang.js';
import { escapeHtml } from 'cdn/html.js';
import { statusLabel, statusActiesHtml, koppelStatusacties } from './statusacties.js';
import { koppelUitnodigen, uitnodigenHtml, wisUitnodiging } from './uitnodigen.js';

// the team of a gesprek, in its tab on the page of the gesprek (views/gesprek.html), for superbeheerders
// and its gespreksbeheerders: pausing, restoring and removing leden, and inviting new ones

let lijst, leeg, fout, uitnodigen;
let gekoppeld = false;

function koppel() {
    if (gekoppeld) {
        return;
    }
    lijst = document.getElementById('team-lijst');
    leeg = document.getElementById('team-leeg');
    fout = document.getElementById('team-fout');
    uitnodigen = document.getElementById('team-uitnodigen');
    uitnodigen.innerHTML = uitnodigenHtml({
        titel: 'Nieuw teamlid uitnodigen',
        uitleg: 'Een moderator keurt stellingen goed of af. Een gespreksbeheerder kan ook de gegevens aanpassen en het team beheren.',
        rollen: [['moderator', 'moderator'], ['gespreksbeheerder', 'gespreksbeheerder']],
    });
    koppelUitnodigen(uitnodigen, () => ({ gesprek_id: gesprekId }));
    koppelStatusacties(lijst, wijzig);
    gekoppeld = true;
}

let gesprekId = null;
let leden = [];

// loads and shows the team of a gesprek; errors are for the caller
export async function toonTeam(id) {
    koppel();
    if (gesprekId !== id) {
        wisUitnodiging(uitnodigen);
    }
    gesprekId = id;
    fout.hidden = true;
    const geladen = await getTeam(id);
    if (gesprekId !== id) {
        return;
    }
    leden = geladen;
    tekenLijst();
}

// your own status only as superbeheerder: a gespreksbeheerder would lock himself out
function tekenLijst() {
    const ik = ingelogdAccount()?.id;
    const superbeheerder = isSuperbeheerder();
    lijst.innerHTML = leden.map(lid => `
        <li data-id="${escapeHtml(lid.account_id)}">
            <p class="rij-kop"><strong>${escapeHtml(lid.gebruikersnaam)}</strong> ${escapeHtml(lid.email)}
                <span class="label">${escapeHtml(lid.rol)}</span>${lid.status !== 'actief' ? statusLabel(lid.status) : ''}${lid.account_id === ik ? ' <span class="rij-noot">(jij)</span>' : ''}</p>
            ${statusActiesHtml(lid.status, lid.account_id !== ik || superbeheerder)}
        </li>
    `).join('');
    lijst.hidden = leden.length === 0;
    leeg.hidden = leden.length > 0;
}

async function wijzig(rij, status, reden) {
    fout.hidden = true;
    try {
        const nieuw = await postTeamstatus({ gesprek_id: gesprekId, account_id: rij.dataset.id, status, reden });
        leden = leden.map(lid => lid.account_id === nieuw.account_id ? nieuw : lid);
        tekenLijst();
    } catch (error) {
        fout.textContent = 'De status kon niet worden veranderd. Probeer het opnieuw.';
        verwerkFout(error, fout);
        throw error;
    }
}
