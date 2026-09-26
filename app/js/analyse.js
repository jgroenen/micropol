import { escapeHtml } from 'cdn/util.js';

// Scatter plot of the PCA + K-means result from GET /analyse, and placing the current deelnemer with its model.
// Groups are shown by color (A-C, the colors that stay distinguishable in a scatter plot),
// by shape (all groups) and by a letter at the center of each group, never by color alone.

const NAMEN = ['A', 'B', 'C', 'D', 'E'];

// marker shapes around (0, 0), about 10px
const VORMEN = [
    'M5 0A5 5 0 1 1-5 0A5 5 0 1 1 5 0Z',                                                   // circle
    'M-4.5-4.5H4.5V4.5H-4.5Z',                                                              // square
    'M0-5.5L5.5 4.5H-5.5Z',                                                                 // triangle
    'M0-6L6 0 0 6-6 0Z',                                                                    // diamond
    'M-1.8-5.5H1.8V-1.8H5.5V1.8H1.8V5.5H-1.8V1.8H-5.5V-1.8H-1.8Z',                          // plus
];

const WAARDEN = { eens: 1, neutraal: 0, oneens: -1 };

// place one deelnemer with the model from GET /analyse, the same calculation as Analyse::plaats() in PHP:
// { x, y, groep, antwoorden }, or null without antwoorden on the stellingen in the model;
// x and y are the first two components (the plot), the group is the nearest center over all components
export function plaats(model, antwoorden) {
    if (!model || model.componenten.length < 2) {
        return null;
    }
    const m = model.stellingen.length;
    let aantal = 0;
    const punt = model.componenten.map(() => 0);
    model.stellingen.forEach((stellingId, j) => {
        const waarde = WAARDEN[antwoorden[stellingId]];
        if (waarde === undefined) {
            return; // not answered: counts as the mean, adds 0
        }
        aantal++;
        const afwijking = waarde - model.gemiddelden[j];
        model.componenten.forEach((component, c) => {
            punt[c] += afwijking * component[j];
        });
    });
    if (aantal === 0) {
        return null;
    }
    const schaal = Math.sqrt(m / aantal);
    const geschaald = punt.map(x => x * schaal);

    const afstand2 = centrum => centrum.reduce((som, c, d) => som + (geschaald[d] - c) ** 2, 0);
    let groep = null;
    model.centra.forEach((centrum, g) => {
        if (groep === null || afstand2(centrum) < afstand2(model.centra[groep])) {
            groep = g;
        }
    });
    return { x: geschaald[0], y: geschaald[1], groep, antwoorden: aantal };
}

export function groepNaam(groep) {
    return NAMEN[groep];
}

// small inline marker for legends and table rows
export function groepMarker(groep) {
    return `<svg class="groep-marker groep-${groep + 1}" width="14" height="14" viewBox="-7 -7 14 14" aria-hidden="true"><path d="${VORMEN[groep]}"/></svg>`;
}

