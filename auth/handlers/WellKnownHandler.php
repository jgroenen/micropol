<?php

// GET /.well-known/openid-configuration (and /.well-known/oauth-authorization-server, RFC 8414):
// where the endpoints are. A client that reads this works with Keycloak too: only the issuer url changes.
// This service does the OAuth part only: no id_token and no jwks_uri.
class WellKnownHandler {
    public function GET($id = null) {
        if (!in_array($id, ['openid-configuration', 'oauth-authorization-server'], true)) {
            throw new HttpFout(404, 'Unknown resource.');
        }
        Http::json([
            'issuer' => ISSUER,
            'authorization_endpoint' => ISSUER . '/authorize',
            'token_endpoint' => ISSUER . '/token',
            'introspection_endpoint' => ISSUER . '/introspect',
            'revocation_endpoint' => ISSUER . '/revoke',
            'userinfo_endpoint' => ISSUER . '/userinfo',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'introspection_endpoint_auth_methods_supported' => ['client_secret_basic'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
        ]);
    }
}
