<?php

class AntwoordenHandler {
    private $waarden = ['eens', 'oneens', 'neutraal'];

    // GET /antwoorden?gesprek_id=<id>&deelnemer_id=<id>   { antwoorden: [Antwoord] } of one deelnemer
    // GET /antwoorden?gesprek_id=<id>                matrix: { gesprek_id, deelnemers: [{ nummer, antwoorden }] },
    //                                                anonymous, like the export
    public function GET($id = null) {
        $gesprekId = Http::field($_GET, 'gesprek_id');
        $deelnemerId = Http::field($_GET, 'deelnemer_id');
        if ($gesprekId === '') {
            Http::error(400, "gesprek_id is required.");
            return;
        }
        if (!Data::gesprekExists($gesprekId)) {
            Http::error(404, "Gesprek not found.");
            return;
        }

        if ($deelnemerId === '') {
            Http::json([
                "gesprek_id" => $gesprekId,
                "deelnemers" => Data::deelnemers($gesprekId)
            ]);
            return;
        }

        Http::json([
            "antwoorden" => $this->_vanDeelnemer($gesprekId, $deelnemerId)
        ]);
    }

    // POST /antwoorden  { gesprek_id, deelnemer_id, stelling_id, waarde }
    public function POST($id = null) {
        $input = Http::body();
        if ($input === null) {
            Http::error(400, "Invalid JSON body.");
            return;
        }

        $gesprekId = Http::field($input, 'gesprek_id');
        $deelnemerId = Http::field($input, 'deelnemer_id');
        $stellingId = Http::field($input, 'stelling_id');
        $waarde = Http::field($input, 'waarde');

        if ($gesprekId === '' || $deelnemerId === '' || $stellingId === '') {
            Http::error(400, "gesprek_id, deelnemer_id and stelling_id are required.");
            return;
        }
        if (!in_array($waarde, $this->waarden, true)) {
            Http::error(400, "waarde must be one of: " . implode(', ', $this->waarden) . ".");
            return;
        }
        if (!Data::gesprekExists($gesprekId)) {
            Http::error(404, "Gesprek not found.");
            return;
        }
        // only stellingen deelnemers can see, not those afgekeurd or waiting for goedkeuring
        if (!Data::stellingZichtbaar($stellingId, $gesprekId)) {
            Http::error(400, "Stelling is not part of this gesprek.");
            return;
        }

        $antwoord = Csv::append(Data::antwoordenFile($gesprekId), Data::ANTWOORDEN, [$deelnemerId, $stellingId, $waarde]);
        Http::json(['gesprek_id' => $gesprekId] + $antwoord, 201);
    }

    // the file is append only, so a later antwoord overrides an earlier one
    private function _vanDeelnemer($gesprekId, $deelnemerId) {
        $antwoorden = [];
        foreach (Data::antwoorden($gesprekId) as $antwoord) {
            if ($antwoord['deelnemer_id'] === $deelnemerId) {
                $antwoorden[$antwoord['stelling_id']] = ['gesprek_id' => $gesprekId] + $antwoord;
            }
        }
        return array_values($antwoorden);
    }
}
