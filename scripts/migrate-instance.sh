#!/bin/sh
# Porta un'installazione creata con un altro nome a quello attuale (brumy) senza perdere dati.
#
#   scripts/migrate-instance.sh <vecchio-nome>
#
# <vecchio-nome> è il nome della cartella dati (./<vecchio-nome>/) e il prefisso delle variabili
# del .env (<VECCHIO_NOME>_TAG, <VECCHIO_NOME>_REGISTRY). Cosa fa:
#   1. esporta il database con un MariaDB usa-e-getta avviato sulla vecchia cartella (la tocca solo in lettura);
#   2. sposta ./<vecchio-nome>/ in ./brumy/ (allegati, backup e chiavi JWT restano com'erano);
#   3. mette la vecchia cartella del database da parte in ./brumy/db.pre-rename;
#   4. aggiorna il .env (copia in .env.pre-rename): prefisso delle variabili, e nome e utente del
#      database se coincidevano con il vecchio nome;
#   5. avvia solo il database nuovo, vi importa l'esportazione e confronta il numero di tabelle.
#
# Da lanciare nella cartella con compose.yml e .env, a stack fermo: prima `docker compose down`
# con il compose.yml vecchio, poi sostituisci compose.yml con quello nuovo. Poi `docker compose up -d`.
# Non cancella nulla: db.pre-rename e .env.pre-rename si tolgono a mano, a migrazione verificata.
set -eu

NEW=brumy

usage() {
    echo "Uso: $0 <vecchio-nome>" >&2
    exit 2
}

fail() {
    echo "Errore: $*" >&2
    exit 1
}

OLD="${1:-}"
[ -n "$OLD" ] || usage
case "$OLD" in
    *[!a-zA-Z0-9_-]*) fail "il nome può contenere solo lettere, cifre, - e _: '$OLD'" ;;
esac
[ "$OLD" != "$NEW" ] || fail "il vecchio nome coincide con quello nuovo ($NEW)"

[ -f compose.yml ] && [ -f .env ] || fail "lancialo nella cartella che contiene compose.yml e .env"
[ -d "./$OLD/db" ] || fail "non trovo ./$OLD/db"
[ ! -e "./$NEW" ] || fail "./$NEW esiste già: sposta o rimuovi quella cartella e riprova"
command -v docker >/dev/null || fail "docker non è disponibile"
docker compose version >/dev/null 2>&1 || fail "docker compose non è disponibile"

env_value() {
    sed -n "s/^$1=//p" .env | tail -n 1 | sed 's/^"\(.*\)"$/\1/; s/^'"'"'\(.*\)'"'"'$/\1/'
}

OLD_DB="$(env_value MARIADB_DATABASE)"
OLD_USER="$(env_value MARIADB_USER)"
[ -n "$OLD_DB" ] || fail "MARIADB_DATABASE non è impostato nel .env"
[ -n "$OLD_USER" ] || fail "MARIADB_USER non è impostato nel .env"
NEW_DB="$OLD_DB"
NEW_USER="$OLD_USER"
[ "$OLD_DB" != "$OLD" ] || NEW_DB="$NEW"
[ "$OLD_USER" != "$OLD" ] || NEW_USER="$NEW"
OLD_PREFIX="$(printf '%s' "$OLD" | tr 'a-z-' 'A-Z_')"

# Lo stack deve essere fermo: due MariaDB sulla stessa cartella la corromperebbero.
DB_PATH="$(pwd)/$OLD/db"
for id in $(docker ps -q); do
    if docker inspect -f '{{range .Mounts}}{{println .Source}}{{end}}' "$id" | grep -qx "$DB_PATH"; then
        fail "il container $(docker inspect -f '{{.Name}}' "$id") sta usando $DB_PATH: fai prima docker compose down"
    fi
done

