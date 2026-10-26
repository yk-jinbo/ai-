#!/bin/sh
set -eu

php artisan config:clear --no-ansi
php artisan route:clear --no-ansi

exec "$@"
