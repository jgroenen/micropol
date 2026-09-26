<?php

// An OAuth error (RFC 6749 5.2), answered by index.php as { error, error_description }:
// error is a code like invalid_grant, beschrijving is for people.
class OAuthFout extends HttpFout {
    public $error;

    public function __construct($status, $error, $beschrijving) {
        parent::__construct($status, $beschrijving);
        $this->error = $error;
    }
}
