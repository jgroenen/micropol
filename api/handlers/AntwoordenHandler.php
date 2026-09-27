<?php

// antwoorden of deelnemers on stellingen; answering again is fine, the last antwoord counts
class AntwoordenHandler {
    // GET /antwoorden?gesprek_id=<id>&deelnemer_id=<id>   { antwoorden: [Antwoord] } of one deelnemer
    // GET /antwoorden?gesprek_id=<id>                    matrix: { gesprek_id, deelnemers: [{ nummer, antwoorden }] },
    //                                                    anonymous, like the export
    public function GET($id = null) {
        $gesprekId = Http::field($_GET, 'gesprek_id');
        $deelnemerId = Http::field($_GET, 'deelnemer_id');
        if ($gesprekId === '') {
            throw new HttpFout(400, 'gesprek_id is required.');
        }
        if ($deelnemerId !== '' && !Data::deelnemerIdGeldig($deelnemerId)) {
            throw new HttpFout(400, 'deelnemer_id may only have letters, digits and dashes, at most ' . Data::MAX_DEELNEMER_ID . '.');
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

    // POST /antwoorden  { gesprek_id, deelnemer_id, stelling_id, waarde, kanaal }
    // kanaal: the token of the link the deelnemer came with (optional); required when the gesprek only takes
    // part through kanalen, see Data::kanaalVelden()
    public function POST($id = null) {
        $input = Http::body();
        $gesprekId = Http::field($input, 'gesprek_id');
        $deelnemerId = Http::field($input, 'deelnemer_id');
        $stellingId = Http::field($input, 'stelling_id');
        $waarde = Http::field($input, 'waarde');
        if ($gesprekId === '' || $deelnemerId === '' || $stellingId === '') {
            throw new HttpFout(400, 'gesprek_id, deelnemer_id and stelling_id are required.');
        }
        if (!Data::deelnemerIdGeldig($deelnemerId)) {
            throw new HttpFout(400, 'deelnemer_id may only have letters, digits and dashes, at most ' . Data::MAX_DEELNEMER_ID . '.');
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
        $kanaal = Data::kanaalVelden($gesprekId, Http::field($input, 'kanaal'));
        // only stellingen deelnemers can see, not those afgekeurd or waiting for goedkeuring
        if (!Data::stellingZichtbaar($stellingId, $gesprekId)) {
            throw new HttpFout(404, 'Stelling not found in this gesprek.');
        }

        Data::voegEventToe(Data::ANTWOORD_GEGEVEN, Data::doorDeelnemer($deelnemerId), $gesprekId, ['stelling_id' => $stellingId, 'waarde' => $waarde] + $kanaal);
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
