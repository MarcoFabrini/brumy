import { authFetch } from '@/api/client';

/**
 * Disiscrive il browser corrente dalle notifiche push (server + browser). Da chiamare PRIMA del logout,
 * quando il token è ancora valido: altrimenti, con un altro utente sullo stesso browser, le notifiche
 * del precedente continuerebbero ad arrivare e il nuovo vedrebbe "attive" notifiche che non riceve.
 * Best-effort: un errore qui non deve mai impedire il logout.
 */
export async function unsubscribeThisDevice(): Promise<void> {
  try {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return;
    const reg = await navigator.serviceWorker.getRegistration();
    const sub = await reg?.pushManager.getSubscription();
    if (!sub) return;
    try {
      // Con la sessione ancora aperta: dopo il logout la chiamata darebbe 401, lasciando viva la riga sul server
      // (e il push al prossimo utente di questo dispositivo).
      await authFetch<void>('/api/push-subscriptions/unsubscribe', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ platform: 'web', endpoint: sub.endpoint }),
      });
    } finally {
      // Anche se il server non risponde, la sottoscrizione del browser va comunque chiusa.
      await sub.unsubscribe();
    }
  } catch {
    // ignorato
  }
}
