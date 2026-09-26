// the logged in user; main.js shows the right view when it changes

let user = null;
let bijWijziging = () => {};

// { id, username, email } or null
export function ingelogdeUser() {
    return user;
}

export function zetUser(nieuw) {
    user = nieuw;
    bijWijziging(user);
}

export function bijWijzigingVanUser(callback) {
    bijWijziging = callback;
}

// for a failed api call: a 401 means the session ended (e.g. logged out in another tab),
// otherwise show the melding (an element with the message already in it), if any
export function verwerkFout(error, melding = null) {
    if (error.status === 401) {
        zetUser(null);
        return;
    }
    console.error(error);
    if (melding) {
        melding.hidden = false;
    }
}
