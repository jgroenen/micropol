#!/bin/sh
# Deploys MiniPol to a VPS with Apache, every part on its own subdomain. See docs/livegang.md.
#
#   deploy/deploy.sh bouw                        build locally in deploy/build/, to look at
#   deploy/deploy.sh installeer                  once: install Apache, PHP and the certificate, then deploy
#   deploy/deploy.sh uitrollen                   deploy the current code (the default)
#   deploy/deploy.sh controleer                  check that every part answers
#   deploy/deploy.sh beheerder <naam> <email>    add a beheerder (a gebruiker of the auth service) on the server
#
# The settings come from deploy/$OMGEVING.env (default: productie), see deploy/productie.env.voorbeeld.
# The data on the server (api/data, math/data, auth/data) is never overwritten.
set -eu
cd "$(dirname "$0")/.."

fout() {
    echo "Fout: $*" >&2
    exit 1
}

ACTIE=${1:-uitrollen}
[ $# -gt 0 ] && shift
OMGEVING=${OMGEVING:-productie}
ENV_BESTAND=deploy/$OMGEVING.env
[ -f "$ENV_BESTAND" ] || fout "$ENV_BESTAND ontbreekt; kopieer deploy/productie.env.voorbeeld en vul het in."

set -a
. "./$ENV_BESTAND"
: "${APP_URL:=https://www.$DOMEIN}"
: "${ADMIN_URL:=https://admin.$DOMEIN}"
: "${API_URL:=https://api.$DOMEIN}"
: "${AUTH_URL:=https://auth.$DOMEIN}"
: "${CDN_URL:=https://cdn.$DOMEIN}"
: "${MATH_URL:=https://math.$DOMEIN}"
set +a

for naam in DOMEIN SERVER DOEL EMAIL API_SECRET; do
    eval "waarde=\${$naam:-}"
    [ -n "$waarde" ] || fout "$naam is niet ingevuld in $ENV_BESTAND."
done
[ ${#API_SECRET} -ge 32 ] || fout "API_SECRET is te kort; maak er een met: openssl rand -hex 32"

# host names for the virtual hosts: the url without https:// and path
host() {
    echo "$1" | sed -E 's#^https?://##; s#/.*$##'
}
APP_HOST=$(host "$APP_URL")
ADMIN_HOST=$(host "$ADMIN_URL")
API_HOST=$(host "$API_URL")
AUTH_HOST=$(host "$AUTH_URL")
CDN_HOST=$(host "$CDN_URL")
MATH_HOST=$(host "$MATH_URL")
export APP_HOST ADMIN_HOST API_HOST AUTH_HOST CDN_HOST MATH_HOST

# root needs no sudo; running as www-data then goes with runuser
case $SERVER in
    root@*) SUDO= ; ALS_WWW_DATA='runuser -u www-data --' ;;
    *) SUDO=sudo ; ALS_WWW_DATA='sudo -u www-data' ;;
esac
GEBRUIKER=${SERVER%@*}
BUILD=deploy/build/$OMGEVING
DELEN="app admin cdn api math auth"

# fills in {{NAAM}} with the environment variable NAAM; stops at an unknown name
vul_in() {
    perl -pe 's/\{\{(\w+)\}\}/exists $ENV{$1} ? $ENV{$1} : die "Onbekend in sjabloon: $1\n"/ge' "$1"
}

bouw() {
    rm -rf "$BUILD"
    mkdir -p "$BUILD/.deploy"
    for deel in $DELEN; do
        rsync -a --exclude 'data/' --exclude '.DS_Store' "$deel/" "$BUILD/$deel/"
    done

    # the static parts cannot read the environment: write their settings into the files
    cat > "$BUILD/app/js/config.js" <<EOF
// Made by deploy/deploy.sh for $OMGEVING; the values for local development are in the repository.
export const API_URL = '$API_URL';
export const MATH_URL = '$MATH_URL';
EOF
    cat > "$BUILD/admin/js/config.js" <<EOF
// Made by deploy/deploy.sh for $OMGEVING; the values for local development are in the repository.
export const API_URL = '$API_URL';
export const APP_URL = '$APP_URL';
export const AUTH_URL = '$AUTH_URL';
export const CLIENT_ID = 'minipol-admin';
EOF
    for bestand in "$BUILD/app/index.html" "$BUILD/admin/index.html"; do
        perl -pi -e "s#http://localhost:8003#$CDN_URL#g" "$bestand"
    done
    if grep -rl 'http://localhost:' "$BUILD/app" "$BUILD/admin" "$BUILD/cdn"; then
        fout "in de bestanden hierboven staat nog een url voor lokaal ontwikkelen."
    fi

    vul_in deploy/apache-http.conf.sjabloon > "$BUILD/.deploy/minipol-http.conf"
    vul_in deploy/apache-https.conf.sjabloon > "$BUILD/.deploy/minipol-https.conf"
    chmod 600 "$BUILD/.deploy/minipol-https.conf"
    cp deploy/op-server.sh "$BUILD/.deploy/"
    echo "Gebouwd in $BUILD"
}

stuur() {
    for deel in $DELEN; do
        # --delete removes files that are gone from the code, never the data (excluded)
        rsync -az --delete --exclude 'data/' "$BUILD/$deel/" "$SERVER:$DOEL/$deel/"
    done
    rsync -az --delete --chmod=go-rwx "$BUILD/.deploy/" "$SERVER:$DOEL/.deploy/"
}

op_server() {
    ssh -t "$SERVER" "$SUDO sh $DOEL/.deploy/op-server.sh $1 '$DOEL' '$GEBRUIKER' '$DOMEIN' '$EMAIL' $APP_HOST $ADMIN_HOST $API_HOST $AUTH_HOST $CDN_HOST $MATH_HOST"
}

controleer() {
    fouten=0
    check() {
        # $1 description, $2 url, $3 expected status, $4 optional header that must be there
        antwoord=$(curl -s -o /dev/null -D - -m 15 -w '%{http_code}' "$2" || true)
        status=$(printf '%s' "$antwoord" | tail -n 1)
        if [ "$status" != "$3" ] || { [ -n "${4:-}" ] && ! printf '%s' "$antwoord" | grep -qi "^$4"; }; then
            echo "FOUT $1: $2 gaf $status${4:+, of mist $4}"
            fouten=$((fouten + 1))
        else
            echo "ok   $1"
        fi
    }
    check "app" "$APP_URL/" 200
    check "admin" "$ADMIN_URL/" 200
    check "cdn (met CORS)" "$CDN_URL/js/util.js" 200 "access-control-allow-origin"
    check "api" "$API_URL/gesprekken" 200
    check "api docs" "$API_URL/docs" 200
    check "api data afgeschermd" "$API_URL/data/gesprekken.csv" 404
    check "math" "$MATH_URL/docs/openapi.json" 200
    check "auth" "$AUTH_URL/.well-known/openid-configuration" 200
    check "api beheer afgeschermd" "$API_URL/beoordelingen?gesprek_id=x" 401
    # the shared secret works at the auth service: an unknown token then gives 200 with active false
    if curl -s -m 15 -u "minipol-api:$API_SECRET" -d token=x "$AUTH_URL/introspect" | grep -q '"active":false'; then
        echo "ok   api-geheim bij auth"
    else
        echo "FOUT api-geheim bij auth: introspection weigert het geheim"
        fouten=$((fouten + 1))
    fi
    check "http -> https" "http://$APP_HOST/" 301
    check "kaal domein -> app" "https://$DOMEIN/" 301
    [ $fouten -eq 0 ] || fout "$fouten controle(s) mislukt."
    echo "Alles bereikbaar."
}

case $ACTIE in
    bouw)
        bouw
        ;;
    installeer)
        bouw
        ssh -t "$SERVER" "$SUDO mkdir -p '$DOEL' && $SUDO chown '$GEBRUIKER' '$DOEL'"
        stuur
        op_server installeer
        controleer
        echo "Maak nu een beheerder aan: deploy/deploy.sh beheerder <naam> <email>"
        ;;
    uitrollen)
        bouw
        stuur
        op_server activeer
        controleer
        ;;
    controleer)
        controleer
        ;;
    beheerder)
        [ $# -eq 2 ] || fout "gebruik: deploy/deploy.sh beheerder <naam> <email>"
        ssh -t "$SERVER" "$ALS_WWW_DATA php '$DOEL/auth/bin/gebruiker-toevoegen.php' '$1' '$2'"
        ;;
    *)
        fout "onbekende actie $ACTIE; kies bouw, installeer, uitrollen, controleer of beheerder."
        ;;
esac
