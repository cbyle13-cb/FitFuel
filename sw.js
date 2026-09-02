// Retire the legacy cache-first worker: authenticated FitFuel requires live responses.
self.addEventListener("install", event => {
  event.waitUntil(self.skipWaiting());
});
self.addEventListener("activate", event => {
  event.waitUntil((async () => {
    await caches.delete("fitfuel-v5");
    await self.clients.claim();
    await self.registration.unregister();
  })());
});
// No fetch handler: page and API requests go directly to the network.
