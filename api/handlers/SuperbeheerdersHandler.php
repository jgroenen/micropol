<?php

// the superbeheerders of this server, see Beheer. New ones come in with an uitnodiging (POST /uitnodigingen);
// here they are paused, restored or removed.
class SuperbeheerdersHandler {
    // GET /superbeheerders   superbeheerders only
    // { superbeheerders: [{ id, gebruikersnaam, email, status }] }, also opgeschorte and verwijderde
    public function GET($id = null) {
        Toegang::vereisSuperbeheerder();
        Http::json(['superbeheerders' => Beheer::superbeheerders()]);
    }

    // POST /superbeheerders  { account_id, status, reden }   superbeheerders only
    // status actief (restore), opgeschort or verwijderd; reden is required for the last two.
    // Nobody changes his own status, so there is always a superbeheerder left. Returns the superbeheerder, like GET.
    public function POST($id = null) {
        $account = Toegang::vereisSuperbeheerder();
        $input = Http::body();
        $accountId = Http::field($input, 'account_id');
        $status = Http::field($input, 'status');
        $reden = Http::line($input, 'reden');
        Beheer::controleerStatus($status, $reden);
        $huidig = Beheer::account($accountId)['superbeheerder'] ?? null;
        if ($huidig === null) {
            throw new HttpFout(404, 'Superbeheerder not found.');
        }
        if ($accountId === $account['id']) {
            throw new HttpFout(409, 'You cannot change your own status.');
        }
        if ($huidig === Data::STATUS_VERWIJDERD) {
            throw new HttpFout(409, 'This superbeheerder is verwijderd; invite again instead.');
        }
        if ($huidig !== $status) {
            Data::voegEventToe(Beheer::SUPERBEHEERDER_STATUS_EVENTS[$status], Data::doorBeheerder($account), null, Beheer::statusVelden($accountId, $status, $reden));
        }
        foreach (Beheer::superbeheerders() as $superbeheerder) {
            if ($superbeheerder['id'] === $accountId) {
                Http::json($superbeheerder);
            }
        }
    }
}
