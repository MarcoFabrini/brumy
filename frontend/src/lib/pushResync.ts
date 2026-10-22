import { z } from 'zod';
import { authFetch } from '@/api/client';
import { useAuthStore, type AuthStatus } from '@/stores/useAuthStore';
import { hasDifferentServerKey, webSubscriptionBody } from '@/lib/webPush';

const vapidPublicKeySchema = z.object({ publicKey: z.string() });

/**
 * Ri-registra sul server la subscription push già presente in questo browser. Il server cancella le subscription
 * di tutti i dispositivi a ogni reset/cambio password e cambio email: senza questo passo il dispositivo da cui
 * l'utente ha appena fatto il cambio smetterebbe di ricevere notifiche pur risultando "iscritto".
 *
 * Solo se il permesso è già concesso, esiste una subscription e la sua chiave VAPID è quella corrente: non chiede
 * permessi, non crea subscription nuove, non disiscrive (quello resta a `usePushSubscription`). L'upsert per
 * endpoint lato server la rende idempotente. Best-effort: non lancia mai.
 */
export async function resyncThisDevice(): Promise<void> {
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
    const sub = await reg?.pushManager.getSubscription();
    if (!sub) return;

    const { publicKey } = vapidPublicKeySchema.parse(await authFetch<unknown>('/api/push-subscriptions/vapid-public-key'));
    if (!publicKey || hasDifferentServerKey(sub, publicKey)) return;

    await authFetch<void>('/api/push-subscriptions', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: webSubscriptionBody(sub),
    });
  } catch {
    // ignorato: al massimo questo dispositivo resta senza notifiche finché l'utente non le riattiva
  }
}

type Session = { status: AuthStatus; user: { id: number } | null };

/**
 * True quando inizia una sessione autenticata: lo store passa a `authenticated` (login, registrazione, invito,
 * bootstrap al reload) oppure cambia l'utente. Non la sola sostituzione dell'access token (refresh, switch-org).
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
