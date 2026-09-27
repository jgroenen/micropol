// The header of the page (views/pagina.html) and the title of the browser tab: MiniPol, and on the pages
// of a gesprek its title too, as "MiniPol | <titel>"

const NAAM = 'MiniPol';

// the title of the gesprek shown, or null on the other pages
export function zetGesprekTitel(titel) {
    const gesprek = document.getElementById('site-gesprek');
    gesprek.textContent = titel ?? '';
    gesprek.hidden = !titel;
    document.title = titel ? `${NAAM} | ${titel}` : NAAM;
}
