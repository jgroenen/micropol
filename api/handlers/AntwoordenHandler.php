<?php

class AntwoordenHandler {
    private $waarden = ['eens', 'oneens', 'neutraal'];

    // GET /antwoorden?gesprek_id=<id>&user_id=<id>   antwoorden of one user
    // GET /antwoorden?gesprek_id=<id>                matrix: one { stelling_id: waarde } per deelnemer
    public function GET($id = null) {
        $gesprekId = Http::field($_GET, 'gesprek_id');
        $userId = Http::field($_GET, 'user_id');
        if ($gesprekId === '') {
            Http::error(400, "gesprek_id is required.");
            return;
        }
        if (!Data::gesprekExists($gesprekId)) {
            Http::error(404, "Gesprek not found.");
            return;
        }

        if ($userId === '') {
            Http::json([
                "gesprek_id" => $gesprekId,
                "antwoorden" => $this->_matrix($gesprekId)
            ]);
            return;
        }

        Http::json([
            "antwoorden" => $this->_vanUser($gesprekId, $userId)
        ]);
    }

    // POST /antwoorden  { gesprek_id, user_id, stelling_id, waarde }
    public function POST($id = null) {
        $input = Http::body();
        if ($input === null) {
            Http::error(400, "Invalid JSON body.");
            return;
        }

        $gesprekId = Http::field($input, 'gesprek_id');
        $userId = Http::field($input, 'user_id');
        $stellingId = Http::field($input, 'stelling_id');
        $waarde = Http::field($input, 'waarde');

        if ($gesprekId === '' || $userId === '' || $stellingId === '') {
            Http::error(400, "gesprek_id, user_id and stelling_id are required.");
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

        $antwoord = Csv::append(Data::antwoordenFile($gesprekId), Data::ANTWOORDEN, [$userId, $stellingId, $waarde]);
        Http::json($antwoord, 201);
    }

    // the file is append only, so a later antwoord overrides an earlier one
    private function _vanUser($gesprekId, $userId) {
        $antwoorden = [];
        foreach (Data::antwoorden($gesprekId) as $antwoord) {
            if ($antwoord['user_id'] === $userId) {
                $antwoorden[$antwoord['stelling_id']] = $antwoord;
            }
        }
        return array_values($antwoorden);
    }

    // rows are deelnemers in order of first antwoord, without their user_id
    private function _matrix($gesprekId) {
        // cast to object so numeric stelling ids still encode as a json object
        return array_map(function ($row) {
            return (object) $row;
        }, array_values(Data::matrix($gesprekId)));
    }
}
