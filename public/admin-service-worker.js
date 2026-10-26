'use strict';

self.addEventListener('push', (event) => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch (_) { data = {}; }
  const title = data.title || 'La Măriuca Boutique';
  event.waitUntil(self.registration.showNotification(title, {
    body: data.body || 'Ai un eveniment nou în magazin.',
    icon: data.icon,
    badge: data.badge,
    tag: data.tag || 'la-mariuca-admin',
    renotify: true,
    requireInteraction: data.type === 'comenzi' || data.type === 'plati',
    vibrate: [90, 45, 90],
    data: { target: data.target || 'admin/notificari/' },
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = new URL(event.notification.data?.target || 'admin/notificari/', self.registration.scope).href;
  event.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
    for (const client of windows) {
      if ('focus' in client) {
        client.navigate(target);
        return client.focus();
      }
    }
    return clients.openWindow ? clients.openWindow(target) : undefined;
  }));
});
