<?php

// Every call of the api and the math server, checked against their OpenAPI spec: the status must be the
// expected one and in the spec, and the request and response must fit the schema (lib/Schemacontrole.php).
// Every operation in the specs must be called at least once. The test starts on an empty api (installing with
// admin/admin) and makes its own data through the api: accounts and teams, gesprekken, stellingen, antwoorden;
// run it with tests/run.sh, which empties the data first and puts it back afterwards.
// Other servers (like a test server) through the environment: TEST_API_URL, TEST_MATH_URL; TEST_ONVEILIG_TLS=1
// accepts a certificate of a local CA (Caddy's local_certs).
require __DIR__ . '/lib/Schemacontrole.php';

const DIENSTEN = ['api', 'math'];
$wortel = dirname(__DIR__);
$specs = $schemas = $controles = [];
foreach (DIENSTEN as $dienst) {
    $specs[$dienst] = json_decode(file_get_contents("$wortel/$dienst/openapi.json"), true);
    // the types, in schema.json next to each spec; the spec refers to them as schema.json#/$defs/...
    $schemas[$dienst] = json_decode(file_get_contents("$wortel/$dienst/schema.json"), true);
    $controles[$dienst] = new Schemacontrole(['schema.json' => $schemas[$dienst]]);
}
$basis = ['api' => getenv('TEST_API_URL') ?: 'http://localhost:8001', 'math' => getenv('TEST_MATH_URL') ?: 'http://localhost:8004'];
$gebruiker = getenv('TEST_GEBRUIKER') ?: 'minipol-test';
$wachtwoord = getenv('TEST_WACHTWOORD') ?: 'testwachtwoord123';

$fouten = 0;
$gedekt = [];

function meld($goed, $melding) {
    global $fouten;
    $fouten += $goed ? 0 : 1;
    echo ($goed ? 'ok   ' : 'FOUT ') . "$melding\n";
}

// one call: [status, headers with lowercase names, body]; status 0 when the server does not answer.
// $deelnemer goes in the header MiniPol-Deelnemer, like the app does: never in a url
function verzoek($dienst, $methode, $pad, $body = null, $token = null, $deelnemer = null) {
    global $basis;
    $headers = ['Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = "Authorization: Bearer $token";
    }
    if ($deelnemer !== null) {
        $headers[] = "MiniPol-Deelnemer: $deelnemer";
    }
    $opties = ['http' => [
        'method' => $methode,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 30,
    ]];
    if ($body !== null) {
        $opties['http']['content'] = $body === [] ? '{}' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    if (getenv('TEST_ONVEILIG_TLS')) {
        $opties['ssl'] = ['verify_peer' => false, 'verify_peer_name' => false];
    }
    $tekst = @file_get_contents($basis[$dienst] . $pad, false, stream_context_create($opties));
    $status = 0;
    $antwoordHeaders = [];
    foreach ($http_response_header ?? [] as $regel) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $regel, $match)) {
            $status = (int) $match[1];
        } elseif (str_contains($regel, ':')) {
            [$naam, $waarde] = explode(':', $regel, 2);
            $antwoordHeaders[strtolower($naam)] = trim($waarde);
        }
    }
    return [$status, $antwoordHeaders, $tekst === false ? '' : $tekst];
}

// one call, checked against the spec; returns the json of the response (as arrays), or the headers for a redirect
function check($dienst, $methode, $pad, $verwacht, $body = null, $token = null, $deelnemer = null) {
    global $specs, $controles, $gedekt;
    $spec = $specs[$dienst];
    [$status, $headers, $tekst] = verzoek($dienst, $methode, $pad, $body, $token, $deelnemer);
    $kaal = explode('?', $pad)[0];
    $sjabloon = null;
    foreach (array_keys($spec['paths']) as $pad2) {
        if (preg_match('#^' . preg_replace('/\{[^}]+\}/', '[^/]+', $pad2) . '$#', $kaal)) {
            $sjabloon = $pad2;
            break;
        }
    }
    $operatie = $sjabloon === null ? null : ($spec['paths'][$sjabloon][strtolower($methode)] ?? null);
    $meldingen = [];
    if ($status !== $verwacht) {
        $meldingen[] = "status $status, verwacht $verwacht: " . substr($tekst, 0, 120);
    } elseif ($operatie === null) {
        $meldingen[] = 'niet in de spec';
    } elseif (!isset($operatie['responses'][(string) $status])) {
        $meldingen[] = "status $status niet in de spec";
    } else {
        $gedekt["$dienst $methode $sjabloon"] = true;
        $vraag = $operatie['requestBody']['content']['application/json']['schema'] ?? null;
        if ($vraag !== null && $body !== null && $status < 300) {
            $data = $body === [] ? new stdClass() : json_decode(json_encode($body));
            $meldingen = array_merge($meldingen, prefix('request', $controles[$dienst]->fouten($vraag, $data, $spec)));
        }
        $antwoord = $operatie['responses'][(string) $status];
        while (isset($antwoord['$ref'])) {
            $antwoord = $spec['components']['responses'][basename($antwoord['$ref'])];
        }
        $schema = $antwoord['content']['application/json']['schema'] ?? null;
        if ($schema !== null) {
            $meldingen = array_merge($meldingen, prefix('response', $controles[$dienst]->fouten($schema, json_decode($tekst), $spec)));
        }
    }
    meld($meldingen === [], "$dienst $methode " . substr($pad, 0, 70) . " $status " . implode('; ', array_slice($meldingen, 0, 3)));
    if (in_array($status, [301, 302], true)) {
        return $headers;
    }
    return $tekst !== '' && str_contains($headers['content-type'] ?? '', 'json') ? json_decode($tekst, true) : null;
}

function prefix($wat, array $meldingen) {
    return array_map(function ($melding) use ($wat) {
        return "$wat $melding";
    }, $meldingen);
}

// the first item of the list for which $test is true, or null
function eerste(array $lijst, callable $test) {
    foreach ($lijst as $item) {
        if ($test($item)) {
            return $item;
        }
    }
    return null;
}

