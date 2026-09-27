import { nieuwePagina } from 'cdn/apilog.js';
import { getSessie, logout } from './api.js';
import { ingelogdAccount, isInstallatie, isSuperbeheerder, zetSessie, bijWijzigingVanSessie } from './toegang.js';
import { toonInloggen } from './inloggen.js';
import { toonInstallatie } from './installatie.js';
import { toonUitnodiging } from './uitnodiging.js';
import { toonGesprekken } from './gesprekken.js';
import { toonGesprek } from './gesprek.js';
import { toonSuperbeheerders } from './superbeheerders.js';

// simple hash router, like the app
//   #/uitnodiging/<token>  an uitnodiging, also without logging in: to accept it with a new or an existing account
//   #/                     overview of the gesprekken of the account; superbeheerders make new ones here
//   #/gesprekken/<id>      one gesprek, with tabs for what the account may do in it
//   #/superbeheerders      the superbeheerders, for superbeheerders
// Right after installing, the login of admin/admin only shows the page to make the first superbeheerder;
// without a login every other route shows the login page.
const openRoutes = [
    [/^#\/uitnodiging\/([^/]+)$/, token => toonUitnodiging(decodeURIComponent(token))],
];
const routes = [
    [/^#\/gesprekken\/([^/]+)$/, id => toonGesprek(decodeURIComponent(id))],
    [/^#\/superbeheerders$/, () => toonSuperbeheerders()],
];

const ingelogd = document.getElementById('ingelogd');

function route() {
    // the page of a gesprek adds its title once it is loaded
    document.title = 'MiniPol beheer';
    const account = ingelogdAccount();
    ingelogd.hidden = !account && !isInstallatie();
    document.getElementById('ingelogd-als').textContent = account?.gebruikersnaam ?? 'admin';
    document.getElementById('beheer-menu').hidden = !account;
    document.getElementById('menu-superbeheerders').hidden = !isSuperbeheerder();
    markeerMenu();
    if (vindRoute(openRoutes)) {
        return;
    }
    if (isInstallatie()) {
        toonInstallatie();
        return;
    }
    if (!account) {
        toonInloggen();
        return;
    }
    if (!vindRoute(routes)) {
        toonGesprekken();
    }
}

// the menu item of the current page: superbeheerders, or else gesprekken (the overview and one gesprek)
function markeerMenu() {
    const superbeheerders = location.hash === '#/superbeheerders';
    const gesprekken = !superbeheerders && !location.hash.startsWith('#/uitnodiging/');
    document.getElementById('menu-gesprekken').toggleAttribute('aria-current', gesprekken);
    document.getElementById('menu-superbeheerders').toggleAttribute('aria-current', superbeheerders);
}

// shows the view of the first route that matches the hash; whether there was one
function vindRoute(lijst) {
    for (const [pattern, toon] of lijst) {
        const match = location.hash.match(pattern);
        if (match) {
            toon(...match.slice(1));
            return true;
        }
    }
    return false;
}

document.getElementById('uitloggen').addEventListener('click', async () => {
    try {
        await logout();
    } catch (error) {
        console.error('Error logging out:', error);
    } finally {
        zetSessie(null);
    }
});

// logging in or out (or a login that ended) shows the view that fits
bijWijzigingVanSessie(route);
// the API popover starts over on every page change; logging in keeps the calls of the page
window.addEventListener('hashchange', () => {
    nieuwePagina();
    route();
});

try {
    zetSessie(await getSessie());
} catch (error) {
    console.error('Error loading admin:', error);
    zetSessie(null);
}
