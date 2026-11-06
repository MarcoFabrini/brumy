import { z } from 'zod';
import { authFetch } from '@/api/client';
import { useAuthStore, type AuthStatus } from '@/stores/useAuthStore';
import {
  hasDifferentServerKey,
  rememberPushEndpoint,
  rememberedPushEndpoint,
  urlBase64ToUint8Array,
  webSubscriptionBody,
} from '@/lib/webPush';

const vapidPublicKeySchema = z.object({ publicKey: z.string() });

/**
 * Rimette in ordine le notifiche di questo browser all'inizio di ogni sessione (e quando si apre la scheda delle
 * notifiche):
 * - una subscription presente si ri-registra sul server, che le cancella tutte a ogni reset/cambio password e cambio
 *   email: senza questo passo il dispositivo da cui l'utente ha fatto il cambio smetterebbe di ricevere notifiche pur
 *   risultando "iscritto";
 * - se l'utente le aveva accese qui ({@link rememberedPushEndpoint}) e il browser ha perso la subscription con il
 *   permesso ancora concesso (iPhone, dopo un aggiornamento o un riavvio dell'app), la ricrea senza chiedere niente e
 *   toglie dal server l'endpoint vecchio, così non arrivano notifiche doppie.
 *
 * Non chiede permessi e non disiscrive (quello resta a `usePushSubscription` e al logout). Una subscription con una
 * chiave VAPID diversa da quella corrente si lascia stare. L'upsert per endpoint lato server la rende idempotente;
 * due chiamate insieme condividono lo stesso giro. Best-effort: non lancia mai.
 */
let running: Promise<void> | null = null;

export function resyncThisDevice(): Promise<void> {
  running ??= syncThisDevice().finally(() => {
    running = null;
  });
  return running;
}

async function syncThisDevice(): Promise<void> {
  try {
    if (
      typeof navigator === 'undefined' ||
      !('serviceWorker' in navigator) ||
      !('PushManager' in window) ||
      typeof Notification === 'undefined' ||
      Notification.permission !== 'granted'
    ) {
      return;
    }
    const reg = await navigator.serviceWorker.getRegistration();
    if (!reg) return;
    let sub = await reg.pushManager.getSubscription();
    const remembered = rememberedPushEndpoint();
    if (!sub && remembered === null) return;

    const { publicKey } = vapidPublicKeySchema.parse(await authFetch<unknown>('/api/push-subscriptions/vapid-public-key'));
    if (!publicKey || (sub && hasDifferentServerKey(sub, publicKey))) return;

    sub ??= await reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(publicKey) as BufferSource,
    });
    await authFetch<void>('/api/push-subscriptions', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: webSubscriptionBody(sub),
    });
    if (remembered !== null && remembered !== sub.endpoint) {
      // L'endpoint perso dal browser può restare attivo per il servizio push: niente notifiche doppie
      await authFetch<void>('/api/push-subscriptions/unsubscribe', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ platform: 'web', endpoint: remembered }),
      }).catch(() => undefined);
    }
    rememberPushEndpoint(sub.endpoint);
  } catch {
    // ignorato: al massimo questo dispositivo resta senza notifiche finché l'utente non le riattiva
  }
}

type Session = { status: AuthStatus; user: { id: number } | null };

/**
 * True quando inizia una sessione autenticata: lo store passa a `authenticated` (login, registrazione, invito,
 * bootstrap al reload) oppure cambia l'utente. Non il solo cambio di organizzazione attiva (switch-org).
 */
export function sessionStarted(next: Session, prev: Session): boolean {
  if (next.status !== 'authenticated' || next.user === null) return false;
  return prev.status !== 'authenticated' || prev.user?.id !== next.user.id;
}

/**
 * Ri-registra la subscription di questo browser a ogni inizio di sessione (vedi {@link sessionStarted}), in un
 * unico punto invece che in ogni flusso di login. Ritorna la funzione per smettere di ascoltare.
 */
export function resyncPushOnSessionStart(resync: () => Promise<void> = resyncThisDevice): () => void {
  return useAuthStore.subscribe((state, prev) => {
    if (sessionStarted(state, prev)) void resync();
  });
}
