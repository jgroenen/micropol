// who has access: the login of this browser (see api.js), like Toegang in the api; main.js shows the
// right view when it changes. What an account may do follows from its roles (Beheer in the api):
// superbeheerder, or gespreksbeheerder or moderator in the team of a gesprek.

let sessie = { account: null, installatie: false };
let bijWijziging = () => {};

// { id, gebruikersnaam, email, superbeheerder, rollen: { gesprek_id: rol } } or null
export function ingelogdAccount() {
    return sessie.account;
}

// whether this is the login of admin/admin right after installing
export function isInstallatie() {
    return sessie.installatie;
}

export function isSuperbeheerder() {
    return sessie.account?.superbeheerder === true;
}

// the rol of the account in the team of the gesprek: 'gespreksbeheerder', 'moderator' or null
export function rolIn(gesprekId) {
    return sessie.account?.rollen[gesprekId] ?? null;
}

// { account, installatie }, or null for logged out
export function zetSessie(nieuw) {
    sessie = nieuw ?? { account: null, installatie: false };
    bijWijziging(sessie);
}

export function bijWijzigingVanSessie(callback) {
    bijWijziging = callback;
}

// for a failed api call: a 401 means the session ended (e.g. logged out in another tab),
// otherwise show the melding (an element with the message already in it), if any
export function verwerkFout(error, melding = null) {
    if (error.status === 401) {
        zetSessie(null);
        return;
    }
    console.error(error);
    if (melding) {
        melding.hidden = false;
    }
}
