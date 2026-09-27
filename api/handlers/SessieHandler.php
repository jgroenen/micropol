<?php

// logging in and out of the admin, with a token in "Authorization: Bearer <token>", see Toegang
class SessieHandler {
    // GET /sessie   { account, installatie } for the token: account is { id, gebruikersnaam, email,
    // superbeheerder, rollen } or null; installatie is true for the login of admin/admin right after installing
    public function GET($id = null) {
        $account = Toegang::account();
        Http::json(['account' => $account === null ? null : Beheer::metRollen($account), 'installatie' => Toegang::installatie()]);
    }

    // POST /sessie  { gebruikersnaam, wachtwoord } => { account, installatie, token, verloopt }
    // Right after installing, while there is no superbeheerder, admin/admin logs in too, with account null
    // and installatie true: that login can only make the first superbeheerder (POST /installatie).
    public function POST($id = null) {
        $input = Http::body();
        $gebruikersnaam = Http::field($input, 'gebruikersnaam');
        // not trimmed: spaces may be part of a wachtwoord
        $wachtwoord = isset($input['wachtwoord']) ? (string) $input['wachtwoord'] : '';
        if ($gebruikersnaam === '' || $wachtwoord === '') {
            throw new HttpFout(400, 'gebruikersnaam and wachtwoord are required.');
        }

        if ($gebruikersnaam === Beheer::INSTALLATIE_GEBRUIKERSNAAM && $wachtwoord === Beheer::INSTALLATIE_WACHTWOORD && Beheer::installatieNodig()) {
            Http::json(['account' => null, 'installatie' => true] + Toegang::login(Beheer::INSTALLATIE));
            return;
        }

        $account = Beheer::accountMetNaam($gebruikersnaam);
        if ($account === null) {
            Wachtwoord::doeAlsOf($wachtwoord);
        }
        if ($account === null || !Wachtwoord::klopt($wachtwoord, $account)) {
            throw new HttpFout(401, 'Wrong gebruikersnaam or wachtwoord.');
        }
        Http::json(['account' => Beheer::metRollen($account), 'installatie' => false] + Toegang::login($account['id']));
    }

    // DELETE /sessie   ends the login of the token
    public function DELETE($id = null) {
        Toegang::logout();
        http_response_code(204);
    }
}
