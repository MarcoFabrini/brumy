/**
 * Helper Web Push condivisi da `usePushSubscription` (iscrizione dalle Impostazioni) e da `pushResync`
 * (ri-registrazione della subscription già presente all'inizio di una sessione).
 */

export function urlBase64ToUint8Array(base64: string): Uint8Array {
  const padding = '='.repeat((4 - (base64.length % 4)) % 4);
  const b64 = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/');
  const raw = atob(b64);
  // Backing su ArrayBuffer esplicito (TS strict: no SharedArrayBuffer)
  const arr = new Uint8Array(new ArrayBuffer(raw.length));
  for (let i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
  return arr;
}

export function bufferToBase64(buf: ArrayBuffer | null): string {
  if (!buf) return '';
  return btoa(String.fromCharCode(...new Uint8Array(buf)));
}

/** True se la subscription del browser è stata creata con una chiave VAPID diversa da quella corrente. */
export function hasDifferentServerKey(sub: PushSubscription, vapidKey: string): boolean {
  const current = sub.options?.applicationServerKey;
  if (!current) return false;
  const a = new Uint8Array(current);
  const b = urlBase64ToUint8Array(vapidKey);
  return a.length !== b.length || a.some((byte, i) => byte !== b[i]);
}

/** Corpo di `POST /api/push-subscriptions` per la subscription web di questo browser (upsert per endpoint). */
export function webSubscriptionBody(sub: PushSubscription): string {
  return JSON.stringify({
    platform: 'web',
    endpoint: sub.endpoint,
    p256dh: bufferToBase64(sub.getKey('p256dh')),
    authSecret: bufferToBase64(sub.getKey('auth')),
    deviceLabel: navigator.userAgent.split(' ').slice(-1)[0],
  });
}

/**
 * Su questo dispositivo l'utente ha acceso le notifiche e non le ha spente: si salva l'endpoint dell'ultima
 * subscription. Serve perché il browser può perderla da solo (iPhone, dopo un aggiornamento dell'app o un riavvio:
 * `getSubscription()` torna null con il permesso ancora concesso); con questo segno `pushResync` la ricrea senza
 * chiedere niente all'utente e toglie dal server quella vecchia. Sta sul dispositivo e non sul server perché è una
 * scelta di questo dispositivo, che il server riconosce solo dall'endpoint, cioè proprio quello che cambia.
 */
const PUSH_WANTED_KEY = 'brumy.push.endpoint';

export function rememberedPushEndpoint(): string | null {
  try {
    return localStorage.getItem(PUSH_WANTED_KEY);
  } catch {
    return null;
  }
}

export function rememberPushEndpoint(endpoint: string): void {
  try {
    localStorage.setItem(PUSH_WANTED_KEY, endpoint);
  } catch {
    // Storage non disponibile: le notifiche funzionano, solo non si ripristinano da sole
  }
}

export function forgetPushEndpoint(): void {
  try {
    localStorage.removeItem(PUSH_WANTED_KEY);
  } catch {
    // ignorato
  }
}
