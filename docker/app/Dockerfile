# Il frontend compilato è JavaScript statico, uguale per ogni architettura: si costruisce sempre sulla piattaforma
# del builder (nativa). Sotto QEMU arm64 node va in "Illegal instruction" e il build sarebbe lentissimo.
FROM --platform=$BUILDPLATFORM node:22-alpine AS frontend-builder

WORKDIR /app
COPY frontend/package.json frontend/package-lock.json* ./
RUN npm ci --ignore-scripts
COPY frontend/ .
RUN npm run build

# ---- base: solo ciò che serve a far GIRARE l'app (immagine di produzione) ----
# PHP fissato alla patch: 8.5.12 corregge CVE-2026-109618 e CVE-2026-109619 (RCE via bailout; la seconda colpisce i
# worker, che riusano il processo tra le richieste). Le immagini 1.12 non hanno ricevuto la 8.5.12.
FROM dunglas/frankenphp:1.13-php8.5.12-alpine AS base

RUN apk add --no-cache \
    icu-dev \
    libzip-dev \
    su-exec

RUN docker-php-ext-install \
    pdo_mysql \
    intl \
    zip \
    bcmath

# mariadb-dump per app:backup:create
RUN apk add --no-cache mariadb-client

RUN mkdir -p /var/storage/uploads /var/backups \
    && chown -R www-data:www-data /var/storage /var/backups \
    && chmod 750 /var/backups

WORKDIR /app

# ---- tools: strumenti di sviluppo/build (non finiscono nell'immagine di produzione) ----
FROM base AS tools

RUN apk add --no-cache \
    zip \
    unzip \
    git \
    bash

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

FROM tools AS dev

RUN apk add --no-cache $PHPIZE_DEPS linux-headers \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del $PHPIZE_DEPS

COPY docker/app/php.ini       /usr/local/etc/php/conf.d/brumy.ini
COPY docker/app/xdebug.ini    /usr/local/etc/php/conf.d/xdebug.ini
COPY docker/app/Caddyfile.dev /etc/frankenphp/Caddyfile

# ---- vendor: dipendenze di produzione con autoloader a classmap autoritativa ----
FROM tools AS vendor

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --no-interaction

# La classmap deve conoscere anche le classi dell'app (src/): per questo si genera dopo averle copiate.
COPY src/ src/
RUN composer dump-autoload \
    --no-dev \
    --classmap-authoritative \
    --no-interaction

# ---- prod ----
FROM base AS prod

# Senza compose (docker run) l'immagine non deve partire in modalità dev
ENV APP_ENV=prod

COPY docker/app/php.ini      /usr/local/etc/php/conf.d/brumy.ini
COPY docker/app/opcache.ini  /usr/local/etc/php/conf.d/opcache.ini
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# composer.json serve a runtime (Symfony ricava da lì la root del progetto), composer no.
COPY composer.json composer.lock ./
COPY --from=vendor /app/vendor/ vendor/
# Script di Scalar (/api/doc) serviti dalla stessa origine (script-src 'self'). Il build di composer gira con
# --no-scripts, quindi assets:install non c'è: si copia direttamente il file che serve alla UI, dove
# nelmio_api_doc (assets_mode: bundle) lo cerca. Il Caddyfile lo espone con `handle /bundles/*`.
COPY --from=vendor /app/vendor/nelmio/api-doc-bundle/public/scalar/ public/bundles/nelmioapidoc/scalar/

COPY bin/ bin/
COPY config/ config/
COPY migrations/ migrations/
COPY public/ public/
COPY src/ src/
COPY templates/ templates/
COPY symfony.lock .env ./
COPY --from=frontend-builder /app/dist /app/public/frontend
# La versione dell'app (AppUpdates) è quella del frontend, aggiornata a ogni rilascio.
COPY frontend/package.json frontend/package.json
COPY docker/app/Caddyfile /etc/frankenphp/Caddyfile

RUN mkdir -p var/cache var/log \
    && chown -R www-data:www-data var /data /config

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1/api/health || exit 1

ENTRYPOINT ["entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
