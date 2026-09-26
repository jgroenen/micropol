<?php

// moderatie of stellingen by beheerders; whether a stelling is shown follows from the moderatie
// of its gesprek and its last beoordeling, see Data::zichtbaar()
class BeoordelingenHandler {
    const MAX_REDEN = 500;

    // GET /beoordelingen?gesprek_id=<id>   beheerders only
    // all stellingen of the gesprek, in the order they were added, with beoordeling (or null),
    // reden, zichtbaar and antwoorden { eens, neutraal, oneens }
    public function GET($id = null) {
        Toegang::vereisBeheerder();
        $gesprekId = Http::field($_GET, 'gesprek_id');
        if ($gesprekId === '') {
            throw new HttpFout(400, 'gesprek_id is required.');
        }
        if (!Data::gesprekBestaat($gesprekId)) {
            throw new HttpFout(404, 'Gesprek not found.');
        }
        Http::json(['stellingen' => $this->stellingen($gesprekId)]);
    }

    // POST /beoordelingen  { gesprek_id, stelling_id, beoordeling, reden }   beheerders only
    // beoordeling is goedgekeurd or afgekeurd; reden is required for afgekeurd.
    // Returns the stelling with its new state, like GET.
    public function POST($id = null) {
        $beheerder = Toegang::vereisBeheerder();
        $input = Http::body();
        $gesprekId = Http::field($input, 'gesprek_id');
        $stellingId = Http::field($input, 'stelling_id');
        $beoordeling = Http::field($input, 'beoordeling');
        $reden = Http::line($input, 'reden');
        if ($gesprekId === '' || $stellingId === '') {
            throw new HttpFout(400, 'gesprek_id and stelling_id are required.');
        }
        if (!in_array($beoordeling, Data::BEOORDELING_WAARDEN, true)) {
            throw new HttpFout(400, 'beoordeling must be one of: ' . implode(', ', Data::BEOORDELING_WAARDEN) . '.');
        }
        if ($beoordeling === Data::BEOORDELING_AFGEKEURD && $reden === '') {
            throw new HttpFout(400, 'reden is required for afgekeurd.');
        }
        if (mb_strlen($reden) > self::MAX_REDEN) {
            throw new HttpFout(400, 'reden may be at most ' . self::MAX_REDEN . ' characters.');
        }
        if (!Data::gesprekBestaat($gesprekId)) {
            throw new HttpFout(404, 'Gesprek not found.');
        }
        if (!in_array($stellingId, array_column(Data::stellingen($gesprekId), 'id'), true)) {
            throw new HttpFout(404, 'Stelling not found in this gesprek.');
        }

        Data::voegToe("beoordelingen/$gesprekId", Data::BEOORDELINGEN, [$stellingId, $beoordeling, $reden, $beheerder['id'], time()]);
        foreach ($this->stellingen($gesprekId) as $stelling) {
            if ($stelling['id'] === $stellingId) {
                Http::json($stelling);
                return;
            }
        }
    }

    // all stellingen with moderatie state and tellingen, without deelnemer_id:
    // beheerders don't need to know who added a stelling
    private function stellingen($gesprekId) {
        return array_map(function ($stelling) {
            unset($stelling['deelnemer_id']);
            return $stelling;
        }, Data::metTellingen($gesprekId, Data::stellingenMetBeoordeling($gesprekId)));
    }
}
