<?php

// Reading and appending jsonl files: one JSON object per line.
// Files are append only: lines are never changed or removed.
class Jsonl {
    // every line as an associative array, oldest first; nothing if the file does not exist.
    // A generator, so a large file is never in memory as a whole
    public static function read($file) {
        if (!is_file($file)) {
            return;
        }
        $handle = fopen($file, 'r');
        if ($handle === false) {
            throw new RuntimeException("Failed to open $file.");
        }

        flock($handle, LOCK_SH);
        try {
            while (($line = fgets($handle)) !== false) {
                $record = json_decode($line, true);
                // skips empty and broken lines
                if (is_array($record)) {
                    yield $record;
                }
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    // appends one record as a line; returns it
    public static function append($file, array $record) {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create $dir.");
        }
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            throw new RuntimeException("Failed to open $file.");
        }

        flock($handle, LOCK_EX);
        fseek($handle, 0, SEEK_END);
        if (ftell($handle) > 0) {
            // make sure we start on a new line, files may be edited by hand
            fseek($handle, -1, SEEK_END);
            $lastChar = fgetc($handle);
            fseek($handle, 0, SEEK_END);
            if ($lastChar !== "\n") {
                fwrite($handle, "\n");
            }
        }
        fwrite($handle, $line);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $record;
    }
}
