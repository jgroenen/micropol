<?php

// The validator of the tests (lib/Schemacontrole.php) tested itself: it must let good data through, and
// find what is wrong in bad data, for every keyword; and find unknown keywords and $refs that lead nowhere
// in a schema. Otherwise tests/api.php could pass while the api does not fit its spec.
require __DIR__ . '/lib/Schemacontrole.php';

$fouten = 0;
function proef($naam, $goed) {
    global $fouten;
    $fouten += $goed ? 0 : 1;
    echo ($goed ? 'ok   ' : 'FOUT ') . "schemacontrole: $naam\n";
}

$ander = json_decode('{ "$defs": { "Waarde": { "type": "string", "enum": ["eens", "oneens"] } } }', true);
$wortel = json_decode(<<<'JSON'
{
  "$defs": {
    "Ding": {
      "type": "object",
      "required": ["id", "waarde"],
      "properties": {
        "id": { "type": "string", "pattern": "^[a-z]{2,4}$" },
        "waarde": { "$ref": "ander.json#/$defs/Waarde" },
        "aantal": { "type": "integer", "minimum": 1, "maximum": 5 },
        "tekst": { "type": ["string", "null"], "minLength": 2, "maxLength": 4 },
        "soort": { "const": "ding" },
        "lijst": { "type": "array", "items": { "type": "number" } },
        "vrij": { "type": "object", "additionalProperties": { "type": "boolean" } },
        "dicht": { "type": "object", "properties": { "a": { "type": "string" } }, "additionalProperties": false },
        "een": { "oneOf": [{ "type": "string" }, { "type": "integer" }, { "type": "number" }] },
        "alle": { "allOf": [{ "type": "string" }, { "maxLength": 2 }] },
        "enig": { "anyOf": [{ "type": "string" }, { "type": "boolean" }] }
      }
    }
  }
}
JSON, true);
$controle = new Schemacontrole(['ander.json' => $ander]);
$ding = ['$ref' => '#/$defs/Ding'];
$fout = function ($json) use ($controle, $ding, $wortel) {
    return $controle->fouten($ding, json_decode($json), $wortel);
};

proef('goede data', $fout('{"id": "ab", "waarde": "eens", "aantal": 3, "tekst": null, "soort": "ding", "lijst": [1, 2.5], "vrij": {"x": true}, "dicht": {"a": "b"}, "een": "x", "alle": "xy", "enig": true}') === []);
proef('integer als 3.0', $fout('{"id": "ab", "waarde": "eens", "aantal": 3.0}') === []);
foreach ([
    'required' => '{"id": "ab"}',
    'type' => '{"id": 1, "waarde": "eens"}',
    'object is geen array' => '[]',
    'pattern' => '{"id": "ABC", "waarde": "eens"}',
    '$ref naar een ander document, en enum' => '{"id": "ab", "waarde": "misschien"}',
    'integer' => '{"id": "ab", "waarde": "eens", "aantal": 2.5}',
    'minimum' => '{"id": "ab", "waarde": "eens", "aantal": 0}',
    'maximum' => '{"id": "ab", "waarde": "eens", "aantal": 6}',
    'minLength' => '{"id": "ab", "waarde": "eens", "tekst": "a"}',
    'maxLength, in tekens' => '{"id": "ab", "waarde": "eens", "tekst": "ëëëëë"}',
    'const' => '{"id": "ab", "waarde": "eens", "soort": "iets"}',
    'items' => '{"id": "ab", "waarde": "eens", "lijst": [1, "twee"]}',
    'additionalProperties met een schema' => '{"id": "ab", "waarde": "eens", "vrij": {"x": 1}}',
    'additionalProperties false' => '{"id": "ab", "waarde": "eens", "dicht": {"b": "c"}}',
    'oneOf: op geen' => '{"id": "ab", "waarde": "eens", "een": true}',
    'oneOf: op twee (3 is integer en number)' => '{"id": "ab", "waarde": "eens", "een": 3}',
    'allOf' => '{"id": "ab", "waarde": "eens", "alle": "xyz"}',
    'anyOf' => '{"id": "ab", "waarde": "eens", "enig": 1}',
] as $wat => $json) {
    proef("keurt af: $wat", $fout($json) !== []);
}
proef('een fout noemt de plek', in_array('/aantal: 0 is kleiner dan 1', $fout('{"id": "ab", "waarde": "eens", "aantal": 0}'), true));

// the schema itself
proef('goed schema', $controle->schemafouten($wortel, $wortel) === []);
proef('onbekend trefwoord', $controle->schemafouten(['type' => 'string', 'maxLenght' => 3], $wortel) === ['/maxLenght: onbekend trefwoord']);
proef('onbekend type', $controle->schemafouten(['type' => 'text'], $wortel) !== []);
proef('$ref die nergens heen leidt', $controle->schemafouten(['properties' => ['a' => ['$ref' => '#/$defs/Bestaatniet']]], $wortel) !== []);
proef('$ref naar een onbekend document', $controle->schemafouten(['$ref' => 'weg.json#/$defs/Ding'], $wortel) !== []);
proef('fout diep in een schema', $controle->schemafouten(['$defs' => ['A' => ['oneOf' => [['typ' => 'string']]]]], $wortel) === ['/$defs/A/oneOf/0/typ: onbekend trefwoord']);

echo "schemacontrole.php: $fouten fouten\n";
exit($fouten ? 1 : 0);
