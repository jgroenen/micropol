<?php

// pausing, ending or opening again a gesprek, by a superbeheerder. A paused gesprek (opgeschort) shows
// deelnemers that it is paused, an ended one (beeindigd) that it is over; both take no antwoorden or
// stellingen, and their team has no access. A gesprek is never removed: its events stay.
class GespreksstatusHandler {
    // POST /gespreksstatus  { gesprek_id, status, reden }   superbeheerders only
    // status actief (open again), opgeschort or beeindigd; reden is required for the last two.
    // Returns the gesprek { id, titel, omschrijving, moderatie, status }.
    public function POST($id = null) {
        $account = Toegang::vereisSuperbeheerder();
        $input = Http::body();
        $gesprekId = Http::field($input, 'gesprek_id');
        $status = Http::field($input, 'status');
        $reden = Http::line($input, 'reden');
        if ($gesprekId === '') {
            throw new HttpFout(400, 'gesprek_id is required.');
        }
        Beheer::controleerStatus($status, $reden, Data::GESPREK_STATUSSEN);
        if (!Data::gesprekBestaat($gesprekId)) {
            throw new HttpFout(404, 'Gesprek not found.');
        }
        if (Data::gesprek($gesprekId)['status'] !== $status) {
            $velden = $status === Data::STATUS_ACTIEF ? [] : ['reden' => $reden];
            Data::voegEventToe(Data::GESPREK_STATUS_EVENTS[$status], Data::doorBeheerder($account), $gesprekId, $velden);
        }
        Http::json(Data::gesprek($gesprekId));
    }
}
