<?php

// the team of a gesprek: its gespreksbeheerders and moderators, see Beheer. New leden come in with an
// uitnodiging (POST /uitnodigingen); here leden are paused, restored or removed.
class TeamHandler {
    // GET /team?gesprek_id=<id>   superbeheerders and gespreksbeheerders of the gesprek
    // { leden: [{ account_id, gebruikersnaam, email, rol, status }] }, also opgeschorte and verwijderde
    public function GET($id = null) {
        $gesprekId = $this->gesprekId($_GET);
        Toegang::vereisRol($gesprekId, [Beheer::ROL_SUPERBEHEERDER, Beheer::ROL_GESPREKSBEHEERDER]);
        Http::json(['leden' => $this->leden($gesprekId)]);
    }

    // POST /team  { gesprek_id, account_id, status, reden }   superbeheerders and gespreksbeheerders of the gesprek
    // status actief (restore), opgeschort or verwijderd; reden is required for the last two.
    // A gespreksbeheerder does not change his own status, so he cannot lock himself out; a superbeheerder
    // can, since he always gets to the team: like restoring himself after someone paused him.
    // Returns the lid, like GET.
    public function POST($id = null) {
        $input = Http::body();
        $gesprekId = $this->gesprekId($input);
        $account = Toegang::vereisRol($gesprekId, [Beheer::ROL_SUPERBEHEERDER, Beheer::ROL_GESPREKSBEHEERDER]);
        $accountId = Http::field($input, 'account_id');
        $status = Http::field($input, 'status');
        $reden = Http::line($input, 'reden');
        Beheer::controleerStatus($status, $reden);
        $lid = Beheer::team($gesprekId)[$accountId] ?? null;
        if ($lid === null) {
            throw new HttpFout(404, 'Lid not found in this team.');
        }
        if ($accountId === $account['id'] && !Beheer::isSuperbeheerder($account)) {
            throw new HttpFout(409, 'You cannot change your own status.');
        }
        if ($lid['status'] === Data::STATUS_VERWIJDERD) {
            throw new HttpFout(409, 'This lid is verwijderd; invite again instead.');
        }
        if ($lid['status'] !== $status) {
            Data::voegEventToe(Beheer::LID_STATUS_EVENTS[$status], Data::doorBeheerder($account), $gesprekId, Beheer::statusVelden($accountId, $status, $reden));
        }
        foreach ($this->leden($gesprekId) as $lid) {
            if ($lid['account_id'] === $accountId) {
                Http::json($lid);
            }
        }
    }

    // gesprek_id from the query or the body; a 400 or 404 if missing or unknown
    private function gesprekId(array $bron) {
        $gesprekId = Http::field($bron, 'gesprek_id');
        if ($gesprekId === '') {
            throw new HttpFout(400, 'gesprek_id is required.');
        }
        if (!Data::gesprekBestaat($gesprekId)) {
            throw new HttpFout(404, 'Gesprek not found.');
        }
        return $gesprekId;
    }

    private function leden($gesprekId) {
        $accounts = Beheer::accounts();
        return array_values(array_map(function ($lid) use ($accounts) {
            $account = $accounts[$lid['account_id']] ?? ['gebruikersnaam' => '', 'email' => ''];
            return ['account_id' => $lid['account_id'], 'gebruikersnaam' => $account['gebruikersnaam'], 'email' => $account['email']]
                + ['rol' => $lid['rol'], 'status' => $lid['status']];
        }, Beheer::team($gesprekId)));
    }
}
