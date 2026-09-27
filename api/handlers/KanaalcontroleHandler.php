<?php

// whether the link of a kanaal still works, for the app when a deelnemer comes back. A POST, so the token is
// in the body and never in a url: urls end up in logs, and with a link of a panel the token belongs to one person.
class KanaalcontroleHandler {
    // POST /kanaalcontrole  { gesprek_id, kanaal } => { geldig }
    public function POST($id = null) {
        $input = Http::body();
        $gesprekId = Http::field($input, 'gesprek_id');
        $token = Http::field($input, 'kanaal');
        if ($gesprekId === '' || $token === '') {
            throw new HttpFout(400, 'gesprek_id and kanaal are required.');
        }
        if (!Data::gesprekBestaat($gesprekId)) {
            throw new HttpFout(404, 'Gesprek not found.');
        }
        Http::json(['geldig' => Data::kanaalMetToken($gesprekId, $token) !== null]);
    }
}
