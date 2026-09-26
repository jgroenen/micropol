// Each view is a <section id="<id>" class="view"> in views/<id>.html. It is fetched and placed
// in <main> the first time it is shown, unless it is already in the page. Same as js/views.js of the app.

const main = document.querySelector('main');
const laden = new Map(); // id => Promise of the view element
let gevraagd = null;     // the view that should be visible, for when loads finish out of order

// the view element, placed in the page first if needed
export function laadView(id) {
    if (!laden.has(id)) {
        const belofte = (async () => {
            const bestaand = document.getElementById(id);
            if (bestaand) {
                return bestaand;
            }
            const response = await fetch(`/views/${encodeURIComponent(id)}.html`);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status} for view ${id}`);
            }
            main.insertAdjacentHTML('beforeend', await response.text());
            return document.getElementById(id);
        })();
        // a failed load may be tried again next time
        belofte.catch(() => laden.delete(id));
        laden.set(id, belofte);
    }
    return laden.get(id);
}

// show one view, hide the others; returns the view element
export async function toonView(id) {
    gevraagd = id;
    const view = await laadView(id);
    // only switch if no other view was asked for in the meantime
    if (gevraagd === id) {
        main.querySelectorAll(':scope > .view').forEach(element => {
            element.hidden = element !== view;
        });
    }
    return view;
}
