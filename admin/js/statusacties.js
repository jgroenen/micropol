import { escapeHtml } from 'cdn/html.js';

// Changing the status of someone (a lid of a team, a superbeheerder) or of a gesprek, in the rows of a list:
// the label of the status, buttons, and a form for the reden, which is required for everything but making
// it actief again. The api keeps the status as events (see Beheer in the api).

const NAMEN = { actief: 'Actief', opgeschort: 'Opgeschort', verwijderd: 'Verwijderd', beeindigd: 'Beëindigd' };

// per soort and status: the buttons, each with the status it gives
const ACTIES = {
    // a lid of a team or a superbeheerder: verwijderd takes the access away for good
    persoon: {
        actief: [['opgeschort', 'Opschorten'], ['verwijderd', 'Verwijderen']],
        opgeschort: [['actief', 'Herstellen'], ['verwijderd', 'Verwijderen']],
        verwijderd: [],
    },
    // a gesprek is never removed: it is paused, or ended when it is over, and both can be undone
    gesprek: {
        actief: [['opgeschort', 'Pauzeren'], ['beeindigd', 'Beëindigen']],
        opgeschort: [['actief', 'Hervatten'], ['beeindigd', 'Beëindigen']],
        beeindigd: [['actief', 'Heropenen']],
    },
};

// the label of a status, as html; with other names, like { opgeschort: 'Gepauzeerd' }
export function statusLabel(status, namen = {}) {
    return `<span class="label ${escapeHtml(status)}">${escapeHtml(namen[status] ?? NAMEN[status] ?? status)}</span>`;
}

// the buttons for a status, and the hidden form for the reden, as html; nothing when not allowed
// (like your own status) or when there is nothing to do (verwijderd)
export function statusActiesHtml(status, toegestaan = true, soort = 'persoon') {
    const acties = toegestaan ? ACTIES[soort][status] ?? [] : [];
    if (acties.length === 0) {
        return '';
    }
    return `
        <div class="acties">
            ${acties.map(([nieuw, tekst]) => `<button type="button" class="knop-klein" data-status="${nieuw}">${tekst}</button>`).join('')}
        </div>
        <form class="reden-formulier" hidden>
            <label>Reden
                <input name="reden" maxlength="500" required>
            </label>
            <button type="submit" class="knop-klein">Bevestigen</button>
            <button type="button" class="link-knop" data-annuleren>Annuleren</button>
        </form>
    `;
}

// handles the buttons in the rows of a list; wijzig(rij, status, reden) makes the change (a promise), and
// throws when it fails. Making it actief again needs no reden; the others ask for it first.
export function koppelStatusacties(lijst, wijzig) {
    lijst.addEventListener('click', event => {
        // only the buttons: the form keeps the status it is for in data-nieuwe-status
        const knop = event.target.closest('button[data-status], button[data-annuleren]');
        if (!knop) {
            return;
        }
        const rij = knop.closest('li, [data-rij]');
        const formulier = rij.querySelector('.reden-formulier');
        if (knop.hasAttribute('data-annuleren')) {
            formulier.hidden = true;
        } else if (knop.dataset.status === 'actief') {
            voerUit(rij, () => wijzig(rij, 'actief', ''));
        } else {
            formulier.dataset.nieuweStatus = knop.dataset.status;
            formulier.querySelector('[type=submit]').textContent = knop.textContent;
            formulier.hidden = false;
            formulier.reden.focus();
        }
    });
    lijst.addEventListener('submit', event => {
        event.preventDefault();
        const formulier = event.target;
        const rij = formulier.closest('li, [data-rij]');
        voerUit(rij, () => wijzig(rij, formulier.dataset.nieuweStatus, formulier.reden.value.trim()));
    });
}

async function voerUit(rij, actie) {
    const knoppen = rij.querySelectorAll('button');
    knoppen.forEach(knop => knop.disabled = true);
    try {
        await actie();
    } catch (error) {
        knoppen.forEach(knop => knop.disabled = false);
    }
}
