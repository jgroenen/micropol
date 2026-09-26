<?php

// Opinion groups, loosely after Polis. See docs/analyse.md for the approach and the choices behind it.
//
// maakModel(): the expensive part, done at most every few hours (see AnalyseModel.php)
//   1. antwoorden as numbers (eens +1, neutraal 0, oneens -1), missing ones filled in with the stelling's mean
//   2. PCA: the DIMENSIES directions in which deelnemers differ most
//   3. K-means on the deelnemers projected onto those directions; K is the smallest one where every
//      group has a kenmerkende stelling, or else the one with the best silhouette score
//
// plaats(): the cheap part, done live for every deelnemer (and in the browser, see app/js/analyse.js)
//   project one deelnemer's antwoorden with the model and pick the nearest group center
//
// The plot shows the first two directions; the groups are found on all DIMENSIES of them.
// Everything is deterministic, so the same data always gives the same model.
class Analyse {
    const WAARDEN = ['eens' => 1, 'neutraal' => 0, 'oneens' => -1];
    const MIN_ANTWOORDEN = 7;       // or all stellingen, if there are fewer
    const DIMENSIES = 3;            // PCA components used for the groups
    const K_KANDIDATEN = [3, 4, 5];
    const K_MAX = 5;
    const GROEP_NAMEN = ['A', 'B', 'C', 'D', 'E'];

    // a stelling is kenmerkend for a group when at least KENMERKEND_DREMPEL of the group gives the same antwoord,
    // and the other deelnemers at least KENMERKEND_VERSCHIL less; with enough answers on both sides
    // (the same rule as kenmerkend() in app/js/analyse.js, keep them alike)
    const KENMERKEND_DREMPEL = 0.9;
    const KENMERKEND_VERSCHIL = 0.3;
    const KENMERKEND_MIN_ANTWOORDEN = 3;
    const KENMERKEND_MIN_DEEL = 0.2;

    // $stellingIds: the columns, in order
    // $matrix: list of [stelling_id => waarde], one per deelnemer
    // $vorigModel: the previous ['pca', 'kmeans'], to keep group letters stable
    // returns ['pca' => [...], 'kmeans' => [...]]
    public static function maakModel(array $stellingIds, array $matrix, $vorigModel = null) {
        $stellingIds = array_values($stellingIds);
        $m = count($stellingIds);
        $minAntwoorden = min(self::MIN_ANTWOORDEN, $m);

        // numeric rows of the deelnemers with enough antwoorden
        $X = [];
        $meegeteld = []; // their antwoorden, same order as $X
        foreach ($matrix as $antwoorden) {
            $row = self::getallen($stellingIds, $antwoorden);
            $aantal = count(array_filter($row, 'is_int'));
            if ($aantal > 0 && $aantal >= $minAntwoorden) {
                $X[] = $row;
                $meegeteld[] = $antwoorden;
            }
        }

        $pca = [
            'stellingen' => $stellingIds,
            'min_antwoorden' => $minAntwoorden,
            'deelnemers' => count($X),
            'gemiddelden' => [],
            'componenten' => [],
            'verklaarde_variantie' => [],
        ];
        $kmeans = ['k' => 0, 'centra' => [], 'gekozen_op' => null];
        if (count($X) === 0 || $m < 2) {
            return ['pca' => $pca, 'kmeans' => $kmeans];
        }

        $pca = array_merge($pca, self::pca($X, $m, min(self::DIMENSIES, $m)));

        $punten = [];
        foreach ($X as $row) {
            $punten[] = self::projecteer($pca, $row);
        }
        $groepering = self::groepeer($punten, $meegeteld, $stellingIds);
        if ($groepering === null) {
            return ['pca' => $pca, 'kmeans' => $kmeans];
        }
        [$labels, $gekozenOp] = $groepering;

        // keep the letters of the previous model: where were these deelnemers in the old groups?
        $vorigeGroepen = [];
        if (isset($vorigModel['pca'], $vorigModel['kmeans']) && $vorigModel['kmeans']['k'] > 0) {
            foreach ($meegeteld as $antwoorden) {
                $plek = self::plaats($vorigModel['pca'], $vorigModel['kmeans'], $antwoorden);
                $vorigeGroepen[] = $plek === null ? null : $plek['groep'];
            }
        }
        $volgorde = self::volgorde($labels, $vorigeGroepen);

        $centra = self::centra($punten, $labels);
        $kmeans = [
            'k' => count($centra),
            'centra' => array_map(function ($g) use ($centra) { return $centra[$g]; }, $volgorde),
            'gekozen_op' => $gekozenOp,
        ];
        return ['pca' => $pca, 'kmeans' => $kmeans];
    }

