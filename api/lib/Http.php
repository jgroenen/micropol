<?php

// JSON requests and responses
class Http {
    // the decoded JSON request body, or null if it is not a JSON object
    public static function body() {
        $input = json_decode(file_get_contents('php://input'), true);
        return is_array($input) ? $input : null;
    }

    // a trimmed string field from the body or query, '' if missing
    public static function field(?array $source, $name) {
        return isset($source[$name]) ? trim((string) $source[$name]) : '';
    }

    // CORS headers when the request comes from one of the allowed origins;
    // no cookies are used, logging in goes with a token (see Sessie)
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

    public static function json($data, $code = 200) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    public static function error($code, $message) {
        self::json(["error" => $message], $code);
    }
}
