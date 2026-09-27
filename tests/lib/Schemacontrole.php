<?php

// A small JSON Schema (2020-12) validator for the tests, without dependencies: only the keywords the specs
// and schema.json use. An unknown keyword is an error (see schemafouten()), so a typo in a schema never slips
// through unnoticed, and a new keyword gets added here first. tests/schemacontrole.php tests this class.
//
// Data is JSON decoded with objects as stdClass (json_decode($tekst)), so {} and [] stay apart; schemas are
// decoded as arrays (json_decode($tekst, true)). $ref is "#/..." within the same document, or
// "<naam>#/..." in another document given to the constructor, like "schema.json#/$defs/Gesprek".
class Schemacontrole {
    // the keywords that check something
    const CONTROLES = [
        '$ref', 'type', 'enum', 'const', 'required', 'properties', 'additionalProperties', 'items',
        'allOf', 'anyOf', 'oneOf', 'minimum', 'maximum', 'minLength', 'maxLength', 'pattern',
    ];
    // the keywords that only describe; format too, as in 2020-12 by default
    const BESCHRIJVINGEN = ['$schema', '$id', '$defs', 'title', 'description', 'readOnly', 'writeOnly', 'default', 'format', 'examples', 'deprecated'];
    const TYPES = ['object', 'array', 'string', 'integer', 'number', 'boolean', 'null'];

    // [naam => schema as array], for the $refs to other documents
    private $documenten;

    public function __construct(array $documenten = []) {
        $this->documenten = $documenten;
    }

    // the errors of $data against $schema, as ['<pad>: <melding>']; $wortel is the document $schema is in,
    // $naam its name, for the $refs in it
    public function fouten(array $schema, $data, array $wortel, $naam = '') {
        $fouten = [];
        $this->controleer($schema, $data, $wortel, $naam, '', $fouten);
        return $fouten;
    }

    // the errors in the schema itself: unknown keywords, types, and $refs that lead nowhere
    public function schemafouten(array $schema, array $wortel, $naam = '', $pad = '') {
        $fouten = [];
        foreach ($schema as $sleutel => $waarde) {
            $hier = "$pad/$sleutel";
            if (!in_array($sleutel, self::CONTROLES, true) && !in_array($sleutel, self::BESCHRIJVINGEN, true)) {
                $fouten[] = "$hier: onbekend trefwoord";
                continue;
            }
            switch ($sleutel) {
                case '$ref':
                    try {
                        $this->volg($waarde, $wortel, $naam);
                    } catch (RuntimeException $e) {
                        $fouten[] = "$hier: " . $e->getMessage();
                    }
                    break;
                case 'type':
                    foreach ((array) $waarde as $type) {
                        if (!in_array($type, self::TYPES, true)) {
                            $fouten[] = "$hier: onbekend type $type";
                        }
                    }
                    break;
                case 'properties':
                case '$defs':
                    foreach ($waarde as $naamDeel => $deel) {
                        $fouten = array_merge($fouten, $this->schemafouten($deel, $wortel, $naam, "$hier/$naamDeel"));
                    }
                    break;
                case 'items':
                    $fouten = array_merge($fouten, $this->schemafouten($waarde, $wortel, $naam, $hier));
                    break;
                case 'additionalProperties':
                    if (is_array($waarde)) {
                        $fouten = array_merge($fouten, $this->schemafouten($waarde, $wortel, $naam, $hier));
                    }
                    break;
                case 'allOf':
                case 'anyOf':
                case 'oneOf':
                    foreach ($waarde as $i => $deel) {
                        $fouten = array_merge($fouten, $this->schemafouten($deel, $wortel, $naam, "$hier/$i"));
                    }
                    break;
                case 'pattern':
                    if (@preg_match(self::regex($waarde), '') === false) {
                        $fouten[] = "$hier: ongeldige reguliere expressie";
                    }
                    break;
            }
        }
        return $fouten;
    }

    private function controleer(array $schema, $data, array $wortel, $naam, $pad, array &$fouten) {
        $plek = $pad === '' ? '/' : $pad;
        if (isset($schema['$ref'])) {
            [$doel, $doelWortel, $doelNaam] = $this->volg($schema['$ref'], $wortel, $naam);
            $this->controleer($doel, $data, $doelWortel, $doelNaam, $pad, $fouten);
        }
        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            if (!array_filter($types, function ($type) use ($data) {
                return self::isType($data, $type);
            })) {
                $fouten[] = "$plek: verwacht " . implode(' of ', $types) . ', niet ' . self::typeVan($data);
                return;
            }
        }
        if (array_key_exists('enum', $schema) && !array_filter($schema['enum'], function ($waarde) use ($data) {
            return self::gelijk($waarde, $data);
        })) {
            $fouten[] = "$plek: " . json_encode($data) . ' is niet een van ' . json_encode($schema['enum']);
        }
        if (array_key_exists('const', $schema) && !self::gelijk($schema['const'], $data)) {
            $fouten[] = "$plek: moet " . json_encode($schema['const']) . ' zijn, niet ' . json_encode($data);
        }

