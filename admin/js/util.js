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
        <input id="${prefix}-wachtwoord" name="wachtwoord" type="password" autocomplete="new-password" minlength="12" required>
        <label for="${prefix}-herhaal">Wachtwoord nogmaals</label>
        <input id="${prefix}-herhaal" name="herhaal" type="password" autocomplete="new-password" minlength="12" required>
    `;
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
