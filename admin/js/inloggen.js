import { login } from './api.js';
import { zetSessie } from './toegang.js';
import { toonView } from './views.js';

// elements of views/inloggen.html, set by koppel() once the view is in the page
let formulier, fout, knop;
let gekoppeld = false;

function koppel() {
    if (gekoppeld) {
        return;
    }
    formulier = document.getElementById('inlog-formulier');
    fout = document.getElementById('inlog-fout');
    knop = document.getElementById('inlog-knop');
    formulier.addEventListener('submit', logIn);
    gekoppeld = true;
}

export async function toonInloggen() {
    try {
        await toonView('inloggen');
        koppel();
        formulier.gebruikersnaam.focus();
    } catch (error) {
        console.error('Error showing login:', error);
    }
}

// after logging in, main.js shows the view of the current hash
async function logIn(event) {
    event.preventDefault();
    fout.hidden = true;
    knop.disabled = true;
    try {
        const sessie = await login(formulier.gebruikersnaam.value.trim(), formulier.wachtwoord.value);
        formulier.reset();
        zetSessie(sessie);
    } catch (error) {
        fout.textContent = {
            401: 'Onjuiste gebruikersnaam of wachtwoord.',
            429: 'Te veel mislukte pogingen. Probeer het over een uur opnieuw.',
        }[error.status] ?? 'Inloggen lukt nu niet. Probeer het later opnieuw.';
        fout.hidden = false;
    } finally {
        knop.disabled = false;
    }
}
