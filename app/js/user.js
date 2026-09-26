// random user id, kept in localStorage (for now)
function getUserId() {
    let userId = null;
    try {
        userId = localStorage.getItem('user_id');
    } catch (e) {}
    if (!userId) {
        userId = crypto.randomUUID
            ? crypto.randomUUID()
            : Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
        try {
            localStorage.setItem('user_id', userId);
        } catch (e) {}
    }
    return userId;
}

export const userId = getUserId();
