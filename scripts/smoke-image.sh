#!/usr/bin/env bash
# Smoke test dell'immagine Docker di produzione (target `prod` di docker/app/Dockerfile).
#
#   scripts/smoke-image.sh <immagine>        es. scripts/smoke-image.sh brumy-smoke:local
#
# Perché esiste: la CI costruiva e pubblicava l'immagine senza mai avviarla, quindi un'estensione PHP
# mancante, un entrypoint rotto, un Caddyfile sbagliato, le sessioni che non si salvano, le migrazioni
# che falliscono o la SPA assente arrivavano fino a chi fa `docker compose pull`. Qui l'immagine parte
# davvero, con un MariaDB usa-e-getta, e si guarda cosa serve a un deploy reale.
#
# Come funziona:
#   - rete Docker privata e container con nome univoco (PID + casuale): nessuna porta pubblicata sull'host,
#     quindi non può collidere con Lando né con altri container; le richieste HTTP partono con `curl`
#     DENTRO il container dell'app (il Caddyfile di produzione ascolta già in HTTP semplice su :80,
#     il TLS lo fa il reverse proxy davanti: nessuna modifica alla config di produzione per testarla);
#   - tutto ciò che crea (container, rete) viene rimosso dal trap in uscita, anche in caso di errore o Ctrl-C;
#   - le verifiche non si fermano alla prima che fallisce: si vedono tutte, poi l'exit code è 1 se ne è
#     fallita almeno una; se l'app non parte, o a fine run se qualcosa è fallito, si stampano i log dei container.
#
# La documentazione API (/api/doc, /api/doc.json) è spenta di default: il test controlla che dia 404 e poi
# avvia una seconda istanza con API_DOC_ENABLED=true (stessa immagine, stesso DB) per controllare che la UI sia
# servibile con la CSP di produzione (script della stessa origine, nessun inline).
#
# Requisiti sull'host: docker (CLI + daemon) e bash. Nessun segreto: password e chiavi sono casuali e
# vivono solo per la durata del test. Variabili opzionali: SMOKE_TIMEOUT (secondi di attesa per
# l'avvio, default 180).

set -euo pipefail

IMAGE="${1:-}"
if [ -z "$IMAGE" ]; then
    echo "Uso: $0 <immagine>" >&2
    exit 2
fi
command -v docker >/dev/null 2>&1 || { echo "docker non trovato nel PATH" >&2; exit 2; }
docker image inspect "$IMAGE" >/dev/null 2>&1 || { echo "Immagine '$IMAGE' non trovata in locale (docker build, oppure docker pull)." >&2; exit 2; }

MARIADB_IMAGE="mariadb:11.4"   # stessa versione di compose.yml
READY_TIMEOUT="${SMOKE_TIMEOUT:-180}"

# Valore casuale esadecimale (niente pipe verso `head`: con pipefail SIGPIPE farebbe fallire lo script).
random_hex() { od -An -N"${1:-16}" -tx1 /dev/urandom | tr -d ' \n'; }

SUFFIX="$$-$(random_hex 3)"
NET="brumy-smoke-net-$SUFFIX"
DB="brumy-smoke-db-$SUFFIX"
APP="brumy-smoke-app-$SUFFIX"
APP_DOC="brumy-smoke-doc-$SUFFIX"   # seconda istanza, con la documentazione API accesa (fase 7)
DB_PASSWORD="$(random_hex 16)"
APP_SECRET_VALUE="$(random_hex 32)"
USER_EMAIL="smoke-$SUFFIX@example.test"
USER_PASSWORD="$(random_hex 12)"

FAILED=0

# ---------------------------------------------------------------------------------------------
# Pulizia e log
# ---------------------------------------------------------------------------------------------

dump_logs() {
    local name
    for name in "$APP" "$APP_DOC" "$DB"; do
        # Se lo script si è fermato prima di creare il container non c'è nulla da mostrare
        docker inspect "$name" >/dev/null 2>&1 || continue
        echo >&2
        echo "---- log del container $name (ultime 150 righe) ----" >&2
        docker logs --tail 150 "$name" >&2 2>&1 || true
    done
}

