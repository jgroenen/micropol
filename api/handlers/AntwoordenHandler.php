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
        if (!Data::gesprekBestaat($gesprekId)) {
            throw new HttpFout(404, 'Gesprek not found.');
        }
        if ($deelnemerId === '') {
            Http::json(['gesprek_id' => $gesprekId, 'deelnemers' => Data::deelnemers($gesprekId)]);
            return;
        }
        Http::json(['antwoorden' => $this->vanDeelnemer($gesprekId, $deelnemerId)]);
    }

    // POST /antwoorden  { gesprek_id, deelnemer_id, stelling_id, waarde }
    public function POST($id = null) {
        $input = Http::body();
        $gesprekId = Http::field($input, 'gesprek_id');
        $deelnemerId = Http::field($input, 'deelnemer_id');
        $stellingId = Http::field($input, 'stelling_id');
        $waarde = Http::field($input, 'waarde');
        if ($gesprekId === '' || $deelnemerId === '' || $stellingId === '') {
            throw new HttpFout(400, 'gesprek_id, deelnemer_id and stelling_id are required.');
        }
        if (!in_array($waarde, Data::WAARDEN, true)) {
            throw new HttpFout(400, 'waarde must be one of: ' . implode(', ', Data::WAARDEN) . '.');
        }
        if (!Data::gesprekBestaat($gesprekId)) {
            throw new HttpFout(404, 'Gesprek not found.');
        }
        // only stellingen deelnemers can see, not those afgekeurd or waiting for goedkeuring
        if (!Data::stellingZichtbaar($stellingId, $gesprekId)) {
            throw new HttpFout(404, 'Stelling not found in this gesprek.');
        }

        $antwoord = Data::voegToe("antwoorden/$gesprekId", Data::ANTWOORDEN, [$deelnemerId, $stellingId, $waarde]);
        Http::json(['gesprek_id' => $gesprekId] + $antwoord, 201);
    }

    // the last antwoord of the deelnemer on each stelling
    private function vanDeelnemer($gesprekId, $deelnemerId) {
        $antwoorden = [];
        foreach (Data::antwoorden($gesprekId) as $antwoord) {
            if ($antwoord['deelnemer_id'] === $deelnemerId) {
                $antwoorden[$antwoord['stelling_id']] = ['gesprek_id' => $gesprekId] + $antwoord;
            }
        }
        return array_values($antwoorden);
    }
}
