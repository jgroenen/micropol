import { nieuwePagina } from 'cdn/apilog.js';
import { verwerkTerugkeer, ingelogdeUser, uitloggen } from './auth.js';
import { ingelogdeUser as huidigeUser, zetUser, bijWijzigingVanUser } from './sessie.js';
import { toonInloggen } from './inloggen.js';
import { toonGesprekken } from './gesprekken.js';
import { toonGesprek } from './gesprek.js';

// simple hash router, like the app; without a logged in user every route shows the login page
//   #/                   overview of the gesprekken, with a button for a new one
//   #/gesprekken/<id>    one gesprek, to change it and to approve or reject its stellingen
const routes = [
    [/^#\/gesprekken\/([^/]+)$/, id => toonGesprek(decodeURIComponent(id))]
];

const ingelogd = document.getElementById('ingelogd');
let inlogMelding = null; // why the last login failed, shown once on the login page

function route() {
    const user = huidigeUser();
    ingelogd.hidden = !user;
    if (!user) {
        toonInloggen(inlogMelding);
        inlogMelding = null;
        return;
    }
    document.getElementById('ingelogd-als').textContent = user.username;
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
        zetUser(null);
    }
});

// logging in or out (or a login that ended) shows the view that fits
bijWijzigingVanUser(route);
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
    zetUser(await ingelogdeUser());
} catch (error) {
    console.error('Error loading admin:', error);
    zetUser(null);
}