// draws the plot into container, with the current deelnemer (result of plaats(), or null) highlighted;
// returns false if there is nothing to show
export function tekenPlot(container, analyse, jij = null) {
    const punten = analyse.deelnemers;
    if (punten.length === 0 && !jij) {
        container.innerHTML = '';
        return false;
    }

    const breedte = 600;
    const hoogte = 380;
    const marge = 32;

    // same scale on both axes, so distances in the plot mean the same in every direction
    const alle = jij ? [...punten, jij] : punten;
    const xs = alle.map(p => p.x);
    const ys = alle.map(p => p.y);
    const [minX, maxX, minY, maxY] = [Math.min(...xs), Math.max(...xs), Math.min(...ys), Math.max(...ys)];
    const schaal = Math.min(
        (breedte - 2 * marge) / Math.max(maxX - minX, 1e-9),
        (hoogte - 2 * marge) / Math.max(maxY - minY, 1e-9),
        200 // a few points close together should not fill the whole plot
    );
    const midX = (minX + maxX) / 2;
    const midY = (minY + maxY) / 2;
    const px = x => breedte / 2 + (x - midX) * schaal;
    const py = y => hoogte / 2 - (y - midY) * schaal;

    // axes through the origin (the average deelnemer), when in view
    const assen = [];
    if (px(0) > 0 && px(0) < breedte) {
        assen.push(`<line class="as" x1="${px(0)}" y1="0" x2="${px(0)}" y2="${hoogte}"/>`);
    }
    if (py(0) > 0 && py(0) < hoogte) {
        assen.push(`<line class="as" x1="0" y1="${py(0)}" x2="${breedte}" y2="${py(0)}"/>`);
    }
    const [v1, v2] = analyse.verklaarde_variantie.map(v => Math.round(v * 100));

    const markers = punten.map(p => {
        const groep = p.groep;
        const tekst = groep === null
            ? `Deelnemer ${p.deelnemer} · ${p.antwoorden} antwoorden`
            : `Deelnemer ${p.deelnemer} · Groep ${NAMEN[groep]} · ${p.antwoorden} antwoorden`;
        return `
            <g class="punt ${groep === null ? 'zonder-groep' : `groep-${groep + 1}`}" transform="translate(${px(p.x).toFixed(1)} ${py(p.y).toFixed(1)})"
               tabindex="0" role="img" aria-label="${escapeHtml(tekst)}" data-tooltip="${escapeHtml(tekst)}">
                <circle class="raakvlak" r="12"/>
                <path class="vorm" d="${VORMEN[groep ?? 0]}"/>
            </g>`;
    }).join('');

    // group letter above the center of each group
    const letters = analyse.groepen.map((g, groep) => {
        const leden = punten.filter(p => p.groep === groep);
        if (leden.length === 0) {
            return '';
        }
        const cx = leden.reduce((som, p) => som + px(p.x), 0) / leden.length;
        const cy = leden.reduce((som, p) => som + py(p.y), 0) / leden.length;
        return `<text class="groep-letter" x="${cx.toFixed(1)}" y="${(cy - 16).toFixed(1)}">${NAMEN[groep]}</text>`;
    }).join('');

    // the current deelnemer on top: a ring around its own marker, with a label
    let jijMarker = '';
    if (jij) {
        const tekst = jij.groep === null ? 'Jij' : `Jij · Groep ${NAMEN[jij.groep]}`;
        jijMarker = `
            <g class="punt jij ${jij.groep === null ? 'zonder-groep' : `groep-${jij.groep + 1}`}" transform="translate(${px(jij.x).toFixed(1)} ${py(jij.y).toFixed(1)})"
               tabindex="0" role="img" aria-label="${escapeHtml(tekst)}" data-tooltip="${escapeHtml(tekst)} · ${jij.antwoorden} antwoorden">
                <circle class="raakvlak" r="14"/>
                <circle class="jij-ring" r="11"/>
                <path class="vorm" d="${VORMEN[jij.groep ?? 0]}"/>
                <text class="jij-label" y="-17">jij</text>
            </g>`;
    }

    container.innerHTML = `
        <svg viewBox="0 0 ${breedte} ${hoogte}" role="group" aria-label="Deelnemers op twee assen, gegroepeerd">
            ${assen.join('')}
            <text class="as-label" x="${breedte - 6}" y="${hoogte - 8}" text-anchor="end">as 1 · ${v1}% van de verschillen</text>
            <text class="as-label" x="6" y="16">as 2 · ${v2}%</text>
            ${markers}
            ${letters}
            ${jijMarker}
        </svg>
    `;
    return true;
}

// What the groups say about the stellingen, from the counts per group in GET /analyse:
// - kenmerkend: a group almost unanimous (>= 90%) where the others clearly are not
// - eensgezind: a group mostly (>= 70%) giving the same antwoord
// - consensus: every group mostly (>= 70%) giving the same antwoord
// (the same kenmerkend rule is used in api/lib/Analyse.php to choose K; keep them alike)
const KENMERKEND_DREMPEL = 0.9;         // share of the group's answers
const KENMERKEND_VERSCHIL = 0.3;        // the rest at least this much lower
const EENSGEZIND_DREMPEL = 0.7;         // share of the answers, also per group for consensus
const MIN_ANTWOORDEN = 3;               // at least this many answers...
const MIN_DEEL = 0.2;                   // ...from at least this share of the deelnemers counted
const MAX_PER_LIJST = 5;
const KANTEN = ['eens', 'oneens'];

