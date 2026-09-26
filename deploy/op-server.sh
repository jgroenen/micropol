#!/bin/sh
# Runs on the server, as root, started by deploy/deploy.sh; the Apache configuration comes along in
# the same directory ($DOEL/.deploy/).
#
#   op-server.sh installeer <doel> <gebruiker> <domein> <email> <hosts...>
#       once: Apache with PHP, the data directories, the certificate, then activeer
#   op-server.sh activeer <doel> <gebruiker> <domein> <email> <hosts...>
#       the (new) Apache configuration, checked, and Apache reloaded
set -eu

ACTIE=$1
DOEL=$2
GEBRUIKER=$3
DOMEIN=$4
EMAIL=$5
shift 5
HOSTS="$*"
HIER=$(dirname "$0")

activeer() {
    install -m 644 "$HIER/minipol-http.conf" /etc/apache2/sites-available/minipol-http.conf
    # holds the api secret
    install -m 600 "$HIER/minipol-https.conf" /etc/apache2/sites-available/minipol-https.conf
    a2ensite -q minipol-http minipol-https
    apachectl configtest
    systemctl reload apache2
    echo "Apache herladen."
}

if [ "$ACTIE" = installeer ]; then
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y apache2 libapache2-mod-php php-mbstring certbot rsync curl
    a2enmod -q rewrite headers ssl setenvif

    # the data: written by php (www-data), not by the deploy user
    for deel in api math auth; do
        mkdir -p "$DOEL/$deel/data"
        chown www-data:www-data "$DOEL/$deel/data"
        chmod 750 "$DOEL/$deel/data"
    done
    chown "$GEBRUIKER" "$DOEL"

    # first port 80 only, so Let's Encrypt can check the domains; then the certificate for all of them
    mkdir -p /var/www/letsencrypt
    install -m 644 "$HIER/minipol-http.conf" /etc/apache2/sites-available/minipol-http.conf
    a2ensite -q minipol-http
    apachectl configtest
    systemctl reload apache2

    DOMEINEN="-d $DOMEIN"
    for host in $HOSTS; do
        DOMEINEN="$DOMEINEN -d $host"
    done
    # renewals go by themselves (certbot.timer); Apache reloads after each one
    # shellcheck disable=SC2086
    certbot certonly --webroot -w /var/www/letsencrypt --cert-name "$DOMEIN" $DOMEINEN \
        -m "$EMAIL" --agree-tos --non-interactive --deploy-hook "systemctl reload apache2"
fi

case $ACTIE in
    installeer | activeer) activeer ;;
    *) echo "Onbekende actie $ACTIE" >&2; exit 1 ;;
esac
