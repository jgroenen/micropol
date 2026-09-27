// The kanaal a deelnemer came with: a gesprek can have links per promotion channel, like
// <app>/#/gesprekken/<id>?kanaal=<token>. The app keeps the token per gesprek in localStorage and sends it
// along with every antwoord and stelling (see Data::kanaalVelden in the api).

const SLEUTEL = 'kanaal:';

// takes the kanaal out of the link of a gesprek and keeps it; the address bar then shows the link without it,
// so a deelnemer who shares the page does not share the kanaal. Returns the hash without the kanaal.
export function neemKanaalUitLink(hash) {
    const [pad, zoek = ''] = hash.split('?');
    const token = new URLSearchParams(zoek).get('kanaal');
    const gesprek = pad.match(/^#\/gesprekken\/([^/]+)/);
    if (!token || !gesprek) {
        return hash;
    }
    bewaar(decodeURIComponent(gesprek[1]), token);
    history.replaceState(null, '', pad);
    return pad;
}

// the token of the kanaal of the gesprek, or null
export function kanaalVan(gesprekId) {
    try {
        return localStorage.getItem(SLEUTEL + gesprekId);
    } catch (error) {
        return null;
    }
}

// when the link of the kanaal works no more
export function vergeetKanaal(gesprekId) {
    bewaar(gesprekId, null);
}

function bewaar(gesprekId, token) {
    try {
        if (token) {
            localStorage.setItem(SLEUTEL + gesprekId, token);
        } else {
            localStorage.removeItem(SLEUTEL + gesprekId);
        }
    } catch (error) {}
}