// ---- the types: schema.json and the schemas in the spec use only known keywords, and their $refs lead somewhere;
// schema.json is served with its own url as $id
foreach (DIENSTEN as $dienst) {
    $controle = $controles[$dienst];
    $schemafouten = $controle->schemafouten($schemas[$dienst], $schemas[$dienst]);
    foreach ($specs[$dienst]['components']['schemas'] as $naam => $schema) {
        $schemafouten = array_merge($schemafouten, $controle->schemafouten($schema, $specs[$dienst], '', "/components/schemas/$naam"));
    }
    foreach ($specs[$dienst]['paths'] as $pad => $operaties) {
        foreach ($operaties as $methode => $operatie) {
            foreach (($operatie['responses'] ?? []) + ['request' => $operatie['requestBody'] ?? []] as $code => $deel) {
                if (isset($deel['content']['application/json']['schema'])) {
                    $schemafouten = array_merge($schemafouten, $controle->schemafouten($deel['content']['application/json']['schema'], $specs[$dienst], '', "$pad $methode $code"));
                }
            }
            foreach ($operatie['parameters'] ?? [] as $parameter) {
                if (isset($parameter['schema'])) {
                    $schemafouten = array_merge($schemafouten, $controle->schemafouten($parameter['schema'], $specs[$dienst], '', "$pad $methode parameter"));
                }
            }
        }
    }
    meld($schemafouten === [], "$dienst: schema.json en de schema's in openapi.json " . implode('; ', array_slice($schemafouten, 0, 3)));
    $live = check($dienst, 'GET', '/docs/schema.json', 200);
    meld(($live['$id'] ?? null) === $basis[$dienst] . '/docs/schema.json' && ($live['$defs'] ?? null) == $schemas[$dienst]['$defs'],
        "$dienst: /docs/schema.json heeft de eigen url als \$id, en dezelfde typen");
}

// ---- api: installing: on an empty server admin/admin logs in, only to make the first superbeheerder
check('api', 'GET', '/sessie', 200);
check('api', 'POST', '/sessie', 400, []);
$installatie = check('api', 'POST', '/sessie', 200, ['gebruikersnaam' => 'admin', 'wachtwoord' => 'admin']);
meld($installatie['account'] === null && $installatie['installatie'] === true, 'admin/admin geeft na installatie de installatielogin');
check('api', 'POST', '/gesprekken', 401, ['titel' => 'x'], $installatie['token']);
check('api', 'POST', '/installatie', 400, ['gebruikersnaam' => $gebruiker, 'email' => 'super@example.org', 'wachtwoord' => 'kort'], $installatie['token']);
check('api', 'POST', '/installatie', 409, ['gebruikersnaam' => 'admin', 'email' => 'super@example.org', 'wachtwoord' => $wachtwoord], $installatie['token']);
$super = check('api', 'POST', '/installatie', 201, ['gebruikersnaam' => $gebruiker, 'email' => 'super@example.org', 'wachtwoord' => $wachtwoord], $installatie['token'])['token'];
check('api', 'POST', '/installatie', 401, ['gebruikersnaam' => 'nog-een', 'email' => 'x@example.org', 'wachtwoord' => $wachtwoord], $installatie['token']);
check('api', 'POST', '/sessie', 401, ['gebruikersnaam' => 'admin', 'wachtwoord' => 'admin']);

// ---- api: logging in
check('api', 'POST', '/sessie', 401, ['gebruikersnaam' => $gebruiker, 'wachtwoord' => 'fout']);
check('api', 'POST', '/sessie', 401, ['gebruikersnaam' => 'bestaat-niet', 'wachtwoord' => 'fout']);
$token = check('api', 'POST', '/sessie', 200, ['gebruikersnaam' => $gebruiker, 'wachtwoord' => $wachtwoord])['token'];
$sessie = check('api', 'GET', '/sessie', 200, null, $token);
meld($sessie['account']['gebruikersnaam'] === $gebruiker && $sessie['account']['superbeheerder'] === true, 'GET /sessie geeft de superbeheerder');
meld(check('api', 'GET', '/sessie', 200, null, str_repeat('a', 64))['account'] === null, 'GET /sessie accepteert geen onbekend token');

// ---- api: gesprekken (the test makes its own; a superbeheerder makes them)
check('api', 'POST', '/gesprekken', 401, ['titel' => 'x']);
check('api', 'POST', '/gesprekken', 400, ['titel' => ''], $super);
$g = check('api', 'POST', '/gesprekken', 201, ['titel' => 'Testgesprek', 'omschrijving' => 'Van tests/api.php.'], $super)['id'];
$vooraf = check('api', 'POST', '/gesprekken', 201, ['titel' => 'Testgesprek vooraf', 'moderatie' => 'vooraf'], $super)['id'];

