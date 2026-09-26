<?php

// logging in and out of the admin environment, with a token in "Authorization: Bearer <token>", see Sessie
class SessieHandler {
    // GET /sessie   { user: { id, username, email } } for a valid token, otherwise { user: null }
    public function GET($id = null) {
        Http::json(["user" => Sessie::user()]);
    }

    // POST /sessie  { username, password } => { user, token, verloopt }
    public function POST($id = null) {
        $input = Http::body();
        if ($input === null) {
            Http::error(400, "Invalid JSON body.");
            return;
        }
        $username = Http::field($input, 'username');
        // not trimmed: spaces may be part of a password
        $password = isset($input['password']) ? (string) $input['password'] : '';
        if ($username === '' || $password === '') {
            Http::error(400, "username and password are required.");
            return;
        }

        $user = Data::user($username);
        if ($user === null) {
            Wachtwoord::doeAlsOf($password);
        }
        if ($user === null || !Wachtwoord::klopt($password, $user)) {
            Http::error(401, "Wrong username or password.");
            return;
        }

        $sessie = Sessie::login($user);
        Http::json([
            "user" => ['id' => $user['id'], 'username' => $user['username'], 'email' => $user['email']],
            "token" => $sessie['token'],
            "verloopt" => $sessie['verloopt'],
        ]);
    }

    // DELETE /sessie   ends the session of the token
    public function DELETE($id = null) {
        Sessie::logout();
        http_response_code(204);
    }
}
