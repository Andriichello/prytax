# syntax=docker/dockerfile:1

# PryTax's web image (APP-BUILDS-PLAN.md, phase 1): one image for the app, the queue worker and the scheduler.
#
# api-core comes from a second build context, as PryTax's composer.json takes it from the path repository
# `../api-core`. composer.lock pins that repository's symlink (which COMPOSER_MIRROR_PATH_REPOS can't override), so
# the link Composer makes in vendor/ is replaced with a copy:
#
#     docker build --build-context api-core=../api-core -t prytax .
#
# compose.yaml passes the context itself.

ARG PHP_IMAGE=dunglas/frankenphp:1-php8.5

# The SPA's assets (Vite)
FROM node:24-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY vite.config.js tsconfig.json ./
COPY resources ./resources
RUN npm run build

# PHP with the extensions PryTax needs: zip for backups and exports, gd for dompdf's images, pcntl for the queue
# worker's job timeouts, pdo_mysql and pdo_sqlite for the databases, bcmath for brick/math's speed (and DemoSeeder)
FROM ${PHP_IMAGE} AS base
RUN install-php-extensions pdo_mysql pdo_sqlite zip gd pcntl bcmath opcache \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-prytax.ini"

# The PHP dependencies, without the development ones
FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=api-core --exclude=vendor --exclude=.git --exclude=node_modules --exclude=tests --exclude=storage . /build/api-core
WORKDIR /build/prytax
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist \
    && rm vendor/andriichello/api-core \
    && cp -R /build/api-core vendor/andriichello/api-core
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction

# The app: FrankenPHP in classic mode, which boots Laravel on every request, as PHP-FPM does. Worker mode (and Octane)
# is left out on purpose: the code was never checked for state that leaks between requests.
FROM base AS app
WORKDIR /app
COPY --from=vendor /build/prytax /app
COPY --from=assets /app/public/build /app/public/build
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/prytax-entrypoint
RUN mkdir -p storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache /config/caddy /data/caddy

USER www-data
ENV SERVER_NAME=:8080
EXPOSE 8080
HEALTHCHECK --interval=10s --timeout=5s --start-period=60s --retries=5 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/up") === false ? 1 : 0);'

ENTRYPOINT ["prytax-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
