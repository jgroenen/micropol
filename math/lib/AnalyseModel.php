<?php

// The analyse model of a gesprek, stored in data/analyse/<bron>/<gesprek_id>/, where <bron> stands for
// the api server the gesprek is on (see bron()):
//   pca.json     stellingen, column means, the two components
//   kmeans.json  K and the group centers in the plot
// Recalculated when a file is missing or older than MAX_LEEFTIJD.
class AnalyseModel {
    const MAX_LEEFTIJD = 6 * 3600; // seconds

    // ['pca' => [...], 'kmeans' => [...]], recalculated first if needed
    // $maakModel: callable that calculates a fresh model, given the previous model (or null)
    public static function haal($api, $gesprekId, callable $maakModel, $forceer = false) {
        $dir = self::dir($api, $gesprekId);
        $model = self::lees($dir);
        if (!$forceer && self::vers($model)) {
            return $model;
        }

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create $dir.");
        }

        // one request recalculates, others wait and then use its result
        $lock = fopen($dir . '/.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $model = self::lees($dir);
            if (!$forceer && self::vers($model)) {
                return $model;
            }

            $nieuw = $maakModel(isset($model['pca'], $model['kmeans']) ? $model : null);
            $nu = time();
            $nieuw['pca']['berekend'] = $nu;
            $nieuw['kmeans']['berekend'] = $nu;
            self::schrijf($dir . '/pca.json', $nieuw['pca']);
            self::schrijf($dir . '/kmeans.json', $nieuw['kmeans']);
            return $nieuw;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    // only call with a gesprek id that is safe in a path (see AnalyseHandler)
    private static function dir($api, $gesprekId) {
        return DATA_DIR . '/analyse/' . self::bron($api) . '/' . $gesprekId;
    }

    // a short, fixed name for an api server, safe in a path
    public static function bron($api) {
        return substr(hash('sha256', $api), 0, 16);
    }

    private static function lees($dir) {
        $model = [];
        foreach (['pca', 'kmeans'] as $deel) {
            $file = "$dir/$deel.json";
            $data = is_file($file) ? json_decode(file_get_contents($file), true) : null;
            if (is_array($data)) {
                $model[$deel] = $data;
            }
        }
        return $model;
    }

    private static function vers(array $model) {
        foreach (['pca', 'kmeans'] as $deel) {
            if (!isset($model[$deel]['berekend']) || time() - $model[$deel]['berekend'] > self::MAX_LEEFTIJD) {
                return false;
            }
        }
        return true;
    }

    // write to a temporary file first, so readers never see a half written model
    private static function schrijf($file, array $data) {
        $tmp = $file . '.tmp';
        if (file_put_contents($tmp, json_encode($data)) === false || !rename($tmp, $file)) {
            throw new RuntimeException("Failed to write $file.");
        }
    }
}