        if (is_object($data)) {
            foreach ($schema['required'] ?? [] as $veld) {
                if (!property_exists($data, $veld)) {
                    $fouten[] = "$plek: $veld ontbreekt";
                }
            }
            $properties = $schema['properties'] ?? [];
            foreach (get_object_vars($data) as $veld => $waarde) {
                if (isset($properties[$veld])) {
                    $this->controleer($properties[$veld], $waarde, $wortel, $naam, "$pad/$veld", $fouten);
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $fouten[] = "$pad/$veld: niet toegestaan";
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    $this->controleer($schema['additionalProperties'], $waarde, $wortel, $naam, "$pad/$veld", $fouten);
                }
            }
        }
        if (is_array($data) && isset($schema['items'])) {
            foreach ($data as $i => $waarde) {
                $this->controleer($schema['items'], $waarde, $wortel, $naam, "$pad/$i", $fouten);
            }
        }
        if (is_int($data) || is_float($data)) {
            if (isset($schema['minimum']) && $data < $schema['minimum']) {
                $fouten[] = "$plek: $data is kleiner dan {$schema['minimum']}";
            }
            if (isset($schema['maximum']) && $data > $schema['maximum']) {
                $fouten[] = "$plek: $data is groter dan {$schema['maximum']}";
            }
        }
        if (is_string($data)) {
            $lengte = mb_strlen($data);
            if (isset($schema['minLength']) && $lengte < $schema['minLength']) {
                $fouten[] = "$plek: korter dan {$schema['minLength']} tekens";
            }
            if (isset($schema['maxLength']) && $lengte > $schema['maxLength']) {
                $fouten[] = "$plek: langer dan {$schema['maxLength']} tekens";
            }
            if (isset($schema['pattern']) && preg_match(self::regex($schema['pattern']), $data) !== 1) {
                $fouten[] = "$plek: " . json_encode($data) . " past niet op {$schema['pattern']}";
            }
        }

        foreach ($schema['allOf'] ?? [] as $deel) {
            $this->controleer($deel, $data, $wortel, $naam, $pad, $fouten);
        }
        if (isset($schema['anyOf']) && $this->aantalPassend($schema['anyOf'], $data, $wortel, $naam) === 0) {
            $fouten[] = "$plek: past op geen van anyOf";
        }
        if (isset($schema['oneOf'])) {
            $aantal = $this->aantalPassend($schema['oneOf'], $data, $wortel, $naam);
            if ($aantal !== 1) {
                $fouten[] = "$plek: past op $aantal van oneOf, moet precies 1 zijn";
            }
        }
    }

    private function aantalPassend(array $delen, $data, array $wortel, $naam) {
        return count(array_filter($delen, function ($deel) use ($data, $wortel, $naam) {
            return $this->fouten($deel, $data, $wortel, $naam) === [];
        }));
    }

    // [schema, wortel, naam] where the $ref leads; a RuntimeException when it leads nowhere
    private function volg($ref, array $wortel, $naam) {
        [$document, $pointer] = array_pad(explode('#', $ref, 2), 2, '');
        if ($document !== '') {
            if (!isset($this->documenten[$document])) {
                throw new RuntimeException("onbekend document in \$ref $ref");
            }
            $wortel = $this->documenten[$document];
            $naam = $document;
        }
        $doel = $wortel;
        foreach (array_slice(explode('/', $pointer), 1) as $deel) {
            $deel = str_replace(['~1', '~0'], ['/', '~'], $deel);
            if (!is_array($doel) || !array_key_exists($deel, $doel)) {
                throw new RuntimeException("\$ref $ref leidt nergens heen");
            }
            $doel = $doel[$deel];
        }
        if (!is_array($doel)) {
            throw new RuntimeException("\$ref $ref leidt niet naar een schema");
        }
        return [$doel, $wortel, $naam];
    }

    private static function isType($data, $type) {
        switch ($type) {
            case 'object': return is_object($data);
            case 'array': return is_array($data);
            case 'string': return is_string($data);
            case 'integer': return is_int($data) || (is_float($data) && is_finite($data) && floor($data) == $data);
            case 'number': return is_int($data) || is_float($data);
            case 'boolean': return is_bool($data);
            case 'null': return $data === null;
        }
        return false;
    }

    private static function typeVan($data) {
        foreach (self::TYPES as $type) {
            if ($type !== 'number' && self::isType($data, $type)) {
                return $type;
            }
        }
        return 'number';
    }

    // equal as JSON values: 1 and 1.0 are equal, and objects regardless of the order of their fields
    private static function gelijk($a, $b) {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }
        return self::normaal($a) === self::normaal($b);
    }

    private static function normaal($waarde) {
        if (is_object($waarde)) {
            $waarde = get_object_vars($waarde);
        }
        if (!is_array($waarde)) {
            return $waarde;
        }
        $waarde = array_map([self::class, 'normaal'], $waarde);
        if (!array_is_list($waarde)) {
            ksort($waarde);
        }
        return $waarde;
    }

    // a pattern of JSON Schema as a PCRE regex, on unicode
    private static function regex($pattern) {
        return '/' . str_replace('/', '\/', $pattern) . '/u';
    }
}
