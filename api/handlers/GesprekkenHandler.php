<?php

class GesprekkenHandler {
    private $maxTitel = 200;
    private $maxOmschrijving = 1000;

    // GET /gesprekken            all gesprekken
    // GET /gesprekken/<id>       one gesprek with its zichtbare stellingen, in random order
    public function GET($id = null) {
        if ($id === null) {
            Http::json(["gesprekken" => Data::gesprekken()]);
            return;
        }

        $gesprek = Data::gesprek($id);
        if ($gesprek === null) {
            Http::error(404, "Gesprek not found.");
            return;
        }
        // only id and content: who added a stelling stays private
        $gesprek['stellingen'] = array_map(function ($stelling) {
            return ['id' => $stelling['id'], 'content' => $stelling['content']];
        }, $this->_volgorde(Data::zichtbareStellingen($id)));

        Http::json($gesprek);
    }

    // POST /gesprekken  { titel, omschrijving, moderatie }   admin only; returns the new gesprek
    // moderatie is optional, default achteraf
    public function POST($id = null) {
        $velden = $this->_velden(Data::MODERATIE_ACHTERAF);
        if ($velden === null) {
            return;
        }
        $gesprek = Csv::append(Data::gesprekkenFile(), Data::GESPREKKEN, array_merge([Data::uuid()], $velden));
        Http::json($gesprek, 201);
    }

    // PUT /gesprekken/<id>  { titel, omschrijving, moderatie }   admin only; returns the changed gesprek
    // moderatie is optional, default unchanged;
    // the file is append only, so this adds a row with the same id (see Data::gesprekken())
    public function PUT($id = null) {
        if (!Sessie::vereisUser()) {
            return;
        }
        if ($id === null || !Data::gesprekExists($id)) {
            Http::error(404, "Gesprek not found.");
            return;
        }
        $velden = $this->_velden(Data::gesprek($id)['moderatie'] ?: Data::MODERATIE_ACHTERAF);
        if ($velden === null) {
            return;
        }
        $gesprek = Csv::append(Data::gesprekkenFile(), Data::GESPREKKEN, array_merge([$id], $velden));
        Http::json($gesprek);
    }

    // [titel, omschrijving, moderatie] from the body of an admin request, or null after sending an error
    private function _velden($standaardModeratie) {
        if (!Sessie::vereisUser()) {
            return null;
        }
        $input = Http::body();
        if ($input === null) {
            Http::error(400, "Invalid JSON body.");
            return null;
        }

        // single lines, like stellingen
        $titel = trim(preg_replace('/\s+/', ' ', Http::field($input, 'titel')));
        $omschrijving = trim(preg_replace('/\s+/', ' ', Http::field($input, 'omschrijving')));
        $moderatie = Http::field($input, 'moderatie') ?: $standaardModeratie;
        if ($titel === '') {
            Http::error(400, "titel is required.");
            return null;
        }
        if (mb_strlen($titel) > $this->maxTitel) {
            Http::error(400, "titel may be at most {$this->maxTitel} characters.");
            return null;
        }
        if (mb_strlen($omschrijving) > $this->maxOmschrijving) {
            Http::error(400, "omschrijving may be at most {$this->maxOmschrijving} characters.");
            return null;
        }
        if (!in_array($moderatie, [Data::MODERATIE_ACHTERAF, Data::MODERATIE_VOORAF], true)) {
            Http::error(400, "moderatie must be one of: " . Data::MODERATIE_ACHTERAF . ", " . Data::MODERATIE_VOORAF . ".");
            return null;
        }
        return [$titel, $omschrijving, $moderatie];
    }

    // the order in which stellingen are offered for answering; random for now,
    // this is the place for a smarter algorithm later
    private function _volgorde(array $stellingen) {
        shuffle($stellingen);
        return $stellingen;
    }
}