// ---- api: the team, with uitnodigingen: a gespreksbeheerder, who invites a moderator
check('api', 'POST', '/uitnodigingen', 401, ['rol' => 'gespreksbeheerder', 'gesprek_id' => $g]);
check('api', 'POST', '/uitnodigingen', 400, ['rol' => 'baas', 'gesprek_id' => $g], $super);
check('api', 'POST', '/uitnodigingen', 400, ['rol' => 'moderator'], $super);
check('api', 'POST', '/uitnodigingen', 404, ['rol' => 'moderator', 'gesprek_id' => 'bestaatniet'], $super);
$uitnodiging = check('api', 'POST', '/uitnodigingen', 201, ['rol' => 'gespreksbeheerder', 'gesprek_id' => $g], $super);
meld(check('api', 'GET', "/uitnodigingen/{$uitnodiging['token']}", 200)['gesprek']['id'] === $g, 'GET /uitnodigingen geeft het gesprek');
check('api', 'GET', '/uitnodigingen/bestaatniet', 404);
$gbNaam = "$gebruiker-gb";
check('api', 'POST', "/uitnodigingen/{$uitnodiging['token']}", 409, ['gebruikersnaam' => $gebruiker, 'email' => 'gb@example.org', 'wachtwoord' => $wachtwoord]);
check('api', 'POST', "/uitnodigingen/{$uitnodiging['token']}", 400, ['gebruikersnaam' => $gbNaam, 'email' => 'geen-email', 'wachtwoord' => $wachtwoord]);
$gb = check('api', 'POST', "/uitnodigingen/{$uitnodiging['token']}", 200, ['gebruikersnaam' => $gbNaam, 'email' => 'gb@example.org', 'wachtwoord' => $wachtwoord])['token'];
check('api', 'POST', "/uitnodigingen/{$uitnodiging['token']}", 404, ['gebruikersnaam' => 'nog-een', 'email' => 'x@example.org', 'wachtwoord' => $wachtwoord]);
// logged in, an uitnodiging needs no new account: the gespreksbeheerder of G joins VOORAF too
$extra = check('api', 'POST', '/uitnodigingen', 201, ['rol' => 'gespreksbeheerder', 'gesprek_id' => $vooraf], $super);
$rollen = check('api', 'POST', "/uitnodigingen/{$extra['token']}", 200, null, $gb)['account']['rollen'];
meld($rollen == [$g => 'gespreksbeheerder', $vooraf => 'gespreksbeheerder'], 'de gespreksbeheerder heeft de rollen in beide gesprekken');
$modNaam = "$gebruiker-mod";
$moderatoruitnodiging = check('api', 'POST', '/uitnodigingen', 201, ['rol' => 'moderator', 'gesprek_id' => $g], $gb);
$modSessie = check('api', 'POST', "/uitnodigingen/{$moderatoruitnodiging['token']}", 200, ['gebruikersnaam' => $modNaam, 'email' => 'mod@example.org', 'wachtwoord' => $wachtwoord]);
$mod = $modSessie['token'];
check('api', 'POST', '/uitnodigingen', 403, ['rol' => 'moderator', 'gesprek_id' => $g], $mod);
check('api', 'POST', '/uitnodigingen', 403, ['rol' => 'superbeheerder'], $gb);
meld(array_column(check('api', 'GET', '/gesprekken', 200, null, $mod)['gesprekken'], 'rol') === ['moderator'], 'een moderator ziet in GET /gesprekken alleen zijn eigen gesprek');

// ---- api: changing a gesprek: its gespreksbeheerders only
check('api', 'PUT', "/gesprekken/$g", 200, ['titel' => 'Testgesprek (aangepast)'], $gb);
// nothing changed: no event
check('api', 'PUT', "/gesprekken/$g", 200, ['titel' => 'Testgesprek (aangepast)'], $gb);
check('api', 'PUT', "/gesprekken/$g", 403, ['titel' => 'x'], $mod);
check('api', 'PUT', "/gesprekken/$g", 403, ['titel' => 'x'], $super);
check('api', 'PUT', '/gesprekken/bestaatniet', 404, ['titel' => 'x'], $gb);
check('api', 'PUT', "/gesprekken/$g", 401, ['titel' => 'x']);
check('api', 'GET', '/gesprekken', 200);
check('api', 'GET', '/gesprekken/bestaatniet', 404);

// ---- api: the team: pausing and restoring a lid
meld(array_column(check('api', 'GET', "/team?gesprek_id=$g", 200, null, $gb)['leden'], 'gebruikersnaam') === [$gbNaam, $modNaam], 'GET /team geeft de gespreksbeheerder en de moderator');
check('api', 'GET', "/team?gesprek_id=$g", 200, null, $super);
check('api', 'GET', "/team?gesprek_id=$g", 403, null, $mod);
check('api', 'GET', "/team?gesprek_id=$g", 401);
check('api', 'GET', '/team', 400, null, $gb);
check('api', 'GET', '/team?gesprek_id=bestaatniet', 404, null, $gb);
$modId = $modSessie['account']['id'];
check('api', 'POST', '/team', 400, ['gesprek_id' => $g, 'account_id' => $modId, 'status' => 'opgeschort'], $gb);
check('api', 'POST', '/team', 200, ['gesprek_id' => $g, 'account_id' => $modId, 'status' => 'opgeschort', 'reden' => 'Test'], $gb);
check('api', 'GET', "/beoordelingen?gesprek_id=$g", 403, null, $mod);
check('api', 'POST', '/team', 200, ['gesprek_id' => $g, 'account_id' => $modId, 'status' => 'actief'], $super);
$gbId = check('api', 'GET', '/sessie', 200, null, $gb)['account']['id'];
check('api', 'POST', '/team', 409, ['gesprek_id' => $g, 'account_id' => $gbId, 'status' => 'opgeschort', 'reden' => 'Zelf'], $gb);
check('api', 'POST', '/team', 404, ['gesprek_id' => $g, 'account_id' => 'bestaatniet', 'status' => 'actief'], $gb);
// a superbeheerder who is in the team too, paused by a gespreksbeheerder, restores himself
$eigen = check('api', 'POST', '/uitnodigingen', 201, ['rol' => 'moderator', 'gesprek_id' => $vooraf], $super);
check('api', 'POST', "/uitnodigingen/{$eigen['token']}", 200, null, $super);
check('api', 'POST', '/team', 200, ['gesprek_id' => $vooraf, 'account_id' => $sessie['account']['id'], 'status' => 'opgeschort', 'reden' => 'Test'], $gb);
check('api', 'POST', '/team', 200, ['gesprek_id' => $vooraf, 'account_id' => $sessie['account']['id'], 'status' => 'actief'], $super);
check('api', 'POST', '/team', 403, ['gesprek_id' => $g, 'account_id' => $modId, 'status' => 'actief'], $mod);
check('api', 'POST', '/team', 401, ['gesprek_id' => $g, 'account_id' => $modId, 'status' => 'actief']);

// ---- api: superbeheerders: a second one, paused and restored
check('api', 'GET', '/superbeheerders', 200, null, $super);
check('api', 'GET', '/superbeheerders', 403, null, $gb);
check('api', 'GET', '/superbeheerders', 401);
$tweede = check('api', 'POST', '/uitnodigingen', 201, ['rol' => 'superbeheerder'], $super);
$tweedeId = check('api', 'POST', "/uitnodigingen/{$tweede['token']}", 200, ['gebruikersnaam' => "$gebruiker-super2", 'email' => 'super2@example.org', 'wachtwoord' => $wachtwoord])['account']['id'];
check('api', 'POST', '/superbeheerders', 200, ['account_id' => $tweedeId, 'status' => 'opgeschort', 'reden' => 'Test'], $super);
check('api', 'POST', '/superbeheerders', 200, ['account_id' => $tweedeId, 'status' => 'actief'], $super);
check('api', 'POST', '/superbeheerders', 409, ['account_id' => $sessie['account']['id'], 'status' => 'opgeschort', 'reden' => 'Zelf'], $super);
check('api', 'POST', '/superbeheerders', 404, ['account_id' => 'bestaatniet', 'status' => 'actief'], $super);
check('api', 'POST', '/superbeheerders', 400, ['account_id' => $tweedeId, 'status' => 'weg'], $super);
check('api', 'POST', '/superbeheerders', 403, ['account_id' => $tweedeId, 'status' => 'actief'], $gb);
check('api', 'POST', '/superbeheerders', 401, ['account_id' => $tweedeId, 'status' => 'actief']);

