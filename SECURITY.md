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

- Generate `APP_SECRET` and `MARIADB_PASSWORD` with `openssl rand -hex 32`, and `chmod 600 .env`. Changing `APP_SECRET` later signs everyone out and makes the stored two-step verification and VAPID secrets unreadable.
- `TRUSTED_PROXIES` defaults to `REMOTE_ADDR` (set in the container entrypoint): the direct peer is trusted to report the client IP. That is right while the app port stays bound to `127.0.0.1` or a private network (the default in `compose.yml`). If you publish it on `0.0.0.0`, anyone can forge `X-Forwarded-For` and sidestep the per-IP rate limits: set `TRUSTED_PROXIES` to your proxy's IP/CIDR (e.g. Cloudflare ranges) instead.
- Update regularly, after a backup: `docker compose exec -u www-data api php bin/console app:backup:create && docker compose pull && docker compose up -d`. Pin `BRUMY_TAG` to a version if you don't want `latest`.
- The API documentation (`/api/doc`, `/api/doc.json`) is off by default (404). `API_DOC_ENABLED=true` in `.env` publishes it without login: turn it on only if you accept the whole API surface being readable by anyone who can reach the instance.
- Sign-in uses Symfony server sessions stored in the database: an HttpOnly `brumy_session` cookie (SameSite=Lax, Secure in production) and a `brumy_remember` cookie that keeps you signed in for 7 days from the last use, one series per device. Logout deletes that device's series. A remember-me value that was already replaced shows up again (outside a 60-second window for tabs racing) means someone holds a copy: it is refused and the instance logs a `Remember-me token reuse detected` warning on stdout (`docker compose logs api`), without email or token.
- Every unsafe request (POST, PUT, PATCH, DELETE) under `/api`, sign-in included, must come from the same origin as the API (`Sec-Fetch-Site: same-origin`, or `Origin`/`Referer` matching the request host), otherwise `403 auth.csrf_invalid`. Browsers send these headers on their own. Behind a reverse proxy the check compares with the host and scheme the app sees, so `TRUSTED_PROXIES` and `X-Forwarded-Proto`/`X-Forwarded-Host` must be right, or every write is refused. Serving the frontend from another origin is not supported.
- Sign-in is throttled to 5 failures per minute per (email, IP) and 25 per IP; an unknown email costs the same time as a wrong password.
- A password change, a password reset, a confirmed email change and a GDPR deletion end every other session at its next request and invalidate the remember-me cookies (they are signed with the password). A password change keeps the current session (new id, new remember-me cookie); a reset and a confirmed email change end that one too. Password change, reset and confirmed email change also remove the push subscriptions of all devices; the current browser registers its own again. A new email address becomes the login only after the link sent to it is opened.
- Two-step verification (authenticator app, 6-digit TOTP) is optional per user, from Profile › Security: turning it on, off or generating new recovery codes needs the password and a code. The secret is stored encrypted with `APP_SECRET`, the 10 recovery codes as SHA-256 hashes (each works once). 5 wrong codes or passwords in 15 minutes block further attempts for that user. Turning it on, off, new codes, a recovery code used to sign in and an admin reset are logged on stdout (user id only). A user who lost phone and recovery codes: `docker compose exec -u www-data api php bin/console app:user:2fa-reset <email> --confirm`.
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

- Genera `APP_SECRET` e `MARIADB_PASSWORD` con `openssl rand -hex 32`, e fai `chmod 600 .env`. Cambiare `APP_SECRET` in seguito disconnette tutti e rende illeggibili i segreti salvati della verifica in due passaggi e di VAPID.
- `TRUSTED_PROXIES` di default vale `REMOTE_ADDR` (impostato nell'entrypoint del container): ci si fida del peer diretto per l'IP del client. Va bene finché la porta dell'app resta legata a `127.0.0.1` o a una rete privata (il default di `compose.yml`). Se la pubblichi su `0.0.0.0`, chiunque può falsificare `X-Forwarded-For` e aggirare i limiti per IP: imposta `TRUSTED_PROXIES` a IP/CIDR del tuo proxy (es. gli intervalli Cloudflare).
- Aggiorna spesso, dopo un backup: `docker compose exec -u www-data api php bin/console app:backup:create && docker compose pull && docker compose up -d`. Fissa `BRUMY_TAG` a una versione se non vuoi `latest`.
- La documentazione dell'API (`/api/doc`, `/api/doc.json`) è spenta di default (404). `API_DOC_ENABLED=true` in `.env` la pubblica senza login: accendila solo se accetti che tutta la superficie dell'API sia leggibile da chiunque raggiunga l'istanza.
- L'accesso usa le sessioni di Symfony salvate nel database: un cookie HttpOnly `brumy_session` (SameSite=Lax, Secure in produzione) e un cookie `brumy_remember` che tiene dentro per 7 giorni dall'ultimo uso, una serie per dispositivo. Il logout cancella la serie del dispositivo. Un valore remember-me già sostituito che ricompare (fuori da una finestra di 60 secondi per le schede in corsa) vuol dire che qualcuno ne ha una copia: viene rifiutato e l'istanza scrive su stdout un warning `Remember-me token reuse detected` (`docker compose logs api`), senza email né token.
- Ogni richiesta non sicura (POST, PUT, PATCH, DELETE) sotto `/api`, login compreso, deve venire dalla stessa origine dell'API (`Sec-Fetch-Site: same-origin`, oppure `Origin`/`Referer` uguali all'host della richiesta), altrimenti `403 auth.csrf_invalid`. I browser mettono questi header da soli. Dietro un reverse proxy il controllo confronta con host e schema che vede l'app: `TRUSTED_PROXIES` e `X-Forwarded-Proto`/`X-Forwarded-Host` devono essere corretti, altrimenti ogni scrittura viene rifiutata. Un frontend servito da un'altra origine non è supportato.
- Il login è limitato a 5 fallimenti al minuto per (email, IP) e 25 per IP; un'email sconosciuta costa lo stesso tempo di una password sbagliata.
- Cambio password, reset della password, conferma di un cambio email e cancellazione GDPR chiudono ogni altra sessione alla sua richiesta successiva e invalidano i cookie remember-me (sono firmati con la password). Il cambio password tiene la sessione corrente (id nuovo, cookie remember-me nuovo); reset e conferma di un cambio email chiudono anche quella. Cambio password, reset e conferma di un cambio email rimuovono anche le push subscription di tutti i dispositivi; il browser corrente registra di nuovo la propria. Un nuovo indirizzo email diventa il login solo dopo aver aperto il link mandato lì.
- La verifica in due passaggi (app di autenticazione, TOTP a 6 cifre) è facoltativa per utente, da Profilo › Sicurezza: attivarla, disattivarla o generare nuovi codici di recupero richiede password e codice. Il segreto è salvato cifrato con `APP_SECRET`, i 10 codici di recupero come hash SHA-256 (ognuno vale una volta). 5 codici o password sbagliati in 15 minuti bloccano i tentativi successivi per quell'utente. Attivazione, disattivazione, codici nuovi, uso di un codice di recupero al login e reset dell'amministratore finiscono su stdout (solo l'id utente). Chi ha perso telefono e codici di recupero: `docker compose exec -u www-data api php bin/console app:user:2fa-reset <email> --confirm`.
- Copia i backup di `./brumy/backups` (e il tuo `.env`) su un'altra macchina. Non copiare `./brumy/db` a stack acceso.
