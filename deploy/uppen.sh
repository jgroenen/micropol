#!/bin/sh
# Puts the newest version of MiniPol on this server: git pull, then Caddy reloads the Caddyfile.
# Run it on the VPS, as root or with sudo:   /srv/minipol/deploy/uppen.sh
# The data (api/data, math/data) is not in git, so a pull never touches it.
set -eu
REPO=$(cd "$(dirname "$0")/.." && pwd)
. /etc/minipol.env

oud=$(git -C "$REPO" rev-parse --short HEAD)
sudo -u minipol git -C "$REPO" pull --ff-only
nieuw=$(git -C "$REPO" rev-parse --short HEAD)

if [ "$oud" = "$nieuw" ]; then
    echo "Al de nieuwste versie ($nieuw)."
    exit 0
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
    exit 1
fi
