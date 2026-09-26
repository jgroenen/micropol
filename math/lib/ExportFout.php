<?php

// fetching or reading an export failed; getCode() is the status to answer the caller with
class ExportFout extends RuntimeException {
    public function __construct($status, $message) {
        parent::__construct($message, $status);
    }
}
