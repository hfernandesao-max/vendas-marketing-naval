// App «Equipamentos» (PWA): regista o service worker da raiz do portal (sw.js), que só guarda ícones e a página
// «Sem ligação». Fichas, documentos e assinaturas vão sempre à rede e nunca ficam guardados no telefone.
if ('serviceWorker' in navigator && window.isSecureContext) {
  const sw = new URL('../sw.js', document.currentScript.src);
  window.addEventListener('load', () => {
    navigator.serviceWorker.register(sw.href).catch(() => {});
  });
}
