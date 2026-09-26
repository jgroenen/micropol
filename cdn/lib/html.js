// text as HTML: < > & and quotes escaped, so it can go into innerHTML and attributes
export function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text ?? '';
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
