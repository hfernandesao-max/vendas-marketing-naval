/*
 * Service worker do Portal RTM (apps instaladas pelo código QR do portal, no iPhone ou no Android).
 * Regra de segurança: só guarda em cache ficheiros estáticos públicos da app (ícones e a página «Sem ligação»).
 * Mostra também as notificações do portal (Web Push), que chegam cifradas e só são lidas no telefone.
 * Páginas PHP, a API, fichas, documentos e assinaturas vão SEMPRE à rede e nunca ficam guardados no telefone.
 * Sem rede, mostra-se a página «Sem ligação» e não se assina.
 */
const VERSAO = 'rtm-app-v3';
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

// ── Notificações (Web Push) ──────────────────────────────────────────────────────────────────────────────────────
// A mensagem chega cifrada do servidor do portal (RFC 8291) e o telefone decifra-a antes de chegar aqui. Mostra-a
// sempre (regra do iPhone e do Android: cada push tem de aparecer), põe o número de por ler no ícone da app e, ao
// tocar, abre a app no ecrã certo (ou traz para a frente a que já estiver aberta).
self.addEventListener('push', (e) => {
  let m = {};
  try {
    m = e.data ? e.data.json() : {};
  } catch {
    m = { titulo: 'Portal RTM', corpo: e.data ? e.data.text() : '' };
  }
  const titulo = m.titulo || 'Portal RTM';
  const opcoes = {
    body: m.corpo || '',
    icon: BASE + 'app/icones/icone-192.png',
    badge: BASE + 'app/icones/icone-192.png',
    tag: m.tag || m.id || 'naval',
    renotify: true,
    requireInteraction: m.gravidade === 'critico',
    timestamp: m.data ? Date.parse(String(m.data).replace(' ', 'T')) || Date.now() : Date.now(),
    data: { url: new URL(m.url || 'gestao/#alertas', BASE + 'app/').href, id: m.id || '' },
  };
  const numero = typeof m.nao_lidas === 'number' && 'setAppBadge' in self.navigator ? self.navigator.setAppBadge(m.nao_lidas).catch(() => {}) : Promise.resolve();
  e.waitUntil(Promise.all([self.registration.showNotification(titulo, opcoes), numero]));
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const url = (e.notification.data && e.notification.data.url) || BASE + 'gestao/';
  e.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((ws) => {
      const app = ws.find((w) => w.url.startsWith(BASE + 'gestao/'));
      if (app) return app.focus().then((w) => (w && 'navigate' in w ? w.navigate(url) : null));
      return self.clients.openWindow(url);
    }),
  );
});

// o telefone renovou a subscrição (raro): a app volta a registá-la da próxima vez que abrir
self.addEventListener('pushsubscriptionchange', () => {});
