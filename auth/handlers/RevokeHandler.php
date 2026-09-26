<?php

// POST /revoke (RFC 7009), form data: token (an access or a refresh token), client_id
// Logging out: ends the login of the token, so all its tokens stop working at once.
// Always 200, also for an unknown token.
class RevokeHandler {
    public function POST($id = null) {
        Http::noCache();
        if (!isset(CLIENTS[Http::field($_POST, 'client_id')])) {
            throw new OAuthFout(401, 'invalid_client', 'Unknown client_id.');
        }
        Tokens::trekIn(Http::field($_POST, 'token'));
        http_response_code(200);
    }
}
