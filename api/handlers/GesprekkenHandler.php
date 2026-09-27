<?php

// gesprekken: everyone reads them, superbeheerders make them and gespreksbeheerders change them (see Beheer)
class GesprekkenHandler {
    const MAX_TITEL = 200;
    const MAX_OMSCHRIJVING = 1000;

    // GET /gesprekken            the gesprekken deelnemers can take part in (status actief);
    //                            logged in, the ones for the admin, each with the rol of the account in it
    //                            (or null): superbeheerders see all of them, also paused and ended ones,
    //                            the others the gesprekken of their team
    // GET /gesprekken/<id>       one gesprek with its zichtbare stellingen, in random order; a paused or
    //                            ended gesprek has no stellingen, the app shows a notice instead
    public function GET($id = null) {
        if ($id === null) {
            $account = Toegang::account();
            Http::json(['gesprekken' => $account === null ? $this->actieve() : $this->voorBeheer($account)]);
            return;
        }
        if (!Data::gesprekBestaat($id)) {
            throw new HttpFout(404, 'Gesprek not found.');
        }
        $gesprek = Data::gesprek($id);
        // only id and tekst: who added a stelling stays private
        $gesprek['stellingen'] = !Data::gesprekActief($id) ? [] : array_map(function ($stelling) {
            return ['id' => $stelling['id'], 'tekst' => $stelling['tekst']];
        }, $this->volgorde(Data::zichtbareStellingen($id)));
        Http::json($gesprek);
    }

    // POST /gesprekken  { titel, omschrijving, moderatie, zonder_kanaal }   superbeheerders only; returns the new gesprek
    // moderatie is optional, default achteraf; zonder_kanaal optional, default true. Its team comes in with
    // uitnodigingen (POST /uitnodigingen).
    public function POST($id = null) {
        $beheerder = Toegang::vereisSuperbeheerder();
        $velden = $this->velden(Http::body(), Data::MODERATIE_ACHTERAF, true);
        $id = Data::uuid();
        Data::voegEventToe(Data::GESPREK_AANGEMAAKT, Data::doorBeheerder($beheerder), $id, $velden);
        Http::json(Data::gesprek($id), 201);
    }

    // PUT /gesprekken/<id>  { titel, omschrijving, moderatie, zonder_kanaal }   gespreksbeheerders of the gesprek only;
    // returns the changed gesprek. moderatie and zonder_kanaal are optional, default unchanged; the event has only the fields
    // that changed, and there is no event when nothing changed
    public function PUT($id = null) {
        if ($id === null || !Data::gesprekBestaat($id)) {
            throw new HttpFout(404, 'Gesprek not found.');
        }
        $beheerder = Toegang::vereisRol($id, [Beheer::ROL_GESPREKSBEHEERDER]);
        $gesprek = Data::gesprek($id);
        $gewijzigd = array_diff_assoc($this->velden(Http::body(), $gesprek['moderatie'] ?: Data::MODERATIE_ACHTERAF, $gesprek['zonder_kanaal']), $gesprek);
        if ($gewijzigd) {
            Data::voegEventToe(Data::GESPREK_AANGEPAST, Data::doorBeheerder($beheerder), $id, $gewijzigd);
        }
        Http::json(Data::gesprek($id));
    }

    // { titel, omschrijving, moderatie, zonder_kanaal } from the body
    private function velden(array $input, $standaardModeratie, $standaardZonderKanaal) {
        // zonder_kanaal is a boolean; without it, it stays as it was (or true for a new gesprek)
        $zonderKanaal = array_key_exists('zonder_kanaal', $input) ? $input['zonder_kanaal'] : $standaardZonderKanaal;
        if (!is_bool($zonderKanaal)) {
            throw new HttpFout(400, 'zonder_kanaal must be true or false.');
        }
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
        return ['titel' => $titel, 'omschrijving' => $omschrijving, 'moderatie' => $moderatie, 'zonder_kanaal' => $zonderKanaal];
    }

    private function actieve() {
        return array_values(array_filter(Data::gesprekken(), function ($gesprek) {
            return $gesprek['status'] === Data::STATUS_ACTIEF;
        }));
    }

    private function voorBeheer(array $account) {
        $superbeheerder = Beheer::isSuperbeheerder($account);
        $rollen = Beheer::rollen($account);
        $gesprekken = [];
        foreach (Data::gesprekken() as $gesprek) {
            if ($superbeheerder || isset($rollen[$gesprek['id']])) {
                $gesprekken[] = $gesprek + ['rol' => $rollen[$gesprek['id']] ?? null];
            }
        }
        return $gesprekken;
    }

    // the order in which stellingen are offered for answering; random for now,
    // this is the place for a smarter algorithm later
    private function volgorde(array $stellingen) {
        shuffle($stellingen);
        return $stellingen;
    }
}
