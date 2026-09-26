// the logged in beheerder (from the auth service, see auth.js); main.js shows the right view when it changes

let beheerder = null;
let bijWijziging = () => {};

// { id, gebruikersnaam, email } or null
export function ingelogdeBeheerder() {
    return beheerder;
}

export function zetBeheerder(nieuw) {
    beheerder = nieuw;
    bijWijziging(beheerder);
}

export function bijWijzigingVanBeheerder(callback) {
    bijWijziging = callback;
}

// for a failed api call: a 401 means the session ended (e.g. logged out in another tab),
// otherwise show the melding (an element with the message already in it), if any
export function verwerkFout(error, melding = null) {
    if (error.status === 401) {
        zetBeheerder(null);
        return;
    }
    console.error(error);
    if (melding) {
        melding.hidden = false;
    }
}
