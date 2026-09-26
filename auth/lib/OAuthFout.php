<?php

// an OAuth error (RFC 6749 5.2): error is a code like invalid_grant, sent as { error, error_description }
class OAuthFout extends RuntimeException {
    public $error;

    public function __construct($error, $beschrijving, $status = 400) {
        parent::__construct($beschrijving, $status);
        $this->error = $error;
    }
}
