#!/bin/sh
set -e
cd /app

# 1. App key: use APP_KEY if given, otherwise generate once and keep it in /data.
if [ -z "$APP_KEY" ]; then
  if [ ! -s /data/app.key ]; then
    echo "[torii] Generating app key in /data/app.key"
    php -r 'echo "base64:".base64_encode(random_bytes(32));' > /data/app.key
    chmod 600 /data/app.key
  fi
  APP_KEY="$(cat /data/app.key)"
  export APP_KEY
fi

# 2. Cache config with the final environment (incl. APP_KEY), plus routes/views/events.
php artisan config:cache > /dev/null
php artisan route:cache  > /dev/null
php artisan view:cache   > /dev/null
php artisan event:cache  > /dev/null

# 3. Only for the normal start (not `docker compose run ... php artisan xyz`):
#    wait for the database and run migrations.
if [ "$1" = "supervisord" ]; then
  tries=0
  until php artisan migrate --force; do
    tries=$((tries + 1))
    if [ "$tries" -ge 30 ]; then
      echo "[torii] Database not reachable after 30 attempts, giving up." >&2
      exit 1
    fi
    echo "[torii] Waiting for database... ($tries/30)"
    sleep 2
  done
fi

exec "$@"