TMP_NAME="$NEW-migrate-$$"
DUMP="$(mktemp)"
cleanup() {
    docker rm -f "$TMP_NAME" >/dev/null 2>&1 || true
    rm -f "$DUMP"
}
trap cleanup EXIT

echo "1/5 Esporto il database '$OLD_DB' dalla cartella ./$OLD/db"
IMAGE="$(sed -n 's/^[[:space:]]*image:[[:space:]]*\(mariadb:[^[:space:]]*\).*/\1/p' compose.yml | head -n 1)"
[ -n "$IMAGE" ] || IMAGE="mariadb:11.4"
# Senza tabelle dei privilegi e senza rete: serve solo per leggere i dati in locale.
docker run -d --name "$TMP_NAME" -v "$DB_PATH:/var/lib/mysql" "$IMAGE" \
    mariadbd --skip-grant-tables --skip-networking >/dev/null
tries=0
until docker exec "$TMP_NAME" mariadb-admin ping --silent >/dev/null 2>&1; do
    tries=$((tries + 1))
    [ "$tries" -le 90 ] || fail "il MariaDB temporaneo non è partito: docker logs $TMP_NAME"
    sleep 2
done
docker exec "$TMP_NAME" mariadb-dump --single-transaction --routines --triggers --events \
    --default-character-set=utf8mb4 "$OLD_DB" > "$DUMP"
docker stop -t 120 "$TMP_NAME" >/dev/null
docker rm "$TMP_NAME" >/dev/null
grep -q '^-- Dump completed' "$DUMP" || fail "l'esportazione non è completa"
EXPECTED_TABLES="$(grep -c '^CREATE TABLE' "$DUMP" || true)"
[ "$EXPECTED_TABLES" -gt 0 ] || fail "l'esportazione non contiene tabelle"

echo "2/5 Sposto ./$OLD in ./$NEW"
mv "./$OLD" "./$NEW"

echo "3/5 Metto da parte la vecchia cartella del database in ./$NEW/db.pre-rename"
# Le cartelle dati appartengono a root o all'utente dei container: si lavora da un container, così lo script
# non richiede sudo.
docker run --rm -v "$(pwd)/$NEW:/data" "$IMAGE" mv /data/db /data/db.pre-rename
docker run --rm -i -v "$(pwd)/$NEW:/data" "$IMAGE" sh -c \
    'mkdir -p /data/backups && gzip -c > "/data/backups/pre-rename-$(date +%Y%m%d-%H%M%S).sql.gz"' < "$DUMP"

echo "4/5 Aggiorno il .env (copia in .env.pre-rename)"
cp .env .env.pre-rename
sed -i \
    -e "s/^\(#*\)${OLD_PREFIX}_/\1BRUMY_/" \
    -e "s/^MARIADB_DATABASE=.*/MARIADB_DATABASE=$NEW_DB/" \
    -e "s/^MARIADB_USER=.*/MARIADB_USER=$NEW_USER/" \
    .env

echo "5/5 Avvio il database nuovo e importo i dati"
docker compose up -d db >/dev/null
tries=0
until docker compose exec -T db healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; do
    tries=$((tries + 1))
    [ "$tries" -le 90 ] || fail "il database nuovo non è partito: docker compose logs db"
    sleep 2
done
docker compose exec -T db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' < "$DUMP"
FOUND_TABLES="$(docker compose exec -T db sh -c 'mariadb -N -B -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = '"'"'BASE TABLE'"'"'"' | tr -d '\r')"
[ "$FOUND_TABLES" = "$EXPECTED_TABLES" ] || fail "tabelle importate: $FOUND_TABLES, attese: $EXPECTED_TABLES (il vecchio database è intatto in ./$NEW/db.pre-rename)"

echo
echo "Fatto: $FOUND_TABLES tabelle importate in '$NEW_DB'."
echo "Ora: docker compose pull && docker compose up -d"
echo "Quando hai verificato che tutto funziona puoi togliere ./$NEW/db.pre-rename e .env.pre-rename."
