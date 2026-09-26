// Views of an interface: each view is a <section id="<id>" class="view"> in <map>/<id>.html. It is fetched
// and placed in <main> the first time it is shown, unless it is already in the page. Shared by the app
// and the admin; bijWissel runs whenever another view is shown (the app hides its tooltip then).
export function maakViews(map = '/views', bijWissel = () => {}) {
    const main = document.querySelector('main');
    const laden = new Map(); // id => Promise of the view element
    let gevraagd = null;     // the view that should be visible, for when loads finish out of order

    // the view element, placed in the page first if needed
    function laadView(id) {
        if (!laden.has(id)) {
            const belofte = (async () => {
                const bestaand = document.getElementById(id);
                if (bestaand) {
                    return bestaand;
                }
                const response = await fetch(`${map}/${encodeURIComponent(id)}.html`);
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
    async function toonView(id) {
        gevraagd = id;
        bijWissel();
        const view = await laadView(id);
        // only switch if no other view was asked for in the meantime
        if (gevraagd === id) {
            main.querySelectorAll(':scope > .view').forEach(element => {
                element.hidden = element !== view;
            });
        }
        return view;
    }

    return { laadView, toonView };
}
