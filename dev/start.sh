#!/bin/sh
# Starts the five parts on their own server, like in production; Ctrl-C stops them all.
#   app    http://localhost:8000
#   api    http://localhost:8001
#   admin  http://localhost:8002
#   cdn    http://localhost:8003
#   math   http://localhost:8004
cd "$(dirname "$0")/.." || exit 1

trap 'kill 0' INT TERM EXIT

php -S localhost:8000 -t app &
php -S localhost:8001 api/index.php &
php -S localhost:8002 -t admin &
php -S localhost:8003 dev/cdn.php &
php -S localhost:8004 math/index.php &

echo "app http://localhost:8000 · admin http://localhost:8002 · api http://localhost:8001 · cdn http://localhost:8003 · math http://localhost:8004"
wait