    // place one deelnemer with the model: ['x', 'y', 'groep', 'antwoorden'], or null without usable antwoorden;
    // x and y are the first two directions (the plot), the group is the nearest center over all of them
    public static function plaats(array $pca, array $kmeans, array $antwoorden) {
        if (count($pca['componenten']) < 2) {
            return null;
        }
        $row = self::getallen($pca['stellingen'], $antwoorden);
        $aantal = count(array_filter($row, 'is_int'));
        if ($aantal === 0) {
            return null;
        }
        $punt = self::projecteer($pca, $row);

        $groep = null;
        foreach ($kmeans['centra'] as $g => $centrum) {
            if ($groep === null || self::afstand2($punt, $centrum) < self::afstand2($punt, $kmeans['centra'][$groep])) {
                $groep = $g;
            }
        }
        return ['x' => $punt[0], 'y' => $punt[1], 'groep' => $groep, 'antwoorden' => $aantal];
    }

    // antwoorden as numbers in stelling order, null where not answered
    private static function getallen(array $stellingIds, array $antwoorden) {
        $row = [];
        foreach ($stellingIds as $stellingId) {
            $waarde = $antwoorden[$stellingId] ?? null;
            $row[] = $waarde !== null && array_key_exists($waarde, self::WAARDEN) ? self::WAARDEN[$waarde] : null;
        }
        return $row;
    }

    // position on every component; a missing antwoord counts as the mean (adds 0), and deelnemers with
    // fewer antwoorden are scaled up so they don't all collapse to the center
    private static function projecteer(array $pca, array $row) {
        $m = count($row);
        $aantal = 0;
        $punt = array_fill(0, count($pca['componenten']), 0.0);
        foreach ($row as $j => $waarde) {
            if ($waarde === null) {
                continue;
            }
            $aantal++;
            $afwijking = $waarde - $pca['gemiddelden'][$j];
            foreach ($pca['componenten'] as $c => $component) {
                $punt[$c] += $afwijking * $component[$j];
            }
        }
        $schaal = sqrt($m / $aantal);
        return array_map(function ($x) use ($schaal) {
            return $x * $schaal;
        }, $punt);
    }

    // column means, the $d main components and how much of the variance each explains
    private static function pca(array $X, $m, $d) {
        $n = count($X);

        $gemiddelden = array_fill(0, $m, 0.0);
        for ($j = 0; $j < $m; $j++) {
            $som = 0;
            $aantal = 0;
            foreach ($X as $row) {
                if ($row[$j] !== null) {
                    $som += $row[$j];
                    $aantal++;
                }
            }
            $gemiddelden[$j] = $aantal > 0 ? $som / $aantal : 0.0;
        }
        $Xc = [];
        foreach ($X as $i => $row) {
            foreach ($row as $j => $waarde) {
                $Xc[$i][$j] = $waarde === null ? 0.0 : $waarde - $gemiddelden[$j];
            }
        }

        // covariance matrix (m x m)
        $C = array_fill(0, $m, array_fill(0, $m, 0.0));
        $deler = max($n - 1, 1);
        for ($a = 0; $a < $m; $a++) {
            for ($b = $a; $b < $m; $b++) {
                $som = 0.0;
                for ($i = 0; $i < $n; $i++) {
                    $som += $Xc[$i][$a] * $Xc[$i][$b];
                }
                $C[$a][$b] = $C[$b][$a] = $som / $deler;
            }
        }
        $totaal = 0.0;
        for ($j = 0; $j < $m; $j++) {
            $totaal += $C[$j][$j];
        }

        // largest eigenvectors one by one: power iteration, then deflation
        $componenten = [];
        $verklaard = [];
        for ($c = 0; $c < $d; $c++) {
            [$v, $lambda] = self::eigenvector($C, $c % 2 ? -1 : 1);
            $componenten[] = $v;
            $verklaard[] = $totaal > 0 ? max($lambda, 0) / $totaal : 0;
            for ($a = 0; $a < $m; $a++) {
                for ($b = 0; $b < $m; $b++) {
                    $C[$a][$b] -= $lambda * $v[$a] * $v[$b];
                }
            }
        }

        return [
            'gemiddelden' => $gemiddelden,
            'componenten' => $componenten,
            'verklaarde_variantie' => $verklaard,
        ];
    }