// { eens, neutraal, oneens, totaal } of a stelling, for the given groups together
function telling(analyse, groepen, stellingId) {
    const som = { eens: 0, neutraal: 0, oneens: 0, totaal: 0, deelnemers: 0 };
    groepen.forEach(g => {
        const t = analyse.groepen[g].stellingen[stellingId];
        som.deelnemers += analyse.groepen[g].deelnemers;
        if (t) {
            som.eens += t.eens;
            som.neutraal += t.neutraal;
            som.oneens += t.oneens;
        }
    });
    som.totaal = som.eens + som.neutraal + som.oneens;
    return som;
}

// enough answers to say something?
function genoeg(t) {
    return t.totaal >= Math.max(MIN_ANTWOORDEN, Math.ceil(t.deelnemers * MIN_DEEL));
}

export function uitlegOnderscheid() {
    return `Per groep eerst wat kenmerkend is: stellingen waar minstens ${KENMERKEND_DREMPEL * 100}% van de groep hetzelfde antwoordt, `
        + `en de andere deelnemers minstens ${KENMERKEND_VERSCHIL * 100} procentpunt minder.`;
}

export function uitlegConsensus() {
    return `Stellingen waar in elke groep minstens ${EENSGEZIND_DREMPEL * 100}% hetzelfde antwoordt.`;
}

// per group: [{ nummer, stelling, kant, aandeel, rest, aantal }], largest difference with the rest first
export function kenmerkend(analyse, stellingen) {
    const alle = analyse.groepen.map((groep, g) => g);
    return alle.map(g => {
        const anderen = alle.filter(a => a !== g);
        const items = [];
        stellingen.forEach((stelling, i) => {
            const groep = telling(analyse, [g], stelling.id);
            const rest = telling(analyse, anderen, stelling.id);
            if (!genoeg(groep) || !genoeg(rest)) {
                return;
            }
            KANTEN.forEach(kant => {
                const aandeel = groep[kant] / groep.totaal;
                const restAandeel = rest[kant] / rest.totaal;
                if (aandeel >= KENMERKEND_DREMPEL && aandeel - restAandeel >= KENMERKEND_VERSCHIL) {
                    items.push({ nummer: i + 1, stelling, kant, aandeel, rest: restAandeel, aantal: groep.totaal });
                }
            });
        });
        items.sort((a, b) => (b.aandeel - b.rest) - (a.aandeel - a.rest));
        return items.slice(0, MAX_PER_LIJST);
    });
}

// per group: { eens: [...], oneens: [...] }, each item { nummer, stelling, kant, aandeel, aantal }, strongest first
export function eensgezind(analyse, stellingen) {
    return analyse.groepen.map((groep, g) => {
        const resultaat = { eens: [], oneens: [] };
        stellingen.forEach((stelling, i) => {
            const t = telling(analyse, [g], stelling.id);
            if (!genoeg(t)) {
                return;
            }
            KANTEN.forEach(kant => {
                const aandeel = t[kant] / t.totaal;
                if (aandeel >= EENSGEZIND_DREMPEL) {
                    resultaat[kant].push({ nummer: i + 1, stelling, kant, aandeel, aantal: t.totaal });
                }
            });
        });
        KANTEN.forEach(kant => {
            resultaat[kant].sort((a, b) => b.aandeel - a.aandeel || b.aantal - a.aantal);
            resultaat[kant] = resultaat[kant].slice(0, MAX_PER_LIJST);
        });
        return resultaat;
    });
}

