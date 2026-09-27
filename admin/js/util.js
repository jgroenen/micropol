// { titel, omschrijving, moderatie, zonder_kanaal } from the form of a new or an existing gesprek;
// zonder_kanaal only when the form has it
export function gesprekVelden(form) {
    const velden = {
        titel: form.titel.value.trim(),
        omschrijving: form.omschrijving.value.trim(),
        moderatie: form.moderatie.value,
    };
    if (form.zonder_kanaal) {
        velden.zonder_kanaal = form.zonder_kanaal.checked;
    }
    return velden;
}

// the fields of a new account, for the html of a form (installatie, uitnodiging); ids start with the prefix
export function accountVeldenHtml(prefix) {
    return `
        <label for="${prefix}-gebruikersnaam">Gebruikersnaam <span>(letters, cijfers, punt, streepje)</span></label>
        <input id="${prefix}-gebruikersnaam" name="gebruikersnaam" autocomplete="username" maxlength="64" pattern="[A-Za-z0-9._\\-]+" required>
        <label for="${prefix}-email">E-mailadres</label>
        <input id="${prefix}-email" name="email" type="email" autocomplete="email" required>
        <label for="${prefix}-wachtwoord">Wachtwoord <span>(minstens 12 tekens)</span></label>
        <input id="${prefix}-wachtwoord" name="wachtwoord" type="password" autocomplete="new-password" minlength="${MIN_WACHTWOORD}" required aria-describedby="${prefix}-wachtwoord-hint">
        <p id="${prefix}-wachtwoord-hint" class="veld-hint" aria-live="polite"></p>
        <label for="${prefix}-herhaal">Wachtwoord nogmaals</label>
        <input id="${prefix}-herhaal" name="herhaal" type="password" autocomplete="new-password" minlength="${MIN_WACHTWOORD}" required aria-describedby="${prefix}-herhaal-hint">
        <p id="${prefix}-herhaal-hint" class="veld-hint" aria-live="polite"></p>
    `;
}

// as Beheer::MIN_WACHTWOORD in the api
const MIN_WACHTWOORD = 12;

// while typing, below the wachtwoord: how many characters are still needed, and whether the second one is the
// same; call once, after the fields of accountVeldenHtml() are in the form
export function toonWachtwoordHints(form) {
    const hint = document.getElementById(form.wachtwoord.getAttribute('aria-describedby'));
    const herhaalHint = document.getElementById(form.herhaal.getAttribute('aria-describedby'));
    const toon = () => {
        // characters as the api counts them (mb_strlen), not UTF-16 units
        const nog = MIN_WACHTWOORD - [...form.wachtwoord.value].length;
        const goed = nog <= 0;
        hint.textContent = form.wachtwoord.value === '' ? ''
            : goed ? '✓ Lang genoeg'
            : `Nog ${nog} ${nog === 1 ? 'teken' : 'tekens'}`;
        hint.classList.toggle('goed', goed);
        const gelijk = form.herhaal.value === form.wachtwoord.value;
        herhaalHint.textContent = form.herhaal.value === '' ? '' : gelijk ? '✓ Gelijk' : 'Nog niet gelijk';
        herhaalHint.classList.toggle('goed', gelijk);
    };
    form.wachtwoord.addEventListener('input', toon);
    form.herhaal.addEventListener('input', toon);
    // after form.reset()
    form.addEventListener('reset', () => setTimeout(toon));
}

// { gebruikersnaam, email, wachtwoord } from such a form, or null (with the reason in fout) when the two
// wachtwoorden differ
export function accountVelden(form, fout) {
    if (form.wachtwoord.value !== form.herhaal.value) {
        fout.textContent = 'De wachtwoorden zijn niet gelijk.';
        fout.hidden = false;
        return null;
    }
    return {
        gebruikersnaam: form.gebruikersnaam.value.trim(),
        email: form.email.value.trim(),
        wachtwoord: form.wachtwoord.value,
    };
}

// the message for a failed new account: the gebruikersnaam in use (409), or fields that are not right (400)
export function accountFout(error) {
    if (error.status === 409) {
        return 'Deze gebruikersnaam is al in gebruik. Kies een andere.';
    }
    if (error.status === 400) {
        return 'Controleer de velden: een geldig e-mailadres, en een wachtwoord van minstens 12 tekens.';
    }
    return 'Dat lukt nu niet. Probeer het later opnieuw.';
}

// how a rol is called on screen
export const ROLNAMEN = {
    superbeheerder: 'superbeheerder',
    gespreksbeheerder: 'gespreksbeheerder',
    moderator: 'moderator',
};
