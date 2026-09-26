import { getGesprek, getMatrix, getAnalyse, getMijnAntwoorden } from './api.js';
import { userId } from './user.js';
import { labels, escapeHtml } from 'cdn/util.js';
import { toonView } from './views.js';
import { koppelTooltip } from './tooltip.js';
import { tekenPlot, tekenLegenda, tekenGroepKaarten, tekenConsensus, uitlegOnderscheid, uitlegConsensus, groepMarker, groepNaam, plaats } from './analyse.js';

// elements of views/matrix.html, set by koppel() once the view is in the page
let wrapper, leeg, titel, analyseSectie, plot;
let gekoppeld = false;

function koppel() {
    if (gekoppeld) {
        return;
    }
    wrapper = document.getElementById('matrix-wrapper');
    leeg = document.getElementById('matrix-leeg');
    titel = document.getElementById('matrix-titel');
    analyseSectie = document.getElementById('analyse');
    plot = document.getElementById('analyse-plot');

    // full stelling on hover/focus of the S-numbers in the header, deelnemer info on the plot points
    koppelTooltip(wrapper);
    koppelTooltip(plot);
    gekoppeld = true;
}

// matrix of a gesprek: deelnemers as rows, stellingen as columns, grouped by the analyse
export async function toonMatrix(id) {
    try {
        await toonView('matrix');
        koppel();
        document.getElementById('matrix-terug').href = `#/gesprekken/${encodeURIComponent(id)}`;
        wrapper.innerHTML = '';
        plot.innerHTML = '';
        leeg.hidden = true;
        analyseSectie.hidden = true;

        const [gesprek, deelnemers, analyse, mijnAntwoorden] = await Promise.all([
            getGesprek(id), getMatrix(id),
            // the groups are extra: the matrix works without them (e.g. when the math server is down)
            getAnalyse(id).catch(error => {
                console.error('Error fetching analyse:', error);
                return null;
            }),
            getMijnAntwoorden(id, userId)
        ]);
        if (!gesprek) {
            titel.textContent = 'Gesprek niet gevonden';
            return;
        }
        titel.textContent = `Matrix: ${gesprek.titel}`;
        // the api gives the stellingen in random order; sorted by id, S1, S2, ... stay the same stelling
        gesprek.stellingen.sort((a, b) => a.id.localeCompare(b.id));

        if (!deelnemers || deelnemers.length === 0) {
            leeg.hidden = false;
            wrapper.hidden = true;
            return;
        }

        // deelnemer number (1-based) => group index
        const groepVan = new Map();
        // the current user, placed in the browser with the model
        const jij = analyse ? plaats(analyse.model, mijnAntwoorden ?? {}) : null;
        if (analyse && tekenPlot(plot, analyse, jij)) {
            analyse.deelnemers.forEach(d => groepVan.set(d.deelnemer, d.groep));
            tekenLegenda(document.getElementById('groep-legenda'), analyse, jij);
            document.getElementById('analyse-uitleg').textContent = uitleg(analyse, jij);
            tekenGroepKaarten(document.getElementById('groep-kaarten'), analyse, gesprek.stellingen);
            tekenConsensus(document.getElementById('consensus'), analyse, gesprek.stellingen);
            document.getElementById('onderscheid-uitleg').textContent = uitlegOnderscheid();
            document.getElementById('consensus-uitleg').textContent = uitlegConsensus();
            // without groups there is nothing to say per group
            document.getElementById('groepen-blok').hidden = analyse.k === 0;
            analyseSectie.hidden = false;
        }

        wrapper.hidden = false;
        wrapper.innerHTML = maakTabel(gesprek.stellingen, deelnemers, groepVan);
    } catch (error) {
        console.error('Error fetching matrix:', error);
    }
}

function uitleg(analyse, jij) {
    const zinnen = ['Deelnemers die vaak hetzelfde antwoorden staan dicht bij elkaar.'];
    if (analyse.k > 0) {
        zinnen.push(analyse.k_gekozen_op === 'kenmerkend'
            ? `Ze zijn verdeeld in ${analyse.k} groepen: het kleinste aantal waarbij elke groep iets kenmerkends heeft.`
            : `Ze zijn verdeeld in ${analyse.k} groepen: het aantal waarbij de groepen het duidelijkst gescheiden zijn.`);
        if (analyse.model.componenten.length > 2) {
            zinnen.push(`De groepen zijn bepaald op ${analyse.model.componenten.length} assen, de plot toont er 2; daardoor kunnen groepen in de plot wat door elkaar lopen.`);
        }
    } else {
        zinnen.push('Voor groepen zijn nog te weinig deelnemers.');
    }
    if (analyse.buiten_analyse > 0) {
        zinnen.push(`${analyse.buiten_analyse} ${analyse.buiten_analyse === 1 ? 'deelnemer telt' : 'deelnemers tellen'} nog niet mee (minder dan ${analyse.min_antwoorden} antwoorden).`);
    }
    if (jij && jij.groep !== null) {
        zinnen.push(`Met je huidige ${jij.antwoorden} antwoorden hoor je bij groep ${groepNaam(jij.groep)}.`);
    }

    // the model (axes and group centers) is recalculated every few hours; deelnemers move live
    const uren = Math.round(analyse.model.max_leeftijd / 3600);
    zinnen.push(`De assen en groepen worden elke ${uren} uur opnieuw berekend (laatst ${tijdstip(analyse.model.berekend)}, de volgende keer na ${tijdstip(analyse.model.verloopt)}); nieuwe antwoorden verplaatsen deelnemers direct.`);
    return zinnen.join(' ');
}

// "14:05", or "23 sep 14:05" when not today
function tijdstip(iso) {
    const datum = new Date(iso);
    const tijd = datum.toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' });
    return datum.toDateString() === new Date().toDateString()
        ? tijd
        : `${datum.toLocaleDateString('nl-NL', { day: 'numeric', month: 'short' })} ${tijd}`;
}

function maakTabel(stellingen, deelnemers, groepVan) {
    const kop = stellingen
        .map((s, i) => `<th scope="col" tabindex="0" aria-describedby="stelling-tooltip" data-stelling="${escapeHtml(s.content)}">S${i + 1}</th>`)
        .join('');

    // rows grouped: group A first, deelnemers without a group last
    const volgorde = deelnemers
        .map((antwoorden, r) => ({ antwoorden, nummer: r + 1, groep: groepVan.get(r + 1) ?? null }))
        .sort((a, b) => (a.groep ?? Infinity) - (b.groep ?? Infinity) || a.nummer - b.nummer);

    const rijen = volgorde.map(({ antwoorden, nummer, groep }) => {
        const cellen = stellingen.map((s, i) => {
            const waarde = antwoorden[s.id];
            const label = waarde ? labels[waarde] ?? waarde : 'Niet beantwoord';
            const klasse = waarde in labels ? waarde : 'leeg';
            return `<td><span class="bolletje ${klasse}" title="S${i + 1}: ${escapeHtml(label)}" role="img" aria-label="${escapeHtml(label)}"></span></td>`;
        }).join('');
        const markering = groep === null
            ? '<span class="groep-cel"></span>'
            : `<span class="groep-cel" title="Groep ${groepNaam(groep)}">${groepMarker(groep)}${groepNaam(groep)}</span>`;
        return `<tr><th scope="row">${markering}Deelnemer ${nummer}</th>${cellen}</tr>`;
    }).join('');

    return `
        <table class="matrix">
            <thead><tr><th></th>${kop}</tr></thead>
            <tbody>${rijen}</tbody>
        </table>
    `;
}
