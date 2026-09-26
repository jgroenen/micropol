<?php

// The login page (authorization code flow with PKCE, RFC 6749 4.1 and RFC 7636).
//   GET  /authorize?response_type=code&client_id&redirect_uri&state&code_challenge&code_challenge_method=S256
//        shows the login form
//   POST /authorize   the form: on success back to redirect_uri?code=...&state=...
// The wachtwoord is only ever sent to this page, never to the app that asked for the login.
class AuthorizeHandler {
    public function GET($id = null) {
        $verzoek = $this->verzoek($_GET);
        if ($verzoek !== null) {
            $this->pagina($verzoek);
        }
    }

    public function POST($id = null) {
        $verzoek = $this->verzoek($_POST);
        if ($verzoek === null) {
            return;
        }
        $gebruikersnaam = Http::field($_POST, 'gebruikersnaam');
        // not trimmed: spaces may be part of a wachtwoord
        $wachtwoord = isset($_POST['wachtwoord']) ? (string) $_POST['wachtwoord'] : '';

        $gebruiker = $gebruikersnaam === '' ? null : Data::gebruiker($gebruikersnaam);
        if ($gebruiker === null) {
            Wachtwoord::doeAlsOf($wachtwoord);
        }
        if ($gebruiker === null || !Wachtwoord::klopt($wachtwoord, $gebruiker)) {
            http_response_code(401);
            $this->pagina($verzoek, $gebruikersnaam, 'Onjuiste gebruikersnaam of wachtwoord.');
            return;
        }

        $code = Tokens::maakCode($verzoek['client_id'], $verzoek['redirect_uri'], $verzoek['code_challenge'], $gebruiker);
        $this->terug($verzoek, ['code' => $code]);
    }

    // the checked parameters, or null after answering: an unknown client or redirect_uri gets an error page
    // (never a redirect to an unknown address), other mistakes go back to the client as ?error=...
    private function verzoek(array $bron) {
        $clientId = Http::field($bron, 'client_id');
        $redirectUri = Http::field($bron, 'redirect_uri');
        if (!isset(CLIENTS[$clientId]) || !in_array($redirectUri, CLIENTS[$clientId]['redirect_uris'], true)) {
            $this->foutPagina('Onbekende app of terugkeeradres.');
            return null;
        }
        $verzoek = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => Http::field($bron, 'state'),
            'code_challenge' => Http::field($bron, 'code_challenge'),
        ];
        if (Http::field($bron, 'response_type') !== 'code') {
            $this->terug($verzoek, ['error' => 'unsupported_response_type']);
            return null;
        }
        if (Http::field($bron, 'code_challenge_method') !== 'S256' || preg_match('/^[A-Za-z0-9_-]{43}$/', $verzoek['code_challenge']) !== 1) {
            $this->terug($verzoek, ['error' => 'invalid_request', 'error_description' => 'PKCE with S256 is required.']);
            return null;
        }
        return $verzoek;
    }

    private function terug(array $verzoek, array $parameters) {
        if ($verzoek['state'] !== '') {
            $parameters['state'] = $verzoek['state'];
        }
        $scheiding = str_contains($verzoek['redirect_uri'], '?') ? '&' : '?';
        header('Location: ' . $verzoek['redirect_uri'] . $scheiding . http_build_query($parameters), true, 302);
    }

    private function pagina(array $verzoek, $gebruikersnaam = '', $fout = null) {
        $e = function ($tekst) {
            return htmlspecialchars($tekst, ENT_QUOTES);
        };
        $verborgen = '';
        foreach (['client_id', 'redirect_uri', 'state', 'code_challenge'] as $naam) {
            $verborgen .= '<input type="hidden" name="' . $naam . '" value="' . $e($verzoek[$naam]) . '">';
        }
        $verborgen .= '<input type="hidden" name="response_type" value="code"><input type="hidden" name="code_challenge_method" value="S256">';
        $foutHtml = $fout === null ? '' : '<p class="melding fout" role="alert">' . $e($fout) . '</p>';
        $this->html('Inloggen', <<<HTML
<form class="inlog-formulier" method="post" action="authorize">
    <h1 class="pagina-titel">Inloggen</h1>
    $foutHtml
    <label for="gebruikersnaam">Gebruikersnaam</label>
    <input id="gebruikersnaam" name="gebruikersnaam" autocomplete="username" required autofocus value="{$e($gebruikersnaam)}">
    <label for="wachtwoord">Wachtwoord</label>
    <input id="wachtwoord" name="wachtwoord" type="password" autocomplete="current-password" required>
    $verborgen
    <button type="submit" class="knop">Inloggen</button>
</form>
HTML);
    }

    private function foutPagina($melding) {
        http_response_code(400);
        $melding = htmlspecialchars($melding);
        $this->html('Inloggen niet mogelijk', "<h1 class=\"pagina-titel\">Inloggen niet mogelijk</h1><p>$melding</p>");
    }

    private function html($titel, $inhoud) {
        header('Content-Type: text/html; charset=utf-8');
        // not in a frame on another site (clickjacking), and no referrer with the parameters
        header("Content-Security-Policy: frame-ancestors 'none'");
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        Http::noCache();
        $cdn = htmlspecialchars(CDN_URL);
        echo <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>$titel · MiniPol</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@300;400;600&display=swap">
    <link rel="stylesheet" href="$cdn/css/main.css">
    <style>
        .inlog-formulier { display: grid; gap: var(--space-2); max-inline-size: 24rem; margin-inline: auto; }
        .inlog-formulier label { font-weight: 600; }
        .inlog-formulier input { margin-block-end: var(--space-3); padding: var(--space-2) var(--space-3); border: 1px solid var(--color-border);
            border-radius: var(--radius); background: var(--color-surface); color: inherit; font: inherit; font-weight: 300; }
        .inlog-formulier .knop { justify-self: start; }
        .melding.fout { background: var(--color-oneens); }
    </style>
</head>
<body>
    <header class="site-kop"><span class="site-titel">MiniPol</span></header>
    <main><section class="view">$inhoud</section></main>
</body>
</html>
HTML;
    }
}