// ---- api: pausing and ending a gesprek: its own test gesprekken
$pauze = check('api', 'POST', '/gesprekken', 201, ['titel' => 'Testgesprek gepauzeerd'], $super)['id'];
$pauzestelling = check('api', 'POST', '/stellingen', 201, ['gesprek_id' => $pauze, 'deelnemer_id' => 'deelnemer-1', 'tekst' => 'Voor de pauze.'])['id'];
check('api', 'POST', '/gespreksstatus', 400, ['gesprek_id' => $pauze, 'status' => 'opgeschort'], $super);
check('api', 'POST', '/gespreksstatus', 403, ['gesprek_id' => $pauze, 'status' => 'opgeschort', 'reden' => 'Test'], $gb);
check('api', 'POST', '/gespreksstatus', 401, ['gesprek_id' => $pauze, 'status' => 'opgeschort', 'reden' => 'Test']);
check('api', 'POST', '/gespreksstatus', 404, ['gesprek_id' => 'bestaatniet', 'status' => 'opgeschort', 'reden' => 'Test'], $super);
check('api', 'POST', '/gespreksstatus', 200, ['gesprek_id' => $pauze, 'status' => 'opgeschort', 'reden' => 'Test'], $super);
$gepauzeerd = check('api', 'GET', "/gesprekken/$pauze", 200);
meld($gepauzeerd['status'] === 'opgeschort' && $gepauzeerd['stellingen'] === [], 'een gepauzeerd gesprek geeft geen stellingen');
meld(!in_array($pauze, array_column(check('api', 'GET', '/gesprekken', 200)['gesprekken'], 'id'), true), 'een gepauzeerd gesprek staat niet in GET /gesprekken voor deelnemers');
check('api', 'POST', '/stellingen', 409, ['gesprek_id' => $pauze, 'deelnemer_id' => 'deelnemer-1', 'tekst' => 'Tijdens de pauze.']);
check('api', 'POST', '/antwoorden', 409, ['gesprek_id' => $pauze, 'deelnemer_id' => 'deelnemer-1', 'stelling_id' => $pauzestelling, 'waarde' => 'eens']);
// a gesprek is never removed, it is ended (beeindigd): like paused, with another notice in the app
check('api', 'POST', '/gespreksstatus', 400, ['gesprek_id' => $pauze, 'status' => 'verwijderd', 'reden' => 'Test'], $super);
$voorbij = check('api', 'POST', '/gesprekken', 201, ['titel' => 'Testgesprek beëindigd'], $super)['id'];
check('api', 'POST', '/gespreksstatus', 400, ['gesprek_id' => $voorbij, 'status' => 'beeindigd'], $super);
check('api', 'POST', '/gespreksstatus', 200, ['gesprek_id' => $voorbij, 'status' => 'beeindigd', 'reden' => 'Test'], $super);
meld(check('api', 'GET', "/gesprekken/$voorbij", 200)['status'] === 'beeindigd', 'een beëindigd gesprek heeft de status beeindigd');
check('api', 'POST', '/stellingen', 409, ['gesprek_id' => $voorbij, 'deelnemer_id' => 'deelnemer-1', 'tekst' => 'Na het einde.']);
meld(!in_array($voorbij, array_column(check('api', 'GET', '/gesprekken', 200)['gesprekken'], 'id'), true), 'een beëindigd gesprek staat niet in GET /gesprekken voor deelnemers');
meld(in_array($voorbij, array_column(check('api', 'GET', '/gesprekken', 200, null, $super)['gesprekken'], 'id'), true), 'een superbeheerder ziet een beëindigd gesprek');
// opening it again
check('api', 'POST', '/gespreksstatus', 200, ['gesprek_id' => $voorbij, 'status' => 'actief'], $super);
check('api', 'POST', '/gespreksstatus', 200, ['gesprek_id' => $voorbij, 'status' => 'beeindigd', 'reden' => 'Echt voorbij'], $super);

