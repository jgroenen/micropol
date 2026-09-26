<?php
// the page of the admin; only the url of the cdn is filled in, the rest is static
$cdn = (require __DIR__ . '/instellingen.php')['CDN_URL'];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>MiniPol beheer</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@300;400;600&display=swap" rel="stylesheet">
    <!-- the cdn: the design system and the libraries (import 'cdn/...'); its url comes from instellingen.php -->
    <link rel="stylesheet" href="<?= htmlspecialchars($cdn) ?>/design/main.css">
    <script type="importmap"><?= json_encode(["imports" => ["cdn/" => "$cdn/lib/"]], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
    <link rel="stylesheet" href="/css/main.css">
</head>
<body>
    <header class="site-kop">
        <a class="site-titel" href="/">MiniPol <span class="beheer-label">beheer</span></a>
        <div class="ingelogd" id="ingelogd" hidden>
            <span id="ingelogd-als"></span>
            <button class="link-knop" id="uitloggen">Uitloggen</button>
        </div>
    </header>

    <!-- views are loaded from views/<id>.html when first shown, see js/views.js -->
    <main></main>

    <script type="module" src="/js/main.js"></script>
</body>
</html>
