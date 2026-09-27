<?php

// Who may do what in the admin. Everything is in events (see Data):
//   beheer.jsonl                accounts, their wachtwoorden, the superbeheerders and the uitnodigingen
//   gesprekken/<id>/team.jsonl  the team of a gesprek: its gespreksbeheerders and moderators
// The roles:
//   superbeheerder     of the whole server: makes gesprekken, pauses or ends gesprekken, pauses or removes team
//                      leden and other superbeheerders, always with a reden; reads and moderates no content itself
//   gespreksbeheerder  of one gesprek: changes its gegevens, invites and manages its team, and moderates
//   moderator          of one gesprek: approves and rejects stellingen, and reads the logboek
// One account can have several roles, in several gesprekken. New leden come in with an uitnodiging: a link
// with a random token, valid for UITNODIGING_GELDIG seconds and only once; only its sha256 is stored.
// Right after installing there is no superbeheerder yet: then admin/admin logs in, only to make the first one.
class Beheer {
    const ROL_SUPERBEHEERDER = 'superbeheerder';
    const ROL_GESPREKSBEHEERDER = 'gespreksbeheerder';
    const ROL_MODERATOR = 'moderator';
    const TEAMROLLEN = [self::ROL_GESPREKSBEHEERDER, self::ROL_MODERATOR];
    const ROLLEN = [self::ROL_SUPERBEHEERDER, self::ROL_GESPREKSBEHEERDER, self::ROL_MODERATOR];

    // the types of events, with their fields besides the ones every event has
    const ACCOUNT_AANGEMAAKT = 'account.aangemaakt';                 // account_id, gebruikersnaam, email
    const WACHTWOORD_INGESTELD = 'wachtwoord.ingesteld';             // account_id, wachtwoord_methode, versleuteld_wachtwoord
    const SUPERBEHEERDER_BENOEMD = 'superbeheerder.benoemd';         // account_id
    const SUPERBEHEERDER_OPGESCHORT = 'superbeheerder.opgeschort';   // account_id, reden
    const SUPERBEHEERDER_HERSTELD = 'superbeheerder.hersteld';       // account_id
    const SUPERBEHEERDER_VERWIJDERD = 'superbeheerder.verwijderd';   // account_id, reden
    const UITNODIGING_AANGEMAAKT = 'uitnodiging.aangemaakt';         // uitnodiging_id, token_hash, rol, verloopt
    const UITNODIGING_GEBRUIKT = 'uitnodiging.gebruikt';             // uitnodiging_id, account_id
    const LID_TOEGEVOEGD = 'lid.toegevoegd';                         // account_id, rol
    const LID_OPGESCHORT = 'lid.opgeschort';                         // account_id, reden
    const LID_HERSTELD = 'lid.hersteld';                             // account_id
    const LID_VERWIJDERD = 'lid.verwijderd';                         // account_id, reden
    // the event that gives a superbeheerder or a lid each status
    const SUPERBEHEERDER_STATUS_EVENTS = [
        Data::STATUS_ACTIEF => self::SUPERBEHEERDER_HERSTELD,
        Data::STATUS_OPGESCHORT => self::SUPERBEHEERDER_OPGESCHORT,
        Data::STATUS_VERWIJDERD => self::SUPERBEHEERDER_VERWIJDERD,
    ];
    const LID_STATUS_EVENTS = [
        Data::STATUS_ACTIEF => self::LID_HERSTELD,
        Data::STATUS_OPGESCHORT => self::LID_OPGESCHORT,
        Data::STATUS_VERWIJDERD => self::LID_VERWIJDERD,
    ];

    // right after installing: this gebruikersnaam and wachtwoord log in, only to make the first superbeheerder
    const INSTALLATIE_GEBRUIKERSNAAM = 'admin';
    const INSTALLATIE_WACHTWOORD = 'admin';
    // the account_id of that login in sessies.csv
    const INSTALLATIE = 'installatie';

    const UITNODIGING_GELDIG = 48 * 3600;   // seconds
    const MIN_WACHTWOORD = 12;
    const MAX_GEBRUIKERSNAAM = 64;
    const MAX_REDEN = 500;

    // ---- accounts

    // [id => { id, gebruikersnaam, email, wachtwoord_methode, versleuteld_wachtwoord,
    //          superbeheerder: status (see Data::STATUSSEN) or null }]
    public static function accounts() {
        $superbeheerder = array_flip(self::SUPERBEHEERDER_STATUS_EVENTS) + [self::SUPERBEHEERDER_BENOEMD => Data::STATUS_ACTIEF];
        $accounts = [];
        foreach (Data::events('beheer') as $event) {
            $id = $event['account_id'] ?? null;
            if ($event['type'] === self::ACCOUNT_AANGEMAAKT) {
                $accounts[$id] = [
                    'id' => $id,
                    'gebruikersnaam' => $event['gebruikersnaam'],
                    'email' => $event['email'],
                    'wachtwoord_methode' => '',
                    'versleuteld_wachtwoord' => '',
                    'superbeheerder' => null,
                ];
            } elseif (!isset($accounts[$id])) {
                continue;
            } elseif ($event['type'] === self::WACHTWOORD_INGESTELD) {
                $accounts[$id]['wachtwoord_methode'] = $event['wachtwoord_methode'];
                $accounts[$id]['versleuteld_wachtwoord'] = $event['versleuteld_wachtwoord'];
            } elseif (isset($superbeheerder[$event['type']])) {
                $accounts[$id]['superbeheerder'] = $superbeheerder[$event['type']];
            }
        }
        return $accounts;
    }

