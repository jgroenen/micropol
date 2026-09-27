// Tabs in the ARIA tabs pattern, with the styles of design/tabs.css:
//   <div class="tab-lijst" role="tablist" aria-label="...">
//       <button role="tab" id="tab-x" aria-controls="paneel-x" data-tab="x">X</button>
//   </div>
//   <div class="tab-paneel" role="tabpanel" id="paneel-x" aria-labelledby="tab-x" tabindex="0">...</div>
// A click shows a tab, and the arrow keys move between them. A tab can be hidden (hidden on its button).

// the tabs of this tablist => { toon(naam) }; bijWissel(naam) is called whenever a tab is shown
export function maakTabs(lijst, bijWissel = () => {}) {
    const knoppen = [...lijst.querySelectorAll('[role="tab"]')];

    function toon(naam) {
        knoppen.forEach(knop => {
            const actief = knop.dataset.tab === naam;
            knop.setAttribute('aria-selected', String(actief));
            knop.tabIndex = actief ? 0 : -1;
            document.getElementById(knop.getAttribute('aria-controls')).hidden = !actief;
        });
        bijWissel(naam);
    }

    knoppen.forEach(knop => {
        knop.addEventListener('click', () => toon(knop.dataset.tab));
        knop.addEventListener('keydown', event => {
            const stap = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
            if (stap) {
                // hidden tabs are skipped
                const zichtbaar = knoppen.filter(k => !k.hidden);
                const volgende = zichtbaar[(zichtbaar.indexOf(knop) + stap + zichtbaar.length) % zichtbaar.length];
                toon(volgende.dataset.tab);
                volgende.focus();
            }
        });
    });

    return { toon };
}
