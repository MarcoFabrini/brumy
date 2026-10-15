# Contributing to Brumy

[En](#contributing-to-brumy) · [It](#contribuire-ad-brumy)

Brumy is mostly maintained by one person, so small, focused PRs get merged faster.

## Before opening a PR

1. For non-trivial changes, open an issue first so we can talk about it before you spend time on it.
2. One PR = one change.
3. Rebase on `main` before opening it.
4. These must pass:

```bash
lando phpunit
lando phpstan
lando npm run lint
lando npm run typecheck
lando npm run test
```

CI runs only on version tags (`v*.*.*`) and on manual dispatch, not on pull requests or pushes to branches: this local run is the only gate before merging, so run all five commands, not just the ones you think your change touches.

## Setup

You need [Lando](https://lando.dev).

```bash
git clone https://github.com/MarcoFabrini/brumy.git
cd brumy
lando start
lando jwt-keys
lando sf doctrine:migrations:migrate --no-interaction
lando sf doctrine:database:create --env=test --if-not-exists
lando phpunit
```

- App: https://brumy.lndo.site (the frontend rebuilds on its own)
- Dev mail: https://mail.brumy.lndo.site
- API docs: https://brumy.lndo.site/api/doc (always on in dev; in production it needs `API_DOC_ENABLED=true`)

`compose.yml` is only for deployment, use Lando for development.

**Faster backend tests.** The test suite resets the schema for every test, which is slow on a database stored on a bind-mounted volume (tens of seconds per test). Run it against a throw-away MariaDB in memory:

```bash
docker run -d --name brumy-testdb --network brumy_default --network-alias testdb --tmpfs /var/lib/mysql \
  -e MARIADB_ROOT_PASSWORD=root mariadb:11.4 --innodb-flush-log-at-trx-commit=0 --skip-innodb-doublewrite
lando ssh -s appserver -c 'cd /app && XDEBUG_MODE=off APP_ENV=test DATABASE_URL="mysql://root:root@testdb:3306/brumy?serverVersion=mariadb-11.4.0&charset=utf8mb4" php bin/phpunit'
```

**Production image smoke test.** CI builds and publishes the Docker image on version tags, so a broken image (missing PHP extension, bad entrypoint or Caddyfile, JWT keys, migrations, SPA assets) must never get there unnoticed. `scripts/smoke-image.sh` starts the image against a throw-away MariaDB and checks the SPA, the API, the security headers, the first-user registration and login, the scheduled commands and a restart. It needs only Docker, publishes no host port and removes everything it creates, even on failure:

```bash
docker build -f docker/app/Dockerfile --target prod -t brumy-smoke:local .
scripts/smoke-image.sh brumy-smoke:local
```

Run it when you touch `docker/`, the Caddyfile, `entrypoint.sh`, `composer.json` or the frontend build. On GitHub the same script runs before the image is pushed, and from the "Run workflow" button (job `image-smoke`, which never pushes).

## Conventions

### Backend

- PHP 8.5, PSR-12, `declare(strict_types=1);` in every file.
- Attributes, autowiring, `final` controllers.
- Naming: `XxxController`, `XxxService`, `XxxVoter`, `XxxRepository`. Singular entities, plural snake_case tables (`Vehicle` → `vehicles`).
- Every endpoint that works on a single resource goes through the Voter. Lists filter by organization explicitly in the query, no hidden global filters.

### Frontend

Strict TypeScript, Tailwind + shadcn/ui, TanStack Query + Zustand, React Hook Form + Zod.

### Tests

- Functional tests extend `App\Tests\Support\ApiTestCase`, with Foundry fixtures.
- If you add an entity, add its factory too.
- If you touch organization data, add a case to `TenantIsolationTest`: org A must not see org B's data.

## Commits

[Conventional Commits](https://www.conventionalcommits.org), with `closes #N` if you're closing an issue.

```
feat: multi-currency support for expenses
fix: VehicleVoter returned VIEW for expired shares
```

## Where help is welcome

- Translations: files are in `frontend/src/i18n/locales/`, a new language must be registered in `frontend/src/i18n/index.ts`.
- Edge-case tests.
- Documentation.
- Bug reports with steps to reproduce.

## What I won't accept

- SaaS features (payments, plans, subscriptions): Brumy is self-host only.
- Big rewrites without an issue first.
- Proprietary or AGPL-incompatible dependencies.

## License

By opening a PR you confirm the code is yours or you have the right to contribute it, and that it's released under AGPL-3.0-or-later (see `LICENSE`). There's no CLA.

## Behavior

Be respectful. Toxic behavior, discrimination or spam get you banned from the repository.

---

# Contribuire ad Brumy

Brumy lo mantengo praticamente da solo, quindi le PR piccole e mirate passano prima.

## Prima di aprire una PR

1. Per modifiche non banali apri prima un issue, così ne parliamo prima che tu ci perda tempo.
2. Una PR = una modifica.
3. Fai rebase su `main` prima di aprirla.
4. Devono passare:

```bash
lando phpunit
lando phpstan
lando npm run lint
lando npm run typecheck
lando npm run test
```

La CI gira solo sui tag di versione (`v*.*.*`) e su avvio manuale, non sulle pull request né sui push dei branch: questa esecuzione in locale è l'unico controllo prima del merge, quindi lancia tutti e cinque i comandi, non solo quelli che pensi riguardino la tua modifica.

## Setup

Serve [Lando](https://lando.dev).

```bash
git clone https://github.com/MarcoFabrini/brumy.git
cd brumy
lando start
lando jwt-keys
lando sf doctrine:migrations:migrate --no-interaction
lando sf doctrine:database:create --env=test --if-not-exists
lando phpunit
```

- App: https://brumy.lndo.site (il frontend si ricompila da solo)
- Email di sviluppo: https://mail.brumy.lndo.site
- Documentazione API: https://brumy.lndo.site/api/doc (sempre attiva in sviluppo; in produzione serve `API_DOC_ENABLED=true`)

`compose.yml` serve solo per il deploy, per sviluppare usa Lando.

**Test backend più veloci.** La suite riazzera lo schema a ogni test: su un database in un volume montato è lento (decine di secondi a test). Lanciala su un MariaDB usa-e-getta in memoria:

```bash
docker run -d --name brumy-testdb --network brumy_default --network-alias testdb --tmpfs /var/lib/mysql \
  -e MARIADB_ROOT_PASSWORD=root mariadb:11.4 --innodb-flush-log-at-trx-commit=0 --skip-innodb-doublewrite
lando ssh -s appserver -c 'cd /app && XDEBUG_MODE=off APP_ENV=test DATABASE_URL="mysql://root:root@testdb:3306/brumy?serverVersion=mariadb-11.4.0&charset=utf8mb4" php bin/phpunit'
```

**Smoke test dell'immagine di produzione.** La CI costruisce e pubblica l'immagine Docker sui tag di versione: un'immagine rotta (estensione PHP mancante, entrypoint o Caddyfile sbagliati, chiavi JWT, migrazioni, asset della SPA) non deve arrivarci senza che nessuno se ne accorga. `scripts/smoke-image.sh` avvia l'immagine con un MariaDB usa-e-getta e controlla SPA, API, header di sicurezza, registrazione e login del primo utente, comandi pianificati e un riavvio. Serve solo Docker, non pubblica porte sull'host e rimuove tutto ciò che crea, anche in caso di errore:

```bash
docker build -f docker/app/Dockerfile --target prod -t brumy-smoke:local .
scripts/smoke-image.sh brumy-smoke:local
```

Lanciala quando tocchi `docker/`, il Caddyfile, `entrypoint.sh`, `composer.json` o la build del frontend. Su GitHub lo stesso script gira prima di pubblicare l'immagine, e dal pulsante "Run workflow" (job `image-smoke`, che non pubblica mai).

## Convenzioni

### Backend

- PHP 8.5, PSR-12, `declare(strict_types=1);` in ogni file.
- Attributes, autowiring, controller `final`.
- Nomi: `XxxController`, `XxxService`, `XxxVoter`, `XxxRepository`. Entità al singolare, tabelle al plurale in snake_case (`Vehicle` → `vehicles`).
- Ogni endpoint che lavora su una singola risorsa passa dal Voter. Le liste filtrano per organizzazione in modo esplicito nella query, niente filtri globali nascosti.

### Frontend

TypeScript strict, Tailwind + shadcn/ui, TanStack Query + Zustand, React Hook Form + Zod.

### Test

- I test funzionali estendono `App\Tests\Support\ApiTestCase`, con fixture Foundry.
- Se aggiungi un'entità, aggiungi anche la sua factory.
- Se tocchi dati di un'organizzazione, aggiungi il caso in `TenantIsolationTest`: l'org A non deve vedere i dati dell'org B.

## Commit

[Conventional Commits](https://www.conventionalcommits.org), con `closes #N` se chiudi un issue.

```
feat: supporto multi-valuta sulle spese
fix: VehicleVoter restituiva VIEW per condivisioni scadute
```

## Dove serve una mano

- Traduzioni: i file sono in `frontend/src/i18n/locales/`, la lingua nuova va registrata in `frontend/src/i18n/index.ts`.
- Test sui casi limite.
- Documentazione.
- Bug report con i passi per riprodurre il problema.

## Cosa non accetto

- Funzioni da SaaS (pagamenti, piani, abbonamenti): Brumy è solo self-host.
- Riscritture grosse senza un issue prima.
- Dipendenze proprietarie o non compatibili con l'AGPL.

## Licenza

Aprendo una PR confermi che il codice è tuo o che hai il diritto di contribuirlo, e che viene rilasciato sotto AGPL-3.0-or-later (vedi `LICENSE`). Non c'è un CLA.

## Comportamento

Sii rispettoso. Comportamenti tossici, discriminazione o spam portano al ban dal repository.
