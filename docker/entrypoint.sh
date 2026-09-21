#!/bin/sh
set -e
cd /app

if [ -z "$APP_KEY" ]; then
  echo "APP_KEY is not set. Generate one with:  echo \"base64:\$(openssl rand -base64 32)\"" >&2
  echo "and put it into .env as APP_KEY=base64:..." >&2
  exit 1
fi

# Cache config/routes/views at container start, so runtime env vars are used.
php artisan config:cache  > /dev/null
php artisan route:cache   > /dev/null
php artisan view:cache    > /dev/null
php artisan event:cache   > /dev/null

exec "$@"
