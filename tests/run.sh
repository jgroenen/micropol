#!/bin/sh
# Runs all tests against the servers of dev/start.sh, which must be running:
#   tests/migraties.sh      the migraties, on data in the oldest form
#   tests/schemacontrole.php the validator of api.php, tested itself
#   tests/paneltellingen.php the counts of the links of a panel, per day
#   tests/inlogpogingen.php  the limit on logging in, without IP addresses
#   tests/paden.php          a gesprek id never leads to a path outside the data map
#   tests/api.php           every call of api and math checked against their OpenAPI spec, logging in included
#   tests/browser.mjs       the product page, the app and the admin in Chrome
# The tests start on an empty api: they install it (admin/admin makes the first superbeheerder) and make
# their own data. The data of api and math is copied first and put back afterwards, so nothing is left
# behind. Don't use the app while they run.
# Needs php (8.1 or newer), node (18 or newer) and Chrome; nothing to install.
set -eu
cd "$(dirname "$0")/.."

for poort in 8000 8001 8002 8003 8004 8005; do
    if [ "$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:$poort/")" = 000 ]; then
        echo "Fout: niets op localhost:$poort; start eerst ./dev/start.sh" >&2
        exit 1
    fi
done


BEWAARD=$(mktemp -d)
UITVOER=$BEWAARD/testdata.json
# in a fresh checkout the data maps are not there yet (they are made on first use)
for deel in api math; do
    mkdir -p "$deel/data"
    cp -R "$deel/data" "$BEWAARD/$deel"
done
terugzetten() {
    for deel in api math; do
        rm -rf "$deel/data"
        cp -R "$BEWAARD/$deel" "$deel/data"
    done
    rm -rf "$BEWAARD"
}
trap terugzetten EXIT

# an empty api, as right after installing
rm -rf api/data
mkdir api/data
export TEST_GEBRUIKER="minipol-test" TEST_WACHTWOORD=testwachtwoord123 TEST_UITVOER="$UITVOER"

# runs one test; shows everything but the "ok" lines, and remembers when it failed
mislukt=0
draai() {
    if "$@" > "$BEWAARD/uitvoer" 2>&1; then
        grep -v '^ok' "$BEWAARD/uitvoer" || true
    else
        grep -v '^ok' "$BEWAARD/uitvoer" || true
        mislukt=1
    fi
}
draai tests/migraties.sh
draai php tests/schemacontrole.php
draai php tests/paneltellingen.php
draai php tests/inlogpogingen.php
draai php tests/paden.php
draai php tests/api.php
draai node tests/browser.mjs

# the script for a superbeheerder when nobody can log in anymore
if printf '%s\n%s\n' "$TEST_WACHTWOORD" "$TEST_WACHTWOORD" | php api/bin/superbeheerder-toevoegen.php minipol-cli cli@example.org | grep -q toegevoegd; then
    echo "ok   superbeheerder-toevoegen.php"
else
    echo "FOUT superbeheerder-toevoegen.php voegt geen superbeheerder toe"
    mislukt=1
fi

if [ $mislukt -eq 0 ]; then
    echo "Alle tests geslaagd."
else
    echo "Er zijn tests mislukt (zie FOUT hierboven)." >&2
    exit 1
fi
