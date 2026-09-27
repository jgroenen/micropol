<?php

// the panels of a gesprek: a set of links, one per panellid, for panels whose members are known elsewhere
// (like a research agency), see Data::panels(). MiniPol keeps no name of a member, only a nummer per link;
// an antwoord keeps only its panel, and the links are counted apart (Data::telPanellink()), so no deelnemer
// can be linked to his link. The export gives per link its nummer, link and counts, for the other party.
class PanelsHandler {
    const MAX_NAAM = 100;
    const MAX_LINKS = 1000;

    // GET /panels/<panel_id>?gesprek_id=<id>   the team of the gesprek
    // the links of the panel as CSV: nummer, link, antwoorden, stellingen, status; one row per link
    public function GET($id = null) {
        $gesprekId = $this->gesprekId($_GET);
        Toegang::vereisRol($gesprekId, Beheer::TEAMROLLEN);
        $panel = $this->panel($gesprekId, $id);
        $tellingen = Data::paneltellingen($gesprekId);
        $links = array_filter(Data::kanalen($gesprekId), function ($kanaal) use ($id) {
            return $kanaal['panel_id'] === $id;
        });
        usort($links, function ($a, $b) {
            return $a['nummer'] <=> $b['nummer'];
        });

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . self::bestandsnaam($panel['naam']) . '.csv"');
        $uit = fopen('php://output', 'w');
        fputcsv($uit, ['nummer', 'link', 'antwoorden', 'stellingen', 'status'], ',', '"', '');
        foreach ($links as $link) {
            $telling = $tellingen[$link['kanaal_id']] ?? ['antwoorden' => 0, 'stellingen' => 0];
            fputcsv($uit, [$link['nummer'], self::link($gesprekId, $link), $telling['antwoorden'], $telling['stellingen'], $link['status']], ',', '"', '');
        }
        fclose($uit);
    }

    // POST /panels  { gesprek_id, naam, aantal }   gespreksbeheerders of the gesprek
    // a panel with aantal links, numbered from 1; returns the panel, like in GET /kanalen
    public function POST($id = null) {
        $input = Http::body();
        $gesprekId = $this->gesprekId($input);
        $beheerder = Toegang::vereisRol($gesprekId, [Beheer::ROL_GESPREKSBEHEERDER]);
        $naam = Http::line($input, 'naam');
        if ($naam === '' || mb_strlen($naam) > self::MAX_NAAM) {
            throw new HttpFout(400, 'naam is required, at most ' . self::MAX_NAAM . ' characters.');
        }
        $aantal = $this->aantal($input, 'aantal', 1);
        $panelId = Data::uuid();
        Data::voegEventToe(Data::PANEL_AANGEMAAKT, Data::doorBeheerder($beheerder), $gesprekId, ['panel_id' => $panelId, 'naam' => $naam, 'links' => self::nieuweLinks($aantal, 1)]);
        Http::json(self::metAantallen($gesprekId, Data::panels($gesprekId)[$panelId]), 201);
    }

    // PUT /panels/<panel_id>  { gesprek_id, erbij, link_intrekken, meetellen, status }   gespreksbeheerders of the gesprek
    // erbij: that many links more, numbered on; link_intrekken: the nummer of one link that works no more, like
    // one that was shared on social media; meetellen (true or false): whether the antwoorden through the panel
    // count; status ingetrokken: all its links work no more, for good. All optional. Returns the panel.
    public function PUT($id = null) {
        $input = Http::body();
        $gesprekId = $this->gesprekId($input);
        $beheerder = Toegang::vereisRol($gesprekId, [Beheer::ROL_GESPREKSBEHEERDER]);
        $panel = $this->panel($gesprekId, $id);
        $erbij = array_key_exists('erbij', $input) ? $this->aantal($input, 'erbij', 0) : 0;
        $meetellen = array_key_exists('meetellen', $input) ? $input['meetellen'] : $panel['meetellen'];
        if (!is_bool($meetellen)) {
            throw new HttpFout(400, 'meetellen must be true or false.');
        }
        $status = Http::field($input, 'status') ?: $panel['status'];
        if (!in_array($status, [Data::KANAAL_ACTIEF, Data::KANAAL_INGETROKKEN_STATUS], true)) {
            throw new HttpFout(400, 'status must be actief or ingetrokken.');
        }
        if ($panel['status'] === Data::KANAAL_INGETROKKEN_STATUS && ($status === Data::KANAAL_ACTIEF || $erbij > 0)) {
            throw new HttpFout(409, 'A panel that is ingetrokken stays so; make a new one.');
        }
        if ($panel['links'] + $erbij > self::MAX_LINKS) {
            throw new HttpFout(400, 'A panel has at most ' . self::MAX_LINKS . ' links.');
        }
        $intrekken = null;
        if (array_key_exists('link_intrekken', $input)) {
            foreach (Data::kanalen($gesprekId) as $kanaal) {
                if ($kanaal['panel_id'] === $id && $kanaal['nummer'] === $input['link_intrekken']) {
                    $intrekken = $kanaal;
                }
            }
            if ($intrekken === null) {
                throw new HttpFout(404, 'No link with this nummer in the panel.');
            }
        }

        $door = Data::doorBeheerder($beheerder);
        if ($intrekken !== null && $intrekken['status'] === Data::KANAAL_ACTIEF) {
            Data::voegEventToe(Data::KANAAL_INGETROKKEN, $door, $gesprekId, ['kanaal_id' => $intrekken['kanaal_id']]);
        }
        if ($erbij > 0) {
            Data::voegEventToe(Data::PANEL_UITGEBREID, $door, $gesprekId, ['panel_id' => $id, 'links' => self::nieuweLinks($erbij, $panel['links'] + 1)]);
        }
        if ($status !== $panel['status']) {
            Data::voegEventToe(Data::PANEL_INGETROKKEN, $door, $gesprekId, ['panel_id' => $id, 'meetellen' => $meetellen]);
        } elseif ($meetellen !== $panel['meetellen']) {
            Data::voegEventToe(Data::PANEL_AANGEPAST, $door, $gesprekId, ['panel_id' => $id, 'meetellen' => $meetellen]);
        }
        Http::json(self::metAantallen($gesprekId, Data::panels($gesprekId)[$id]));
    }

    // the panel with its counts: links, gebruikt (links with at least one antwoord or stelling), antwoorden and
    // stellingen (through all its links), and deelnemers (how many answered through it); $perPanel from
    // KanalenHandler::aantallen(), when known already
    public static function metAantallen($gesprekId, array $panel, ?array $perPanel = null) {
        $perPanel ??= KanalenHandler::aantallen($gesprekId)[2];
        $tellingen = Data::paneltellingen($gesprekId);
        $gebruikt = $antwoorden = $stellingen = 0;
        foreach (Data::kanalen($gesprekId) as $kanaal) {
            if ($kanaal['panel_id'] === $panel['panel_id'] && isset($tellingen[$kanaal['kanaal_id']])) {
                $telling = $tellingen[$kanaal['kanaal_id']];
                $gebruikt += ($telling['antwoorden'] + $telling['stellingen']) > 0 ? 1 : 0;
                $antwoorden += $telling['antwoorden'];
                $stellingen += $telling['stellingen'];
            }
        }
        return $panel + [
            'gebruikt' => $gebruikt,
            'antwoorden' => $antwoorden,
            'stellingen' => $stellingen,
            'deelnemers' => $perPanel[$panel['panel_id']]['deelnemers'] ?? 0,
        ];
    }

    // [{ kanaal_id, token, nummer }] for $aantal new links, numbered from $vanaf; the token is longer than for
    // an open kanaal, since a link belongs to one person
    private static function nieuweLinks($aantal, $vanaf) {
        $links = [];
        for ($i = 0; $i < $aantal; $i++) {
            $links[] = ['kanaal_id' => Data::uuid(), 'token' => bin2hex(random_bytes(16)), 'nummer' => $vanaf + $i];
        }
        return $links;
    }

    // the link for taking part in the app
    private static function link($gesprekId, array $kanaal) {
        return rtrim(APP_URL, '/') . '/#/gesprekken/' . rawurlencode($gesprekId) . '?kanaal=' . rawurlencode($kanaal['token']);
    }

    private static function bestandsnaam($naam) {
        return trim(preg_replace('/[^A-Za-z0-9]+/', '-', $naam), '-') ?: 'panel';
    }

    // a whole number from the body, from $minimum to MAX_LINKS; a 400 otherwise
    private function aantal(array $input, $veld, $minimum) {
        $aantal = $input[$veld] ?? null;
        if (!is_int($aantal) || $aantal < $minimum || $aantal > self::MAX_LINKS) {
            throw new HttpFout(400, "$veld must be a number from $minimum to " . self::MAX_LINKS . '.');
        }
        return $aantal;
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

    private function panel($gesprekId, $id) {
        $panel = Data::panels($gesprekId)[$id ?? ''] ?? null;
        if ($panel === null) {
            throw new HttpFout(404, 'Panel not found in this gesprek.');
        }
        return $panel;
    }
}
