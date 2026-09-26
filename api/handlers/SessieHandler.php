<?php

// logging in and out of the admin, with a token in "Authorization: Bearer <token>", see Toegang
class SessieHandler {
    // GET /sessie   { beheerder: { id, gebruikersnaam, email } } for a valid token, otherwise { beheerder: null }
    public function GET($id = null) {
        Http::json(['beheerder' => Toegang::beheerder()]);
    }

    // POST /sessie  { gebruikersnaam, wachtwoord } => { beheerder, token, verloopt }
    public function POST($id = null) {
        $input = Http::body();
        $gebruikersnaam = Http::field($input, 'gebruikersnaam');
        // not trimmed: spaces may be part of a wachtwoord
        $wachtwoord = isset($input['wachtwoord']) ? (string) $input['wachtwoord'] : '';
        if ($gebruikersnaam === '' || $wachtwoord === '') {
            throw new HttpFout(400, 'gebruikersnaam and wachtwoord are required.');
        }

        $beheerder = Data::beheerder($gebruikersnaam);
        if ($beheerder === null) {
            Wachtwoord::doeAlsOf($wachtwoord);
        }
        if ($beheerder === null || !Wachtwoord::klopt($wachtwoord, $beheerder)) {
            throw new HttpFout(401, 'Wrong gebruikersnaam or wachtwoord.');
        }

        $sessie = Toegang::login($beheerder);
        Http::json([
            'beheerder' => ['id' => $beheerder['id'], 'gebruikersnaam' => $beheerder['gebruikersnaam'], 'email' => $beheerder['email']],
            'token' => $sessie['token'],
            'verloopt' => $sessie['verloopt'],
        ]);
    }

    // DELETE /sessie   ends the login of the token
    public function DELETE($id = null) {
        Toegang::logout();
        http_response_code(204);
    }
}
