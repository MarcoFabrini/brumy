/// <reference lib="webworker" />
import { cleanupOutdatedCaches, createHandlerBoundToURL, precacheAndRoute } from 'workbox-precaching';
import { NavigationRoute, registerRoute } from 'workbox-routing';
import { sameOriginPushUrl } from './lib/pushUrl';

declare const self: ServiceWorkerGlobalScope;

// Auto-injected manifest da vite-plugin-pwa
precacheAndRoute(self.__WB_MANIFEST);
// Le cache di precache delle build precedenti non si accumulano a ogni deploy
cleanupOutdatedCaches();

// SPA: i deep link (/vehicles/3) aperti offline o da una notifica servono index.html.
// Le API non sono mai navigazioni SPA.
registerRoute(
  new NavigationRoute(createHandlerBoundToURL('/index.html'), {
    denylist: [/^\/api\//],
  }),
);

// Il nuovo worker si attiva subito e prende il controllo delle pagine aperte (clients.claim): le pagine
// già aperte restano però sul JS vecchio finché non ricaricano. Il `controllerchange` che ne segue fa
// comparire l'avviso "Nuova versione disponibile" (lib/pwaUpdate), che ricarica su richiesta dell'utente.
self.addEventListener('install', () => {
  void self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

interface PushData {
  title?: string;
  body?: string;
  url?: string;
  icon?: string;
}

/** Payload non JSON o vuoto: ricade su testo semplice, così la notifica viene mostrata comunque. */
function readPushData(event: PushEvent): PushData {
  if (!event.data) return {};
  try {
    return (event.data.json() ?? {}) as PushData;
  } catch {
    return { body: event.data.text() };
  }
}

// Web Push handler
self.addEventListener('push', (event) => {
  const data = readPushData(event);

  event.waitUntil(
    self.registration.showNotification(data.title ?? 'Brumy', {
      body: data.body ?? '',
      icon: data.icon ?? '/icons/icon-192.png',
      badge: '/icons/icon-192.png',
      data: { url: data.url ?? '/' },
    }),
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  // L'URL arriva dal payload del push: si naviga solo dentro la stessa origine.
  const url = sameOriginPushUrl((event.notification.data as { url?: unknown } | null)?.url, self.location.origin);

  event.waitUntil(
    (async () => {
      // Riusa la finestra della PWA già aperta invece di aprirne una nuova a ogni tap
      const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
      const existing = windows.find((w) => new URL(w.url).origin === self.location.origin);
      if (existing) {
        await existing.focus();
        if ('navigate' in existing) await existing.navigate(url);
        return;
      }
      await self.clients.openWindow(url);
    })(),
  );
});
