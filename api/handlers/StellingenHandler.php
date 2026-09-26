<?php

class StellingenHandler {
    private $maxLength = 500;

    // GET /stellingen?gesprek_id=<id>&user_id=<id>   stellingen added by one user,
    // with their moderatie state (beoordeling, reden, zichtbaar) and antwoorden { eens, neutraal, oneens }
    public function GET($id = null) {
        $gesprekId = Http::field($_GET, 'gesprek_id');
        $userId = Http::field($_GET, 'user_id');
        if ($gesprekId === '' || $userId === '') {
            Http::error(400, "gesprek_id and user_id are required.");
            return;
        }
        if (!Data::gesprekExists($gesprekId)) {
            Http::error(404, "Gesprek not found.");
            return;
        }

        $stellingen = array_values(array_filter(Data::stellingenMetBeoordeling($gesprekId), function ($stelling) use ($userId) {
            return $stelling['user_id'] === $userId;
        }));
        Http::json(["stellingen" => Data::metTellingen($gesprekId, $stellingen)]);
    }

    // POST /stellingen  { gesprek_id, user_id, content }
    // returns the new stelling with its moderatie state, like GET
    public function POST($id = null) {
        $input = Http::body();
        if ($input === null) {
            Http::error(400, "Invalid JSON body.");
            return;
        }

        $gesprekId = Http::field($input, 'gesprek_id');
        $userId = Http::field($input, 'user_id');
        // keep stellingen on a single line
        $content = trim(preg_replace('/\s+/', ' ', Http::field($input, 'content')));

        if ($gesprekId === '' || $userId === '' || $content === '') {
            Http::error(400, "gesprek_id, user_id and content are required.");
            return;
        }
        if (mb_strlen($content) > $this->maxLength) {
            Http::error(400, "content may be at most {$this->maxLength} characters.");
            return;
        }
        if (!Data::gesprekExists($gesprekId)) {
            Http::error(404, "Gesprek not found.");
            return;
        }

        $stelling = Csv::append(Data::stellingenFile(), Data::STELLINGEN, [Data::uuid(), $gesprekId, $content, $userId]);
        $stelling['beoordeling'] = null;
        $stelling['reden'] = '';
        $stelling['zichtbaar'] = Data::zichtbaar(Data::gesprek($gesprekId)['moderatie'], null);
        Http::json($stelling, 201);
    }
}
