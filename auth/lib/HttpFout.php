<?php

// A request that cannot be answered: thrown by handlers and caught in index.php, which answers with
// the status and { error: melding }.
class HttpFout extends RuntimeException {
    public function __construct($status, $melding) {
        parent::__construct($melding, $status);
    }
}
