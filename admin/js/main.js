import { nieuwePagina } from 'cdn/apilog.js';
import { getUser, logout } from './api.js';
import { ingelogdeUser, zetUser, bijWijzigingVanUser } from './sessie.js';
import { toonInloggen } from './inloggen.js';
import { toonGesprekken } from './gesprekken.js';
import { toonGesprek } from './gesprek.js';

// simple hash router, like the app; without a logged in user every route shows the login form
//   #/                   overview of the gesprekken, with a button for a new one
//   #/gesprekken/<id>    one gesprek, to change it and to approve or reject its stellingen
const routes = [
    [/^#\/gesprekken\/([^/]+)$/, id => toonGesprek(decodeURIComponent(id))]
];

const ingelogd = document.getElementById('ingelogd');

function route() {
    const user = ingelogdeUser();
    ingelogd.hidden = !user;
    if (!user) {
        toonInloggen();
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
        await logout();
    } finally {
        zetUser(null);
    }
});

// logging in or out (or a session that ended) shows the view that fits
bijWijzigingVanUser(route);
// the API popover starts over on every page change; logging in keeps the calls of the page
window.addEventListener('hashchange', () => {
    nieuwePagina();
    route();
});

try {
    zetUser(await getUser());
} catch (error) {
    console.error('Error loading admin:', error);
    route();
}
