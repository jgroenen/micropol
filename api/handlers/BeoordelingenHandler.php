<?php

// moderatie of stellingen by admins; whether a stelling is shown follows from the moderatie
// of its gesprek and its last beoordeling, see Data::zichtbaar()
class BeoordelingenHandler {
    private $maxReden = 500;

    // GET /beoordelingen?gesprek_id=<id>   admin only
    // all stellingen of the gesprek, in the order they were added, with beoordeling (or null),
    // reden, zichtbaar and antwoorden { eens, neutraal, oneens }
    public function GET($id = null) {
        if (!Sessie::vereisBeheerder()) {
            return;
        }
        $gesprekId = Http::field($_GET, 'gesprek_id');
        if ($gesprekId === '') {
            Http::error(400, "gesprek_id is required.");
            return;
        }
        if (!Data::gesprekExists($gesprekId)) {
            Http::error(404, "Gesprek not found.");
            return;
        }

        // without deelnemer_id: beheerders don't need to know who added a stelling
        $stellingen = array_map(function ($stelling) {
            unset($stelling['deelnemer_id']);
            return $stelling;
        }, Data::metTellingen($gesprekId, Data::stellingenMetBeoordeling($gesprekId)));
        Http::json(["stellingen" => $stellingen]);
    }

    // POST /beoordelingen  { gesprek_id, stelling_id, beoordeling, reden }   admin only
    // beoordeling is goedgekeurd or afgekeurd; reden is required for afgekeurd.
    // Returns the stelling with its new state, like GET.
    public function POST($id = null) {
        $beheerder = Sessie::vereisBeheerder();
        if (!$beheerder) {
            return;
        }
        $input = Http::body();
        if ($input === null) {
            Http::error(400, "Invalid JSON body.");
            return;
        }

        $gesprekId = Http::field($input, 'gesprek_id');
        $stellingId = Http::field($input, 'stelling_id');
        $beoordeling = Http::field($input, 'beoordeling');
        $reden = trim(preg_replace('/\s+/', ' ', Http::field($input, 'reden')));

        if ($gesprekId === '' || $stellingId === '') {
            Http::error(400, "gesprek_id and stelling_id are required.");
            return;
        }
        if (!in_array($beoordeling, [Data::BEOORDELING_GOEDGEKEURD, Data::BEOORDELING_AFGEKEURD], true)) {
            Http::error(400, "beoordeling must be one of: " . Data::BEOORDELING_GOEDGEKEURD . ", " . Data::BEOORDELING_AFGEKEURD . ".");
            return;
        }
        if ($beoordeling === Data::BEOORDELING_AFGEKEURD && $reden === '') {
            Http::error(400, "reden is required for afgekeurd.");
            return;
        }
        if (mb_strlen($reden) > $this->maxReden) {
            Http::error(400, "reden may be at most {$this->maxReden} characters.");
            return;
        }
        if (!Data::gesprekExists($gesprekId)) {
            Http::error(404, "Gesprek not found.");
            return;
        }
        if (!in_array($stellingId, array_column(Data::stellingen($gesprekId), 'id'), true)) {
            Http::error(404, "Stelling not found in this gesprek.");
            return;
        }

        Csv::append(Data::beoordelingenFile($gesprekId), Data::BEOORDELINGEN, [$stellingId, $beoordeling, $reden, $beheerder['id'], date('c')]);

        foreach (Data::metTellingen($gesprekId, Data::stellingenMetBeoordeling($gesprekId)) as $stelling) {
            if ($stelling['id'] === $stellingId) {
                unset($stelling['deelnemer_id']);
                Http::json($stelling);
                return;
            }
        }
    }
}
