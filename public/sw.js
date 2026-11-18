/*
 * RzSense service worker (1.285.0): toont alleen Web Push-meldingen. Geen cache en niets offline:
 * RzSense werkt verder precies zoals zonder service worker.
 *
 * Een bericht (JSON, versleuteld door RzSense, ontsleuteld door de browser):
 *   t titel · b tekst · u adres bij tikken · a bevestig-adres (al = tekst knop) · o RzSense openen (ol = tekst knop)
 *   q stil · r blijft staan tot je hem wegklikt · ts tijd (seconden)
 */
'use strict';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('push', (e) => {
  let d;
  try {
    d = e.data ? e.data.json() : {};
  } catch (_) {
    d = { t: 'RzSense', b: e.data ? e.data.text() : '' };
  }
  const actions = [];
  if (d.a) actions.push({ action: 'ack', title: String(d.al || '✔') });
  if (d.o) actions.push({ action: 'open', title: String(d.ol || 'RzSense') });
  e.waitUntil(self.registration.showNotification(String(d.t || 'RzSense'), {
    body: String(d.b || ''),
    icon: 'icon-192.png',
    badge: 'icon-192.png',
    silent: !!d.q,
    requireInteraction: !!d.r,
    timestamp: d.ts ? d.ts * 1000 : Date.now(),
    data: { url: d.u || d.o || './', ack: d.a || null, open: d.o || './' },
    actions,
  }));
});

// Alleen http(s)-adressen openen; een bestaand venster met hetzelfde adres krijgt de focus
function safeUrl(u) {
  try {
    const x = new URL(u, self.registration.scope);
    return x.protocol === 'https:' || x.protocol === 'http:' ? x.href : self.registration.scope;
  } catch (_) {
    return self.registration.scope;
  }
}

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const dt = e.notification.data || {};
  const url = safeUrl(e.action === 'ack' ? dt.ack : e.action === 'open' ? dt.open : dt.url);
  e.waitUntil((async () => {
    const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of all) {
      if (c.url === url && 'focus' in c) return c.focus();
    }
    return self.clients.openWindow(url);
  })());
});
