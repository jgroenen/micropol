#!/bin/sh
# Installs MiniPol on a VPS (Ubuntu or Debian), once. Run it as root from the cloned repository:
#
#   git clone https://github.com/jgroenen/micropol.git /srv/minipol
#   /srv/minipol/deploy/installeer.sh <domein> <email>
#
# It installs FrankenPHP, makes the user minipol (who owns the repository and runs the service),
# writes /etc/minipol.env, and starts the service minipol. Caddy gets the certificates itself, so the
# domain and its subdomains (www, app, admin, api, math, cdn) must already point to this server.
# Running it again is safe; see docs/livegang.md.
set -eu

fout() {
    echo "Fout: $*" >&2
    exit 1
}

[ "$(id -u)" -eq 0 ] || fout "draai dit als root (sudo)."
[ $# -eq 2 ] || fout "gebruik: deploy/installeer.sh <domein> <email>"
DOMEIN=$1
EMAIL=$2
REPO=$(cd "$(dirname "$0")/.." && pwd)

echo "== FrankenPHP"
# a fixed version, checked against its sha256 before it is installed, so a changed or false download never
# runs. A newer version is a conscious step: change the version and both checksums (from the release on
# GitHub), and run this script again; see SECURITY.md.
FRANKENPHP_VERSIE=v1.12.7
case $(uname -m) in
    x86_64) ARCH=x86_64 SHA256=728a8b2476abb90810960615d8f6fb2f2129c3eb239a15f516672c0fd39ba884 ;;
    aarch64 | arm64) ARCH=aarch64 SHA256=d2ec0cbb24cffc606af127f83e8a18282586d5f98954ee9d2a96e7a3f9e81b4b ;;
    *) fout "onbekende processor $(uname -m)" ;;
esac
if ! /usr/local/bin/frankenphp version 2>/dev/null | grep -qF "$FRANKENPHP_VERSIE"; then
    download=$(mktemp)
    curl -fsSL -o "$download" "https://github.com/php/frankenphp/releases/download/$FRANKENPHP_VERSIE/frankenphp-linux-$ARCH"
    if ! echo "$SHA256  $download" | sha256sum -c --quiet; then
        rm -f "$download"
        fout "de download van FrankenPHP $FRANKENPHP_VERSIE heeft niet de verwachte sha256; niets geïnstalleerd."
    fi
    install -m 755 "$download" /usr/local/bin/frankenphp
    rm -f "$download"
fi
/usr/local/bin/frankenphp version

echo "== gebruiker minipol, eigenaar van $REPO"
# its home (/var/lib/minipol) also holds the certificates of Caddy, and a deploy key for a private repository
id minipol > /dev/null 2>&1 || useradd --system --home-dir /var/lib/minipol --create-home --shell /usr/sbin/nologin minipol
mkdir -p "$REPO/api/data" "$REPO/math/data"
chown -R minipol:minipol "$REPO"
chmod 750 "$REPO/api/data" "$REPO/math/data"
# git refuses a repository of another user, also for root
git config --system --get-all safe.directory | grep -qx "$REPO" || git config --system --add safe.directory "$REPO"

echo "== /etc/minipol.env"
cat > /etc/minipol.env <<EOT
MINIPOL_DOMEIN=$DOMEIN
MINIPOL_EMAIL=$EMAIL
MINIPOL_ROOT=$REPO
EOT
chmod 644 /etc/minipol.env

# 1 GB memory is enough, but a little swap keeps a peak from stopping the service
if [ "$(swapon --show | wc -l)" -eq 0 ] && [ ! -f /swapfile ]; then
    echo "== swap"
    fallocate -l 1G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile > /dev/null
    swapon /swapfile
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

if command -v ufw > /dev/null && ufw status | grep -q active; then
    echo "== firewall: http en https"
    ufw allow 80/tcp > /dev/null
    ufw allow 443/tcp > /dev/null
fi

echo "== data: de migraties"
# on new data they have nothing to do, but are marked as done, so an update never runs them again
sudo -u minipol /usr/local/bin/frankenphp php-cli "$REPO/api/bin/migreer.php"

echo "== dienst minipol"
cp "$REPO/deploy/minipol.service" /etc/systemd/system/minipol.service
systemctl daemon-reload
systemctl enable minipol > /dev/null
systemctl restart minipol
sleep 2
systemctl --no-pager --lines=0 status minipol

echo
echo "Klaar. Log nu in op https://admin.$DOMEIN met admin/admin, en maak je eigen account:"
echo "de eerste superbeheerder. Doe dat meteen: tot die tijd kan iedereen dat."
echo "Een nieuwe versie uitrollen: $REPO/deploy/uppen.sh"
