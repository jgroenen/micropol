import { nieuwePagina } from 'cdn/apilog.js';
import { verwerkTerugkeer, ingelogdeBeheerder as haalBeheerder, uitloggen } from './auth.js';
import { ingelogdeBeheerder, zetBeheerder, bijWijzigingVanBeheerder } from './sessie.js';
import { toonInloggen } from './inloggen.js';
import { toonGesprekken } from './gesprekken.js';
import { toonGesprek } from './gesprek.js';

// simple hash router, like the app; without a logged in beheerder every route shows the login page
//   #/                   overview of the gesprekken, with a button for a new one
//   #/gesprekken/<id>    one gesprek, to change it and to approve or reject its stellingen
const routes = [
    [/^#\/gesprekken\/([^/]+)$/, id => toonGesprek(decodeURIComponent(id))]
];

const ingelogd = document.getElementById('ingelogd');
let inlogMelding = null; // why the last login failed, shown once on the login page

function route() {
    const beheerder = ingelogdeBeheerder();
    ingelogd.hidden = !beheerder;
    if (!beheerder) {
        toonInloggen(inlogMelding);
        inlogMelding = null;
        return;
    }
    document.getElementById('ingelogd-als').textContent = beheerder.gebruikersnaam;
    for (const [pattern, toon] of routes) {
        const match = location.hash.match(pattern);
        if (match) {
            toon(...match.slice(1));
            return;
        }
    }
    toonGesprekken();
}

document.getElementById('uitloggen').addEventListener('click', async () => {
    try {
        await uitloggen();
    } catch (error) {
        console.error('Error logging out:', error);
    } finally {
        zetBeheerder(null);
    }
});

// logging in or out (or a login that ended) shows the view that fits
bijWijzigingVanBeheerder(route);
// the API popover starts over on every page change; logging in keeps the calls of the page
window.addEventListener('hashchange', () => {
    nieuwePagina();
    route();
});

// back from the login page of the auth service, or a normal page load
try {
    await verwerkTerugkeer();
} catch (error) {
    console.error(error);
    inlogMelding = 'Inloggen is niet gelukt. Probeer het opnieuw.';
}
try {
    zetBeheerder(await haalBeheerder());
} catch (error) {
    console.error('Error loading admin:', error);
    zetBeheerder(null);
}
