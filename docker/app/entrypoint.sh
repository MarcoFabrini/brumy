#!/bin/sh
set -e

chown -R www-data:www-data /var/storage/uploads /var/backups /app/config/jwt

# Dietro un reverse proxy locale (nginx/Caddy/Traefik sullo stesso network Docker,
# il caso comune self-host) l'IP del peer TCP è il proxy stesso: fidarsene per
# leggere X-Forwarded-For è sicuro. Se il deploy è dietro un proxy esterno con IP
# noti (es. Cloudflare), override esplicito in .env — vedi SECURITY.md.
if [ -z "${TRUSTED_PROXIES:-}" ]; then
    TRUSTED_PROXIES="REMOTE_ADDR"; export TRUSTED_PROXIES
fi

# Derivazione di fallback dei valori che dipendono da DOMAIN. Con compose FRONTEND_URL e
# DEFAULT_URI arrivano già nell'environment del container (visibili anche a `docker exec`,
# che qui non vedrebbe gli export del PID 1): questi `export` servono per `docker run`
# senza compose, e per CORS_ALLOW_ORIGIN, che compose non sa calcolare (punti da escapare).
if [ -n "${DOMAIN:-}" ] && [ -z "${CORS_ALLOW_ORIGIN:-}" ]; then
    CORS_ALLOW_ORIGIN="^https://$(printf '%s' "$DOMAIN" | sed 's/\./\\./g')\$"; export CORS_ALLOW_ORIGIN
fi
if [ -n "${DOMAIN:-}" ] && [ -z "${FRONTEND_URL:-}" ]; then
    FRONTEND_URL="https://${DOMAIN}"; export FRONTEND_URL
fi
if [ -n "${DOMAIN:-}" ] && [ -z "${DEFAULT_URI:-}" ]; then
    DEFAULT_URI="https://${DOMAIN}"; export DEFAULT_URI
fi

# Le chiavi JWT servono a ogni login: se non ci sono (cartella ./brumy/jwt non scrivibile)
# o non si aprono con JWT_PASSPHRASE (passphrase cambiata dopo la prima generazione) il
# container sarebbe "healthy" ma ogni accesso fallirebbe. Meglio non partire, con un
# messaggio chiaro; l'errore del comando resta visibile su stderr.
JWT_DIR=/app/config/jwt
if ! su-exec www-data php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction; then
    echo "entrypoint: lexik:jwt:generate-keypair failed (see the error above)." >&2
fi
if [ ! -s "$JWT_DIR/private.pem" ] || [ ! -s "$JWT_DIR/public.pem" ]; then
    echo "entrypoint: JWT keys are missing in $JWT_DIR after the generation attempt: check that ./brumy/jwt is writable by the container. Refusing to start." >&2
    exit 1
fi
if ! su-exec www-data php -r 'exit(openssl_pkey_get_private((string) file_get_contents($argv[1]), (string) getenv("JWT_PASSPHRASE")) !== false ? 0 : 1);' -- "$JWT_DIR/private.pem"; then
    echo "entrypoint: $JWT_DIR/private.pem cannot be opened with JWT_PASSPHRASE: the passphrase changed since the keys were generated. Restore the original passphrase, or delete ./brumy/jwt to generate new keys (this signs everyone out). Refusing to start." >&2
    exit 1
fi

if [ "${AUTO_MIGRATE:-false}" = "true" ]; then
    su-exec www-data php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
fi

# Best-effort: se la cache non si scalda si costruisce alla prima richiesta, ma l'errore va visto.
su-exec www-data php bin/console cache:warmup --no-debug || echo "entrypoint: cache:warmup failed (non-fatal, the cache will be built on the first request)." >&2

exec su-exec www-data "$@"
