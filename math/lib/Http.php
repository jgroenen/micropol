<?php

// JSON requests and responses
class Http {
    // the decoded JSON request body; a body that is not a JSON object is a 400
    public static function body() {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            throw new HttpFout(400, 'Invalid JSON body.');
        }
        return $input;
    }

    // a trimmed string field from the body or query, '' if missing
    public static function field(?array $source, $name) {
        return isset($source[$name]) ? trim((string) $source[$name]) : '';
    }

    // like field(), on a single line: every run of whitespace (also newlines) becomes one space
    public static function line(?array $source, $name) {
        return trim(preg_replace('/\s+/', ' ', self::field($source, $name)));
    }

    // CORS headers when the request comes from one of the allowed origins; no cookies are used
    public static function cors(array $origins) {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        header('Vary: Origin');
        if (!in_array($origin, $origins, true)) {
            return;
        }
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Max-Age: 600');
    }

    // the token from "Authorization: Bearer <token>", or null
    // (under Apache the header only reaches PHP with CGIPassAuth or SetEnvIf, see deploy/)
    public static function bearer() {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        return preg_match('/^Bearer\s+(\S+)$/i', $header, $match) === 1 ? $match[1] : null;
    }

    public static function json($data, $code = 200) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    public static function error($code, $message) {
        self::json(['error' => $message], $code);
    }
}
