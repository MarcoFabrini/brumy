/**
 * Destinazione di una notifica push: solo un percorso della stessa origine dell'app. Oggi il server manda
 * sempre percorsi interni (`/reminders/12`), ma il service worker non deve fidarsi del payload: un URL assoluto
 * o `//host` lo porterebbe fuori dall'app (phishing da una notifica "ufficiale").
 */
export function sameOriginPushUrl(raw: unknown, origin: string): string {
  if (typeof raw !== 'string' || raw === '') return '/';
  try {
    const url = new URL(raw, origin);
    return url.origin === origin ? `${url.pathname}${url.search}${url.hash}` : '/';
  } catch {
    return '/';
  }
}
