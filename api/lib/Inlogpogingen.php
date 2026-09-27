<?php

// A limit on logging in (POST /sessie): after too many failed attempts from one IP address, or for one
// gebruikersnaam, a 429 until it is over. Without IP addresses on the server: data/inlogpogingen.json holds per
// IP address (the /64 of IPv6) and per gebruikersnaam only a HMAC, with a key that is made new every hour and
// thrown away after it. The counts go with it: every hour starts over. So there is never more than an hour
// of it, and after that hour not even whoever runs the server can tell which IP address or name it was.
class Inlogpogingen {
    const MAX_PER_IP = 10;
    const MAX_PER_NAAM = 20;
    const VENSTER = 3600;   // seconds: a new key, and the counts start over

    // before checking the wachtwoord: a HttpFout 429 (with Retry-After) when there were too many failed
    // attempts this hour, from this IP address or for this gebruikersnaam
    public static function controleer($gebruikersnaam) {
        $pogingen = self::lees();
        [$ip, $naam] = self::sleutels($pogingen['sleutel'], $gebruikersnaam);
        if (count($pogingen['mislukt'][$ip] ?? []) >= self::MAX_PER_IP || count($pogingen['mislukt'][$naam] ?? []) >= self::MAX_PER_NAAM) {
            header('Retry-After: ' . max(1, $pogingen['venster'] + self::VENSTER - time()));
            throw new HttpFout(429, 'Too many failed attempts; try again later.');
        }
    }

    // after a wrong gebruikersnaam or wachtwoord
    public static function mislukt($gebruikersnaam) {
        self::bewerk(function (array $pogingen) use ($gebruikersnaam) {
            foreach (self::sleutels($pogingen['sleutel'], $gebruikersnaam) as $sleutel) {
                $pogingen['mislukt'][$sleutel][] = 1;
            }
            return $pogingen;
        });
    }

    // [hmac of the IP address, hmac of the gebruikersnaam] with the key of this hour
    private static function sleutels($sleutel, $gebruikersnaam) {
        return [
            'ip:' . substr(hash_hmac('sha256', self::ipDeel(), $sleutel), 0, 32),
            'naam:' . substr(hash_hmac('sha256', mb_strtolower($gebruikersnaam), $sleutel), 0, 32),
        ];
    }

    // the IP address of the request; of IPv6 only the first 64 bits, since one connection has many addresses there
    private static function ipDeel() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $bytes = @inet_pton($ip);
        if ($bytes === false) {
            return $ip;
        }
        return strlen($bytes) === 16 ? substr($bytes, 0, 8) : $bytes;
    }

    private static function lees() {
        return self::bewerk(function (array $pogingen) {
            return $pogingen;
        });
    }

    // reads the file, starts over with a new key when the hour is past, lets $wijzig change it, and writes it
    // back; returns { venster, sleutel, mislukt: { hmac: [1, 1, ...] } }
    private static function bewerk(callable $wijzig) {
        if (!is_dir(DATA_DIR)) {
            mkdir(DATA_DIR, 0775, true);
        }
        $handle = fopen(DATA_DIR . '/inlogpogingen.json', 'c+');
        if ($handle === false) {
            throw new RuntimeException('Failed to open inlogpogingen.json.');
        }
        flock($handle, LOCK_EX);
        $pogingen = json_decode(stream_get_contents($handle), true);
        $venster = intdiv(time(), self::VENSTER) * self::VENSTER;
        if (!is_array($pogingen) || ($pogingen['venster'] ?? 0) !== $venster) {
            // a new hour: a new key, the old one and its counts are gone
            $pogingen = ['venster' => $venster, 'sleutel' => bin2hex(random_bytes(32)), 'mislukt' => []];
        }
        $pogingen = $wijzig($pogingen);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode(['venster' => $pogingen['venster'], 'sleutel' => $pogingen['sleutel'], 'mislukt' => (object) $pogingen['mislukt']]));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        return $pogingen;
    }
}
