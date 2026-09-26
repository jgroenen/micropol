<?php

class StellingenHandler {
    private $maxLength = 500;

    // GET /stellingen?gesprek_id=<id>&deelnemer_id=<id>   stellingen added by one deelnemer,
    // with their moderatie state (beoordeling, reden, zichtbaar) and antwoorden { eens, neutraal, oneens }
    public function GET($id = null) {
        $gesprekId = Http::field($_GET, 'gesprek_id');
        $deelnemerId = Http::field($_GET, 'deelnemer_id');
        if ($gesprekId === '' || $deelnemerId === '') {
            Http::error(400, "gesprek_id and deelnemer_id are required.");
            return;
        }
        if (!Data::gesprekExists($gesprekId)) {
            Http::error(404, "Gesprek not found.");
            return;
        }

        $stellingen = array_values(array_filter(Data::stellingenMetBeoordeling($gesprekId), function ($stelling) use ($deelnemerId) {
            return $stelling['deelnemer_id'] === $deelnemerId;
        }));
        Http::json(["stellingen" => Data::metTellingen($gesprekId, $stellingen)]);
    }

    // POST /stellingen  { gesprek_id, deelnemer_id, tekst }
    // returns the new stelling with its moderatie state, like GET
    public function POST($id = null) {
        $input = Http::body();
        if ($input === null) {
            Http::error(400, "Invalid JSON body.");
            return;
        }

        $gesprekId = Http::field($input, 'gesprek_id');
        $deelnemerId = Http::field($input, 'deelnemer_id');
        // keep stellingen on a single line
        $tekst = trim(preg_replace('/\s+/', ' ', Http::field($input, 'tekst')));

        if ($gesprekId === '' || $deelnemerId === '' || $tekst === '') {
            Http::error(400, "gesprek_id, deelnemer_id and tekst are required.");
            return;
        }
        if (mb_strlen($tekst) > $this->maxLength) {
            Http::error(400, "tekst may be at most {$this->maxLength} characters.");
            return;
        }
        if (!Data::gesprekExists($gesprekId)) {
            Http::error(404, "Gesprek not found.");
            return;
        }

        $stelling = Csv::append(Data::stellingenFile(), Data::STELLINGEN, [Data::uuid(), $gesprekId, $tekst, $deelnemerId]);
        $stelling['beoordeling'] = null;
        $stelling['reden'] = '';
        $stelling['zichtbaar'] = Data::zichtbaar(Data::gesprek($gesprekId)['moderatie'], null);
        Http::json($stelling, 201);
    }
}