    public static function account($id) {
        return self::accounts()[$id] ?? null;
    }

    // by gebruikersnaam, not case sensitive, or null
    public static function accountMetNaam($gebruikersnaam) {
        foreach (self::accounts() as $account) {
            if (strcasecmp($account['gebruikersnaam'], $gebruikersnaam) === 0) {
                return $account;
            }
        }
        return null;
    }

    // what the api gives of an account: { id, gebruikersnaam, email }
    public static function openbaar(array $account) {
        return ['id' => $account['id'], 'gebruikersnaam' => $account['gebruikersnaam'], 'email' => $account['email']];
    }

    // the logged in account as the api gives it: { id, gebruikersnaam, email, superbeheerder (bool),
    // rollen: { gesprek_id: rol } }
    public static function metRollen(array $account) {
        return self::openbaar($account) + [
            'superbeheerder' => self::isSuperbeheerder($account),
            'rollen' => (object) self::rollen($account),
        ];
    }

    // whether there never was a superbeheerder: then admin/admin may log in to make the first one
    public static function installatieNodig() {
        foreach (Data::events('beheer') as $event) {
            if ($event['type'] === self::SUPERBEHEERDER_BENOEMD) {
                return false;
            }
        }
        return true;
    }

    // checks the fields of a new account; a HttpFout (400, or 409 for a gebruikersnaam in use) if wrong
    public static function controleerNieuwAccount($gebruikersnaam, $email, $wachtwoord) {
        if ($gebruikersnaam === '' || $email === '' || $wachtwoord === '') {
            throw new HttpFout(400, 'gebruikersnaam, email and wachtwoord are required.');
        }
        if (preg_match('/^[A-Za-z0-9._-]{1,' . self::MAX_GEBRUIKERSNAAM . '}$/', $gebruikersnaam) !== 1) {
            throw new HttpFout(400, 'gebruikersnaam may only have letters, digits, dots, dashes and underscores, at most ' . self::MAX_GEBRUIKERSNAAM . '.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpFout(400, 'email is not a valid e-mail address.');
        }
        if (mb_strlen($wachtwoord) < self::MIN_WACHTWOORD) {
            throw new HttpFout(400, 'wachtwoord must have at least ' . self::MIN_WACHTWOORD . ' characters.');
        }
        if (strcasecmp($gebruikersnaam, self::INSTALLATIE_GEBRUIKERSNAAM) === 0 || self::accountMetNaam($gebruikersnaam) !== null) {
            throw new HttpFout(409, 'This gebruikersnaam is already taken.');
        }
    }

    // makes an account (check the fields first with controleerNieuwAccount()); returns it
    public static function maakAccount($gebruikersnaam, $email, $wachtwoord) {
        $id = Data::uuid();
        $door = Data::doorBeheerder(['id' => $id]);
        Data::voegEventToe(self::ACCOUNT_AANGEMAAKT, $door, null, ['account_id' => $id, 'gebruikersnaam' => $gebruikersnaam, 'email' => $email]);
        Data::voegEventToe(self::WACHTWOORD_INGESTELD, $door, null, ['account_id' => $id] + Wachtwoord::versleutel($wachtwoord));
        return self::account($id);
    }

    // ---- roles

    public static function isSuperbeheerder(array $account) {
        return ($account['superbeheerder'] ?? null) === Data::STATUS_ACTIEF;
    }

    // the superbeheerders, also opgeschorte and verwijderde: [{ id, gebruikersnaam, email, status }]
    public static function superbeheerders() {
        $superbeheerders = [];
        foreach (self::accounts() as $account) {
            if ($account['superbeheerder'] !== null) {
                $superbeheerders[] = self::openbaar($account) + ['status' => $account['superbeheerder']];
            }
        }
        return $superbeheerders;
    }

    // the team of a gesprek, also opgeschorte and verwijderde leden: [account_id => { account_id, rol, status }];
    // who is added again after removal gets the new rol
    public static function team($gesprekId) {
        $statussen = array_flip(self::LID_STATUS_EVENTS);
        $team = [];
        foreach (Data::events('team', $gesprekId) as $event) {
            $id = $event['account_id'];
            if ($event['type'] === self::LID_TOEGEVOEGD) {
                $team[$id] = ['account_id' => $id, 'rol' => $event['rol'], 'status' => Data::STATUS_ACTIEF];
            } elseif (isset($team[$id], $statussen[$event['type']])) {
                $team[$id]['status'] = $statussen[$event['type']];
            }
        }
        return $team;
    }

    // the rol of the account in the team of the gesprek, or null; only for an actief lid (a paused or
    // ended gesprek keeps its team, but see Toegang::vereisRol)
    public static function rolIn($gesprekId, array $account) {
        if (!Data::gesprekBestaat($gesprekId)) {
            return null;
        }
        $lid = self::team($gesprekId)[$account['id']] ?? null;
        return ($lid['status'] ?? null) === Data::STATUS_ACTIEF ? $lid['rol'] : null;
    }

    // [gesprek_id => rol] of the account in all gesprekken
    public static function rollen(array $account) {
        $rollen = [];
        foreach (Data::gesprekken() as $gesprek) {
            $rol = self::rolIn($gesprek['id'], $account);
            if ($rol !== null) {
                $rollen[$gesprek['id']] = $rol;
            }
        }
        return $rollen;
    }

    // checks a new status with its reden (required for all but actief); a HttpFout 400 if wrong;
    // $statussen: Data::STATUSSEN for people, Data::GESPREK_STATUSSEN for a gesprek
    public static function controleerStatus($status, $reden, array $statussen = Data::STATUSSEN) {
        if (!in_array($status, $statussen, true)) {
            throw new HttpFout(400, 'status must be one of: ' . implode(', ', $statussen) . '.');
        }
        if ($status !== Data::STATUS_ACTIEF && $reden === '') {
            throw new HttpFout(400, 'reden is required for ' . $status . '.');
        }
        if (mb_strlen($reden) > self::MAX_REDEN) {
            throw new HttpFout(400, 'reden may be at most ' . self::MAX_REDEN . ' characters.');
        }
    }

    // the fields of a status event: the account, and the reden when it is not actief
    public static function statusVelden($accountId, $status, $reden) {
        return ['account_id' => $accountId] + ($status === Data::STATUS_ACTIEF ? [] : ['reden' => $reden]);
    }

    // ---- uitnodigingen

    // a new uitnodiging for a rol, in a gesprek (null for superbeheerder) => { token, uitnodiging_id, rol,
    // gesprek_id, verloopt (ISO 8601) }; the token is only here, it is stored as its sha256
    public static function nodigUit($rol, $gesprekId, array $door) {
        $token = bin2hex(random_bytes(32));
        $uitnodiging = [
            'uitnodiging_id' => Data::uuid(),
            'token_hash' => hash('sha256', $token),
            'rol' => $rol,
            'verloopt' => time() + self::UITNODIGING_GELDIG,
        ];
        Data::voegEventToe(self::UITNODIGING_AANGEMAAKT, Data::doorBeheerder($door), $gesprekId, $uitnodiging);
        return [
            'token' => $token,
            'uitnodiging_id' => $uitnodiging['uitnodiging_id'],
            'rol' => $rol,
            'gesprek_id' => $gesprekId,
            'verloopt' => date('c', $uitnodiging['verloopt']),
        ];
    }

    // the uitnodiging of this token when it can still be used, or null: { uitnodiging_id, rol, gesprek_id, verloopt };
    // not when used, expired, or for a gesprek that is not there anymore
    public static function uitnodiging($token) {
        $hash = hash('sha256', $token);
        $gevonden = null;
        foreach (Data::events('beheer') as $event) {
            if ($event['type'] === self::UITNODIGING_AANGEMAAKT && hash_equals($event['token_hash'], $hash)) {
                $gevonden = $event;
            } elseif ($gevonden !== null && $event['type'] === self::UITNODIGING_GEBRUIKT && $event['uitnodiging_id'] === $gevonden['uitnodiging_id']) {
                return null;
            }
        }
        if ($gevonden === null || $gevonden['verloopt'] < time()
            || ($gevonden['gesprek_id'] !== null && !Data::gesprekBestaat($gevonden['gesprek_id']))) {
            return null;
        }
        return [
            'uitnodiging_id' => $gevonden['uitnodiging_id'],
            'rol' => $gevonden['rol'],
            'gesprek_id' => $gevonden['gesprek_id'],
            'verloopt' => $gevonden['verloopt'],
        ];
    }

    // uses the uitnodiging for the account: it gets the rol
    public static function gebruik(array $uitnodiging, array $account) {
        $door = Data::doorBeheerder($account);
        $gesprekId = $uitnodiging['gesprek_id'];
        Data::voegEventToe(self::UITNODIGING_GEBRUIKT, $door, $gesprekId, ['uitnodiging_id' => $uitnodiging['uitnodiging_id'], 'account_id' => $account['id']]);
        if ($uitnodiging['rol'] === self::ROL_SUPERBEHEERDER) {
            Data::voegEventToe(self::SUPERBEHEERDER_BENOEMD, $door, null, ['account_id' => $account['id']]);
        } else {
            Data::voegEventToe(self::LID_TOEGEVOEGD, $door, $gesprekId, ['account_id' => $account['id'], 'rol' => $uitnodiging['rol']]);
        }
    }
}
