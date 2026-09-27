<?php

// stellingen that deelnemers add; whether others see them depends on the moderatie, see Data::zichtbaar()
class StellingenHandler {
    const MAX_TEKST = 500;

    // GET /stellingen?gesprek_id=<id>   with header MiniPol-Deelnemer: <id>   stellingen added by one deelnemer,
    // with their moderatie state (beoordeling, reden, zichtbaar) and antwoorden { eens, neutraal, oneens };
    // the deelnemer_id is a header, never in the url (see AntwoordenHandler)
    public function GET($id = null) {
        $gesprekId = Http::field($_GET, 'gesprek_id');
        $deelnemerId = Http::kop('MiniPol-Deelnemer');
        if ($gesprekId === '' || $deelnemerId === '') {
            throw new HttpFout(400, 'gesprek_id and the header MiniPol-Deelnemer are required.');
        }
        if (!Data::deelnemerIdGeldig($deelnemerId)) {
            throw new HttpFout(400, 'deelnemer_id may only have letters, digits and dashes, at most ' . Data::MAX_DEELNEMER_ID . '.');
        }
        if (!Data::gesprekBestaat($gesprekId)) {
            throw new HttpFout(404, 'Gesprek not found.');
        }
        $stellingen = array_values(array_filter(Data::stellingen($gesprekId), function ($stelling) use ($deelnemerId) {
            return $stelling['deelnemer_id'] === $deelnemerId;
        }));
        Http::json(['stellingen' => Data::metTellingen($gesprekId, $stellingen)]);
    }

    // POST /stellingen  { gesprek_id, deelnemer_id, tekst, kanaal }
    // returns the new stelling with its moderatie state, like GET; kanaal as in POST /antwoorden
    public function POST($id = null) {
        $input = Http::body();
        $gesprekId = Http::field($input, 'gesprek_id');
        $deelnemerId = Http::field($input, 'deelnemer_id');
        $tekst = Http::line($input, 'tekst');
        if ($gesprekId === '' || $deelnemerId === '' || $tekst === '') {
            throw new HttpFout(400, 'gesprek_id, deelnemer_id and tekst are required.');
        }
        if (!Data::deelnemerIdGeldig($deelnemerId)) {
            throw new HttpFout(400, 'deelnemer_id may only have letters, digits and dashes, at most ' . Data::MAX_DEELNEMER_ID . '.');
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
