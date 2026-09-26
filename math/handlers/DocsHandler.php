<?php

// Live documentation of this server, from openapi.json next to index.php:
//   GET /docs                 Swagger UI (loaded from cdn.jsdelivr.net)
//   GET /docs/openapi.json    the spec, with this server as its server url, so "Try it out" works
class DocsHandler {
    const SPEC = __DIR__ . '/../openapi.json';
    const SWAGGER_UI = 'https://cdn.jsdelivr.net/npm/swagger-ui-dist@5';

    public function GET($id = null) {
        if ($id === 'openapi.json') {
            $this->_spec();
        } elseif ($id === null) {
            $this->_pagina();
        } else {
            Http::error(404, "Unknown resource.");
        }
    }

    private function _spec() {
        $spec = json_decode(file_get_contents(self::SPEC), true);
        $spec['servers'] = [['url' => $this->_basis()]];
        header('Content-Type: application/json');
        echo json_encode($spec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    // the url of this server as the client reached it, with a subdirectory like /api/ if any
    private function _basis() {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $pad = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $voorDocs = substr($pad, 0, (int) strrpos($pad, '/docs'));
        return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $voorDocs;
    }

    private function _pagina() {
        $titel = htmlspecialchars(json_decode(file_get_contents(self::SPEC), true)['info']['title']);
        $ui = self::SWAGGER_UI;
        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>$titel</title>
    <link rel="stylesheet" href="$ui/swagger-ui.css">
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="$ui/swagger-ui-bundle.js"></script>
    <script>
        // the spec next to this page: /docs/openapi.json (also below a subdirectory)
        SwaggerUIBundle({
            url: location.pathname.replace(/\/?$/, '/') + 'openapi.json',
            dom_id: '#swagger-ui',
            deepLinking: true,
            persistAuthorization: true,
        });
    </script>
</body>
</html>
HTML;
    }
}
