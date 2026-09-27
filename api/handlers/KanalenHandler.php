<?php

// the kanalen of a gesprek: a link for taking part per promotion channel (like a newsletter or an ad), see
// Data::kanalen(). The team sees them with how many deelnemers and antwoorden came through each; its
// gespreksbeheerders make them, turn meetellen on or off, and withdraw them.
class KanalenHandler {
    const MAX_NAAM = 100;

    // GET /kanalen?gesprek_id=<id>   the team of the gesprek
    // { kanalen: [Kanaal with deelnemers and antwoorden], zonder_kanaal: { deelnemers, antwoorden } }
    public function GET($id = null) {
        $gesprekId = $this->gesprekId($_GET);
        Toegang::vereisRol($gesprekId, Beheer::TEAMROLLEN);
        [$perKanaal, $zonder] = $this->aantallen($gesprekId);
        Http::json([
            'kanalen' => array_values(array_map(function ($kanaal) use ($perKanaal) {
                return $kanaal + ($perKanaal[$kanaal['kanaal_id']] ?? ['deelnemers' => 0, 'antwoorden' => 0]);
            }, Data::kanalen($gesprekId))),
            'zonder_kanaal' => $zonder,
        ]);
    }

    // POST /kanalen  { gesprek_id, naam }   gespreksbeheerders of the gesprek; returns the new kanaal
    public function POST($id = null) {
        $input = Http::body();
        $gesprekId = $this->gesprekId($input);
        $beheerder = Toegang::vereisRol($gesprekId, [Beheer::ROL_GESPREKSBEHEERDER]);
        $naam = Http::line($input, 'naam');
        if ($naam === '' || mb_strlen($naam) > self::MAX_NAAM) {
            throw new HttpFout(400, 'naam is required, at most ' . self::MAX_NAAM . ' characters.');
        }
        $kanaalId = Data::uuid();
        // short, for a link that is easy to share; no secret
        $token = bin2hex(random_bytes(8));
        Data::voegEventToe(Data::KANAAL_AANGEMAAKT, Data::doorBeheerder($beheerder), $gesprekId, ['kanaal_id' => $kanaalId, 'naam' => $naam, 'token' => $token]);
        Http::json(Data::kanalen($gesprekId)[$kanaalId] + ['deelnemers' => 0, 'antwoorden' => 0], 201);
    }

    // PUT /kanalen/<kanaal_id>  { gesprek_id, meetellen, status }   gespreksbeheerders of the gesprek
    // meetellen (true or false): whether the antwoorden through it count; status ingetrokken: the link works
    // no more, for good. Both optional. Returns the kanaal, like GET.
    public function PUT($id = null) {
        $input = Http::body();
        $gesprekId = $this->gesprekId($input);
        $beheerder = Toegang::vereisRol($gesprekId, [Beheer::ROL_GESPREKSBEHEERDER]);
        $kanaal = Data::kanalen($gesprekId)[$id ?? ''] ?? null;
        if ($kanaal === null) {
            throw new HttpFout(404, 'Kanaal not found in this gesprek.');
        }
        $meetellen = array_key_exists('meetellen', $input) ? $input['meetellen'] : $kanaal['meetellen'];
        if (!is_bool($meetellen)) {
            throw new HttpFout(400, 'meetellen must be true or false.');
        }
        $status = Http::field($input, 'status') ?: $kanaal['status'];
        if (!in_array($status, [Data::KANAAL_ACTIEF, Data::KANAAL_INGETROKKEN_STATUS], true)) {
            throw new HttpFout(400, 'status must be actief or ingetrokken.');
        }
        if ($kanaal['status'] === Data::KANAAL_INGETROKKEN_STATUS && $status === Data::KANAAL_ACTIEF) {
            throw new HttpFout(409, 'A kanaal that is ingetrokken stays so; make a new one.');
        }

        $door = Data::doorBeheerder($beheerder);
        if ($status !== $kanaal['status']) {
            Data::voegEventToe(Data::KANAAL_INGETROKKEN, $door, $gesprekId, ['kanaal_id' => $id, 'meetellen' => $meetellen]);
        } elseif ($meetellen !== $kanaal['meetellen']) {
            Data::voegEventToe(Data::KANAAL_AANGEPAST, $door, $gesprekId, ['kanaal_id' => $id, 'meetellen' => $meetellen]);
        }
        [$perKanaal] = $this->aantallen($gesprekId);
        Http::json(Data::kanalen($gesprekId)[$id] + ($perKanaal[$id] ?? ['deelnemers' => 0, 'antwoorden' => 0]));
    }

    // gesprek_id from the query or the body; a 400 or 404 if missing or unknown
    private function gesprekId(array $bron) {
        $gesprekId = Http::field($bron, 'gesprek_id');
        if ($gesprekId === '') {
            throw new HttpFout(400, 'gesprek_id is required.');
        }
        if (!Data::gesprekBestaat($gesprekId)) {
            throw new HttpFout(404, 'Gesprek not found.');
        }
        return $gesprekId;
    }

    // [[kanaal_id => { deelnemers, antwoorden }], { deelnemers, antwoorden } without a kanaal]: all antwoorden,
    // also through a kanaal that does not count; a deelnemer counts once per kanaal
    private function aantallen($gesprekId) {
        $deelnemers = [];
        $antwoorden = [];
        foreach (Data::events('antwoorden', $gesprekId) as $event) {
            $kanaal = $event['kanaal_id'] ?? '';
            $deelnemers[$kanaal][$event['door']['id']] = true;
            $antwoorden[$kanaal] = ($antwoorden[$kanaal] ?? 0) + 1;
        }
        $aantallen = [];
        foreach ($antwoorden as $kanaal => $aantal) {
            $aantallen[$kanaal] = ['deelnemers' => count($deelnemers[$kanaal]), 'antwoorden' => $aantal];
        }
        $zonder = $aantallen[''] ?? ['deelnemers' => 0, 'antwoorden' => 0];
        unset($aantallen['']);
        return [$aantallen, $zonder];
    }
}