// ---- api: stellingen and antwoorden, from a few deelnemers
$stellingen = [];
for ($i = 1; $i <= 8; $i++) {
    $stellingen[] = check('api', 'POST', '/stellingen', 201, ['gesprek_id' => $g, 'deelnemer_id' => 'deelnemer-1', 'tekst' => "Teststelling $i."])['id'];
}
check('api', 'POST', '/stellingen', 400, ['gesprek_id' => $g]);
check('api', 'POST', '/stellingen', 400, ['gesprek_id' => $g, 'deelnemer_id' => str_repeat('x', 65), 'tekst' => 'Te lang id.']);
check('api', 'POST', '/stellingen', 400, ['gesprek_id' => $g, 'deelnemer_id' => '../x', 'tekst' => 'Vreemd id.']);
check('api', 'POST', '/stellingen', 404, ['gesprek_id' => 'bestaatniet', 'deelnemer_id' => 'x', 'tekst' => 'x']);
$wacht = check('api', 'POST', '/stellingen', 201, ['gesprek_id' => $vooraf, 'deelnemer_id' => 'deelnemer-1', 'tekst' => 'Wacht op goedkeuring.']);
meld($wacht['zichtbaar'] === false, 'een stelling in een gesprek met moderatie vooraf is niet meteen zichtbaar');
check('api', 'GET', "/stellingen?gesprek_id=$g", 200, null, null, 'deelnemer-1');
check('api', 'GET', "/stellingen?gesprek_id=$g", 400);
check('api', 'GET', '/stellingen?gesprek_id=bestaatniet', 404, null, null, 'x');
check('api', 'GET', "/gesprekken/$g", 200);
for ($d = 1; $d <= 4; $d++) {
    foreach ($stellingen as $i => $s) {
        check('api', 'POST', '/antwoorden', 201, ['gesprek_id' => $g, 'deelnemer_id' => "deelnemer-$d", 'stelling_id' => $s, 'waarde' => ['eens', 'neutraal', 'oneens'][($i + $d) % 3]]);
    }
}
check('api', 'POST', '/antwoorden', 400, ['gesprek_id' => $g, 'deelnemer_id' => 'x', 'stelling_id' => $stellingen[0], 'waarde' => 'misschien']);
check('api', 'POST', '/antwoorden', 400, ['gesprek_id' => $g, 'deelnemer_id' => str_repeat('x', 65), 'stelling_id' => $stellingen[0], 'waarde' => 'eens']);
check('api', 'GET', "/antwoorden?gesprek_id=$g", 400, null, null, str_repeat('x', 65));
check('api', 'POST', '/antwoorden', 404, ['gesprek_id' => $g, 'deelnemer_id' => 'x', 'stelling_id' => $wacht['id'], 'waarde' => 'eens']);
check('api', 'POST', '/antwoorden', 404, ['gesprek_id' => 'bestaatniet', 'deelnemer_id' => 'x', 'stelling_id' => 'x', 'waarde' => 'eens']);
meld(count(check('api', 'GET', "/antwoorden?gesprek_id=$g", 200, null, null, 'deelnemer-1')['antwoorden']) === count($stellingen), 'GET /antwoorden met de header MiniPol-Deelnemer geeft de eigen antwoorden');
check('api', 'GET', "/antwoorden?gesprek_id=$g", 200);
check('api', 'GET', '/antwoorden', 400);
check('api', 'GET', '/antwoorden?gesprek_id=bestaatniet', 404);

// ---- api: moderatie
check('api', 'GET', "/beoordelingen?gesprek_id=$g", 200, null, $mod);
check('api', 'GET', "/beoordelingen?gesprek_id=$g", 403, null, $super);
check('api', 'GET', "/beoordelingen?gesprek_id=$g", 401);
check('api', 'GET', '/beoordelingen', 400, null, $mod);
check('api', 'GET', '/beoordelingen?gesprek_id=bestaatniet', 404, null, $mod);
check('api', 'POST', '/beoordelingen', 200, ['gesprek_id' => $vooraf, 'stelling_id' => $wacht['id'], 'beoordeling' => 'goedgekeurd'], $gb);
check('api', 'POST', '/beoordelingen', 403, ['gesprek_id' => $vooraf, 'stelling_id' => $wacht['id'], 'beoordeling' => 'goedgekeurd'], $mod);
check('api', 'POST', '/beoordelingen', 200, ['gesprek_id' => $g, 'stelling_id' => end($stellingen), 'beoordeling' => 'afgekeurd', 'reden' => 'Test'], $mod);
check('api', 'POST', '/beoordelingen', 400, ['gesprek_id' => $g, 'stelling_id' => end($stellingen), 'beoordeling' => 'afgekeurd'], $mod);
check('api', 'POST', '/beoordelingen', 401, ['gesprek_id' => $g, 'stelling_id' => end($stellingen), 'beoordeling' => 'goedgekeurd']);
check('api', 'POST', '/beoordelingen', 404, ['gesprek_id' => $g, 'stelling_id' => 'bestaatniet', 'beoordeling' => 'goedgekeurd'], $mod);

// ---- api: the logboek (events)
$events = check('api', 'GET', "/events?gesprek_id=$g", 200, null, $gb)['events'];
$ids = array_column($events, 'id');
$typen = array_column($events, 'type');
$gesorteerd = $ids;
rsort($gesorteerd, SORT_STRING);
meld($ids === $gesorteerd, 'GET /events is nieuwste eerst');
foreach (['gesprek.aangemaakt', 'gesprek.aangepast', 'stelling.toegevoegd', 'stelling.afgekeurd', 'antwoord.gegeven', 'lid.toegevoegd', 'lid.opgeschort', 'lid.hersteld'] as $soort) {
    meld(in_array($soort, $typen, true), "GET /events heeft $soort");
}
$aantallen = array_count_values($typen);
meld($aantallen['gesprek.aangepast'] === 1, 'een PUT zonder wijziging geeft geen event');
meld($aantallen['antwoord.gegeven'] === 4 * count($stellingen), 'GET /events heeft alle antwoorden');
meld(!str_contains(json_encode($events), 'deelnemer-'), 'GET /events geeft nooit de id van een deelnemer');
$antwoorden = array_filter($events, function ($e) {
    return $e['type'] === 'antwoord.gegeven';
});
$nummers = array_values(array_unique(array_map(function ($e) {
    return $e['door']['nummer'];
}, $antwoorden)));
sort($nummers);
meld($nummers === [1, 2, 3, 4], 'de deelnemers in GET /events hebben de nummers van de matrix');
$afgekeurd = eerste($events, function ($e) {
    return $e['type'] === 'stelling.afgekeurd';
});
meld(($afgekeurd['door']['gebruikersnaam'] ?? null) === $modNaam, 'een beoordeling in GET /events heeft de moderator');
meld(in_array($modNaam, array_column(array_filter($events, function ($e) {
    return $e['type'] === 'lid.opgeschort';
}), 'gebruikersnaam'), true), 'een event van het team in GET /events heeft de gebruikersnaam van het lid');
meld(($afgekeurd['tekst'] ?? null) === 'Teststelling 8.' && !array_filter($antwoorden, function ($e) {
    return !str_starts_with($e['tekst'] ?? '', 'Teststelling');
}), 'events over een stelling in GET /events hebben de tekst ervan');
$pagina = check('api', 'GET', "/events?gesprek_id=$g&limiet=5", 200, null, $gb);
$volgende = check('api', 'GET', "/events?gesprek_id=$g&limiet=5&voor=" . end($pagina['events'])['id'], 200, null, $gb);
meld($pagina['meer'] === true && array_column(array_merge($pagina['events'], $volgende['events']), 'id') === array_slice($ids, 0, 10), 'GET /events met limiet en voor geeft de volgende events');
check('api', 'GET', "/events?gesprek_id=$g", 401);
check('api', 'GET', "/events?gesprek_id=$g", 403, null, $super);
check('api', 'GET', '/events', 400, null, $gb);
check('api', 'GET', "/events?gesprek_id=$g&limiet=0", 400, null, $gb);
check('api', 'GET', '/events?gesprek_id=bestaatniet', 404, null, $gb);

