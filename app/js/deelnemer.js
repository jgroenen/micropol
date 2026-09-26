// The deelnemer: a random id, kept in localStorage (for now). There is no login.
// It used to be kept as 'user_id'; that id is taken over, so a deelnemer keeps his antwoorden.
function leesOfMaakDeelnemerId() {
    let id = null;
    try {
        id = localStorage.getItem('deelnemer_id') || localStorage.getItem('user_id');
    } catch (e) {}
    if (!id) {
        id = crypto.randomUUID
            ? crypto.randomUUID()
            : Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
    }
    try {
        localStorage.setItem('deelnemer_id', id);
        localStorage.removeItem('user_id');
    } catch (e) {}
    return id;
}

export const deelnemerId = leesOfMaakDeelnemerId();