    // [eigenvector, eigenvalue] of the largest eigenvalue of symmetric matrix $C
    private static function eigenvector(array $C, $variant) {
        $m = count($C);
        // fixed start vector, so the result is deterministic
        $v = [];
        for ($j = 0; $j < $m; $j++) {
            $v[] = ($variant < 0 && $j % 2 ? -1 : 1) * (1 + $j / $m);
        }
        $v = self::normaliseer($v);

        for ($iteratie = 0; $iteratie < 1000; $iteratie++) {
            $w = self::vermenigvuldig($C, $v);
            $lengte = sqrt(array_sum(array_map(function ($x) {
                return $x * $x;
            }, $w)));
            if ($lengte < 1e-12) {
                return [array_fill(0, $m, 0.0), 0.0];
            }
            $w = array_map(function ($x) use ($lengte) {
                return $x / $lengte;
            }, $w);
            $verschil = 0.0;
            for ($j = 0; $j < $m; $j++) {
                $verschil = max($verschil, abs($w[$j] - $v[$j]));
            }
            $v = $w;
            if ($verschil < 1e-10) {
                break;
            }
        }

        // fixed sign: the largest component is positive
        $grootste = 0;
        for ($j = 1; $j < $m; $j++) {
            if (abs($v[$j]) > abs($v[$grootste])) {
                $grootste = $j;
            }
        }
        if ($v[$grootste] < 0) {
            $v = array_map(function ($x) {
                return -$x;
            }, $v);
        }

        $Cv = self::vermenigvuldig($C, $v);
        $lambda = 0.0;
        for ($j = 0; $j < $m; $j++) {
            $lambda += $v[$j] * $Cv[$j];
        }
        return [$v, $lambda];
    }

    // [labels 0..k-1 (0 = largest group), 'kenmerkend' or 'silhouette'], or null if there are too few deelnemers
    // K: the smallest candidate where every group has a kenmerkende stelling, so every group means something;
    // if no candidate manages that, the one whose groups are separated best (silhouette score)
    private static function groepeer(array $punten, array $antwoorden, array $stellingIds) {
        $n = count($punten);
        $kMax = min(self::K_MAX, $n - 1);
        if ($kMax < 2) {
            return null;
        }
        $kandidaten = array_values(array_filter(self::K_KANDIDATEN, function ($kandidaat) use ($kMax) {
            return $kandidaat <= $kMax;
        }));
        if (count($kandidaten) === 0) {
            $kandidaten = [2];
        }

        $alle = [];
        foreach ($kandidaten as $kandidaat) {
            $labels = self::hernummer(self::kmeans($punten, $kandidaat));
            if (self::elkeGroepKenmerkend($labels, $antwoorden, $stellingIds)) {
                return [$labels, 'kenmerkend'];
            }
            $alle[] = $labels;
        }

        $besteLabels = null;
        $besteScore = -INF;
        foreach ($alle as $labels) {
            $score = self::silhouette($punten, $labels);
            if ($score > $besteScore) {
                $besteScore = $score;
                $besteLabels = $labels;
            }
        }
        // everyone in one place (e.g. all the same antwoorden): no groups
        if (count(array_unique($besteLabels)) < 2) {
            return null;
        }
        return [$besteLabels, 'silhouette'];
    }

    // does every group have at least one kenmerkende stelling (see KENMERKEND_*)?
    private static function elkeGroepKenmerkend(array $labels, array $antwoorden, array $stellingIds) {
        $k = max($labels) + 1;
        $groottes = array_count_values($labels);
        if (count($groottes) < $k) {
            return false; // an empty group
        }
        $totaalDeelnemers = count($labels);
        $minimum = function ($deelnemers) {
            return max(self::KENMERKEND_MIN_ANTWOORDEN, (int) ceil($deelnemers * self::KENMERKEND_MIN_DEEL));
        };

        $gevonden = array_fill(0, $k, false);
        foreach ($stellingIds as $stellingId) {
            // per group: eens, oneens and all answers on this stelling
            $tel = array_fill(0, $k, ['eens' => 0, 'oneens' => 0, 'totaal' => 0]);
            foreach ($labels as $i => $groep) {
                $waarde = $antwoorden[$i][$stellingId] ?? null;
                if ($waarde !== null && array_key_exists($waarde, self::WAARDEN)) {
                    $tel[$groep]['totaal']++;
                    if ($waarde !== 'neutraal') {
                        $tel[$groep][$waarde]++;
                    }
                }
            }
            $som = ['eens' => 0, 'oneens' => 0, 'totaal' => 0];
            foreach ($tel as $t) {
                foreach ($som as $veld => $_) {
                    $som[$veld] += $t[$veld];
                }
            }

            for ($g = 0; $g < $k; $g++) {
                if ($gevonden[$g]) {
                    continue;
                }
                $groep = $tel[$g];
                $restTotaal = $som['totaal'] - $groep['totaal'];
                if ($groep['totaal'] < $minimum($groottes[$g]) || $restTotaal < $minimum($totaalDeelnemers - $groottes[$g])) {
                    continue;
                }
                foreach (['eens', 'oneens'] as $kant) {
                    $aandeel = $groep[$kant] / $groep['totaal'];
                    $rest = ($som[$kant] - $groep[$kant]) / $restTotaal;
                    if ($aandeel >= self::KENMERKEND_DREMPEL && $aandeel - $rest >= self::KENMERKEND_VERSCHIL) {
                        $gevonden[$g] = true;
                    }
                }
            }
            if (!in_array(false, $gevonden, true)) {
                return true;
            }
        }
        return false;
    }

