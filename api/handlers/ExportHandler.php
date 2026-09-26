<?php

// The standard export of a gesprek, for the math server (math/) and other tools.
// Anonymous: deelnemers are numbered in order of their first antwoord (the same numbers as the
// matrix of GET /antwoorden), without deelnemer_id. Only zichtbare stellingen and antwoorden on those;
// the last antwoord of each deelnemer counts.
class ExportHandler {
    const FORMAAT = 'minipol-export';
    const VERSIE = 1;

    // GET /export?gesprek_id=<id>
    // { formaat, versie, gegenereerd, gesprek: { id, titel }, stellingen: [{ id, tekst }],
    //   deelnemers: [{ nummer, antwoorden: { stelling_id: waarde } }] }
    public function GET($id = null) {
        $gesprekId = Http::field($_GET, 'gesprek_id');
        if ($gesprekId === '') {
            Http::error(400, "gesprek_id is required.");
            return;
        }
        if (!Data::gesprekExists($gesprekId)) {
            Http::error(404, "Gesprek not found.");
            return;
        }

        $gesprek = Data::gesprek($gesprekId);
        $stellingen = array_map(function ($stelling) {
            return ['id' => $stelling['id'], 'tekst' => $stelling['tekst']];
        }, Data::zichtbareStellingen($gesprekId));
        $deelnemers = Data::deelnemers($gesprekId, array_column($stellingen, 'id'));

        Http::json([
            'formaat' => self::FORMAAT,
            'versie' => self::VERSIE,
            'gegenereerd' => date('c'),
            'gesprek' => ['id' => $gesprek['id'], 'titel' => $gesprek['titel']],
            'stellingen' => $stellingen,
            'deelnemers' => $deelnemers,
        ]);
    }
}
