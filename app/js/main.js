import { toonGesprekken } from './gesprekken.js';
import { toonGesprek } from './gesprek.js';
import { toonMatrix } from './matrix.js';
import { nieuwePagina } from 'cdn/apilog.js';
import { zetGesprekTitel } from './kop.js';

// simple hash router so the back button works
const routes = [
    [/^#\/gesprekken\/([^/]+)\/matrix$/, id => toonMatrix(decodeURIComponent(id))],
    [/^#\/gesprekken\/([^/]+)$/, id => toonGesprek(decodeURIComponent(id))]
];

function route() {
    nieuwePagina();
    // the page of a gesprek sets its title once it is loaded
    zetGesprekTitel(null);
    for (const [pattern, toon] of routes) {
        const match = location.hash.match(pattern);
        if (match) {
            toon(...match.slice(1));
            return;
        }
    }
    toonGesprekken();
}

window.addEventListener('hashchange', route);
route();
