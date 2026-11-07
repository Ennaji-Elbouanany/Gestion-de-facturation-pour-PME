#!/bin/sh
set -eu
case "${PORT:-10000}" in ''|*[!0-9]*) echo 'Invalid PORT' >&2; exit 1;; esac
[ "$PORT" -ge 1024 ] && [ "$PORT" -le 65535 ] || { echo 'PORT outside allowed range' >&2; exit 1; }
if [ "${APP_ENV:-production}" = production ] && [ -z "${APP_KEY:-}" ]; then
    echo 'APP_KEY must be configured; no key is generated at startup.' >&2
    exit 1
fi
# No migrations, cache flush, seeding, or automatic role changes at startup.
printf 'Listen %s\n' "$PORT" > /etc/apache2/ports.conf
php artisan config:cache --quiet
chown -R www-data:www-data storage bootstrap/cache
apache2ctl configtest
exec apache2-foreground
