import { zetGesprekTitel } from './kop.js';
import { toonView } from './views.js';

// a gesprek that a superbeheerder paused (status opgeschort) or ended (beeindigd): a notice that fills the
// page, instead of the gesprek or its matrix (views/gesloten.html)
const TEKSTEN = {
    opgeschort: {
        titel: 'Dit gesprek is tijdelijk gepauzeerd',
        tekst: 'Je kunt nu even niet meedoen. Je antwoorden en stellingen blijven bewaard; kom later terug.',
    },
    beeindigd: {
        titel: 'Dit gesprek is voorbij',
        tekst: 'Je kunt niet meer meedoen. Bedankt voor je antwoorden en stellingen.',
    },
};

// whether the gesprek is closed for deelnemers
export function isGesloten(gesprek) {
    return gesprek.status in TEKSTEN;
}

export async function toonGesloten(gesprek) {
    zetGesprekTitel(gesprek.titel);
    await toonView('gesloten');
    const { titel, tekst } = TEKSTEN[gesprek.status];
    document.getElementById('gesloten-titel').textContent = titel;
    document.getElementById('gesloten-tekst').textContent = tekst;
    const sectie = document.getElementById('gesloten');
    // an svg has no hidden property, only the attribute
    sectie.querySelector('.icoon-gepauzeerd').toggleAttribute('hidden', gesprek.status !== 'opgeschort');
    sectie.querySelector('.icoon-voorbij').toggleAttribute('hidden', gesprek.status !== 'beeindigd');
}
