#!/bin/sh
set -eu

usage() {
    echo "Uso: $0 <remote> [--tag vX.Y.Z] [--message \"testo\"] [--force] [--dry-run]" >&2
    exit 2
}

W1='clau''de'
W2='anthro''pic'
W3='co-authored''-by'
LEAK_RE="$W1|$W2|$W3"

GITHUB_REMOTE="${1:-}"
[ -n "$GITHUB_REMOTE" ] || usage
# Un URL che inizia con "-" verrebbe letto da git come un'opzione.
case "$GITHUB_REMOTE" in
    -*) echo "Remote non valido: '$GITHUB_REMOTE'" >&2; exit 2 ;;
esac
shift

TAG=""
MESSAGE=""
FORCE=0
DRY_RUN=0
while [ $# -gt 0 ]; do
    case "$1" in
        --tag) [ $# -ge 2 ] || usage; TAG="$2"; shift 2 ;;
        --message) [ $# -ge 2 ] || usage; MESSAGE="$2"; shift 2 ;;
        --force) FORCE=1; shift ;;
        --dry-run) DRY_RUN=1; shift ;;
        *) usage ;;
    esac
done

if [ -n "$TAG" ] && ! printf '%s' "$TAG" | grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+([-+].+)?$'; then
    echo "Il tag deve avere la forma vX.Y.Z (es. v0.2.0): '$TAG'" >&2
    exit 2
fi

if printf '%s' "$MESSAGE" | grep -qiE -e "$LEAK_RE"; then
    echo "Pubblicazione annullata: il messaggio contiene un termine che non deve comparire nella storia pubblica." >&2
    exit 1
fi

if [ "$FORCE" -eq 1 ] && [ "$DRY_RUN" -eq 0 ]; then
    printf "ATTENZIONE: --force sovrascrive la storia di %s. Continuare? [scrivi 'si'] " "$GITHUB_REMOTE" >&2
    read -r answer
    [ "$answer" = "si" ] || { echo "Annullato." >&2; exit 1; }
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

SNAP="$TMP/snapshot"
mkdir "$SNAP"
git archive HEAD | tar -xC "$SNAP"

LEAKS="$TMP/leaks.txt"
: > "$LEAKS"
find "$SNAP" \( -iname "*$W1*" -o -iname "*$W2*" -o -name handoffs -o -name .forgejo \) -print | sed "s|^$SNAP/||; s|\$|  (percorso vietato)|" >> "$LEAKS"
if [ -e "$SNAP/docs" ]; then
    echo "docs  (cartella docs/ nella radice)" >> "$LEAKS"
fi
find "$SNAP" -type l | while IFS= read -r link; do
    if readlink "$link" | grep -qiE -e "$LEAK_RE"; then
        echo "${link#"$SNAP"/}  (collegamento verso un percorso vietato)" >> "$LEAKS"
    fi
done
GREP_STATUS=0
grep -rlaiE -e "$LEAK_RE" "$SNAP" > "$TMP/content.txt" || GREP_STATUS=$?
if [ "$GREP_STATUS" -gt 1 ]; then
    echo "Pubblicazione annullata: la scansione del contenuto e' fallita (grep exit $GREP_STATUS)." >&2
    exit 1
fi
sed "s|^$SNAP/||; s|\$|  (contenuto vietato)|" "$TMP/content.txt" >> "$LEAKS"

if [ -s "$LEAKS" ]; then
    echo "Pubblicazione annullata: lo snapshot contiene materiale che non deve diventare pubblico:" >&2
    sed 's/^/  - /' "$LEAKS" >&2
    exit 1
fi

cd "$SNAP"
git init -q -b main
git remote add origin "$GITHUB_REMOTE"

CHANGED=1
PARENT=""
if [ "$FORCE" -eq 0 ] && git fetch -q --depth=1 origin main 2>/dev/null; then
    PARENT="$(git log -1 --format='%h %s' FETCH_HEAD)"
    git reset -q --soft FETCH_HEAD
    git add -A
    git diff --cached --quiet FETCH_HEAD && CHANGED=0
else
    git add -A
fi

if [ "$DRY_RUN" -eq 1 ]; then
    echo "Dry run su $GITHUB_REMOTE: nessun commit, push o tag."
    echo "File nello snapshot: $(git ls-files | wc -l | tr -d ' ')"
    if [ -n "$PARENT" ]; then
        echo "Ultimo commit pubblico (genitore): $PARENT"
    elif [ "$FORCE" -eq 1 ]; then
        echo "Con --force non si parte dal remoto: il commit sarebbe senza genitore."
    else
        echo "Remoto non raggiungibile o senza main: il commit sarebbe senza genitore."
    fi
    if [ "$CHANGED" -eq 1 ]; then
        echo "Modifiche da pubblicare: si${TAG:+, con tag $TAG}"
    else
        echo "Modifiche da pubblicare: nessuna${TAG:+ (il tag $TAG verrebbe comunque creato)}"
    fi
    exit 0
fi

if [ "$CHANGED" -eq 1 ]; then
    git commit -q -m "${MESSAGE:-release: $(date +%Y-%m-%d)${TAG:+ ($TAG)}}"
    if [ "$FORCE" -eq 1 ]; then
        git push --force -u origin main
    else
        git push -u origin main
    fi
else
    echo "Nessuna modifica rispetto a $GITHUB_REMOTE: main resta com'è."
    [ -n "$TAG" ] || exit 0
fi

if [ -n "$TAG" ]; then
    git tag -a "$TAG" -m "${MESSAGE:-$TAG}"
    git push origin "$TAG"
fi

echo "Pubblicato su $GITHUB_REMOTE${TAG:+ con tag $TAG}"
