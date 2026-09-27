import { postUitnodiging } from './api.js';
import { verwerkFout } from './toegang.js';

// Making an uitnodiging (a form with a button, and a rol to choose if there is a select), and showing the
// link to send on: there is no e-mail, the one who invites sends it himself. The html:
//   <form class="uitnodigen">  [<select name="rol">]  <button type="submit">
//       <div class="uitnodiging-link" hidden><input readonly> <button data-actie="kopieer"> <p class="geldig"></div>
//       <p class="melding fout" hidden>
//   </form>

// geefGegevens() gives { rol, gesprek_id } for the new uitnodiging; rol from the select when there is one
export function koppelUitnodigen(formulier, geefGegevens) {
    const link = formulier.querySelector('.uitnodiging-link');
    const fout = formulier.querySelector('.fout');
    formulier.addEventListener('submit', async event => {
        event.preventDefault();
        fout.hidden = true;
        const knop = formulier.querySelector('[type=submit]');
        knop.disabled = true;
        try {
            const gegevens = geefGegevens();
            if (formulier.rol) {
                gegevens.rol = formulier.rol.value;
            }
            toonLink(formulier, await postUitnodiging(gegevens));
        } catch (error) {
            fout.textContent = 'De uitnodiging kon niet worden gemaakt. Probeer het opnieuw.';
            verwerkFout(error, fout);
        } finally {
            knop.disabled = false;
        }
    });
    link.querySelector('[data-actie=kopieer]').addEventListener('click', async event => {
        try {
            await navigator.clipboard.writeText(link.querySelector('input').value);
            event.target.textContent = 'Gekopieerd';
        } catch (error) {
            link.querySelector('input').select();
        }
    });
}

// hides the link of an earlier uitnodiging
export function wisUitnodiging(formulier) {
    formulier.querySelector('.uitnodiging-link').hidden = true;
    formulier.querySelector('.fout').hidden = true;
}

// shows the link of a new uitnodiging { token, verloopt } in the form
export function toonLink(formulier, uitnodiging) {
    const link = formulier.querySelector('.uitnodiging-link');
    link.querySelector('input').value = `${location.origin}${location.pathname}#/uitnodiging/${encodeURIComponent(uitnodiging.token)}`;
    link.querySelector('[data-actie=kopieer]').textContent = 'Kopiëren';
    const tot = new Date(uitnodiging.verloopt).toLocaleString('nl-NL', { dateStyle: 'medium', timeStyle: 'short' });
    link.querySelector('.geldig').textContent = `Stuur deze link zelf door. Hij werkt één keer, tot ${tot}.`;
    link.hidden = false;
}

// the html of such a form: { titel, uitleg } above it (optional), the text of the button, and
// rollen [[waarde, naam]] for a select (optional)
export function uitnodigenHtml({ titel = '', uitleg = '', knoptekst = 'Link maken', rollen = [] } = {}) {
    const keuze = rollen.length === 0 ? '' : `
        <label>Rol
            <select name="rol">${rollen.map(([waarde, naam]) => `<option value="${waarde}">${naam}</option>`).join('')}</select>
        </label>`;
    return `
        ${titel ? `<h2>${titel}</h2>` : ''}
        ${uitleg ? `<p class="uitleg-tekst">${uitleg}</p>` : ''}
        <div class="uitnodigen-kop">${keuze}<button type="submit" class="knop-klein">${knoptekst}</button></div>
        <div class="uitnodiging-link" hidden>
            <div class="uitnodiging-regel">
                <input readonly aria-label="De link van de uitnodiging">
                <button type="button" class="knop-klein" data-actie="kopieer">Kopiëren</button>
            </div>
            <p class="geldig"></p>
        </div>
        <p class="melding fout" role="alert" hidden></p>
    `;
}
