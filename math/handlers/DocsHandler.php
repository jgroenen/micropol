<?php

// Live documentation of this server, from openapi.json and schema.json next to index.php:
//   GET /docs                 to the viewer DOCS_VIEWER (see config.php) with the url of the spec, so this
//                             server needs no viewer of its own
//   GET /docs/openapi.json    the spec, with this server as its server url, so "Try it out" works
//   GET /docs/schema.json     the types as JSON Schema, with its own url as $id; the spec refers to it
// Everyone may read the spec and the types, also from other sites like the viewer (CORS *).
class DocsHandler {
    const SPEC = __DIR__ . '/../openapi.json';
    const SCHEMA = __DIR__ . '/../schema.json';

    public function GET($id = null) {
        if ($id === 'openapi.json') {
            $this->spec();
        } elseif ($id === 'schema.json') {
            $this->schema();
        } elseif ($id === null) {
            header('Location: ' . DOCS_VIEWER . '?url=' . rawurlencode($this->basis() . '/docs/openapi.json'), true, 302);
        } else {
            throw new HttpFout(404, 'Unknown resource.');
        }
    }

    private function spec() {
        $spec = json_decode(file_get_contents(self::SPEC), true);
        $spec['servers'] = [['url' => $this->basis()]];
        $this->json($spec);
    }

    private function schema() {
        $schema = json_decode(file_get_contents(self::SCHEMA), true);
        // $id first, as JSON Schema tools expect
        $this->json(['$id' => $this->basis() . '/docs/schema.json'] + $schema);
    }

    private function json(array $data) {
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    // the url of this server as the client reached it, with a subdirectory like /api/ if any
    private function basis() {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $pad = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $voorDocs = substr($pad, 0, (int) strrpos($pad, '/docs'));
        return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $voorDocs;
    }
}
