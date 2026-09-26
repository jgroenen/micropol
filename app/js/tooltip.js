import { escapeHtml } from 'cdn/util.js';

// tooltip on elements with data-stelling (S-number + full stelling) or data-tooltip (plain text);
// fixed position so a scrolling container does not clip it
const tooltip = document.getElementById('stelling-tooltip');
const DOEL = '[data-stelling], [data-tooltip]';

function toonTooltip(element) {
    if (element.dataset.stelling !== undefined) {
        tooltip.innerHTML = `<strong>${escapeHtml(element.textContent)}</strong> ${escapeHtml(element.dataset.stelling)}`;
    } else {
        tooltip.textContent = element.dataset.tooltip;
    }
    tooltip.hidden = false;
    const rect = element.getBoundingClientRect();
    const breedte = tooltip.offsetWidth;
    const hoogte = tooltip.offsetHeight;
    let left = rect.left + rect.width / 2 - breedte / 2;
    left = Math.max(16, Math.min(left, window.innerWidth - breedte - 16));
    let top = rect.bottom + 8;
    if (top + hoogte > window.innerHeight - 16) {
        top = rect.top - hoogte - 8;
    }
    tooltip.style.left = `${left}px`;
    tooltip.style.top = `${top}px`;
}

export function verbergTooltip() {
    tooltip.hidden = true;
}

// show the tooltip for such elements inside container
export function koppelTooltip(container) {
    container.addEventListener('mouseover', event => {
        const element = event.target.closest(DOEL);
        if (element) {
            toonTooltip(element);
        }
    });
    container.addEventListener('mouseout', event => {
        const element = event.target.closest(DOEL);
        if (element && !element.contains(event.relatedTarget) && document.activeElement !== element) {
            verbergTooltip();
        }
    });
    // focus covers keyboard users and tapping on touch screens
    container.addEventListener('focusin', event => {
        const element = event.target.closest(DOEL);
        if (element) {
            toonTooltip(element);
        }
    });
    container.addEventListener('focusout', verbergTooltip);
    container.addEventListener('scroll', verbergTooltip);
}

window.addEventListener('scroll', verbergTooltip, { passive: true });
document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        verbergTooltip();
    }
});
