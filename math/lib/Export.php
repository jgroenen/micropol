<?php

// Fetching and checking the standard export of a gesprek from an api server (see ExportHandler in the api):
// { formaat: "minipol-export", versie: 1, gesprek: { id }, stellingen: [{ id }],
//   deelnemers: [{ nummer, antwoorden: { stelling_id: waarde } }] }
class Export {
    const FORMAAT = 'minipol-export';
    const VERSIES = [1];
    const WAARDEN = ['eens', 'neutraal', 'oneens'];
    const TIMEOUT = 10; // seconds

    // the export, or throws ExportFout with the status code to answer with
    public static function haal($api, $gesprekId) {
        $url = $api . '/export?gesprek_id=' . rawurlencode($gesprekId);
        $context = stream_context_create(['http' => [
            'timeout' => self::TIMEOUT,
            'ignore_errors' => true, // read the status and body of an error too
            'follow_location' => 0,
            'header' => "Accept: application/json\r\n",
        ]]);
        $tekst = @file_get_contents($url, false, $context);
        if ($tekst === false) {
            throw new ExportFout(502, "Could not fetch the export.");
        }
        $status = self::status($http_response_header ?? []);
        if ($status === 404) {
            throw new ExportFout(404, "Gesprek not found.");
        }
        if ($status !== 200) {
            throw new ExportFout(502, "The api answered the export with status $status.");
        }
        return self::controleer(json_decode($tekst, true));
    }

    // the export if it is in the standard format, with only the parts the analyse needs checked
    public static function controleer($export) {
        if (!is_array($export) || ($export['formaat'] ?? null) !== self::FORMAAT) {
            throw new ExportFout(502, "The export is not in the " . self::FORMAAT . " format.");
        }
        if (!in_array($export['versie'] ?? null, self::VERSIES, true)) {
            throw new ExportFout(502, "Unsupported export version.");
        }
        if (!is_array($export['stellingen'] ?? null) || !is_array($export['deelnemers'] ?? null)) {
            throw new ExportFout(502, "The export has no stellingen or deelnemers.");
        }
        foreach ($export['stellingen'] as $stelling) {
            if (!is_string($stelling['id'] ?? null)) {
                throw new ExportFout(502, "A stelling in the export has no id.");
            }
        }
        foreach ($export['deelnemers'] as $deelnemer) {
            if (!is_array($deelnemer['antwoorden'] ?? null)) {
                throw new ExportFout(502, "A deelnemer in the export has no antwoorden.");
            }
            foreach ($deelnemer['antwoorden'] as $waarde) {
                if (!in_array($waarde, self::WAARDEN, true)) {
                    throw new ExportFout(502, "Unknown waarde in the export.");
                }
            }
        }
        return $export;
    }

    // the status code of the last response in $http_response_header (after redirects)
    private static function status(array $headers) {
        $status = 0;
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match)) {
                $status = (int) $match[1];
            }
        }
        return $status;
    }
}
