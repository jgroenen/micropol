#!/bin/sh
# Starts the six parts on their own server, like in production; Ctrl-C stops them all.
#   www    http://localhost:8000   the product page, with links to all the others
#   api    http://localhost:8001
#   admin  http://localhost:8002
#   cdn    http://localhost:8003
#   math   http://localhost:8004
#   app    http://localhost:8005
cd "$(dirname "$0")/.." || exit 1

trap 'kill 0' INT TERM EXIT

# the data up to date with the code, like deploy/uppen.sh does on the server
php api/bin/migreer.php || exit 1

php -S localhost:8000 -t www &
# panel links counted per second instead of per day, to see it at once (see api/config.php)
MINIPOL_PANEL_TELVENSTER=1 php -S localhost:8001 api/index.php &
php -S localhost:8002 -t admin &
php -S localhost:8003 dev/cdn.php &
php -S localhost:8004 math/index.php &
php -S localhost:8005 -t app &

echo "www http://localhost:8000 · app http://localhost:8005 · admin http://localhost:8002 · api http://localhost:8001 · cdn http://localhost:8003 · math http://localhost:8004"
wait
