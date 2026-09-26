import { inloggen } from './auth.js';
import { toonView } from './views.js';

// elements of views/inloggen.html, set by koppel() once the view is in the page
let fout, knop;
let gekoppeld = false;

function koppel() {
    if (gekoppeld) {
        return;
    }
    fout = document.getElementById('inlog-fout');
    knop = document.getElementById('inlog-knop');
    knop.addEventListener('click', naarInlogpagina);
    gekoppeld = true;
}

// the page with the button to the login page of the auth service; melding: why logging in failed, if it did
export async function toonInloggen(melding = null) {
    try {
        await toonView('inloggen');
        koppel();
        fout.textContent = melding ?? '';
        fout.hidden = !melding;
        knop.disabled = false;
        knop.focus();
    } catch (error) {
        console.error('Error showing login:', error);
    }
}

async function naarInlogpagina() {
    knop.disabled = true;
    fout.hidden = true;
    try {
        await inloggen(); // leaves this page
    } catch (error) {
        console.error('Error starting login:', error);
        fout.textContent = 'De inlogpagina is nu niet bereikbaar. Probeer het later opnieuw.';
        fout.hidden = false;
        knop.disabled = false;
    }
}
