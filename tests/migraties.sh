#!/bin/sh
# The migraties (api/bin/migreer.php) on data in the oldest form: csv files for gesprekken, stellingen,
# antwoorden, beoordelingen and beheerders. After converting, the old beheerder can log in as superbeheerder,
# and a second run has nothing to do. Works on a data map of its own; run by tests/run.sh.
set -eu
cd "$(dirname "$0")/.."
DATA=$(mktemp -d)
trap 'rm -rf "$DATA"' EXIT
fouten=0
check() {
    if eval "$2"; then echo "ok   migraties: $1"; else echo "FOUT migraties: $1"; fouten=$((fouten + 1)); fi
}

HASH=$(php -r 'echo password_hash("oudwachtwoord123", PASSWORD_DEFAULT);')
mkdir -p "$DATA/antwoorden" "$DATA/beoordelingen"
cat > "$DATA/gesprekken.csv" <<EOT
id,titel,omschrijving,moderatie
g1,"Oud gesprek","Van vroeger",achteraf
g1,"Oud gesprek (aangepast)","Van vroeger",vooraf
EOT
cat > "$DATA/stellingen.csv" <<EOT
id,gesprek_id,tekst,deelnemer_id
s1,g1,"Een oude stelling.",d1
s2,g1,"Nog een.",d2
EOT
printf 'deelnemer_id,stelling_id,waarde\nd1,s1,eens\nd2,s1,oneens\nd2,s2,neutraal\n' > "$DATA/antwoorden/g1.csv"
printf 'stelling_id,beoordeling,reden,beheerder_id,tijdstip\ns1,goedgekeurd,,b1,1790000000\n' > "$DATA/beoordelingen/g1.csv"
printf 'id,gebruikersnaam,email,salt,versleuteld_wachtwoord,wachtwoord_methode\nb1,oudbeheer,oud@example.org,,%s,password_hash\n' "$HASH" > "$DATA/beheerders.csv"
printf 'token_hash,beheerder_id,begonnen,verloopt\nabc,b1,1,2\n' > "$DATA/sessies.csv"

uitvoer=$(php api/bin/migreer.php "$DATA")
check "beide migraties gedraaid" '[ "$(echo "$uitvoer" | grep -c omgezet)" -eq 2 ]'
check "gesprek aangemaakt en aangepast" 'grep -q gesprek.aangemaakt "$DATA/gesprekken.jsonl" && grep -q gesprek.aangepast "$DATA/gesprekken.jsonl"'
check "stellingen en beoordeling" '[ "$(wc -l < "$DATA/gesprekken/g1/stellingen.jsonl")" -eq 3 ]'
check "antwoorden" '[ "$(wc -l < "$DATA/gesprekken/g1/antwoorden.jsonl")" -eq 3 ]'
check "oude csv-bestanden bewaard" '[ -f "$DATA/csv-voor-events/gesprekken.csv" ] && [ -f "$DATA/csv-voor-teams/beheerders.csv" ] && [ ! -f "$DATA/sessies.csv" ]'
check "oude beheerder is superbeheerder en gespreksbeheerder, met zijn wachtwoord" 'php -r '"'"'
    define("DATA_DIR", $argv[1]);
    foreach (["Csv", "Jsonl", "Data", "HttpFout", "Beheer", "Wachtwoord"] as $c) { require "api/lib/$c.php"; }
    $a = Beheer::accountMetNaam("oudbeheer");
    exit($a && Beheer::isSuperbeheerder($a) && Beheer::rolIn("g1", $a) === "gespreksbeheerder" && Wachtwoord::klopt("oudwachtwoord123", $a) && Data::gesprek("g1")["titel"] === "Oud gesprek (aangepast)" ? 0 : 1);
'"'"' "$DATA"'
check "tweede keer niets te doen" 'php api/bin/migreer.php "$DATA" | grep -q "De data is bij"'

# a new, empty data map: nothing to convert, but marked as done
LEEG="$DATA/leeg"
check "lege data: niets om te zetten" '[ "$(php api/bin/migreer.php "$LEEG" | grep -c "niets om te zetten")" -eq 2 ] && [ "$(wc -l < "$LEEG/migraties.jsonl")" -eq 2 ]'

echo "migraties.sh: $fouten fouten"
[ $fouten -eq 0 ]
