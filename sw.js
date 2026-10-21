const CACHE_NAME = 'nuvis-studiox-v5.0.2';
const urlsToCache = [
  './assets/css/nubuilder-next.css',
  './assets/js/nubuilder-next.js'
];

// Paths that must NEVER be cached or intercepted by the SW
const AUTH_PATHS = [
  'index.php',
  'auth.php',
  'debug_login.php',
  'debug_session.php'
];

function isAuthRequest(request) {
  // Never intercept non-GET requests (login POST etc.)
  if (request.method !== 'GET') return true;

  try {
    var url = new URL(request.url);
    var pathname = url.pathname;

    // Block any auth-related path
    for (var i = 0; i < AUTH_PATHS.length; i++) {
      if (pathname.endsWith(AUTH_PATHS[i])) return true;
    }

    // Block if query string contains auth keywords
    var search = url.search;
    if (search.indexOf('login') !== -1 ||
        search.indexOf('logout') !== -1 ||
        search.indexOf('action=logout') !== -1) {
      return true;
    }
  } catch (e) {
    return true;
  }

  return false;
}

self.addEventListener('install', function(event) {
  event.waitUntil(
    caches.open(CACHE_NAME).then(function(cache) {
      return Promise.all(
        urlsToCache.map(function(url) {
          return fetch(url, { cache: 'no-cache' }).then(function(response) {
            if (!response.ok) {
              throw new Error('Failed to fetch: ' + url + ' (' + response.status + ')');
            }
            return cache.put(url, response.clone());
          });
        })
      );
    }).catch(function(err) {
      console.error('Service worker install cache error:', err);
    })
  );
  self.skipWaiting();
});

self.addEventListener('activate', function(event) {
  event.waitUntil(
    caches.keys().then(function(keys) {
      return Promise.all(
        keys
          .filter(function(key) { return key !== CACHE_NAME; })
          .map(function(key) {
            console.log('SW: deleting old cache', key);
            return caches.delete(key);
          })
      );
    })
  );
  self.clients.claim();
});

self.addEventListener('fetch', function(event) {
  if (isAuthRequest(event.request)) {
    return;
  }

  event.respondWith(
    caches.match(event.request).then(function(cached) {
      if (cached) return cached;
      return fetch(event.request).then(function(response) {
        if (!response || response.status !== 200 || response.type !== 'basic') {
          return response;
        }
        var url = event.request.url;
        var isStatic = url.indexOf('.css') !== -1 ||
                       url.indexOf('.js') !== -1 ||
                       url.indexOf('.woff') !== -1 ||
                       url.indexOf('.png') !== -1 ||
                       url.indexOf('.ico') !== -1;
        if (isStatic) {
          var copy = response.clone();
          caches.open(CACHE_NAME).then(function(cache) {
            cache.put(event.request, copy);
          });
        }
        return response;
      });
    })
  );
});

self.addEventListener('push', function(event) {
  var data = {
    title: 'Notification',
    body: '',
    url: '/',
    icon: './assets/icon-192.png',
    badge: './assets/icon-192.png'
  };

  if (event.data) {
    try {
      var payload = event.data.json();
      data.title = payload.title || data.title;
      data.body = payload.body || data.body;
      data.url = payload.url || data.url;
    } catch (e) {
      console.error('Push payload parse error:', e);
    }
  }

  event.waitUntil(
    self.registration.showNotification(data.title, {
      body: data.body,
      icon: data.icon,
      badge: data.badge,
      data: data.url
    })
  );
});

self.addEventListener('notificationclick', function(event) {
  event.notification.close();
  event.waitUntil(
    clients.openWindow(event.notification.data || './')
  );
});