cleanup() {
    local status=$?
    # Anche con tutti i check passati, un exit != 0 vuol dire che lo script stesso si è interrotto
    if [ "$status" -ne 0 ] || [ "$FAILED" -ne 0 ]; then
        dump_logs
    fi
    docker rm -f -v "$APP" "$APP_DOC" "$DB" >/dev/null 2>&1 || true
    docker network rm "$NET" >/dev/null 2>&1 || true
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# ---------------------------------------------------------------------------------------------
# Helper di output e di asserzione
# ---------------------------------------------------------------------------------------------

ok() { printf '  [ OK ] %s\n' "$*"; }
ko() { printf '  [FAIL] %s\n' "$*" >&2; FAILED=$((FAILED + 1)); }
info() { printf '  [INFO] %s\n' "$*"; }
section() { printf '\n== %s\n' "$*"; }

# assert_eq <descrizione> <atteso> <ottenuto>
assert_eq() {
    if [ "$2" = "$3" ]; then ok "$1"; else ko "$1 (atteso '$2', ottenuto '$3')"; fi
}

# assert_contains <descrizione> <testo> <ago>   (confronto senza distinguere maiuscole)
assert_contains() {
    if grep -qiF -- "$3" <<<"$2"; then ok "$1"; else ko "$1 (manca '$3')"; fi
}

# assert_not_contains <descrizione> <testo> <ago>
assert_not_contains() {
    if grep -qiF -- "$3" <<<"$2"; then ko "$1 (contiene '$3')"; else ok "$1"; fi
}

# ---------------------------------------------------------------------------------------------
# HTTP: curl dentro il container dell'app (nessuna porta sull'host)
# ---------------------------------------------------------------------------------------------

# HTTP_APP seleziona il container su cui girano le richieste (default: l'app principale)
HTTP_APP="$APP"
dexec() { docker exec "$HTTP_APP" "$@"; }

RESP_STATUS=""
RESP_HEADERS=""
RESP_BODY=""

# fetch <argomenti curl...> <url>: imposta RESP_STATUS, RESP_HEADERS e RESP_BODY.
# READ_BODY=0 salta la lettura del corpo (asset grandi).
fetch() {
    RESP_STATUS="$(dexec curl -sS --max-time 20 -o /tmp/smoke.body -D /tmp/smoke.hdr -w '%{http_code}' "$@" 2>/dev/null)" || RESP_STATUS="000"
    RESP_HEADERS="$(dexec cat /tmp/smoke.hdr 2>/dev/null | tr -d '\r' || true)"
    if [ "${READ_BODY:-1}" = "1" ]; then
        RESP_BODY="$(dexec cat /tmp/smoke.body 2>/dev/null || true)"
    else
        RESP_BODY=""
    fi
}

# header <nome>: valore dell'header di risposta (vuoto se assente)
header() {
    awk -v n="$1" 'BEGIN { n = tolower(n) } { k = tolower(substr($0, 1, index($0, ":") - 1)) } k == n { sub(/^[^:]*: */, ""); print; exit }' <<<"$RESP_HEADERS"
}

# json_field <chiave> <corpo>: valore (stringa) di una chiave di primo livello in un JSON compatto.
json_field() {
    sed -n "s/.*\"$1\":\"\\([^\"]*\\)\".*/\\1/p" <<<"$2"
}

# wait_ready [container]: attende /api/health del container (default l'app principale)
wait_ready() {
    local target="${1:-$APP}" waited=0
    while [ "$waited" -lt "$READY_TIMEOUT" ]; do
        if [ "$(docker inspect -f '{{.State.Running}}' "$target" 2>/dev/null || echo false)" != "true" ]; then
            echo "Il container $target si è fermato durante l'avvio (exit code $(docker inspect -f '{{.State.ExitCode}}' "$target" 2>/dev/null || echo '?')): entrypoint rotto o configurazione mancante." >&2
            return 1
        fi
        if docker exec "$target" curl -fsS --max-time 5 -o /dev/null http://127.0.0.1/api/health >/dev/null 2>&1; then
            return 0
        fi
        sleep 2
        waited=$((waited + 2))
    done
    echo "L'app non risponde su /api/health entro ${READY_TIMEOUT}s." >&2
    return 1
}

# ---------------------------------------------------------------------------------------------
# 1. Contenuto dell'immagine (senza avviare l'app: --entrypoint bypassa entrypoint.sh)
# ---------------------------------------------------------------------------------------------

in_image() { docker run --rm --entrypoint sh "$IMAGE" -c "$1" 2>&1; }

section "Contenuto dell'immagine ($IMAGE)"

php_version="$(in_image 'php -v | head -n 1')"
assert_contains "PHP 8.5" "$php_version" "PHP 8.5."
# 8.5.12 corregge CVE-2026-109618 e CVE-2026-109619: un'immagine con un PHP precedente non va pubblicata.
php_patched="$(in_image 'php -r "echo version_compare(PHP_VERSION, \"8.5.12\", \">=\") ? \"si\" : PHP_VERSION;"')"
assert_eq "PHP >= 8.5.12 (CVE-2026-109618, CVE-2026-109619)" "si" "$php_patched"
upload_progress="$(in_image 'php -r "echo var_export((bool) ini_get(\"session.upload_progress.enabled\"), true);"')"
assert_eq "session.upload_progress disattivato" "false" "$upload_progress"

modules="$(in_image 'php -m')"
for ext in pdo_mysql intl zip bcmath sodium mbstring opcache; do
    assert_contains "estensione PHP $ext" "$modules" "$ext"
done
assert_not_contains "xdebug assente" "$modules" "xdebug"

assert_eq "APP_ENV=prod di default" "prod" "$(in_image 'echo "$APP_ENV"')"
assert_eq "opcache senza validate_timestamps" "0" "$(in_image 'php -r "echo ini_get(\"opcache.validate_timestamps\");"')"
assert_eq "expose_php spento" "" "$(in_image 'php -r "echo ini_get(\"expose_php\");"')"
assert_eq "upload_max_filesize 11M" "11M" "$(in_image 'php -r "echo ini_get(\"upload_max_filesize\");"')"

# /app deve contenere solo ciò che serve a far girare l'app: qualunque altra voce (tests, .git, sorgenti del frontend,
# node_modules, docs, file di configurazione locali, cartelle di lavoro...) vuol dire che il Dockerfile o .dockerignore
# sono cambiati. Del frontend c'è solo package.json, da cui l'app legge la propria versione (AppUpdates).
unexpected="$(in_image 'cd /app && for e in $(ls -A); do case "$e" in .env|bin|composer.json|composer.lock|config|migrations|public|src|symfony.lock|templates|var|vendor) ;; frontend) [ "$(ls -A frontend)" = "package.json" ] || echo "frontend/: $(ls -A frontend | tr "\n" " ")" ;; *) echo "$e" ;; esac; done')"
assert_eq "/app contiene solo l'applicazione (nessun tests, .git, sorgenti del frontend, node_modules, docs...)" "" "$unexpected"

dev_tools="$(in_image 'for p in vendor/phpunit vendor/phpstan/phpstan vendor/bin/phpunit vendor/bin/phpstan public/frontend/node_modules; do [ -e "/app/$p" ] && echo "$p"; done; for b in composer git xdebug; do command -v "$b"; done; true')"
assert_eq "nessuna dipendenza né strumento di sviluppo (phpunit, phpstan, composer, git, xdebug)" "" "$dev_tools"

assert_eq ".env senza segreti valorizzati" "" "$(in_image 'grep -E "^(APP_SECRET|MARIADB_PASSWORD)=." /app/.env || true')"
assert_eq "SPA presente (public/frontend/index.html)" "present" "$(in_image '[ -s /app/public/frontend/index.html ] && echo present || echo missing')"
assert_eq "script di Scalar presenti (public/bundles/nelmioapidoc, per /api/doc)" "present" "$(in_image '[ -s /app/public/bundles/nelmioapidoc/scalar/scalar.standalone.js ] && echo present || echo missing')"
assert_eq "mariadb-dump disponibile (backup)" "present" "$(in_image 'command -v mariadb-dump >/dev/null && echo present || echo missing')"

healthcheck="$(docker image inspect -f '{{json .Config.Healthcheck}}' "$IMAGE")"
if [ "$healthcheck" = "null" ]; then ko "l'immagine dichiara un HEALTHCHECK"; else ok "l'immagine dichiara un HEALTHCHECK"; fi

# ---------------------------------------------------------------------------------------------
# 2. Avvio: rete privata + MariaDB + app con le variabili che richiede un deploy reale (compose.yml)
# ---------------------------------------------------------------------------------------------

section "Avvio (rete $NET)"

docker network create --label brumy-smoke=1 "$NET" >/dev/null
docker run -d --name "$DB" --network "$NET" --label brumy-smoke=1 \
    --tmpfs /var/lib/mysql \
    -e MARIADB_ROOT_PASSWORD="$DB_PASSWORD" -e MARIADB_DATABASE=brumy \
    "$MARIADB_IMAGE" --innodb-flush-log-at-trx-commit=0 --skip-innodb-doublewrite >/dev/null

waited=0
until docker exec "$DB" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; do
    if [ "$waited" -ge 120 ]; then
        echo "MariaDB non è pronto entro 120s." >&2
        exit 1
    fi
    sleep 2
    waited=$((waited + 2))
done
ok "MariaDB pronto"

# Stesse variabili obbligatorie di compose.yml (APP_ENV, AUTO_MIGRATE, DOMAIN, APP_SECRET, DATABASE_URL). FRONTEND_URL/DEFAULT_URI/CORS_ALLOW_ORIGIN li ricava l'entrypoint da DOMAIN.
docker run -d --name "$APP" --network "$NET" --label brumy-smoke=1 \
    -e APP_ENV=prod \
    -e AUTO_MIGRATE=true \
    -e DOMAIN=brumy.smoke.test \
    -e APP_SECRET="$APP_SECRET_VALUE" \
    -e "DATABASE_URL=mysql://root:${DB_PASSWORD}@${DB}:3306/brumy?serverVersion=mariadb-11.4.0&charset=utf8mb4" \
    "$IMAGE" >/dev/null

if wait_ready; then
    ok "l'app risponde su /api/health"
else
    ko "l'app non è partita"
    exit 1
fi

# ---------------------------------------------------------------------------------------------
# 3. HTTP: health, SPA, asset, fallback, header di sicurezza, documentazione
# ---------------------------------------------------------------------------------------------

section "API e SPA"

fetch http://127.0.0.1/api/health
assert_eq "GET /api/health -> 200" "200" "$RESP_STATUS"
assert_contains "/api/health: status ok" "$RESP_BODY" '"status":"ok"'
assert_contains "/api/health: database raggiungibile" "$RESP_BODY" '"database":{"ok":true}'
assert_contains "/api/health: Content-Type JSON" "$(header content-type)" "application/json"
assert_eq "/api/health: nessun X-Powered-By" "" "$(header x-powered-by)"

fetch http://127.0.0.1/
index_html="$RESP_BODY"
index_headers="$RESP_HEADERS"
assert_eq "GET / -> 200" "200" "$RESP_STATUS"
assert_contains "GET /: Content-Type HTML" "$(header content-type)" "text/html"
assert_contains "GET /: contiene <div id=\"root\"" "$index_html" '<div id="root"'
assert_contains "GET /: index.html mai in cache" "$(header cache-control)" "no-store"

js_path="$(grep -o 'src="/assets/[^"]*\.js"' <<<"$index_html" | head -n 1 | sed 's/^src="//; s/"$//' || true)"
css_path="$(grep -o 'href="/assets/[^"]*\.css"' <<<"$index_html" | head -n 1 | sed 's/^href="//; s/"$//' || true)"
if [ -z "$js_path" ]; then
    ko "index.html referenzia un bundle JS in /assets/"
else
    READ_BODY=0 fetch "http://127.0.0.1$js_path"
    assert_eq "GET $js_path -> 200" "200" "$RESP_STATUS"
    assert_contains "$js_path: Content-Type JavaScript" "$(header content-type)" "javascript"
    assert_contains "$js_path: cache immutabile" "$(header cache-control)" "immutable"
fi
if [ -z "$css_path" ]; then
    ko "index.html referenzia un foglio di stile in /assets/"
else
    READ_BODY=0 fetch "http://127.0.0.1$css_path"
    assert_eq "GET $css_path -> 200" "200" "$RESP_STATUS"
    assert_contains "$css_path: Content-Type CSS" "$(header content-type)" "text/css"
fi

READ_BODY=0 fetch http://127.0.0.1/assets/does-not-exist-0000.js
assert_eq "asset inesistente -> 404 (non l'index.html della SPA)" "404" "$RESP_STATUS"

fetch http://127.0.0.1/vehicles/1
assert_eq "deep link /vehicles/1 -> 200" "200" "$RESP_STATUS"
assert_eq "deep link /vehicles/1 serve la stessa index.html" "$index_html" "$RESP_BODY"

for sw in /sw.js /manifest.webmanifest; do
    READ_BODY=0 fetch "http://127.0.0.1$sw"
    assert_eq "GET $sw -> 200" "200" "$RESP_STATUS"
done
READ_BODY=0 fetch http://127.0.0.1/sw.js
assert_contains "/sw.js: no-cache (il service worker si aggiorna)" "$(header cache-control)" "no-cache"

section "Header di sicurezza (Caddyfile)"

RESP_HEADERS="$index_headers"
csp="$(header content-security-policy)"
assert_contains "CSP: default-src 'self'" "$csp" "default-src 'self'"
assert_contains "CSP: script-src 'self'" "$csp" "script-src 'self'"
assert_contains "CSP: object-src 'none'" "$csp" "object-src 'none'"
assert_contains "CSP: frame-src 'self'" "$csp" "frame-src 'self'"
script_src="$(grep -o "script-src [^;]*" <<<"$csp" || true)"
assert_not_contains "CSP: script-src senza 'unsafe-inline'" "$script_src" "unsafe-inline"
assert_not_contains "CSP: script-src senza 'unsafe-eval'" "$script_src" "unsafe-eval"
assert_eq "X-Content-Type-Options: nosniff" "nosniff" "$(header x-content-type-options)"
assert_eq "X-Frame-Options: SAMEORIGIN" "SAMEORIGIN" "$(header x-frame-options)"
assert_contains "Referrer-Policy" "$(header referrer-policy)" "strict-origin-when-cross-origin"
assert_contains "Strict-Transport-Security" "$(header strict-transport-security)" "max-age="
assert_contains "Permissions-Policy" "$(header permissions-policy)" "camera="

# Gli stessi header valgono anche per le risposte dell'API (il blocco header è a livello di sito)
fetch http://127.0.0.1/api/health
assert_eq "API: X-Content-Type-Options: nosniff" "nosniff" "$(header x-content-type-options)"

section "Documentazione API (spenta di default)"

# API_DOC_ENABLED non è impostata in questa istanza: /api/doc e /api/doc.json devono rispondere come
# una rotta inesistente (404 Problem Details), senza rivelare di esistere. La fase 7 le riaccende.
for doc in /api/doc /api/doc.json; do
    fetch -H 'Accept: text/html,application/xhtml+xml' "http://127.0.0.1$doc"
    assert_eq "GET $doc -> 404 (documentazione spenta)" "404" "$RESP_STATUS"
    assert_contains "$doc: Content-Type JSON (Problem Details anche per un browser)" "$(header content-type)" "application/json"
    assert_contains "$doc: corpo Problem Details" "$RESP_BODY" '"status":404'
done

# ---------------------------------------------------------------------------------------------
# 4. Migrazioni, database, sessioni: registrazione del primo utente, login, /me, CSRF
# ---------------------------------------------------------------------------------------------

section "Primo avvio: migrazioni, registrazione, login a sessione e CSRF"

# Le scritture devono venire dalla stessa origine (come fa il browser con la SPA): Sec-Fetch-Site.
SAME_ORIGIN=(-H 'Sec-Fetch-Site: same-origin')

# Valore del cookie <nome> dagli header di risposta (vuoto se assente). Il cookie si rimanda a mano con
# -H "Cookie: ...": il cookie è Secure e qui si parla HTTP, curl non lo rimanderebbe da solo.
set_cookie() {
    awk -v n="$1" 'tolower($0) ~ /^set-cookie:/ { sub(/^[^:]*: */, ""); split($0, kv, ";"); split(kv[1], p, "="); if (p[1] == n) { print substr(kv[1], length(n) + 2); exit } }' <<<"$RESP_HEADERS"
}
set_cookie_line() {
    awk -v n="$1" 'tolower($0) ~ /^set-cookie:/ { sub(/^[^:]*: */, ""); if (index($0, n "=") == 1) { print; exit } }' <<<"$RESP_HEADERS"
}

fetch http://127.0.0.1/api/auth/registration
assert_contains "registrazione aperta su istanza vuota" "$RESP_BODY" '"open":true'

credentials="{\"email\":\"$USER_EMAIL\",\"password\":\"$USER_PASSWORD\",\"firstName\":\"Smoke\",\"lastName\":\"Test\"}"
fetch -X POST -H 'Content-Type: application/json' "${SAME_ORIGIN[@]}" -d "$credentials" http://127.0.0.1/api/auth/register
assert_eq "POST /api/auth/register -> 201" "201" "$RESP_STATUS"
assert_not_contains "register: nessun token nel corpo" "$RESP_BODY" 'token'
if [ -n "$(set_cookie brumy_session)" ]; then ok "register: apre la sessione (cookie brumy_session)"; else ko "register: cookie di sessione mancante"; fi

fetch -X POST -H 'Content-Type: application/json' -d "{\"email\":\"$USER_EMAIL\",\"password\":\"$USER_PASSWORD\"}" http://127.0.0.1/api/auth/login
assert_eq "POST /api/auth/login senza origine -> 403 (CSRF)" "403" "$RESP_STATUS"
assert_contains "403 CSRF: auth.csrf_invalid" "$RESP_BODY" '"title":"auth.csrf_invalid"'

fetch -X POST -H 'Content-Type: application/json' -H 'Origin: https://evil.example' -d "{\"email\":\"$USER_EMAIL\",\"password\":\"$USER_PASSWORD\"}" http://127.0.0.1/api/auth/login
assert_eq "POST /api/auth/login da un'altra origine -> 403" "403" "$RESP_STATUS"

fetch -X POST -H 'Content-Type: application/json' "${SAME_ORIGIN[@]}" -d "{\"email\":\"$USER_EMAIL\",\"password\":\"$USER_PASSWORD\"}" http://127.0.0.1/api/auth/login
assert_eq "POST /api/auth/login -> 200" "200" "$RESP_STATUS"
assert_contains "login: twoFactorRequired false" "$RESP_BODY" '"twoFactorRequired":false'
SESSION_ID="$(set_cookie brumy_session)"
session_cookie="$(set_cookie_line brumy_session)"
remember_cookie="$(set_cookie_line brumy_remember)"
if [ -n "$SESSION_ID" ]; then ok "login: cookie brumy_session"; else ko "login: cookie brumy_session mancante"; fi
assert_contains "brumy_session HttpOnly" "$session_cookie" "httponly"
assert_contains "brumy_session Secure" "$session_cookie" "secure"
assert_contains "brumy_session SameSite=Lax" "$session_cookie" "samesite=lax"
assert_contains "brumy_session path /api" "$session_cookie" "path=/api"
assert_contains "login: cookie brumy_remember (7 giorni)" "$remember_cookie" "brumy_remember="
assert_contains "brumy_remember HttpOnly" "$remember_cookie" "httponly"
assert_contains "brumy_remember Secure" "$remember_cookie" "secure"

fetch -H "Cookie: brumy_session=$SESSION_ID" http://127.0.0.1/api/auth/me
assert_eq "GET /api/auth/me con il cookie di sessione -> 200" "200" "$RESP_STATUS"
assert_contains "/me: utente registrato" "$RESP_BODY" "$USER_EMAIL"
assert_contains "/me: org attiva della sessione" "$RESP_BODY" '"activeOrganizationId":'

fetch http://127.0.0.1/api/auth/me
assert_eq "GET /api/auth/me senza sessione -> 401" "401" "$RESP_STATUS"
assert_contains "401 in formato Problem Details" "$RESP_BODY" '"title":"http.401"'

fetch -X POST -H 'Content-Type: application/json' "${SAME_ORIGIN[@]}" -H "Cookie: brumy_session=$SESSION_ID" -d '{}' http://127.0.0.1/api/auth/refresh
assert_eq "POST /api/auth/refresh non esiste più -> 404" "404" "$RESP_STATUS"

fetch http://127.0.0.1/api/auth/registration
assert_contains "dopo il primo utente la registrazione si chiude" "$RESP_BODY" '"open":false'

sql() { docker exec -e MYSQL_PWD="$DB_PASSWORD" "$DB" mariadb -uroot -N -B brumy -e "$1"; }
tables="$(sql 'SHOW TABLES' || true)"
for table in users organizations organization_members vehicles reminders messenger_messages doctrine_migration_versions sessions rememberme_token; do
    assert_contains "tabella $table creata dalle migrazioni" "$tables" "$table"
done
assert_not_contains "tabella refresh_tokens eliminata" "$tables" "refresh_tokens"
sessions_rows="$(sql 'SELECT COUNT(*) FROM sessions' || echo 0)"
if [ "${sessions_rows:-0}" -ge 1 ]; then ok "le sessioni sono salvate in MariaDB ($sessions_rows righe)"; else ko "nessuna riga in sessions dopo il login"; fi
expected_migrations="$(in_image 'ls /app/migrations/Version*.php | wc -l' | tr -d ' ')"
applied_migrations="$(sql 'SELECT COUNT(*) FROM doctrine_migration_versions' || echo '?')"
assert_eq "tutte le migrazioni dell'immagine sono applicate" "$expected_migrations" "$applied_migrations"

# ---------------------------------------------------------------------------------------------
# 5. Come lo usa compose: healthcheck, processi, comandi di cron/worker
# ---------------------------------------------------------------------------------------------

section "Processi, healthcheck e comandi pianificati"

if dexec wget -q -O /dev/null http://127.0.0.1/api/health; then ok "wget (usato da HEALTHCHECK e compose) funziona"; else ko "wget -> /api/health fallisce"; fi

server_user="$(dexec ps 2>/dev/null | awk '/frankenphp run/ && !/awk/ { print $2; exit }' || true)"
assert_eq "il server gira come www-data (non root)" "www-data" "$server_user"

# Gli stessi comandi che compose.yml affida a Ofelia e al worker, eseguiti come www-data
commands="$(docker exec -u www-data "$APP" php bin/console list --raw 2>/dev/null || true)"
for cmd in app:reminders:dispatch app:backup:create app:tokens:cleanup app:user:2fa-reset messenger:consume messenger:failed:retry; do
    assert_contains "comando $cmd registrato" "$commands" "$cmd"
done
# Il vecchio nome resta come alias: un compose.yml non aggiornato lo lancia ancora.
if docker exec -u www-data "$APP" php bin/console app:refresh-tokens:cleanup --no-interaction >/dev/null 2>&1; then ok "alias app:refresh-tokens:cleanup eseguito"; else ko "alias app:refresh-tokens:cleanup fallito"; fi
if docker exec -u www-data "$APP" php bin/console app:reminders:dispatch --no-interaction >/dev/null 2>&1; then ok "app:reminders:dispatch eseguito"; else ko "app:reminders:dispatch fallito"; fi
# Le righe esatte dei job Ofelia, lette da compose.yml: un'opzione inesistente o un comando che rifiuta
# --no-interaction farebbero fallire il job a ogni esecuzione senza che nessuno se ne accorga.
# Per messenger-retry, --time-limit=2 in coda sostituisce quello del job (vince l'ultimo): a coda vuota consume aspetta.
for job in messenger-retry cache-prune tokens-cleanup; do
    job_cmd="$(sed -n "s/^ *ofelia\\.job-exec\\.$job\\.command: \"\\(.*\\)\"\$/\\1/p" "$(dirname "$0")/../compose.yml")"
    extra=""
    [ "$job" = "messenger-retry" ] && extra=" --time-limit=2"
    if [ -z "$job_cmd" ]; then
        ko "job Ofelia $job non trovato in compose.yml"
    elif docker exec -u www-data "$APP" sh -c "$job_cmd$extra" >/dev/null 2>&1; then
        ok "job Ofelia $job eseguito ($job_cmd)"
    else
        ko "job Ofelia $job fallito ($job_cmd)"
    fi
done
if docker exec -u www-data "$APP" php bin/console app:backup:create --skip-uploads --no-interaction >/dev/null 2>&1; then
    backups="$(dexec sh -c 'ls /var/backups/db-*.sql.gz 2>/dev/null' || true)"
    assert_contains "app:backup:create scrive il dump (mariadb-dump raggiunge il DB)" "$backups" "db-"
else
    ko "app:backup:create fallito"
fi

# ---------------------------------------------------------------------------------------------
# 6. Riavvio: entrypoint idempotente (migrazioni già presenti), sessioni conservate in MariaDB
# ---------------------------------------------------------------------------------------------

section "Riavvio"

docker restart "$APP" >/dev/null
if wait_ready; then
    ok "l'app riparte dopo un restart (migrazioni già applicate)"
    fetch -H "Cookie: brumy_session=$SESSION_ID" http://127.0.0.1/api/auth/me
    assert_eq "la sessione aperta prima del restart è ancora valida (sessioni in MariaDB)" "200" "$RESP_STATUS"
else
    ko "l'app non riparte dopo il restart"
fi

# ---------------------------------------------------------------------------------------------
# 7. Documentazione API accesa: seconda istanza della stessa immagine con API_DOC_ENABLED=true
# ---------------------------------------------------------------------------------------------

section "Documentazione API accesa (API_DOC_ENABLED=true)"

# Stesso DB e stessa rete: le migrazioni sono già applicate. Con un JWT_PASSPHRASE rimasto nell'ambiente (il .env di
# un'installazione precedente alle sessioni) l'avvio non deve rompersi: la variabile non è più letta.
docker run -d --name "$APP_DOC" --network "$NET" --label brumy-smoke=1 \
    -e APP_ENV=prod \
    -e AUTO_MIGRATE=true \
    -e API_DOC_ENABLED=true \
    -e DOMAIN=brumy.smoke.test \
    -e APP_SECRET="$APP_SECRET_VALUE" \
    -e JWT_PASSPHRASE=residuo-di-una-versione-precedente \
    -e "DATABASE_URL=mysql://root:${DB_PASSWORD}@${DB}:3306/brumy?serverVersion=mariadb-11.4.0&charset=utf8mb4" \
    "$IMAGE" >/dev/null

if wait_ready "$APP_DOC"; then
    ok "la seconda istanza risponde su /api/health"
    HTTP_APP="$APP_DOC"

    fetch -H 'Accept: text/html' http://127.0.0.1/api/doc
    doc_html="$RESP_BODY"
    assert_eq "GET /api/doc -> 200 (senza login)" "200" "$RESP_STATUS"
    assert_contains "/api/doc: Content-Type HTML" "$(header content-type)" "text/html"
    # La CSP di produzione vale anche qui e non viene allentata: per questo gli script devono stare sulla stessa origine
    doc_script_src="$(grep -o "script-src [^;]*" <<<"$(header content-security-policy)" || true)"
    assert_contains "/api/doc: CSP script-src 'self'" "$doc_script_src" "script-src 'self'"
    assert_not_contains "/api/doc: CSP senza 'unsafe-inline' negli script" "$doc_script_src" "unsafe-inline"

    # L'URL dello script lo si ricava dalla pagina, non è scritto qui
    doc_js="$(grep -o '<script[^>]*src="[^"]*"' <<<"$doc_html" | head -n 1 | sed 's/.*src="//; s/"$//' || true)"
    if [ -z "$doc_js" ]; then
        ko "/api/doc referenzia uno script"
    else
        case "$doc_js" in
            /bundles/*) ok "/api/doc: lo script è della stessa origine ($doc_js)" ;;
            *) ko "/api/doc: lo script deve stare sulla stessa origine, sotto /bundles/ (ottenuto '$doc_js')" ;;
        esac
        READ_BODY=0 fetch "http://127.0.0.1$doc_js"
        assert_eq "GET $doc_js -> 200" "200" "$RESP_STATUS"
        assert_contains "$doc_js: Content-Type JavaScript (non l'index.html della SPA)" "$(header content-type)" "javascript"
        assert_contains "$doc_js: cache" "$(header cache-control)" "max-age="
        # Il file vero, non un fallback: la SPA risponderebbe con un HTML di poche righe
        doc_js_bytes="$(dexec sh -c 'wc -c < /tmp/smoke.body' | tr -d ' ')"
        if [ "${doc_js_bytes:-0}" -gt 100000 ]; then ok "$doc_js: è la libreria (${doc_js_bytes} byte)"; else ko "$doc_js: troppo piccolo (${doc_js_bytes:-0} byte), non è la libreria"; fi
    fi
    assert_not_contains "/api/doc: nessuno script da CDN" "$doc_html" "cdn.jsdelivr.net"
    # Ogni <script> senza src deve essere un blocco di dati (JSON): uno script inline eseguibile violerebbe la CSP
    inline_scripts="$(grep -o '<script[^>]*>' <<<"$doc_html" | grep -v 'src=' | grep -v 'type="application/json"' || true)"
    assert_eq "/api/doc: nessuno script inline eseguibile" "" "$inline_scripts"

    fetch -H 'Accept: application/json' http://127.0.0.1/api/doc.json
    assert_eq "GET /api/doc.json -> 200 (senza login)" "200" "$RESP_STATUS"
    assert_contains "/api/doc.json: Content-Type JSON" "$(header content-type)" "json"
    if dexec php -r 'json_decode(file_get_contents("/tmp/smoke.body"), false, 512, JSON_THROW_ON_ERROR);' >/dev/null 2>&1; then ok "/api/doc.json: JSON valido"; else ko "/api/doc.json: JSON non valido"; fi
    assert_contains "/api/doc.json: contiene openapi" "$RESP_BODY" '"openapi"'
    assert_not_contains "/api/doc.json: nessun server localhost" "$RESP_BODY" "localhost"

    HTTP_APP="$APP"
else
    ko "la seconda istanza (API_DOC_ENABLED=true) non è partita"
fi

# ---------------------------------------------------------------------------------------------
# 8. Log: l'entrypoint segnala gli errori non fatali solo su stderr, ma non devono esserci
# ---------------------------------------------------------------------------------------------

section "Log dell'applicazione"

app_logs="$(docker logs "$APP" 2>&1 || true)"
problems="$(grep -iE 'entrypoint: .*(failed|refusing|missing)|PHP (Fatal|Parse) error|Uncaught |Segmentation fault' <<<"$app_logs" || true)"
assert_eq "nessun errore di entrypoint o PHP nei log" "" "$problems"
if [ -n "$problems" ]; then printf '%s\n' "$problems" >&2; fi

# ---------------------------------------------------------------------------------------------

section "Esito"
if [ "$FAILED" -eq 0 ]; then
    echo "Smoke test superato: $IMAGE"
else
    echo "Smoke test FALLITO ($FAILED verifiche): $IMAGE" >&2
    exit 1
fi
