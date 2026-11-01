/*
 * Service worker do Portal RTM (app «Equipamentos» instalada no iPhone ou no Android).
 * Regra de segurança: só guarda em cache ficheiros estáticos públicos da app (ícones e a página «Sem ligação»).
 * Páginas PHP, a API, fichas, documentos e assinaturas vão SEMPRE à rede e nunca ficam guardados no telefone.
 * Sem rede, mostra-se a página «Sem ligação» e não se assina.
 */
const VERSAO = 'rtm-app-v1';
const BASE = new URL('./', self.location).href;
const ESTATICOS = ['app/offline.html', 'app/offline.js', 'app/icones/icone-192.png', 'app/icones/apple-touch-icon-180.png'].map((p) => BASE + p);

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches
      .open(VERSAO)
      .then((c) => c.addAll(ESTATICOS))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches
      .keys()
      .then((ks) => Promise.all(ks.filter((k) => k !== VERSAO).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== self.location.origin) return; // POST e outros domínios: sem intervenção
  // Navegação (páginas): sempre à rede; sem rede, a página «Sem ligação»
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match(BASE + 'app/offline.html')));
    return;
  }
  // Estáticos da app: cache primeiro, rede como reserva
  if (url.href.startsWith(BASE + 'app/')) e.respondWith(caches.match(req).then((r) => r || fetch(req)));
  // Tudo o resto (PHP, API, PDF): comportamento normal do navegador, sem cache do service worker
});
