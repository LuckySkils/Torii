# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Base: FrankenPHP (PHP 8.4 + web server in one binary) + extensions + supervisor
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4 AS base

RUN install-php-extensions pdo_pgsql intl zip opcache pcntl bcmath \
 && apt-get update \
 && apt-get install -y --no-install-recommends curl supervisor \
 && rm -rf /var/lib/apt/lists/* \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /app

# ---------------------------------------------------------------------------
# Build: full composer install (incl. dev) + Node, to build the Vite assets.
# PHP is present on purpose: the Vite build may call `php artisan`.
# ---------------------------------------------------------------------------
FROM base AS build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

COPY composer.json composer.lock ./
RUN composer install --prefer-dist --no-interaction --no-scripts --no-autoloader

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
RUN composer dump-autoload --optimize \
 && php artisan package:discover --ansi \
 && npm run build

# ---------------------------------------------------------------------------
# Runtime
# ---------------------------------------------------------------------------
FROM base AS runtime

# Sensible defaults: the user only has to set qBittorrent settings.
ENV APP_NAME=Torii \
    APP_ENV=production \
    APP_DEBUG=false \
    APP_URL=http://localhost:8080 \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=info \
    DB_CONNECTION=pgsql \
    DB_HOST=db \
    DB_PORT=5432 \
    DB_DATABASE=torii \
    DB_USERNAME=torii \
    DB_PASSWORD=torii \
    QUEUE_CONNECTION=database \
    CACHE_STORE=database \
    SESSION_DRIVER=database \
    FEED_URL=https://subsplease.org/rss/?r=1080 \
    FEED_POLL_BASE_MINUTES=15 \
    QBIT_FEED_PATH="SubsPlease 1080p" \
    QBIT_CATEGORY=anime \
    QBIT_RULE_PREFIX="[ST] " \
    QBIT_TAG=subtracker

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader

COPY . .
COPY --from=build /app/public/build ./public/build

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
 && php artisan package:discover --ansi \
 && rm /usr/bin/composer \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache /data \
 && chown -R www-data:www-data storage bootstrap/cache

COPY docker/supervisord.conf /etc/supervisor/torii.conf
COPY docker/entrypoint.sh /usr/local/bin/torii-entrypoint
RUN chmod +x /usr/local/bin/torii-entrypoint

VOLUME /data
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
  CMD curl -fsS http://localhost/up > /dev/null || exit 1

ENTRYPOINT ["torii-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/torii.conf"]
