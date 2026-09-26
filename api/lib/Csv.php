<?php

// Reading and appending csv files with a header row.
// Files are append only: rows are never changed or removed.
class Csv {
    // all rows as associative arrays keyed by the header row; [] if the file does not exist
    public static function read($file) {
        if (!is_file($file)) {
            return [];
        }
        $handle = fopen($file, 'r');
        if ($handle === false) {
            throw new RuntimeException("Failed to open $file.");
        }

        flock($handle, LOCK_SH);
        $rows = [];
        $headers = fgetcsv($handle, 0, ",", '"', "");
        while ($headers !== false && ($data = fgetcsv($handle, 0, ",", '"', "")) !== false) {
            // skips empty and broken lines
            if (count($data) === count($headers)) {
                $rows[] = array_combine($headers, $data);
            }
        }
        flock($handle, LOCK_UN);
        fclose($handle);

        return $rows;
    }

    // the files are append only: a change is a new row with the same key, and the last row counts.
    // [key => row] with the last row of every value in $column, in the order the keys first appeared
    public static function lastPer($file, $column) {
        $rows = [];
        foreach (self::read($file) as $row) {
            $rows[$row[$column]] = $row;
        }
        return $rows;
    }

    // appends one row (values in header order), writes the header row if the file is new;
    // returns the row as an associative array
    public static function append($file, array $headers, array $row) {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create $dir.");
        }
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            throw new RuntimeException("Failed to open $file.");
        }

        flock($handle, LOCK_EX);
        fseek($handle, 0, SEEK_END);
        if (ftell($handle) === 0) {
            fputcsv($handle, $headers, ",", '"', "");
        } else {
            // make sure we start on a new line, files may be edited by hand
            fseek($handle, -1, SEEK_END);
            $lastChar = fgetc($handle);
            fseek($handle, 0, SEEK_END);
            if ($lastChar !== "\n") {
                fwrite($handle, "\n");
            }
        }
        fputcsv($handle, $row, ",", '"', "");
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return array_combine($headers, $row);
    }
}
