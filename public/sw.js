const CACHE_NAME = 'walkie-talkie-v13';
const urlsToCache = [
  '/',
  '/embed.php',
  '/assets/style.css',
  '/assets/embed.css',
  '/assets/walkie-talkie.js',
  '/assets/walkie-talkie.svg',
  '/assets/icon-192.png',
  '/assets/icon-512.png',
  '/manifest.json',
];

// Store last active channel and app state
let lastActiveChannel = '1';
let isAppActive = true;
let hasNotificationPermission = false;

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(urlsToCache))
  );
});

self.addEventListener('fetch', (event) => {
  event.respondWith(
    caches.match(event.request)
      .then((response) => {
        if (response) {
          return response;
        }
        return fetch(event.request);
      }
    )
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          if (cacheName !== CACHE_NAME) {
            return caches.delete(cacheName);
          }
        })
      );
    })
  );
});

// Handle messages from the main app
self.addEventListener('message', (event) => {
  const { type, data } = event.data;

  switch (type) {
    case 'SKIP_WAITING':
      self.skipWaiting();
      break;

    case 'APP_STATE_CHANGED':
      isAppActive = data.isActive;
      break;

    case 'CHANNEL_CHANGED':
      lastActiveChannel = data.channel;
      break;

    case 'TRANSMISSION_STARTED':
      if (!isAppActive && hasNotificationPermission && data.channel === lastActiveChannel) {
        showTransmissionNotification(data.channel);
      }
      break;

    case 'NOTIFICATION_PERMISSION':
      hasNotificationPermission = data.granted;
      break;
  }
});

// Show notification for new transmission
async function showTransmissionNotification(channel) {
  try {
    const title = '📻 Walkie Talkie';
    const options = {
      body: `New transmission on Channel ${channel}`,
      icon: '/assets/icon-192.png',
      badge: '/assets/icon-192.png',
      tag: 'walkie-transmission',
      renotify: true,
      requireInteraction: false,
      actions: [
        {
          action: 'open',
          title: 'Open App'
        },
        {
          action: 'dismiss',
          title: 'Dismiss'
        }
      ],
      data: {
        channel: channel,
        timestamp: Date.now()
      }
    };

    await self.registration.showNotification(title, options);
    console.log(`Notification shown for transmission on channel ${channel}`);

  } catch (error) {
    console.error('Failed to show notification:', error);
  }
}

// Handle notification clicks
self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  const { action, data } = event;
  const channel = data?.channel || '1';

  if (action === 'open' || !action) {
    // Open or focus the walkie talkie app
    event.waitUntil(
      clients.matchAll({ type: 'window', includeUncontrolled: true })
        .then((clientList) => {
          // Check if app is already open
          for (const client of clientList) {
            if (client.url.includes('walkie-talkie') && 'focus' in client) {
              return client.focus();
            }
          }

          // Open new window if app not found
          if (clients.openWindow) {
            return clients.openWindow(`/?channel=${channel}`);
          }
        })
    );
  }
});

// Handle notification close
self.addEventListener('notificationclose', (event) => {
  console.log('Notification closed:', event.notification.tag);
});