// [{ nummer, stelling, kant, aandelen: [per group], laagste }], most shared first
export function consensus(analyse, stellingen) {
    const items = [];
    stellingen.forEach((stelling, i) => {
        const tellingen = analyse.groepen.map((groep, g) => telling(analyse, [g], stelling.id));
        if (tellingen.length < 2 || !tellingen.every(genoeg)) {
            return;
        }
        KANTEN.forEach(kant => {
            const aandelen = tellingen.map(t => t[kant] / t.totaal);
            const laagste = Math.min(...aandelen);
            if (laagste >= EENSGEZIND_DREMPEL) {
                items.push({ nummer: i + 1, stelling, kant, aandelen, laagste });
            }
        });
    });
    return items.sort((a, b) => b.laagste - a.laagste);
}

function stellingRegel(item, extra = '') {
    return `
        <li title="${item.aantal ? `${item.aantal} groepsleden beantwoordden deze stelling` : ''}">
            <span class="bolletje ${item.kant}" role="img" aria-label="${item.kant === 'eens' ? 'Eens' : 'Oneens'}"></span>
            <span class="aandeel">${Math.round((item.aandeel ?? item.laagste) * 100)}%</span>
            <span class="nummer">S${item.nummer}</span>
            <span class="tekst">${escapeHtml(item.stelling.tekst)}${extra}</span>
        </li>`;
}

// one card per group: what is kenmerkend, and (folded) what it mostly agrees and disagrees with
export function tekenGroepKaarten(container, analyse, stellingen) {
    const perGroepKenmerkend = kenmerkend(analyse, stellingen);
    const perGroepEensgezind = eensgezind(analyse, stellingen);

    const lijst = (items, leeg, extra) => items.length === 0
        ? `<p class="geen">${leeg}</p>`
        : `<ul>${items.map(item => stellingRegel(item, extra ? extra(item) : '')).join('')}</ul>`;

    container.innerHTML = analyse.groepen.map((groep, g) => `
        <article class="groep-kaart">
            <h4>${groepMarker(g)} Groep ${escapeHtml(groep.naam)} <span>${groep.deelnemers} ${groep.deelnemers === 1 ? 'deelnemer' : 'deelnemers'}</span></h4>
            <h5>Kenmerkend</h5>
            ${lijst(perGroepKenmerkend[g], 'Niets waar deze groep bijna unaniem anders over denkt dan de rest.',
                item => ` <span class="rest">rest ${Math.round(item.rest * 100)}%</span>`)}
            <details>
                <summary>Verder eens of oneens (≥ ${EENSGEZIND_DREMPEL * 100}%)</summary>
                <h5>Eens met</h5>
                ${lijst(perGroepEensgezind[g].eens, 'Geen duidelijke overeenstemming.')}
                <h5>Oneens met</h5>
                ${lijst(perGroepEensgezind[g].oneens, 'Geen duidelijke overeenstemming.')}
            </details>
        </article>
    `).join('');
}

// stellingen every group agrees on, with the share per group
export function tekenConsensus(container, analyse, stellingen) {
    const items = consensus(analyse, stellingen);
    container.innerHTML = items.length === 0
        ? '<p class="geen">Er zijn (nog) geen stellingen waar alle groepen het over eens zijn.</p>'
        : `<ul>${items.map(item => stellingRegel(item,
            ` <span class="rest">${item.aandelen.map((a, g) => `${NAMEN[g]} ${Math.round(a * 100)}%`).join(' · ')}</span>`
        )).join('')}</ul>`;
}

// legend: one line per group with its marker and size, and where the current deelnemer is
export function tekenLegenda(lijst, analyse, jij = null) {
    const regels = analyse.groepen.map((g, groep) => `
        <li>${groepMarker(groep)} <strong>Groep ${escapeHtml(g.naam)}</strong> <span>${g.deelnemers} ${g.deelnemers === 1 ? 'deelnemer' : 'deelnemers'}</span></li>
    `);
    if (jij) {
        regels.push(`<li class="jij-regel"><svg class="groep-marker" width="14" height="14" viewBox="-7 -7 14 14" aria-hidden="true"><circle class="jij-ring" r="5.5"/></svg> <strong>Jij</strong> <span>${jij.groep === null ? 'nog geen groep' : `groep ${NAMEN[jij.groep]}`}</span></li>`);
    }
    lijst.innerHTML = regels.join('');
    lijst.hidden = regels.length === 0;
}