// ---- api: export and docs
$export = check('api', 'GET', "/export?gesprek_id=$g", 200);
meld(count($export['stellingen']) === count($stellingen) - 1, 'de afgekeurde stelling staat niet in de export');
check('api', 'GET', '/export', 400);
check('api', 'GET', '/export?gesprek_id=bestaatniet', 404);
check('api', 'GET', '/docs/openapi.json', 200);

// ---- math: the analyse of the test gesprek
$api = rawurlencode($basis['api']);
$analyse = check('math', 'GET', "/analyse?api=$api&gesprek_id=$g", 200);
// a forced recalculation only when the model is at least 5 minutes old, so not right after the first
$herberekend = check('math', 'GET', "/analyse?api=$api&gesprek_id=$g&herbereken=1", 200);
meld($herberekend['model']['berekend'] === $analyse['model']['berekend'], 'herbereken=1 rekent niet opnieuw als het model net berekend is');
check('math', 'GET', '/analyse', 400);
check('math', 'GET', "/analyse?api=http://evil.example&gesprek_id=$g", 403);
check('math', 'GET', "/analyse?api=$api&gesprek_id=bestaatniet", 404);
check('math', 'GET', '/docs/openapi.json', 200);

// ---- docs: /docs goes to the viewer with the url of the spec, which everyone may read (CORS *)
foreach (DIENSTEN as $dienst) {
    $locatie = check($dienst, 'GET', '/docs', 302)['location'] ?? '';
    meld(str_starts_with($locatie, 'https://petstore.swagger.io/?url=') && rawurldecode(explode('url=', $locatie, 2)[1]) === $basis[$dienst] . '/docs/openapi.json',
        "$dienst: /docs stuurt door naar de viewer met de spec");
    foreach (['/docs/openapi.json', '/docs/schema.json'] as $pad) {
        meld((verzoek($dienst, 'GET', $pad)[1]['access-control-allow-origin'] ?? null) === '*', "$dienst: $pad met Access-Control-Allow-Origin: *");
    }
}

// ---- api: kanalen, on the gesprek VOORAF (G keeps its numbers for the other tests): a link per promotion channel
check('api', 'GET', "/kanalen?gesprek_id=$vooraf", 200, null, $gb);
check('api', 'GET', "/kanalen?gesprek_id=$vooraf", 403, null, $mod);
check('api', 'GET', "/kanalen?gesprek_id=$vooraf", 401);
check('api', 'GET', '/kanalen', 400, null, $gb);
check('api', 'GET', '/kanalen?gesprek_id=bestaatniet', 404, null, $gb);
check('api', 'POST', '/kanalen', 400, ['gesprek_id' => $vooraf, 'naam' => ''], $gb);
check('api', 'POST', '/kanalen', 403, ['gesprek_id' => $vooraf, 'naam' => 'Nieuwsbrief'], $mod);
$kanaal = check('api', 'POST', '/kanalen', 201, ['gesprek_id' => $vooraf, 'naam' => 'Nieuwsbrief'], $gb);
$antwoordVooraf = ['gesprek_id' => $vooraf, 'stelling_id' => $wacht['id'], 'waarde' => 'eens'];
check('api', 'POST', '/antwoorden', 201, $antwoordVooraf + ['deelnemer_id' => 'kanaal-1', 'kanaal' => $kanaal['token']]);
check('api', 'POST', '/antwoorden', 403, $antwoordVooraf + ['deelnemer_id' => 'kanaal-x', 'kanaal' => 'bestaatniet']);
check('api', 'POST', '/stellingen', 201, ['gesprek_id' => $vooraf, 'deelnemer_id' => 'kanaal-1', 'tekst' => 'Via de nieuwsbrief.', 'kanaal' => $kanaal['token']]);
// only through a kanaal: without one a 403, and the app can check a link
check('api', 'PUT', "/gesprekken/$vooraf", 400, ['titel' => 'Testgesprek vooraf', 'zonder_kanaal' => 'nee'], $gb);
meld(check('api', 'PUT', "/gesprekken/$vooraf", 200, ['titel' => 'Testgesprek vooraf', 'moderatie' => 'vooraf', 'zonder_kanaal' => false], $gb)['zonder_kanaal'] === false, 'zonder_kanaal kan uit');
check('api', 'POST', '/antwoorden', 403, $antwoordVooraf + ['deelnemer_id' => 'zonder-1']);
check('api', 'POST', '/antwoorden', 201, $antwoordVooraf + ['deelnemer_id' => 'kanaal-2', 'kanaal' => $kanaal['token']]);
meld(check('api', 'POST', '/kanaalcontrole', 200, ['gesprek_id' => $vooraf, 'kanaal' => $kanaal['token']])['geldig'] === true, 'een werkende kanaallink is geldig');
meld(check('api', 'POST', '/kanaalcontrole', 200, ['gesprek_id' => $vooraf, 'kanaal' => 'bestaatniet'])['geldig'] === false, 'een onbekende kanaallink is niet geldig');
check('api', 'POST', '/kanaalcontrole', 400, ['gesprek_id' => $vooraf]);
check('api', 'POST', '/kanaalcontrole', 404, ['gesprek_id' => 'bestaatniet', 'kanaal' => 'x']);
$kanalen = check('api', 'GET', "/kanalen?gesprek_id=$vooraf", 200, null, $gb);
meld($kanalen['kanalen'][0]['deelnemers'] === 2 && $kanalen['kanalen'][0]['antwoorden'] === 2 && $kanalen['zonder_kanaal']['deelnemers'] === 0, 'GET /kanalen telt de deelnemers en antwoorden per kanaal');
$deelnemersVooraf = function () use ($vooraf) {
    return count(check('api', 'GET', "/antwoorden?gesprek_id=$vooraf", 200)['deelnemers']);
};
meld($deelnemersVooraf() === 2, 'de antwoorden via een kanaal tellen mee');
// not counting: out of the matrix, but a deelnemer still sees his own antwoorden
check('api', 'PUT', "/kanalen/{$kanaal['kanaal_id']}", 400, ['gesprek_id' => $vooraf, 'meetellen' => 'nee'], $gb);
check('api', 'PUT', "/kanalen/{$kanaal['kanaal_id']}", 200, ['gesprek_id' => $vooraf, 'meetellen' => false], $gb);
meld($deelnemersVooraf() === 0, 'een kanaal dat niet meetelt, staat niet in de matrix');
meld(count(check('api', 'GET', "/antwoorden?gesprek_id=$vooraf", 200, null, null, 'kanaal-1')['antwoorden']) === 1, 'een deelnemer ziet zijn antwoorden via een kanaal dat niet meetelt');
// withdrawn: the link works no more; its antwoorden count again, as chosen
check('api', 'PUT', "/kanalen/{$kanaal['kanaal_id']}", 200, ['gesprek_id' => $vooraf, 'status' => 'ingetrokken', 'meetellen' => true], $gb);
meld($deelnemersVooraf() === 2, 'bij intrekken gekozen: de antwoorden tellen weer mee');
check('api', 'POST', '/antwoorden', 403, $antwoordVooraf + ['deelnemer_id' => 'kanaal-3', 'kanaal' => $kanaal['token']]);
meld(check('api', 'POST', '/kanaalcontrole', 200, ['gesprek_id' => $vooraf, 'kanaal' => $kanaal['token']])['geldig'] === false, 'een ingetrokken kanaallink is niet geldig');
check('api', 'PUT', "/kanalen/{$kanaal['kanaal_id']}", 409, ['gesprek_id' => $vooraf, 'status' => 'actief'], $gb);
check('api', 'PUT', '/kanalen/bestaatniet', 404, ['gesprek_id' => $vooraf, 'meetellen' => true], $gb);
check('api', 'PUT', "/kanalen/{$kanaal['kanaal_id']}", 403, ['gesprek_id' => $vooraf, 'meetellen' => true], $mod);
check('api', 'PUT', "/kanalen/{$kanaal['kanaal_id']}", 401, ['gesprek_id' => $vooraf, 'meetellen' => true]);
$typenVooraf = array_column(check('api', 'GET', "/events?gesprek_id=$vooraf", 200, null, $gb)['events'], 'kanaal', 'type');
meld(isset($typenVooraf['kanaal.aangemaakt'], $typenVooraf['kanaal.aangepast'], $typenVooraf['kanaal.ingetrokken']) && ($typenVooraf['antwoord.gegeven'] ?? null) === 'Nieuwsbrief',
    'het logboek heeft de kanalen, en bij een antwoord het kanaal');
