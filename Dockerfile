# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Base: FrankenPHP (PHP 8.4 + Caddy in one binary) with the extensions Torii needs
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4 AS base

RUN install-php-extensions pdo_pgsql intl zip opcache pcntl bcmath \
 && apt-get update \
 && apt-get install -y --no-install-recommends curl \
 && rm -rf /var/lib/apt/lists/* \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /app

# ---------------------------------------------------------------------------
# Build: full composer install (incl. dev) + Node, to build the Vite assets.
# PHP is present here on purpose: the Vite build may call `php artisan`
# (e.g. the Wayfinder plugin generating route helpers).
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
# Runtime: production dependencies only + built assets. No Node, no composer.
# ---------------------------------------------------------------------------
FROM base AS runtime

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader

COPY . .
COPY --from=build /app/public/build ./public/build

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
 && php artisan package:discover --ansi \
 && rm /usr/bin/composer \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/torii-entrypoint
RUN chmod +x /usr/local/bin/torii-entrypoint

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD test "$TORII_ROLE" != "web" || curl -fsS http://localhost/up > /dev/null || exit 1

ENV TORII_ROLE=web
ENTRYPOINT ["torii-entrypoint"]
CMD ["frankenphp", "php-server", "--listen", ":80", "--root", "/app/public"]
