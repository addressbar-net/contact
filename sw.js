// Site-Aware Service Worker (PWA & Web Push)
// Automatically adapts to any domain and installation directory.
const CACHE_NAME = 'site-app-v3';

self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(clients.claim());
});

// Push Notification Event Handler
// Notifications are issued under the base website domain name.
self.addEventListener('push', (event) => {
  const baseDomain = self.location.hostname;
  const appScope = self.registration.scope;

  let title = baseDomain;
  let options = {
    body: 'You have received a new message in your inbox.',
    icon: new URL('icon-192.png', appScope).href,
    badge: new URL('icon-192.png', appScope).href,
    tag: 'msg-' + Date.now(),
    renotify: true,
    data: { url: new URL('index.php?tab=inbox', appScope).href }
  };

  // If payload is present directly in the push event
  if (event.data) {
    try {
      const payload = event.data.json();
      if (payload.title) title = payload.title;
      if (payload.body) options.body = payload.body;
      if (payload.url) options.data.url = new URL(payload.url, appScope).href;
    } catch (e) {
      const text = event.data.text();
      if (text) options.body = text;
    }
    event.waitUntil(self.registration.showNotification(title, options));
    return;
  }

  // If wake-up ping, fetch latest notification from the specific app directory
  const fetchUrl = new URL('index.php?action=get_latest_notification', appScope).href;
  event.waitUntil(
    fetch(fetchUrl)
      .then((res) => {
        if (!res.ok) throw new Error('Network response not ok');
        return res.json();
      })
      .then((data) => {
        if (data && data.success) {
          title = data.title || title;
          options.body = data.body || options.body;
          if (data.url) options.data.url = new URL(data.url, appScope).href;
        }
        return self.registration.showNotification(title, options);
      })
      .catch(() => {
        return self.registration.showNotification(title, options);
      })
  );
});

// Notification Click Handler - Only opens/focuses the app directory
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const appScope = self.registration.scope;
  const targetUrl = (event.notification.data && event.notification.data.url)
    ? new URL(event.notification.data.url, appScope).href
    : new URL('index.php?tab=inbox', appScope).href;

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      // Look for an open window specifically within this installation directory
      for (let i = 0; i < clientList.length; i++) {
        const client = clientList[i];
        if (client.url.startsWith(appScope) && 'focus' in client) {
          return client.focus();
        }
      }
      // If not currently open, open ONLY the app directory
      if (clients.openWindow) {
        return clients.openWindow(targetUrl);
      }
    })
  );
});
