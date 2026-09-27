<?php

// uitnodigingen: a link to join as superbeheerder, or as gespreksbeheerder or moderator of a gesprek.
// There is no e-mail: who invites gets the token, and sends the link on. See Beheer.
class UitnodigingenHandler {
    // POST /uitnodigingen  { rol, gesprek_id }   gesprek_id only for the roles of a team
    // superbeheerders invite for every rol, gespreksbeheerders for the team of their gesprek
    // => { token, uitnodiging_id, rol, gesprek_id, verloopt }
    //
    // POST /uitnodigingen/<token>  { gebruikersnaam, email, wachtwoord }   uses the uitnodiging:
    // logged in, for that account; otherwise for a new account with these fields, logged in right away
    // => { account, installatie: false, token, verloopt } (token and verloopt only for a new account)
    public function POST($id = null) {
        if ($id !== null) {
            $this->gebruik($id);
            return;
        }
        $input = Http::body();
        $rol = Http::field($input, 'rol');
        $gesprekId = Http::field($input, 'gesprek_id');
        if (!in_array($rol, Beheer::ROLLEN, true)) {
            throw new HttpFout(400, 'rol must be one of: ' . implode(', ', Beheer::ROLLEN) . '.');
        }
        if ($rol === Beheer::ROL_SUPERBEHEERDER) {
            $account = Toegang::vereisSuperbeheerder();
            $gesprekId = null;
        } else {
            if ($gesprekId === '') {
                throw new HttpFout(400, 'gesprek_id is required for a ' . $rol . '.');
            }
            if (!Data::gesprekBestaat($gesprekId)) {
                throw new HttpFout(404, 'Gesprek not found.');
            }
            $account = Toegang::vereisRol($gesprekId, [Beheer::ROL_SUPERBEHEERDER, Beheer::ROL_GESPREKSBEHEERDER]);
        }
        Http::json(Beheer::nodigUit($rol, $gesprekId, $account), 201);
    }

    // GET /uitnodigingen/<token>   what the uitnodiging is for: { rol, gesprek: { id, titel } or null, verloopt };
    // 404 when it is used, expired or unknown
    public function GET($id = null) {
        $uitnodiging = $this->uitnodiging($id);
        $gesprek = $uitnodiging['gesprek_id'] === null ? null : Data::gesprek($uitnodiging['gesprek_id']);
        Http::json([
            'rol' => $uitnodiging['rol'],
            'gesprek' => $gesprek === null ? null : ['id' => $gesprek['id'], 'titel' => $gesprek['titel']],
            'verloopt' => date('c', $uitnodiging['verloopt']),
        ]);
    }

    private function gebruik($token) {
        $uitnodiging = $this->uitnodiging($token);
        $account = Toegang::account();
        $sessie = [];
        if ($account === null) {
            $input = Http::body();
            $gebruikersnaam = Http::field($input, 'gebruikersnaam');
            $email = Http::field($input, 'email');
            $wachtwoord = isset($input['wachtwoord']) ? (string) $input['wachtwoord'] : '';
            Beheer::controleerNieuwAccount($gebruikersnaam, $email, $wachtwoord);
            $account = Beheer::maakAccount($gebruikersnaam, $email, $wachtwoord);
            $sessie = Toegang::login($account['id']);
        }
        Beheer::gebruik($uitnodiging, $account);
        Http::json(['account' => Beheer::metRollen(Beheer::account($account['id'])), 'installatie' => false] + $sessie);
    }

    // the uitnodiging of the token, or a 404
    private function uitnodiging($token) {
        $uitnodiging = $token === null ? null : Beheer::uitnodiging($token);
        if ($uitnodiging === null) {
            throw new HttpFout(404, 'Uitnodiging not found, used or expired.');
        }
        return $uitnodiging;
    }
}
