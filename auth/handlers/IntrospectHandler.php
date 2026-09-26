<?php

// POST /introspect (RFC 7662), form data: token
// Only for the servers in RESOURCE_SERVERS, with HTTP Basic (id and secret).
// => { active: false } or { active: true, sub, username, preferred_username, email, client_id, exp, iat }
class IntrospectHandler {
    public function POST($id = null) {
        Http::noCache();
        $inlog = Http::basic();
        $geheim = $inlog === null ? null : (RESOURCE_SERVERS[$inlog[0]] ?? null);
        if ($geheim === null || !hash_equals($geheim, $inlog[1])) {
            header('WWW-Authenticate: Basic realm="introspect"');
            throw new OAuthFout(401, 'invalid_client', 'Unknown resource server or wrong secret.');
        }
        Http::json(Tokens::introspecteer(Http::field($_POST, 'token')));
    }
}
