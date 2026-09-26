<?php

// Requests and responses of the auth service; OAuth endpoints take form data (application/x-www-form-urlencoded)
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

    public static function json($data, $code = 200) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    public static function error($code, $message) {
        self::json(["error" => $message], $code);
    }

    // an OAuth error response: { error, error_description }
    public static function oauthFout(OAuthFout $fout) {
        self::json(['error' => $fout->error, 'error_description' => $fout->getMessage()], $fout->getCode());
    }

    // [id, secret] from "Authorization: Basic ...", or null
    public static function basic() {
        if (isset($_SERVER['PHP_AUTH_USER'])) {
            return [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ?? ''];
        }
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Basic\s+(\S+)$/i', $header, $match) !== 1) {
            return null;
        }
        $delen = explode(':', (string) base64_decode($match[1], true), 2);
        return count($delen) === 2 ? array_map('urldecode', $delen) : null;
    }

    // the token from "Authorization: Bearer <token>", or null
    public static function bearer() {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        return preg_match('/^Bearer\s+(\S+)$/i', $header, $match) === 1 ? $match[1] : null;
    }

    // responses with tokens must not be stored anywhere (RFC 6749 5.1)
    public static function geenCache() {
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
    }
}
