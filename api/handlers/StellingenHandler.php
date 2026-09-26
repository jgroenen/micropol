<?php

// stellingen that deelnemers add; whether others see them depends on the moderatie, see Data::zichtbaar()
class StellingenHandler {
    const MAX_TEKST = 500;

    // GET /stellingen?gesprek_id=<id>&deelnemer_id=<id>   stellingen added by one deelnemer,
    // with their moderatie state (beoordeling, reden, zichtbaar) and antwoorden { eens, neutraal, oneens }
    public function GET($id = null) {
        $gesprekId = Http::field($_GET, 'gesprek_id');
        $deelnemerId = Http::field($_GET, 'deelnemer_id');
        if ($gesprekId === '' || $deelnemerId === '') {
            throw new HttpFout(400, 'gesprek_id and deelnemer_id are required.');
        }
        if (!Data::gesprekBestaat($gesprekId)) {
            throw new HttpFout(404, 'Gesprek not found.');
        }
        $stellingen = array_values(array_filter(Data::stellingen($gesprekId), function ($stelling) use ($deelnemerId) {
            return $stelling['deelnemer_id'] === $deelnemerId;
        }));
        Http::json(['stellingen' => Data::metTellingen($gesprekId, $stellingen)]);
    }

    // POST /stellingen  { gesprek_id, deelnemer_id, tekst }
    // returns the new stelling with its moderatie state, like GET
    public function POST($id = null) {
        $input = Http::body();
        $gesprekId = Http::field($input, 'gesprek_id');
        $deelnemerId = Http::field($input, 'deelnemer_id');
        $tekst = Http::line($input, 'tekst');
        if ($gesprekId === '' || $deelnemerId === '' || $tekst === '') {
            throw new HttpFout(400, 'gesprek_id, deelnemer_id and tekst are required.');
        }
        if (mb_strlen($tekst) > self::MAX_TEKST) {
            throw new HttpFout(400, 'tekst may be at most ' . self::MAX_TEKST . ' characters.');
        }
        if (!Data::gesprekBestaat($gesprekId)) {
            throw new HttpFout(404, 'Gesprek not found.');
        }

        $stellingId = Data::uuid();
        Data::voegEventToe(Data::STELLING_TOEGEVOEGD, Data::doorDeelnemer($deelnemerId), $gesprekId, ['stelling_id' => $stellingId, 'tekst' => $tekst]);
        Http::json(Data::stelling($gesprekId, $stellingId), 201);
    }
}