    private static function kmeans(array $punten, $k) {
        $n = count($punten);

        // farthest-first start: the point farthest from the mean, then each time the point farthest from all chosen
        $centra = [$punten[self::verste($punten, [self::gemiddelde($punten)])]];
        while (count($centra) < $k) {
            $centra[] = $punten[self::verste($punten, $centra)];
        }

        $labels = array_fill(0, $n, -1);
        for ($iteratie = 0; $iteratie < 100; $iteratie++) {
            $veranderd = false;
            foreach ($punten as $i => $punt) {
                $beste = 0;
                foreach ($centra as $c => $centrum) {
                    if (self::afstand2($punt, $centrum) < self::afstand2($punt, $centra[$beste])) {
                        $beste = $c;
                    }
                }
                if ($labels[$i] !== $beste) {
                    $labels[$i] = $beste;
                    $veranderd = true;
                }
            }
            if (!$veranderd) {
                break;
            }
            foreach ($centra as $c => $centrum) {
                $leden = array_keys($labels, $c, true);
                if (count($leden) > 0) { // an empty group keeps its center
                    $centra[$c] = self::gemiddelde(array_map(function ($i) use ($punten) {
                        return $punten[$i];
                    }, $leden));
                }
            }
        }
        return $labels;
    }

    // center of each group, by label
    private static function centra(array $punten, array $labels) {
        $centra = [];
        for ($g = 0; $g <= max($labels); $g++) {
            $leden = array_keys($labels, $g, true);
            $centra[$g] = self::gemiddelde(array_map(function ($i) use ($punten) {
                return $punten[$i];
            }, $leden));
        }
        return $centra;
    }

    private static function gemiddelde(array $punten) {
        $som = array_fill(0, count($punten[0]), 0.0);
        foreach ($punten as $punt) {
            foreach ($punt as $d => $x) {
                $som[$d] += $x;
            }
        }
        return array_map(function ($x) use ($punten) {
            return $x / count($punten);
        }, $som);
    }

    // index of the point with the largest distance to its nearest point in $vanaf
    private static function verste(array $punten, array $vanaf) {
        $beste = 0;
        $besteAfstand = -1;
        foreach ($punten as $i => $punt) {
            $dichtstbij = INF;
            foreach ($vanaf as $ander) {
                $dichtstbij = min($dichtstbij, self::afstand2($punt, $ander));
            }
            if ($dichtstbij > $besteAfstand) {
                $besteAfstand = $dichtstbij;
                $beste = $i;
            }
        }
        return $beste;
    }

    // mean silhouette score, -1 (bad) to 1 (clearly separated groups)
    private static function silhouette(array $punten, array $labels) {
        $groepen = array_unique($labels);
        if (count($groepen) < 2) {
            return -1;
        }
        $totaal = 0.0;
        foreach ($punten as $i => $punt) {
            $gemiddeld = [];
            foreach ($groepen as $groep) {
                $som = 0.0;
                $aantal = 0;
                foreach ($punten as $j => $ander) {
                    if ($j !== $i && $labels[$j] === $groep) {
                        $som += sqrt(self::afstand2($punt, $ander));
                        $aantal++;
                    }
                }
                $gemiddeld[$groep] = $aantal > 0 ? $som / $aantal : null;
            }
            $a = $gemiddeld[$labels[$i]];
            if ($a === null) {
                continue; // alone in its group: counts as 0
            }
            $b = INF;
            foreach ($gemiddeld as $groep => $waarde) {
                if ($groep !== $labels[$i] && $waarde !== null) {
                    $b = min($b, $waarde);
                }
            }
            $max = max($a, $b);
            $totaal += $max > 0 ? ($b - $a) / $max : 0;
        }
        return $totaal / count($punten);
    }

