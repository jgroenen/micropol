<?php

// GET /userinfo with "Authorization: Bearer <access token>" (as in OpenID Connect)
// => { sub, preferred_username, email }, for the app to show who is logged in
class UserinfoHandler {
    public function GET($id = null) {
        Http::geenCache();
        $info = Tokens::introspecteer(Http::bearer() ?? '');
        if (!$info['active']) {
            header('WWW-Authenticate: Bearer error="invalid_token"');
            Http::error(401, "Invalid or expired token.");
            return;
        }
        Http::json(['sub' => $info['sub'], 'preferred_username' => $info['preferred_username'], 'email' => $info['email']]);
    }
}
