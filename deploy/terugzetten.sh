#!/bin/sh
# Puts the code and the data back to a back-up of deploy/uppen.sh, for when an update went wrong.
# Run it on the VPS, as root:   /srv/minipol/deploy/terugzetten.sh /var/backups/minipol/<datum>-<commit>.tar.gz
# Without a back-up it lists them. The data of now is kept next to it, in api/data-<datum>, not removed:
# what came in after the back-up is there. The next deploy/uppen.sh brings the newest code again.
set -eu
REPO=$(cd "$(dirname "$0")/.." && pwd)
BACKUPS=${MINIPOL_BACKUPS:-/var/backups/minipol}

if [ $# -ne 1 ]; then
    echo "Gebruik: deploy/terugzetten.sh <back-up>. De back-ups, nieuwste eerst:" >&2
    ls -1t "$BACKUPS"/*.tar.gz >&2 2>/dev/null || echo "  (geen)" >&2
    exit 1
fi
backup=$1
[ -f "$backup" ] || { echo "Fout: $backup bestaat niet." >&2; exit 1; }
# the commit is in the name: <datum>-<commit>.tar.gz
commit=$(basename "$backup" .tar.gz | sed 's/.*-//')
git -C "$REPO" cat-file -e "$commit^{commit}" || { echo "Fout: commit $commit onbekend." >&2; exit 1; }

systemctl stop minipol
bewaard="$REPO/api/data-$(date +%Y%m%d-%H%M%S)"
mv "$REPO/api/data" "$bewaard"
tar -xzf "$backup" -C "$REPO/api"
chown -R minipol:minipol "$REPO/api/data"
sudo -u minipol git -C "$REPO" reset --quiet --hard "$commit"
cp "$REPO/deploy/minipol.service" /etc/systemd/system/minipol.service
systemctl daemon-reload
systemctl start minipol

echo "Terug op $commit, met de data van $(basename "$backup")."
echo "De data van voor het terugzetten staat in $bewaard."
