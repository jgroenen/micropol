#!/bin/sh
# Puts the newest version of MiniPol on this server. Run it on the VPS, as root:   /srv/minipol/deploy/uppen.sh
# or from your own computer:   ssh root@<vps> /srv/minipol/deploy/uppen.sh
#
#   1. is there a new version? (git fetch)
#   2. a back-up of api/data, in /var/backups/minipol/<datum>-<commit>.tar.gz; the last 10 are kept
#   3. git pull
#   4. the migraties: the data up to date with the new code (api/bin/migreer.php)
#   5. Caddy reloads the Caddyfile (a changed service file: a restart), and a check that the api answers
#
# Goes something wrong, then deploy/terugzetten.sh puts the code and the data back to the back-up.
set -eu
REPO=$(cd "$(dirname "$0")/.." && pwd)
# other places only for trying the script out elsewhere
BACKUPS=${MINIPOL_BACKUPS:-/var/backups/minipol}
PHP=${MINIPOL_PHP:-/usr/local/bin/frankenphp php-cli}
. "${MINIPOL_ENV:-/etc/minipol.env}"

als_minipol() {
    sudo -u minipol "$@"
}

als_minipol git -C "$REPO" fetch --quiet
oud=$(git -C "$REPO" rev-parse --short HEAD)
nieuw=$(git -C "$REPO" rev-parse --short '@{u}')
if [ "$oud" = "$nieuw" ]; then
    echo "Al de nieuwste versie ($nieuw)."
    exit 0
fi

mkdir -p "$BACKUPS"
chmod 700 "$BACKUPS"
backup="$BACKUPS/$(date +%Y%m%d-%H%M%S)-$oud.tar.gz"
tar -czf "$backup" -C "$REPO/api" data
ls -1t "$BACKUPS"/*.tar.gz | tail -n +11 | xargs -r rm --
echo "Back-up van de data: $backup"

als_minipol git -C "$REPO" pull --quiet --ff-only
if ! als_minipol $PHP "$REPO/api/bin/migreer.php"; then
    echo "Een migratie is mislukt. Zet code en data terug met:" >&2
    echo "  $REPO/deploy/terugzetten.sh $backup" >&2
    exit 1
fi

# PHP picks up changed files by itself; a changed service file needs a restart, the Caddyfile a reload
if git -C "$REPO" diff --quiet "$oud" "$nieuw" -- deploy/minipol.service; then
    systemctl reload minipol
else
    cp "$REPO/deploy/minipol.service" /etc/systemd/system/minipol.service
    systemctl daemon-reload
    systemctl restart minipol
fi
echo "$oud -> $nieuw"

# a quick check that the api answers
if curl -fsS -o /dev/null "https://api.$MINIPOL_DOMEIN/sessie"; then
    echo "De api is bereikbaar."
else
    echo "Let op: de api antwoordt niet, zie: journalctl -u minipol" >&2
    echo "Terug naar de vorige versie: $REPO/deploy/terugzetten.sh $backup" >&2
    exit 1
fi
