<?php

// POST /token (RFC 6749), form data:
//   grant_type=authorization_code  code, redirect_uri, client_id, code_verifier
//   grant_type=refresh_token       refresh_token, client_id
// => { access_token, token_type, expires_in, refresh_token, refresh_expires_in }
class TokenHandler {
    public function POST($id = null) {
        Http::noCache();
        $clientId = Http::field($_POST, 'client_id');
        if (!isset(CLIENTS[$clientId])) {
            throw new OAuthFout(401, 'invalid_client', 'Unknown client_id.');
        }
        switch (Http::field($_POST, 'grant_type')) {
            case 'authorization_code':
                $tokens = Tokens::wisselCode(
                    Http::field($_POST, 'code'),
                    $clientId,
                    Http::field($_POST, 'redirect_uri'),
                    Http::field($_POST, 'code_verifier')
                );
                break;
            case 'refresh_token':
                $tokens = Tokens::vernieuw(Http::field($_POST, 'refresh_token'), $clientId);
                break;
            default:
                throw new OAuthFout(400, 'unsupported_grant_type', 'grant_type must be authorization_code or refresh_token.');
        }
        Http::json($tokens);
    }
}
