import { getGesprekken } from './api.js';
import { escapeHtml } from 'cdn/util.js';
import { toonView } from './views.js';

// list all gesprekken as cards
export async function toonGesprekken() {
    try {
        await toonView('gesprekken');
        const gesprekkenList = document.getElementById('gesprekken-list');
        const gesprekken = await getGesprekken();
        gesprekkenList.innerHTML = '';
        gesprekken.forEach(gesprek => {
            const kaart = document.createElement('a');
            kaart.className = 'kaart';
            kaart.href = `#/gesprekken/${encodeURIComponent(gesprek.id)}`;
            kaart.innerHTML = `
                <h2>${escapeHtml(gesprek.titel)}</h2>
                <p>${escapeHtml(gesprek.omschrijving)}</p>
            `;
            gesprekkenList.appendChild(kaart);
        });
    } catch (error) {
        console.error('Error fetching gesprekken:', error);
    }
}
