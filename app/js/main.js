import { toonGesprekken } from './gesprekken.js';
import { toonGesprek } from './gesprek.js';
import { toonMatrix } from './matrix.js';
import { nieuwePagina } from 'cdn/apilog.js';

// simple hash router so the back button works
const routes = [
    [/^#\/gesprekken\/([^/]+)\/matrix$/, id => toonMatrix(decodeURIComponent(id))],
    [/^#\/gesprekken\/([^/]+)$/, id => toonGesprek(decodeURIComponent(id))]
];

function route() {
    nieuwePagina();
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