// ---- api: panels, also on VOORAF: a link per panellid; an antwoord keeps only the panel, never the link
check('api', 'POST', '/panels', 400, ['gesprek_id' => $vooraf, 'naam' => 'Burgerpanel', 'aantal' => 0], $gb);
check('api', 'POST', '/panels', 400, ['gesprek_id' => $vooraf, 'naam' => '', 'aantal' => 3], $gb);
check('api', 'POST', '/panels', 403, ['gesprek_id' => $vooraf, 'naam' => 'Burgerpanel', 'aantal' => 3], $mod);
check('api', 'POST', '/panels', 401, ['gesprek_id' => $vooraf, 'naam' => 'Burgerpanel', 'aantal' => 3]);
$panel = check('api', 'POST', '/panels', 201, ['gesprek_id' => $vooraf, 'naam' => 'Burgerpanel', 'aantal' => 3], $gb);
// the export: [nummer => [link, antwoorden, stellingen, status]]
$export = function () use ($panel, $vooraf, $gb) {
    check('api', 'GET', "/panels/{$panel['panel_id']}?gesprek_id=$vooraf", 200, null, $gb);
    $regels = array_map(function ($regel) {
        return str_getcsv($regel, ',', '"', '');
    }, array_filter(explode("\n", verzoek('api', 'GET', "/panels/{$panel['panel_id']}?gesprek_id=$vooraf", null, $gb)[2])));
    $kop = array_shift($regels);
    $rijen = [];
    foreach ($regels as $regel) {
        $rij = array_combine($kop, $regel);
        $rijen[(int) $rij['nummer']] = $rij;
    }
    return $rijen;
};
$links = $export();
meld(array_keys($links) === [1, 2, 3] && str_contains($links[1]['link'], "#/gesprekken/$vooraf?kanaal="), 'de export heeft een link per nummer');
$linkToken = function ($nummer) use (&$links) {
    return explode('kanaal=', $links[$nummer]['link'])[1];
};
check('api', 'POST', '/antwoorden', 201, $antwoordVooraf + ['deelnemer_id' => 'panel-1', 'kanaal' => $linkToken(1)]);
check('api', 'POST', '/antwoorden', 201, ['waarde' => 'oneens'] + $antwoordVooraf + ['deelnemer_id' => 'panel-1', 'kanaal' => $linkToken(1)]);
check('api', 'POST', '/stellingen', 201, ['gesprek_id' => $vooraf, 'deelnemer_id' => 'panel-2', 'tekst' => 'Van het panel.', 'kanaal' => $linkToken(2)]);
// the counts come in the export from the next window of the clock on: a day in production, a second locally
// (dev/start.sh); tests/paneltellingen.php tests the window itself
sleep(2);
$links = $export();
meld([$links[1]['antwoorden'], $links[1]['stellingen'], $links[2]['antwoorden'], $links[2]['stellingen'], $links[3]['antwoorden']] === ['2', '0', '0', '1', '0'], 'de export telt per link de antwoorden en stellingen');
$panels = check('api', 'GET', "/kanalen?gesprek_id=$vooraf", 200, null, $gb);
meld(array_column($panels['kanalen'], 'naam') === ['Nieuwsbrief'], 'de links van een panel staan niet tussen de kanalen');
$p = $panels['panels'][0];
meld([$p['links'], $p['gebruikt'], $p['antwoorden'], $p['stellingen'], $p['deelnemers']] === [3, 2, 2, 1, 1], 'GET /kanalen telt het panel');
$eventsVooraf = check('api', 'GET', "/events?gesprek_id=$vooraf", 200, null, $gb)['events'];
$panelAntwoord = eerste($eventsVooraf, function ($e) {
    return $e['type'] === 'antwoord.gegeven' && isset($e['panel_id']);
});
meld(($panelAntwoord['panel'] ?? null) === 'Burgerpanel' && !isset($panelAntwoord['kanaal_id']) && !isset($panelAntwoord['kanaal']), 'een antwoord via een panel bewaart alleen het panel, niet de link');
$aangemaakt = eerste($eventsVooraf, function ($e) {
    return $e['type'] === 'panel.aangemaakt';
});
meld(($aangemaakt['aantal'] ?? null) === 3 && !isset($aangemaakt['links']) && !str_contains(json_encode($eventsVooraf), $linkToken(1)), 'het logboek heeft geen links van een panel');
// one link withdrawn, links added, meetellen off, and the whole panel withdrawn
check('api', 'PUT', "/panels/{$panel['panel_id']}", 200, ['gesprek_id' => $vooraf, 'link_intrekken' => 3], $gb);
check('api', 'POST', '/antwoorden', 403, $antwoordVooraf + ['deelnemer_id' => 'panel-3', 'kanaal' => $linkToken(3)]);
check('api', 'PUT', "/panels/{$panel['panel_id']}", 404, ['gesprek_id' => $vooraf, 'link_intrekken' => 9], $gb);
meld(check('api', 'PUT', "/panels/{$panel['panel_id']}", 200, ['gesprek_id' => $vooraf, 'erbij' => 2], $gb)['links'] === 5, 'links erbij');
$links = $export();
meld(array_keys($links) === [1, 2, 3, 4, 5] && $links[3]['status'] === 'ingetrokken', 'de export heeft de nieuwe links, en de ingetrokken link');
$metPanel = $deelnemersVooraf();
check('api', 'PUT', "/panels/{$panel['panel_id']}", 200, ['gesprek_id' => $vooraf, 'meetellen' => false], $gb);
meld($deelnemersVooraf() === $metPanel - 1, 'een panel dat niet meetelt, staat niet in de matrix');
check('api', 'PUT', "/panels/{$panel['panel_id']}", 200, ['gesprek_id' => $vooraf, 'status' => 'ingetrokken', 'meetellen' => true], $gb);
meld($deelnemersVooraf() === $metPanel, 'bij intrekken gekozen: de antwoorden van het panel tellen weer mee');
check('api', 'POST', '/antwoorden', 403, $antwoordVooraf + ['deelnemer_id' => 'panel-1', 'kanaal' => $linkToken(1)]);
check('api', 'PUT', "/panels/{$panel['panel_id']}", 409, ['gesprek_id' => $vooraf, 'status' => 'actief'], $gb);
check('api', 'PUT', "/panels/{$panel['panel_id']}", 409, ['gesprek_id' => $vooraf, 'erbij' => 1], $gb);
check('api', 'PUT', "/panels/{$panel['panel_id']}", 400, ['gesprek_id' => $vooraf, 'meetellen' => 'ja'], $gb);
check('api', 'PUT', "/panels/{$panel['panel_id']}", 403, ['gesprek_id' => $vooraf, 'meetellen' => true], $mod);
check('api', 'PUT', "/panels/{$panel['panel_id']}", 401, ['gesprek_id' => $vooraf, 'meetellen' => true]);
check('api', 'PUT', '/panels/bestaatniet', 404, ['gesprek_id' => $vooraf, 'meetellen' => true], $gb);
check('api', 'GET', "/panels/{$panel['panel_id']}?gesprek_id=$vooraf", 401);
check('api', 'GET', "/panels/bestaatniet?gesprek_id=$vooraf", 404, null, $gb);
check('api', 'GET', "/panels/{$panel['panel_id']}", 400, null, $gb);

