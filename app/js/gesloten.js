import { zetGesprekTitel } from './kop.js';
import { toonView } from './views.js';

// a gesprek that a superbeheerder paused (status opgeschort) or ended (beeindigd), or that this deelnemer
// can only take part in through a link: a notice that fills the page, instead of the gesprek or its matrix
// (views/gesloten.html)
const TEKSTEN = {
    opgeschort: {
        titel: 'Dit gesprek is tijdelijk gepauzeerd',
        tekst: 'Je kunt nu even niet meedoen. Je antwoorden en stellingen blijven bewaard; kom later terug.',
    },
    beeindigd: {
        titel: 'Dit gesprek is voorbij',
        tekst: 'Je kunt niet meer meedoen. Bedankt voor je antwoorden en stellingen.',
    },
    // not a status: the gesprek only takes part through the link of a kanaal, and this deelnemer has none
    'alleen-via-link': {
        titel: 'Meedoen kan alleen via een link',
        tekst: 'Aan dit gesprek doe je mee via de link die je van de organisatie kreeg, bijvoorbeeld in een nieuwsbrief of een uitnodiging.',
    },
};

// whether the gesprek is closed for deelnemers
export function isGesloten(gesprek) {
    return gesprek.status in TEKSTEN;
}

// soort: the status, or 'alleen-via-link'
export async function toonGesloten(gesprek, soort = gesprek.status) {
    zetGesprekTitel(gesprek.titel);
    await toonView('gesloten');
    const { titel, tekst } = TEKSTEN[soort];
    document.getElementById('gesloten-titel').textContent = titel;
    document.getElementById('gesloten-tekst').textContent = tekst;
    const sectie = document.getElementById('gesloten');
    // an svg has no hidden property, only the attribute
    sectie.querySelector('.icoon-gepauzeerd').toggleAttribute('hidden', soort !== 'opgeschort');
    sectie.querySelector('.icoon-voorbij').toggleAttribute('hidden', soort !== 'beeindigd');
}
