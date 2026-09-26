<?php

class AnalyseHandler {
    // GET /analyse?api=<base url of the api>&gesprek_id=<id>[&herbereken=1]
    // The analyse of a gesprek on an api server, from its standard export (see lib/Export.php).
    // The model (PCA + K-means, see lib/Analyse.php) is at most 6 hours old, see lib/AnalyseModel.php;
    // the deelnemers are placed live with it. The model is included, so the browser can place its own user.
    public function GET($id = null) {
        $api = rtrim(Http::field($_GET, 'api'), '/');
        $gesprekId = Http::field($_GET, 'gesprek_id');
        if ($api === '' || $gesprekId === '') {
            Http::error(400, "api and gesprek_id are required.");
            return;
        }
        // only the configured api servers: the math server must not fetch any url a caller gives it
        if (!in_array($api, TOEGESTANE_APIS, true)) {
            Http::error(403, "This api is not allowed.");
            return;
        }
        // the id ends up in a path (see AnalyseModel)
        if (preg_match('/^[A-Za-z0-9-]+$/', $gesprekId) !== 1) {
            Http::error(404, "Gesprek not found.");
            return;
        }

        try {
            $export = Export::haal($api, $gesprekId);
        } catch (ExportFout $fout) {
            Http::error($fout->getCode(), $fout->getMessage());
            return;
        }

        // the export has only zichtbare stellingen, and the deelnemers in the order of the matrix
        $stellingIds = array_column($export['stellingen'], 'id');
        $matrix = array_column($export['deelnemers'], 'antwoorden');

        $model = AnalyseModel::haal($api, $gesprekId, function ($vorigModel) use ($stellingIds, $matrix) {
            return Analyse::maakModel($stellingIds, $matrix, $vorigModel);
        }, Http::field($_GET, 'herbereken') === '1');
        $pca = $model['pca'];
        $kmeans = $model['kmeans'];

        // place every deelnemer live; too few antwoorden (on the stellingen in the model) means not in the plot
        $deelnemers = [];
        $geplaatst = [];
        foreach ($matrix as $index => $antwoorden) {
            $plek = Analyse::plaats($pca, $kmeans, $antwoorden);
            if ($plek === null || $plek['antwoorden'] < $pca['min_antwoorden']) {
                continue;
            }
            $deelnemers[] = [
                'deelnemer' => $export['deelnemers'][$index]['nummer'] ?? $index + 1,
                'antwoorden' => $plek['antwoorden'],
                'x' => round($plek['x'], 4),
                'y' => round($plek['y'], 4),
                'groep' => $plek['groep'],
            ];
            $geplaatst[] = [$antwoorden, $plek['groep']];
        }

        Http::json([
            "gesprek_id" => $gesprekId,
            "model" => [
                "berekend" => date('c', $pca['berekend']),
                "verloopt" => date('c', $pca['berekend'] + AnalyseModel::MAX_LEEFTIJD),
                "max_leeftijd" => AnalyseModel::MAX_LEEFTIJD,
                "stellingen" => $pca['stellingen'],
                "gemiddelden" => $pca['gemiddelden'],
                "componenten" => $pca['componenten'],
                "centra" => $kmeans['centra'],
            ],
            "k" => $kmeans['k'],
            "k_gekozen_op" => $kmeans['gekozen_op'] ?? null,
            "verklaarde_variantie" => array_map(function ($v) {
                return round($v, 4);
            }, $pca['verklaarde_variantie']),
            "min_antwoorden" => $pca['min_antwoorden'],
            "buiten_analyse" => count($matrix) - count($deelnemers),
            "deelnemers" => $deelnemers,
            "groepen" => Analyse::groepStatistiek($stellingIds, $kmeans['k'], $geplaatst),
        ]);
    }
}
