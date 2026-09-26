#!/bin/sh
# Installs MiniPol on a VPS (Ubuntu or Debian), once. Run it as root from the cloned repository:
#
#   git clone <url of the repository> /srv/minipol
#   /srv/minipol/deploy/installeer.sh <domein> <email>
#
# It installs FrankenPHP, makes the user minipol (who owns the repository and runs the service),
# writes /etc/minipol.env, and starts the service minipol. Caddy gets the certificates itself, so the
# domain and its subdomains (www, admin, api, math, cdn) must already point to this server.
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
case $(uname -m) in
    x86_64) ARCH=x86_64 ;;
    aarch64 | arm64) ARCH=aarch64 ;;
    *) fout "onbekende processor $(uname -m)" ;;
esac
if [ ! -x /usr/local/bin/frankenphp ]; then
    curl -fsSL -o /usr/local/bin/frankenphp "https://github.com/php/frankenphp/releases/latest/download/frankenphp-linux-$ARCH"
    chmod 755 /usr/local/bin/frankenphp
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

echo "== dienst minipol"
cp "$REPO/deploy/minipol.service" /etc/systemd/system/minipol.service
systemctl daemon-reload
systemctl enable minipol > /dev/null
systemctl restart minipol
sleep 2
systemctl --no-pager --lines=0 status minipol

echo
echo "Klaar. Maak nu een beheerder aan:"
echo "  sudo -u minipol frankenphp php-cli $REPO/api/bin/beheerder-toevoegen.php <gebruikersnaam> <email>"
echo "Een nieuwe versie uitrollen: $REPO/deploy/uppen.sh"
