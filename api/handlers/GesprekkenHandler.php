<?php

// gesprekken: everyone reads them, beheerders make and change them
class GesprekkenHandler {
    const MAX_TITEL = 200;
    const MAX_OMSCHRIJVING = 1000;

    // GET /gesprekken            all gesprekken
    // GET /gesprekken/<id>       one gesprek with its zichtbare stellingen, in random order
    public function GET($id = null) {
        if ($id === null) {
            Http::json(['gesprekken' => Data::gesprekken()]);
            return;
        }
        $gesprek = Data::gesprek($id);
        if ($gesprek === null) {
            throw new HttpFout(404, 'Gesprek not found.');
        }
        // only id and tekst: who added a stelling stays private
        $gesprek['stellingen'] = array_map(function ($stelling) {
            return ['id' => $stelling['id'], 'tekst' => $stelling['tekst']];
        }, $this->volgorde(Data::zichtbareStellingen($id)));
        Http::json($gesprek);
    }

    // POST /gesprekken  { titel, omschrijving, moderatie }   beheerders only; returns the new gesprek
    // moderatie is optional, default achteraf
    public function POST($id = null) {
        $beheerder = Toegang::vereisBeheerder();
        $velden = $this->velden(Http::body(), Data::MODERATIE_ACHTERAF);
        $id = Data::uuid();
        Data::voegEventToe(Data::GESPREK_AANGEMAAKT, Data::doorBeheerder($beheerder), $id, $velden);
        Http::json(Data::gesprek($id), 201);
    }

    // PUT /gesprekken/<id>  { titel, omschrijving, moderatie }   beheerders only; returns the changed gesprek
    // moderatie is optional, default unchanged; the event has only the fields that changed, and there is
    // no event when nothing changed
    public function PUT($id = null) {
        $beheerder = Toegang::vereisBeheerder();
        if ($id === null || !Data::gesprekBestaat($id)) {
            throw new HttpFout(404, 'Gesprek not found.');
        }
        $gesprek = Data::gesprek($id);
        $gewijzigd = array_diff_assoc($this->velden(Http::body(), $gesprek['moderatie'] ?: Data::MODERATIE_ACHTERAF), $gesprek);
        if ($gewijzigd) {
            Data::voegEventToe(Data::GESPREK_AANGEPAST, Data::doorBeheerder($beheerder), $id, $gewijzigd);
        }
        Http::json(Data::gesprek($id));
    }

    // { titel, omschrijving, moderatie } from the body
    private function velden(array $input, $standaardModeratie) {
        $titel = Http::line($input, 'titel');
        $omschrijving = Http::line($input, 'omschrijving');
        $moderatie = Http::field($input, 'moderatie') ?: $standaardModeratie;
        if ($titel === '') {
            throw new HttpFout(400, 'titel is required.');
        }
        if (mb_strlen($titel) > self::MAX_TITEL) {
            throw new HttpFout(400, 'titel may be at most ' . self::MAX_TITEL . ' characters.');
        }
        if (mb_strlen($omschrijving) > self::MAX_OMSCHRIJVING) {
            throw new HttpFout(400, 'omschrijving may be at most ' . self::MAX_OMSCHRIJVING . ' characters.');
        }
        if (!in_array($moderatie, Data::MODERATIES, true)) {
            throw new HttpFout(400, 'moderatie must be one of: ' . implode(', ', Data::MODERATIES) . '.');
        }
        return ['titel' => $titel, 'omschrijving' => $omschrijving, 'moderatie' => $moderatie];
    }

    // the order in which stellingen are offered for answering; random for now,
    // this is the place for a smarter algorithm later
    private function volgorde(array $stellingen) {
        shuffle($stellingen);
        return $stellingen;
    }
}
