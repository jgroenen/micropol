<?php

// antwoorden of deelnemers on stellingen; answering again is fine, the last antwoord counts
class AntwoordenHandler {
    // GET /antwoorden?gesprek_id=<id>   as a deelnemer (Authorization: Bearer <deelnemer_id>, see
    //                                    Toegang::deelnemerId()): { antwoorden: [Antwoord] } of that deelnemer
    // GET /antwoorden?gesprek_id=<id>                    matrix: { gesprek_id, deelnemers: [{ nummer, antwoorden }] },
    //                                                    anonymous, like the export
    public function GET($id = null) {
        $gesprekId = Http::field($_GET, 'gesprek_id');
        $deelnemerId = Toegang::deelnemerId();
        if ($gesprekId === '') {
            throw new HttpFout(400, 'gesprek_id is required.');
        }
        if (!Data::gesprekBestaat($gesprekId)) {
            throw new HttpFout(404, 'Gesprek not found.');
        }
        if ($deelnemerId === '') {
            Http::json(['gesprek_id' => $gesprekId, 'deelnemers' => Data::deelnemers($gesprekId)]);
            return;
        }
        Http::json(['antwoorden' => $this->vanDeelnemer($gesprekId, $deelnemerId)]);
    }

    // POST /antwoorden  { gesprek_id, stelling_id, waarde, kanaal }   as a deelnemer (Authorization: Bearer <deelnemer_id>)
    // kanaal: the token of the link the deelnemer came with (optional); required when the gesprek only takes
    // part through kanalen, see Data::kanaalVoorDeelname()
    public function POST($id = null) {
        $input = Http::body();
        $gesprekId = Http::field($input, 'gesprek_id');
        $deelnemerId = Toegang::vereisDeelnemer();
        $stellingId = Http::field($input, 'stelling_id');
        $waarde = Http::field($input, 'waarde');
        if ($gesprekId === '' || $stellingId === '') {
            throw new HttpFout(400, 'gesprek_id and stelling_id are required.');
        }
        if (!in_array($waarde, Data::WAARDEN, true)) {
            throw new HttpFout(400, 'waarde must be one of: ' . implode(', ', Data::WAARDEN) . '.');
        }
        if (!Data::gesprekBestaat($gesprekId)) {
            throw new HttpFout(404, 'Gesprek not found.');
        }
        if (!Data::gesprekActief($gesprekId)) {
            throw new HttpFout(409, 'This gesprek is paused or over.');
        }
        $kanaal = Data::kanaalVoorDeelname($gesprekId, Http::field($input, 'kanaal'));
        // only stellingen deelnemers can see, not those afgekeurd or waiting for goedkeuring
        if (!Data::stellingZichtbaar($stellingId, $gesprekId)) {
            throw new HttpFout(404, 'Stelling not found in this gesprek.');
        }

        Data::voegEventToe(Data::ANTWOORD_GEGEVEN, Data::doorDeelnemer($deelnemerId), $gesprekId, ['stelling_id' => $stellingId, 'waarde' => $waarde] + Data::deelnameVelden($kanaal));
        Data::telPanellink($gesprekId, $kanaal, 'antwoorden');
        Http::json(['gesprek_id' => $gesprekId, 'deelnemer_id' => $deelnemerId, 'stelling_id' => $stellingId, 'waarde' => $waarde], 201);
    }

    // the last antwoord of the deelnemer on each stelling; also through a kanaal that does not count
    private function vanDeelnemer($gesprekId, $deelnemerId) {
        $antwoorden = [];
        foreach (Data::matrix($gesprekId, true)[$deelnemerId] ?? [] as $stellingId => $waarde) {
            $antwoorden[] = ['gesprek_id' => $gesprekId, 'deelnemer_id' => $deelnemerId, 'stelling_id' => (string) $stellingId, 'waarde' => $waarde];
        }
        return $antwoorden;
    }
}
