export const labels = { eens: 'Eens', neutraal: 'Neutraal', oneens: 'Oneens' };

export function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text ?? '';
    return div.innerHTML;
}