check('api', 'PUT', "/gesprekken/$vooraf", 200, ['titel' => 'Testgesprek vooraf', 'zonder_kanaal' => true], $gb);

// ---- the api and the math server are open to every site (CORS *): also a preflight for a POST with a token
foreach (['api' => '/antwoorden', 'math' => '/analyse'] as $dienst => $pad) {
    $antwoord = @file_get_contents($basis[$dienst] . $pad, false, stream_context_create(['http' => [
        'method' => 'OPTIONS',
        'header' => "Origin: https://fiddle.jshell.net\r\nAccess-Control-Request-Method: POST\r\nAccess-Control-Request-Headers: content-type, authorization",
        'ignore_errors' => true,
    ]]));
    $open = false;
    foreach ($http_response_header ?? [] as $regel) {
        $open = $open || strcasecmp(trim($regel), 'Access-Control-Allow-Origin: *') === 0;
    }
    meld($open, "$dienst: open voor elke site, ook een POST met een token (CORS *)");
}

// ---- api: logging out ends the token
$uit = check('api', 'POST', '/sessie', 200, ['gebruikersnaam' => $gebruiker, 'wachtwoord' => $wachtwoord])['token'];
check('api', 'DELETE', '/sessie', 204, null, $uit);
check('api', 'GET', "/beoordelingen?gesprek_id=$g", 401, null, $uit);
check('api', 'DELETE', '/sessie', 204, null, $token);

// every operation of the specs called at least once
foreach ($specs as $dienst => $spec) {
    foreach ($spec['paths'] as $pad => $operaties) {
        foreach (array_keys($operaties) as $methode) {
            if (in_array($methode, ['get', 'post', 'put', 'delete'], true)) {
                $sleutel = "$dienst " . strtoupper($methode) . " $pad";
                meld(isset($gedekt[$sleutel]), "getest: $sleutel");
            }
        }
    }
}

// for tests/browser.mjs
$uitvoer = getenv('TEST_UITVOER');
if ($uitvoer) {
    file_put_contents($uitvoer, json_encode([
        'gesprek' => $g, 'gepauzeerd' => $pauze, 'beeindigd' => $voorbij, 'deelnemer' => 'deelnemer-1', 'deelnemers' => 4,
        'zichtbaar' => count($stellingen) - 1, 'gespreksbeheerder' => $gbNaam, 'moderator' => $modNaam,
    ]));
}

echo "api.php: $fouten fouten\n";
exit($fouten ? 1 : 0);
