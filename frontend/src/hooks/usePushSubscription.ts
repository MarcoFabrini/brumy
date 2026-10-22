import { useCallback, useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { authFetch } from '@/api/client';
import { vapidPublicKeyKey } from '@/hooks/usePushSettings';
import { hasDifferentServerKey, urlBase64ToUint8Array, webSubscriptionBody } from '@/lib/webPush';

export type PushStatus =
  | 'unsupported' // browser senza Push API / serviceWorker
  | 'sw-unavailable' // Push API c'è, ma il service worker non si registra/attiva (HTTPS/cert, sw.js)
  | 'not-configured' // VAPID key mancante
  | 'denied' // permesso negato
  | 'subscribed'
  | 'unsubscribed'
  | 'loading';

const SW_READY_TIMEOUT_MS = 10_000;

/**
 * Registrazione del service worker con un SW attivo, oppure null se non si
 * attiva in tempo. Non ci si affida solo a `serviceWorker.ready`: quella promise
 * non si risolve MAI se nessun SW è registrato, quindi un errore reale (contesto
 * non sicuro, certificato non fidato, sw.js irraggiungibile) resterebbe muto fino
 * al timeout e verrebbe scambiato per "browser non supportato". Se la
 * registrazione manca (lib/pwaUpdate la registra al `load`, può non essere ancora
 * successo o essere fallito) la si tenta qui: l'eccezione vera arriva al chiamante.
 * '/sw.js' è l'output di vite-plugin-pwa (strategia injectManifest, sw.ts).
 */
async function getActiveRegistration(): Promise<ServiceWorkerRegistration | null> {
  const reg =
    (await navigator.serviceWorker.getRegistration()) ??
    (await navigator.serviceWorker.register('/sw.js', { scope: '/' }));
  if (reg.active) return reg;

  const timeout = new Promise<null>((resolve) => setTimeout(() => resolve(null), SW_READY_TIMEOUT_MS));
  return Promise.race([navigator.serviceWorker.ready, timeout]);
}

/**
 * usePushSubscription — gestisce registrazione Web Push (VAPID).
 *
 * Il service worker è una build reale anche in dev (`npm run watch`), quindi
 * funziona in entrambi gli ambienti — serve però un secure context (HTTPS o
 * `localhost`), altrimenti il browser non registra il service worker e questo
 * hook ritorna 'unsupported'.
 */
export function usePushSubscription() {
  const [status, setStatus] = useState<PushStatus>('loading');

  const supported =
    typeof navigator !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window;

  // VAPID public key letta a runtime dall'API (DB istanza o fallback env), via
  // React Query: così generare/abilitare le chiavi in PushSettingsCard (che
  // invalida questa stessa queryKey) aggiorna subito questo hook, senza reload.
  const { data: vapidKeyData, isLoading: vapidKeyLoading } = useQuery({
    queryKey: vapidPublicKeyKey,
    queryFn: () => authFetch<{ publicKey: string }>('/api/push-subscriptions/vapid-public-key'),
    retry: false,
  });
  // null = ancora in caricamento, '' = non configurata.
  const vapidKey = vapidKeyLoading ? null : (vapidKeyData?.publicKey ?? '');

  // Determina stato iniziale (attende la chiave runtime)
  useEffect(() => {
    if (vapidKey === null) return;
    let cancelled = false;

    async function check() {
      if (!supported) {
        if (!cancelled) setStatus('unsupported');
        return;
      }
      if (!vapidKey) {
        if (!cancelled) setStatus('not-configured');
        return;
      }
      if (Notification.permission === 'denied') {
        if (!cancelled) setStatus('denied');
        return;
      }
      // Il service worker richiede un secure context (HTTPS con certificato
      // fidato, o localhost): senza, il browser rifiuta la registrazione.
      if (!window.isSecureContext) {
        if (!cancelled) setStatus('sw-unavailable');
        return;
      }
      try {
        const reg = await getActiveRegistration();
        if (!reg) {
          if (!cancelled) setStatus('sw-unavailable');
          return;
        }
        let sub = await reg.pushManager.getSubscription();
        // Chiavi VAPID rigenerate: la vecchia subscription non è più valida per il server
        // (e impedirebbe di iscriversi di nuovo). La scarta e riparte da "non iscritto".
        if (sub && hasDifferentServerKey(sub, vapidKey!)) {
          await sub.unsubscribe();
          sub = null;
        }
        if (!cancelled) setStatus(sub ? 'subscribed' : 'unsubscribed');
      } catch {
        if (!cancelled) setStatus('sw-unavailable');
      }
    }

    void check();
    return () => {
      cancelled = true;
    };
  }, [supported, vapidKey]);

  const subscribe = useCallback(async () => {
    if (!supported || !vapidKey) return;
    setStatus('loading');

    try {
      const perm = await Notification.requestPermission();
      if (perm !== 'granted') {
        setStatus(perm === 'denied' ? 'denied' : 'unsubscribed');
        return;
      }

      const reg = await navigator.serviceWorker.ready;
      const stale = await reg.pushManager.getSubscription();
      if (stale && hasDifferentServerKey(stale, vapidKey)) await stale.unsubscribe();
      const sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapidKey) as BufferSource,
      });

      try {
        await authFetch<void>('/api/push-subscriptions', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: webSubscriptionBody(sub),
        });
      } catch (err) {
        // Salvataggio lato server fallito: annulla la sottoscrizione locale
        // appena creata, altrimenti il browser resta iscritto ma il server
        // non lo sa mai (stato non recuperabile finché l'utente non riprova).
        await sub.unsubscribe();
        throw err;
      }

      setStatus('subscribed');
    } catch (err) {
      setStatus('unsubscribed');
      throw err;
    }
  }, [supported, vapidKey]);

  const unsubscribe = useCallback(async () => {
    if (!supported) return;
    setStatus('loading');
    try {
      const reg = await navigator.serviceWorker.ready;
      const sub = await reg.pushManager.getSubscription();
      if (sub) {
        // Prima il server (se fallisce si resta iscritti, stato coerente), poi il browser.
        await authFetch<void>('/api/push-subscriptions/unsubscribe', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ platform: 'web', endpoint: sub.endpoint }),
        });
        await sub.unsubscribe();
      }
      setStatus('unsubscribed');
    } catch (err) {
      setStatus('subscribed');
      throw err;
    }
  }, [supported]);

  return { status, subscribe, unsubscribe };
}
