export { escapeHtml } from 'cdn/util.js';

// { titel, omschrijving, moderatie } from the form of a new or an existing gesprek
export function gesprekVelden(form) {
    return {
        titel: form.titel.value.trim(),
        omschrijving: form.omschrijving.value.trim(),
        moderatie: form.moderatie.value,
    };
}
