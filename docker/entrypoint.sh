#!/bin/sh
# The entrypoint of PryTax's image (builds.md §2), for the app, the worker and the scheduler alike.
set -e

cd /app

# The storage volume starts empty: create what Laravel, the files, the backups and dompdf's fonts write to
mkdir -p storage/app/private storage/app/backups storage/database storage/fonts storage/logs \
    storage/framework/cache/data storage/framework/sessions storage/framework/views

# Generating the key needs none of the rest (`docker compose run --rm --no-deps app php artisan key:generate --show`)
case "$*" in
    *key:generate*) exec "$@" ;;
esac

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is empty: generate one with 'docker compose run --rm app php artisan key:generate --show'" \
        "and put it in .env. Keep it safe: it encrypts tax IDs and IBANs." >&2
    exit 1
fi

# Only one container (the app's) migrates; the database seeder only syncs the roles, so it's safe to repeat
if [ "$RUN_MIGRATIONS" = "true" ]; then
    php artisan migrate --force --no-interaction
    php artisan db:seed --force --no-interaction
fi

php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction
php artisan event:cache --no-interaction

exec "$@"
