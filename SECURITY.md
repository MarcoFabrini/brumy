# Security Policy

[En](#security-policy) · [It](#policy-di-sicurezza)

Brumy is a personal project I work on in my spare time. It comes with no warranty (see `LICENSE`) and no guaranteed support.

## Supported versions

Only the latest release (the newest `vX.Y.Z` tag, image `latest`). Older versions don't get fixes.

## Reporting a vulnerability

Please don't open a public issue. Report it privately through [GitHub](https://github.com/MarcoFabrini/brumy/security/advisories/new) (Security → Report a vulnerability), with steps to reproduce and, if you have one, a fix.

I'll look at it when I can and fix it if possible, but I can't promise response times or deadlines. A PR with the fix is the fastest way to get it solved.

## Not considered vulnerabilities

- DoS through sheer traffic volume.
- Insecure configuration of your own instance.
- Dependency vulnerabilities already fixed upstream: open a PR with the version bump.

## Hardening your instance

Running your instance safely is up to you. A few basics:

- Generate `APP_SECRET`, `JWT_PASSPHRASE` and `MARIADB_PASSWORD` with `openssl rand -hex 32`, and `chmod 600 .env`.
- `TRUSTED_PROXIES` defaults to `REMOTE_ADDR` (set in the container entrypoint): the direct peer is trusted to report the client IP. That is right while the app port stays bound to `127.0.0.1` or a private network (the default in `compose.yml`). If you publish it on `0.0.0.0`, anyone can forge `X-Forwarded-For` and sidestep the per-IP rate limits: set `TRUSTED_PROXIES` to your proxy's IP/CIDR (e.g. Cloudflare ranges) instead.
- Update regularly, after a backup: `docker compose exec -u www-data api php bin/console app:backup:create && docker compose pull && docker compose up -d`. Pin `BRUMY_TAG` to a version if you don't want `latest`.
- The API documentation (`/api/doc`, `/api/doc.json`) is off by default (404). `API_DOC_ENABLED=true` in `.env` publishes it without login: turn it on only if you accept the whole API surface being readable by anyone who can reach the instance.
- Sessions use rotating refresh tokens grouped by login session. If a token that was already rotated shows up again (more than 10 seconds later, so not just two tabs racing), someone holds a copy: Brumy revokes the whole session, the user logs in again, and the instance logs a `Refresh token reuse detected` warning (user id and session id, never the token) on stdout (`docker compose logs api`).
- A password change, a password reset, a confirmed email change, a logout and a detected refresh token reuse invalidate the user's access tokens at once, not when they expire (other devices recover with a transparent refresh). Password change, password reset and a confirmed email change also end every other session and remove the push subscriptions of all devices; the current browser registers its own again. A new email address becomes the login only after the link sent to it is opened.
- Copy the backups in `./brumy/backups` (and your `.env`) to another machine. Don't copy `./brumy/db` while the stack is running.

---

# Policy di sicurezza

Brumy è un progetto personale che porto avanti nel tempo libero. Non ha garanzie (vedi `LICENSE`) né supporto garantito.

## Versioni supportate

Solo l'ultima release (il tag `vX.Y.Z` più recente, immagine `latest`). Le versioni precedenti non ricevono correzioni.

## Segnalare una vulnerabilità

Non aprire un issue pubblico. Segnalala in privato tramite [GitHub](https://github.com/MarcoFabrini/brumy/security/advisories/new) (Security → Report a vulnerability), con i passi per riprodurla e, se ce l'hai, una fix.

La guardo quando posso e la correggo se possibile, ma non garantisco tempi di risposta né scadenze. Una PR con la correzione è il modo più veloce per risolvere.

## Cosa non è una vulnerabilità

- DoS tramite traffico massiccio.
- Configurazioni insicure della tua istanza.
- Vulnerabilità di dipendenze già corrette upstream: apri una PR con l'aggiornamento.

## Mettere in sicurezza l'istanza

La sicurezza della tua istanza è responsabilità tua. Le basi:

- Genera `APP_SECRET`, `JWT_PASSPHRASE` e `MARIADB_PASSWORD` con `openssl rand -hex 32`, e fai `chmod 600 .env`.
- `TRUSTED_PROXIES` di default vale `REMOTE_ADDR` (impostato nell'entrypoint del container): ci si fida del peer diretto per l'IP del client. Va bene finché la porta dell'app resta legata a `127.0.0.1` o a una rete privata (il default di `compose.yml`). Se la pubblichi su `0.0.0.0`, chiunque può falsificare `X-Forwarded-For` e aggirare i limiti per IP: imposta `TRUSTED_PROXIES` a IP/CIDR del tuo proxy (es. gli intervalli Cloudflare).
- Aggiorna spesso, dopo un backup: `docker compose exec -u www-data api php bin/console app:backup:create && docker compose pull && docker compose up -d`. Fissa `BRUMY_TAG` a una versione se non vuoi `latest`.
- La documentazione dell'API (`/api/doc`, `/api/doc.json`) è spenta di default (404). `API_DOC_ENABLED=true` in `.env` la pubblica senza login: accendila solo se accetti che tutta la superficie dell'API sia leggibile da chiunque raggiunga l'istanza.
- Le sessioni usano refresh token a rotazione, raggruppati per sessione di login. Se un token già ruotato ricompare (dopo più di 10 secondi, quindi non per due schede in corsa) qualcuno ne ha una copia: Brumy revoca l'intera sessione, l'utente rifà il login e l'istanza scrive su stdout un warning `Refresh token reuse detected` (id utente e id sessione, mai il token) leggibile con `docker compose logs api`.
- Cambio password, reset della password, conferma di un cambio email, logout e riuso rilevato di un refresh token invalidano subito gli access token dell'utente, senza aspettarne la scadenza (gli altri dispositivi si riprendono con un refresh trasparente). Cambio password, reset e conferma di un cambio email chiudono anche tutte le altre sessioni e rimuovono le push subscription di tutti i dispositivi; il browser corrente registra di nuovo la propria. Un nuovo indirizzo email diventa il login solo dopo aver aperto il link mandato lì.
- Copia i backup di `./brumy/backups` (e il tuo `.env`) su un'altra macchina. Non copiare `./brumy/db` a stack acceso.
