#!/bin/sh
# Runs all tests against the servers of dev/start.sh, which must be running:
#   tests/api.py       every call of api, math and auth checked against their OpenAPI spec
#   tests/oauth.py     the OAuth flows of the auth service, and how the api uses its tokens
#   tests/browser.mjs  the app and the admin in Chrome
# The tests make their own data (and a gebruiker to log in with); the data of api, math and auth is
# copied first and put back afterwards, so nothing is left behind. Don't use the app while they run.
# Needs python3, node (18 or newer) and Chrome; jsonschema is installed in tests/.venv the first time.
set -eu
cd "$(dirname "$0")/.."

for poort in 8000 8001 8002 8003 8004 8005; do
    if [ "$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:$poort/")" = 000 ]; then
        echo "Fout: niets op localhost:$poort; start eerst ./dev/start.sh" >&2
        exit 1
    fi
done

[ -d tests/.venv ] || python3 -m venv tests/.venv
tests/.venv/bin/pip install -q --disable-pip-version-check -r tests/requirements.txt

BEWAARD=$(mktemp -d)
UITVOER=$BEWAARD/testdata.json
for deel in api math auth; do
    cp -R "$deel/data" "$BEWAARD/$deel"
done
terugzetten() {
    for deel in api math auth; do
        rm -rf "$deel/data"
        cp -R "$BEWAARD/$deel" "$deel/data"
    done
    rm -rf "$BEWAARD"
}
trap terugzetten EXIT

# a gebruiker of its own for every run, so it never clashes with an existing one
export TEST_GEBRUIKER="minipol-test-$$" TEST_WACHTWOORD=testwachtwoord123 TEST_UITVOER="$UITVOER"
printf '%s\n%s\n' "$TEST_WACHTWOORD" "$TEST_WACHTWOORD" | php auth/bin/gebruiker-toevoegen.php "$TEST_GEBRUIKER" test@example.org > /dev/null

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
draai tests/.venv/bin/python tests/api.py
draai python3 tests/oauth.py
draai node tests/browser.mjs

if [ $mislukt -eq 0 ]; then
    echo "Alle tests geslaagd."
else
    echo "Er zijn tests mislukt (zie FOUT hierboven)." >&2
    exit 1
fi
