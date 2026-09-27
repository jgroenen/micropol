<?php

// right after installing: the login of admin/admin makes the first superbeheerder, and is then over
class InstallatieHandler {
    // POST /installatie  { gebruikersnaam, email, wachtwoord }   only for the login of admin/admin
    // => { account, installatie: false, token, verloopt }: logged in as the new superbeheerder
    public function POST($id = null) {
        Toegang::vereisInstallatie();
        $input = Http::body();
        $gebruikersnaam = Http::field($input, 'gebruikersnaam');
        $email = Http::field($input, 'email');
        $wachtwoord = isset($input['wachtwoord']) ? (string) $input['wachtwoord'] : '';
        Beheer::controleerNieuwAccount($gebruikersnaam, $email, $wachtwoord);

        $account = Beheer::maakAccount($gebruikersnaam, $email, $wachtwoord);
        Data::voegEventToe(Beheer::SUPERBEHEERDER_BENOEMD, Data::doorBeheerder($account), null, ['account_id' => $account['id']]);
        Toegang::logout();
        $account = Beheer::account($account['id']);
        Http::json(['account' => Beheer::metRollen($account), 'installatie' => false] + Toegang::login($account['id']), 201);
    }
}