    // labels 0..k-1 by group size, largest first; ties by first deelnemer
    private static function hernummer(array $labels) {
        $groottes = array_count_values($labels);
        $eerste = [];
        foreach ($labels as $i => $label) {
            $eerste[$label] = $eerste[$label] ?? $i;
        }
        $volgorde = array_keys($groottes);
        usort($volgorde, function ($a, $b) use ($groottes, $eerste) {
            return [$groottes[$b], $eerste[$a]] <=> [$groottes[$a], $eerste[$b]];
        });
        $nieuw = array_flip($volgorde);
        return array_map(function ($label) use ($nieuw) {
            return $nieuw[$label];
        }, $labels);
    }

    // order of the new groups (list of new group indexes, position = letter):
    // a new group gets the letter of the old group most of its members come from, the rest follow by size
    // $vorigeGroepen: per deelnemer (same order as $labels) the group in the previous model, or [] without one
    private static function volgorde(array $labels, array $vorigeGroepen) {
        $k = max($labels) + 1;
        if (count($vorigeGroepen) === 0) {
            return range(0, $k - 1);
        }

        // all pairs (new, old) by how many deelnemers they share, most first
        $overlap = [];
        foreach ($labels as $i => $nieuw) {
            $oud = $vorigeGroepen[$i];
            if ($oud !== null) {
                $overlap["$nieuw,$oud"] = ($overlap["$nieuw,$oud"] ?? 0) + 1;
            }
        }
        arsort($overlap);

        $oudVoorNieuw = [];
        foreach (array_keys($overlap) as $paar) {
            [$nieuw, $oud] = array_map('intval', explode(',', $paar));
            if (!isset($oudVoorNieuw[$nieuw]) && !in_array($oud, $oudVoorNieuw, true)) {
                $oudVoorNieuw[$nieuw] = $oud;
            }
        }

        // matched groups in the order of their old letter, then new groups (already sorted by size)
        $gematcht = array_keys($oudVoorNieuw);
        usort($gematcht, function ($a, $b) use ($oudVoorNieuw) {
            return $oudVoorNieuw[$a] <=> $oudVoorNieuw[$b];
        });
        $nieuw = array_values(array_diff(range(0, $k - 1), $gematcht));
        return array_merge($gematcht, $nieuw);
    }

    // per group and stelling: how many members answered eens, neutraal, oneens
    // $geplaatst: list of [antwoorden, groep]
    public static function groepStatistiek(array $stellingIds, $k, array $geplaatst) {
        $groepen = [];
        for ($g = 0; $g < $k; $g++) {
            $stellingen = [];
            foreach ($stellingIds as $stellingId) {
                $stellingen[$stellingId] = ['eens' => 0, 'neutraal' => 0, 'oneens' => 0];
            }
            $groepen[$g] = ['naam' => self::GROEP_NAMEN[$g], 'deelnemers' => 0, 'stellingen' => $stellingen];
        }
        foreach ($geplaatst as [$antwoorden, $groep]) {
            if ($groep === null) {
                continue;
            }
            $groepen[$groep]['deelnemers']++;
            foreach ($stellingIds as $stellingId) {
                $waarde = $antwoorden[$stellingId] ?? null;
                if ($waarde !== null && isset($groepen[$groep]['stellingen'][$stellingId][$waarde])) {
                    $groepen[$groep]['stellingen'][$stellingId][$waarde]++;
                }
            }
        }
        return $groepen;
    }

    private static function vermenigvuldig(array $C, array $v) {
        $resultaat = [];
        foreach ($C as $row) {
            $som = 0.0;
            foreach ($row as $j => $waarde) {
                $som += $waarde * $v[$j];
            }
            $resultaat[] = $som;
        }
        return $resultaat;
    }

    private static function normaliseer(array $v) {
        $lengte = sqrt(array_sum(array_map(function ($x) {
            return $x * $x;
        }, $v)));
        return array_map(function ($x) use ($lengte) {
            return $x / $lengte;
        }, $v);
    }

    private static function afstand2(array $p, array $q) {
        $som = 0.0;
        foreach ($p as $d => $x) {
            $som += ($x - $q[$d]) ** 2;
        }
        return $som;
    }
}
