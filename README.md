# Brumy

[![License: AGPL-3.0](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](LICENSE)

[En](#En) · [It](#It)

---

## En

Self-hosted vehicle management (PWA): maintenance, fuel logs, expenses, reminders and vehicle sharing with your family. Your data, 100% self-hosted.

### Screenshots

<table>
  <tr>
    <td width="50%"><img src="screenshots/dashboard-light.png" alt="Brumy dashboard in the light theme: totals, upcoming reminders and monthly charts"><br><sub>Dashboard, light theme</sub></td>
    <td width="50%"><img src="screenshots/dashboard-dark.png" alt="Brumy dashboard in the dark theme"><br><sub>Dashboard, dark theme</sub></td>
  </tr>
  <tr>
    <td colspan="2"><img src="screenshots/vehicle-detail.png" alt="Vehicle detail page with spending, category, distance and consumption charts"><br><sub>Vehicle detail with its charts</sub></td>
  </tr>
  <tr>
    <td width="50%"><img src="screenshots/maintenance.png" alt="Maintenance log of a vehicle with costs and scheduled or unscheduled badges"><br><sub>Maintenance log</sub></td>
    <td width="50%"><img src="screenshots/reminders.png" alt="Reminder list with overdue, due soon and OK badges"><br><sub>Reminders with urgency badges</sub></td>
  </tr>
</table>

<table>
  <tr>
    <td width="33%"><img src="screenshots/mobile-dashboard.png" alt="Brumy dashboard on a phone"><br><sub>Mobile dashboard</sub></td>
    <td width="33%"><img src="screenshots/mobile-vehicle.png" alt="Vehicle charts on a phone"><br><sub>Mobile vehicle charts</sub></td>
    <td width="33%"><img src="screenshots/mobile-reminders.png" alt="Reminder list on a phone"><br><sub>Mobile reminders</sub></td>
  </tr>
</table>

_Sample data for a fictional user._

### Installation

**Requirements:** Docker + Docker Compose.

```bash
curl -O https://raw.githubusercontent.com/MarcoFabrini/brumy/main/compose.yml
curl -O https://raw.githubusercontent.com/MarcoFabrini/brumy/main/.env
```

Open `.env`, set `DOMAIN` and generate the 3 secrets (DB password, `APP_SECRET`, `JWT_PASSPHRASE`):

```bash
openssl rand -hex 32
```

Start:

```bash
docker compose up -d
```

The app is available at `http://127.0.0.1:8080`. Check: `curl http://127.0.0.1:8080/api/health`

The **first user** to register becomes the instance admin; after that, registration is closed and other users can only join by invitation (Settings → Members).

### Configuration

- **Email:** set `MAILER_DSN` in `.env` (e.g. `smtp://user:password@smtp.example.com:587`). Without it the default is the `null` transport: nothing is delivered, **including reminder emails**, and each skipped message is logged as a warning.
- **Web Push:** from Settings → Notifications (admin only).

### HTTPS (reverse proxy)

The app only speaks plain HTTP. To expose it publicly, create a `compose.override.yml` next to `compose.yml` (example for Traefik with an existing external `proxy` network):

```yaml
services:
  api:
    ports: !reset []
    networks:
      - default
      - proxy
    labels:
      traefik.enable: "true"
      traefik.docker.network: "proxy"
      traefik.http.routers.brumy.rule: "Host(`${DOMAIN}`)"
      traefik.http.routers.brumy.entrypoints: "https"
      traefik.http.routers.brumy.tls.certresolver: "letsencrypt"
      traefik.http.services.brumy.loadbalancer.server.port: "80"

networks:
  proxy:
    external: true
```

Then run `docker compose up -d` again. Using another proxy (nginx, Caddy, Cloudflare Tunnel)? Skip the override and point it at `127.0.0.1:8080`.

### Updating

```bash
docker compose exec -u www-data api php bin/console app:backup:create   # backup first
docker compose pull && docker compose up -d
```

DB migrations run automatically on startup, so an update can change the schema: take a backup first. To stay on a known version set `BRUMY_TAG` in `.env` to a release without the `v` (e.g. `0.1.2`, or `0.1` for its latest patch) instead of the default `latest`, which follows the newest release.

<!-- TEMPORARY: guide for installs created before the rename to Brumy. Remove it in a later release. -->
#### Upgrading an install created before the rename (temporary)

Up to v0.1.9 the project was called **autocron**. From v0.2.0 the image, the data folder (`./autocron/` becomes `./brumy/`), the database and user name, the Docker network and the `.env` prefix (`AUTOCRON_*` becomes `BRUMY_*`) all use the new name. Installing from scratch needs nothing special; an existing install must be migrated, otherwise `docker compose up -d` starts an empty database and your data would look gone (it stays untouched in `./autocron/`).

`scripts/migrate-instance.sh` does the move without losing data: it exports the database, moves the folder, rewrites your `.env` (a copy stays in `.env.pre-rename`), imports the data into the new database and keeps the old database folder in `./brumy/db.pre-rename`. Nothing is deleted. From the folder that holds your `compose.yml` and `.env`:

```bash
# 1. backup, and keep copies of the old files
docker compose exec -u www-data api php bin/console app:backup:create
cp compose.yml compose.yml.old && cp .env .env.old
cp -a ./autocron/backups ~/backup-before-rename

# 2. stop the stack with the old compose.yml (no -v: the data is in ./autocron, not in volumes)
docker compose down

# 3. fetch the new compose.yml and the script (keep your own .env)
curl -O https://raw.githubusercontent.com/MarcoFabrini/brumy/main/compose.yml
mkdir -p scripts
curl -o scripts/migrate-instance.sh https://raw.githubusercontent.com/MarcoFabrini/brumy/main/scripts/migrate-instance.sh
chmod +x scripts/migrate-instance.sh

# 4. migrate: it prints "Fatto: N tabelle importate" when the import is complete
scripts/migrate-instance.sh autocron

# 5. start
docker compose pull && docker compose up -d
docker compose ps && docker compose logs --tail 50 api
```

Before step 5, check your reverse proxy: the Docker network is now called `brumy`, so anything attached to the old network name must be updated. After the update, reinstall the PWA on your phone (new name) and expect the saved theme to reset once. Sessions stay valid because the JWT keys move with the folder.

Once everything works, remove `./brumy/db.pre-rename`, `.env.pre-rename`, `.env.old` and `compose.yml.old`.

If something goes wrong before step 5, go back with:

```bash
docker compose down
mv ./brumy ./autocron && mv ./autocron/db.pre-rename ./autocron/db
cp .env.old .env && cp compose.yml.old compose.yml
docker compose up -d
```

If you run the script from an older copy and it stops with `Permission denied` on `./brumy/db`, undo the folder move with `mv ./brumy ./autocron` and run `sudo scripts/migrate-instance.sh autocron`, then `sudo chown "$USER": .env .env.pre-rename`. The current script from `main` does not need `sudo`.

### Backup

Data (DB, attachments, JWT keys) lives in `./brumy/`, but **don't copy `./brumy/db` while the stack is running**: a live copy of a database directory can be inconsistent. A nightly job writes a DB dump (`db-<date>.sql.gz`) and an uploads archive (`uploads-<date>.tar.gz`) to `./brumy/backups` (readable only by the app user): copy those, plus your `.env`, to another machine. The backups contain the database and the attachments only, **not the JWT keys** (see Restore). To copy the whole folder, stop the stack first (`docker compose down`).

To take a backup by hand, run it as the app user (`-u www-data`), otherwise the files end up owned by root:

```bash
docker compose exec -u www-data api php bin/console app:backup:create
```

### Restore

You need the `db-<date>.sql.gz` (and, if you have attachments, the matching `uploads-<date>.tar.gz`) from `./brumy/backups`, and the **same `.env`** as the instance that produced them. Keep the same `APP_SECRET`: the Web Push (VAPID) private key is stored encrypted with a key derived from it, so with a different `APP_SECRET` the push settings can no longer be decrypted.

1. Put `compose.yml`, `.env` and the backup files in `./brumy/backups/` on the target machine. On an existing install stop the app, leaving the database up; on a new machine just start the database:

   ```bash
   docker compose stop api messenger        # existing install
   docker compose up -d --wait db           # new machine (or if db is down)
   ```

2. Restore the database (the dump replaces the tables it contains):

   ```bash
   gunzip -c ./brumy/backups/db-<date>.sql.gz | docker compose exec -T db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"'
   ```

3. Restore the attachments into `./brumy/uploads` and give them to the app user (`www-data`, uid 82 in the image). Done inside a throwaway container, it needs neither `sudo` nor knowing the uid:

   ```bash
   docker compose run --rm --no-deps --entrypoint sh api -c \
     'tar -xzf /var/backups/uploads-<date>.tar.gz -C /var/storage/uploads && chown -R www-data:www-data /var/storage/uploads'
   ```

4. Start everything:

   ```bash
   docker compose up -d
   ```

The JWT keys are **not** in the backups: if `./brumy/jwt` is empty the app generates a new pair at startup, which signs out every user (they just log in again). Migrations run on startup, so restoring a dump from an older version and then pulling a newer image works.

### Settings worth knowing

| Variable | Default | Meaning |
|---|---|---|
| `TRUSTED_PROXIES` | `REMOTE_ADDR` | Trust the direct peer (your reverse proxy) for `X-Forwarded-For`. Safe only while the port stays on `127.0.0.1`/a private network; if you expose it on `0.0.0.0`, set your proxy's IP/CIDR. |
| `APP_TIMEZONE` | `Europe/Rome` | Calendar timezone for reminder due dates ("today"). |
| `ATTACHMENTS_ORG_QUOTA_MB` | `1024` | Total attachment storage allowed per organization, in MB (`0` = no limit). Uploads beyond it get a 413. Separately, each record (vehicle, maintenance, expense...) holds at most 20 files. |
| `VEHICLES_ORG_LIMIT` | `0` | Maximum number of vehicles per organization, archived ones included (`0` = no limit). Creating one past the limit gets a 422 `vehicle.limit_reached`; deleting a vehicle frees a slot, archiving it does not. |
| `API_DOC_ENABLED` | `false` | Serves the interactive API documentation at `/api/doc` (and the OpenAPI spec at `/api/doc.json`) **without login**. Off by default: both answer 404. Set `API_DOC_ENABLED=true` in `.env` and restart to expose them; keep it off on an instance open to the internet unless you want the API surface public. |

### Development and contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security vulnerabilities: [SECURITY.md](SECURITY.md) (do not open public issues).

### License

[AGPL-3.0-or-later](LICENSE)

---

## It

Gestione veicoli self-hosted (PWA): manutenzioni, rifornimenti, spese, scadenze e condivisione in famiglia. Dati tuoi, 100% self-hosted.

### Screenshot

<table>
  <tr>
    <td width="50%"><img src="screenshots/dashboard-light.png" alt="Dashboard di Brumy nel tema chiaro: totali, prossime scadenze e grafici mensili"><br><sub>Dashboard, tema chiaro</sub></td>
    <td width="50%"><img src="screenshots/dashboard-dark.png" alt="Dashboard di Brumy nel tema scuro"><br><sub>Dashboard, tema scuro</sub></td>
  </tr>
  <tr>
    <td colspan="2"><img src="screenshots/vehicle-detail.png" alt="Pagina di dettaglio veicolo con i grafici di spesa, categorie, distanza e consumi"><br><sub>Dettaglio veicolo con i suoi grafici</sub></td>
  </tr>
  <tr>
    <td width="50%"><img src="screenshots/maintenance.png" alt="Registro delle manutenzioni di un veicolo con costi e badge programmata o non programmata"><br><sub>Registro manutenzioni</sub></td>
    <td width="50%"><img src="screenshots/reminders.png" alt="Elenco dei promemoria con badge scaduto, in scadenza e OK"><br><sub>Promemoria con badge di urgenza</sub></td>
  </tr>
</table>

<table>
  <tr>
    <td width="33%"><img src="screenshots/mobile-dashboard.png" alt="Dashboard di Brumy su smartphone"><br><sub>Dashboard su smartphone</sub></td>
    <td width="33%"><img src="screenshots/mobile-vehicle.png" alt="Grafici del veicolo su smartphone"><br><sub>Grafici del veicolo su smartphone</sub></td>
    <td width="33%"><img src="screenshots/mobile-reminders.png" alt="Elenco dei promemoria su smartphone"><br><sub>Promemoria su smartphone</sub></td>
  </tr>
</table>

_Dati di esempio di un utente fittizio. Le schermate mostrano l'interfaccia in inglese._

### Installazione

**Requisiti:** Docker + Docker Compose.

```bash
curl -O https://raw.githubusercontent.com/MarcoFabrini/brumy/main/compose.yml
curl -O https://raw.githubusercontent.com/MarcoFabrini/brumy/main/.env
```

Apri `.env`, imposta `DOMAIN` e genera i 3 secret (password DB, `APP_SECRET`, `JWT_PASSPHRASE`):

```bash
openssl rand -hex 32
```

Avvia:

```bash
docker compose up -d
```

L'app è su `http://127.0.0.1:8080`. Verifica: `curl http://127.0.0.1:8080/api/health`

Il **primo utente** che si registra diventa admin dell'istanza; poi la registrazione si chiude e gli altri entrano solo su invito (Impostazioni → Membri).

### Configurazione

- **Email:** imposta `MAILER_DSN` in `.env` (ad es. `smtp://utente:password@smtp.example.com:587`). Senza, si usa il trasporto `null`: non viene consegnato nulla, **nemmeno le email dei promemoria**, e ogni invio saltato finisce nel log come warning.
- **Web Push:** da Impostazioni → Notifiche (solo admin).

### HTTPS (reverse proxy)

L'app parla solo HTTP. Per esporla pubblicamente crea un `compose.override.yml` accanto a `compose.yml` (esempio per Traefik con una rete esterna `proxy` già esistente):

```yaml
services:
  api:
    ports: !reset []
    networks:
      - default
      - proxy
    labels:
      traefik.enable: "true"
      traefik.docker.network: "proxy"
      traefik.http.routers.brumy.rule: "Host(`${DOMAIN}`)"
      traefik.http.routers.brumy.entrypoints: "https"
      traefik.http.routers.brumy.tls.certresolver: "letsencrypt"
      traefik.http.services.brumy.loadbalancer.server.port: "80"

networks:
  proxy:
    external: true
```

Poi rilancia `docker compose up -d`. Usi un altro proxy (nginx, Caddy, Cloudflare Tunnel)? Salta l'override e puntalo a `127.0.0.1:8080`.

### Aggiornamento

```bash
docker compose exec -u www-data api php bin/console app:backup:create   # prima un backup
docker compose pull && docker compose up -d
```

Le migrazioni DB girano da sole all'avvio, quindi un aggiornamento può cambiare lo schema: fai prima un backup. Per restare su una versione precisa imposta `BRUMY_TAG` in `.env` a una release senza la `v` (es. `0.1.2`, oppure `0.1` per la sua ultima patch) invece del default `latest`, che segue l'ultima release.

<!-- TEMPORANEA: guida per le installazioni create prima del rename in Brumy. Da togliere in un rilascio successivo. -->
#### Aggiornare un'installazione creata prima del rename (temporanea)

Fino alla v0.1.9 il progetto si chiamava **autocron**. Dalla v0.2.0 immagine, cartella dei dati (`./autocron/` diventa `./brumy/`), nome di database e utente, rete Docker e prefisso del `.env` (`AUTOCRON_*` diventa `BRUMY_*`) usano il nome nuovo. Chi installa da zero non deve fare nulla di particolare; un'installazione esistente va migrata, altrimenti `docker compose up -d` avvia un database vuoto e i dati sembrerebbero spariti (restano intatti in `./autocron/`).

`scripts/migrate-instance.sh` fa lo spostamento senza perdere dati: esporta il database, sposta la cartella, riscrive il tuo `.env` (una copia resta in `.env.pre-rename`), importa i dati nel database nuovo e tiene la vecchia cartella del database in `./brumy/db.pre-rename`. Non cancella nulla. Dalla cartella che contiene `compose.yml` e `.env`:

```bash
# 1. backup, e copia dei file vecchi
docker compose exec -u www-data api php bin/console app:backup:create
cp compose.yml compose.yml.old && cp .env .env.old
cp -a ./autocron/backups ~/backup-prima-del-rename

# 2. ferma lo stack con il compose.yml vecchio (senza -v: i dati sono in ./autocron, non in volumi)
docker compose down

# 3. scarica il compose.yml nuovo e lo script (tieni il tuo .env)
curl -O https://raw.githubusercontent.com/MarcoFabrini/brumy/main/compose.yml
mkdir -p scripts
curl -o scripts/migrate-instance.sh https://raw.githubusercontent.com/MarcoFabrini/brumy/main/scripts/migrate-instance.sh
chmod +x scripts/migrate-instance.sh

# 4. migra: a import completato stampa "Fatto: N tabelle importate"
scripts/migrate-instance.sh autocron

# 5. avvia
docker compose pull && docker compose up -d
docker compose ps && docker compose logs --tail 50 api
```

Prima del passo 5 controlla il reverse proxy: la rete Docker ora si chiama `brumy`, quindi ciò che è collegato al vecchio nome va aggiornato. Dopo l'aggiornamento reinstalla la PWA sul telefono (nome nuovo) e aspettati che il tema salvato si azzeri una volta. Le sessioni restano valide perché le chiavi JWT si spostano con la cartella.

Quando tutto funziona, elimina `./brumy/db.pre-rename`, `.env.pre-rename`, `.env.old` e `compose.yml.old`.

Se qualcosa va storto prima del passo 5, torni indietro con:

```bash
docker compose down
mv ./brumy ./autocron && mv ./autocron/db.pre-rename ./autocron/db
cp .env.old .env && cp compose.yml.old compose.yml
docker compose up -d
```

Se lanci lo script da una copia più vecchia e si ferma con `Permission denied` su `./brumy/db`, annulla lo spostamento con `mv ./brumy ./autocron` e lancia `sudo scripts/migrate-instance.sh autocron`, poi `sudo chown "$USER": .env .env.pre-rename`. Lo script attuale su `main` non richiede `sudo`.

### Backup

I dati (DB, allegati, chiavi JWT) sono in `./brumy/`, ma **non copiare `./brumy/db` a stack acceso**: la copia a caldo di una cartella di database può essere incoerente. Un job notturno scrive un dump del DB (`db-<data>.sql.gz`) e un archivio degli upload (`uploads-<data>.tar.gz`) in `./brumy/backups` (leggibile solo dall'utente dell'app): copia quelli, più il tuo `.env`, su un'altra macchina. I backup contengono solo database e allegati, **non le chiavi JWT** (vedi Ripristino). Per copiare l'intera cartella ferma prima lo stack (`docker compose down`).

Per fare un backup a mano eseguilo come utente dell'app (`-u www-data`), altrimenti i file risultano di proprietà di root:

```bash
docker compose exec -u www-data api php bin/console app:backup:create
```

### Ripristino

Servono il `db-<data>.sql.gz` (e, se hai allegati, il relativo `uploads-<data>.tar.gz`) di `./brumy/backups` e lo **stesso `.env`** dell'istanza che li ha prodotti. Mantieni lo stesso `APP_SECRET`: la chiave privata VAPID del Web Push è salvata cifrata con una chiave derivata da quello, quindi con un `APP_SECRET` diverso le impostazioni push non sono più decifrabili.

1. Metti `compose.yml`, `.env` e i file di backup in `./brumy/backups/` sulla macchina di destinazione. Su un'installazione esistente ferma l'app lasciando acceso il database; su una macchina nuova avvia solo il database:

   ```bash
   docker compose stop api messenger        # installazione esistente
   docker compose up -d --wait db           # macchina nuova (o se db è spento)
   ```

2. Ripristina il database (il dump sostituisce le tabelle che contiene):

   ```bash
   gunzip -c ./brumy/backups/db-<data>.sql.gz | docker compose exec -T db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"'
   ```

3. Ripristina gli allegati in `./brumy/uploads` assegnandoli all'utente dell'app (`www-data`, uid 82 nell'immagine). In un container usa-e-getta non servono né `sudo` né l'uid:

   ```bash
   docker compose run --rm --no-deps --entrypoint sh api -c \
     'tar -xzf /var/backups/uploads-<data>.tar.gz -C /var/storage/uploads && chown -R www-data:www-data /var/storage/uploads'
   ```

4. Avvia tutto:

   ```bash
   docker compose up -d
   ```

Le chiavi JWT **non** sono nei backup: se `./brumy/jwt` è vuota l'app ne genera una nuova coppia all'avvio e tutti gli utenti vengono disconnessi (basta rifare il login). Le migrazioni girano all'avvio, quindi si può ripristinare un dump di una versione precedente e poi scaricare un'immagine più recente.

### Impostazioni da conoscere

| Variabile | Default | Significato |
|---|---|---|
| `TRUSTED_PROXIES` | `REMOTE_ADDR` | Si fida del peer diretto (il tuo reverse proxy) per `X-Forwarded-For`. Sicuro solo finché la porta resta su `127.0.0.1`/rete privata; se la esponi su `0.0.0.0` imposta IP/CIDR del proxy. |
| `APP_TIMEZONE` | `Europe/Rome` | Fuso di calendario per le scadenze dei promemoria ("oggi"). |
| `ATTACHMENTS_ORG_QUOTA_MB` | `1024` | Spazio totale degli allegati per organizzazione, in MB (`0` = nessun limite). Oltre il limite l'upload risponde 413. In più, ogni record (veicolo, manutenzione, spesa...) contiene al massimo 20 file. |
| `VEHICLES_ORG_LIMIT` | `0` | Numero massimo di veicoli per organizzazione, archiviati inclusi (`0` = nessun limite). Crearne uno oltre il limite risponde 422 `vehicle.limit_reached`; eliminare un veicolo libera un posto, archiviarlo no. |
| `API_DOC_ENABLED` | `false` | Pubblica la documentazione interattiva dell'API su `/api/doc` (e lo spec OpenAPI su `/api/doc.json`) **senza login**. Spenta di default: entrambe rispondono 404. Imposta `API_DOC_ENABLED=true` in `.env` e riavvia per esporle; su un'istanza aperta a internet lasciala spenta se non vuoi rendere pubblica la superficie dell'API. |

### Sviluppo e contributi

Vedi [CONTRIBUTING.md](CONTRIBUTING.md). Vulnerabilità: [SECURITY.md](SECURITY.md) (non aprire issue pubbliche).

### Licenza

[AGPL-3.0-or-later](LICENSE)
