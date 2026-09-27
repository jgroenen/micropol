<?php

// stellingen that deelnemers add; whether others see them depends on the moderatie, see Data::zichtbaar()
class StellingenHandler {
    const MAX_TEKST = 500;

    // GET /stellingen?gesprek_id=<id>   as a deelnemer (Authorization: Bearer <deelnemer_id>): the stellingen he
    // added, with their moderatie state (beoordeling, reden, zichtbaar) and antwoorden { eens, neutraal, oneens }
    public function GET($id = null) {
        $gesprekId = Http::field($_GET, 'gesprek_id');
        $deelnemerId = Toegang::vereisDeelnemer();
        if ($gesprekId === '') {
            throw new HttpFout(400, 'gesprek_id is required.');
        }
        if (!Data::gesprekBestaat($gesprekId)) {
            throw new HttpFout(404, 'Gesprek not found.');
        }
        $stellingen = array_values(array_filter(Data::stellingen($gesprekId), function ($stelling) use ($deelnemerId) {
            return $stelling['deelnemer_id'] === $deelnemerId;
        }));
        Http::json(['stellingen' => Data::metTellingen($gesprekId, $stellingen)]);
    }

    // POST /stellingen  { gesprek_id, tekst, kanaal }   as a deelnemer (Authorization: Bearer <deelnemer_id>)
    // returns the new stelling with its moderatie state, like GET; kanaal as in POST /antwoorden
    public function POST($id = null) {
        $input = Http::body();
        $gesprekId = Http::field($input, 'gesprek_id');
        $deelnemerId = Toegang::vereisDeelnemer();
        $tekst = Http::line($input, 'tekst');
        if ($gesprekId === '' || $tekst === '') {
            throw new HttpFout(400, 'gesprek_id and tekst are required.');
        }
        if (mb_strlen($tekst) > self::MAX_TEKST) {
            throw new HttpFout(400, 'tekst may be at most ' . self::MAX_TEKST . ' characters.');
        }
        if (!Data::gesprekBestaat($gesprekId)) {
            throw new HttpFout(404, 'Gesprek not found.');
        }
        if (!Data::gesprekActief($gesprekId)) {
            throw new HttpFout(409, 'This gesprek is paused or over.');
        }
        $kanaal = Data::kanaalVoorDeelname($gesprekId, Http::field($input, 'kanaal'));

        $stellingId = Data::uuid();
        Data::voegEventToe(Data::STELLING_TOEGEVOEGD, Data::doorDeelnemer($deelnemerId), $gesprekId, ['stelling_id' => $stellingId, 'tekst' => $tekst] + Data::deelnameVelden($kanaal));
        Data::telPanellink($gesprekId, $kanaal, 'stellingen');
        Http::json(Data::stelling($gesprekId, $stellingId), 201);
    }
}
