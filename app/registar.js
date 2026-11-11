// Apps instaladas pelo código QR do portal (PWA): regista o service worker da raiz do portal (sw.js), que só guarda ícones e a página
// «Sem ligação». Fichas, documentos e assinaturas vão sempre à rede e nunca ficam guardados no telefone.
if ('serviceWorker' in navigator && window.isSecureContext) {
  const sw = new URL('../sw.js', document.currentScript.src);
  const registar = () => navigator.serviceWorker.register(sw.href).catch(() => {});
  // depois de a página aparecer (não disputa a rede com o que a pessoa vai ver)
  if (document.readyState === 'complete') registar();
  else window.addEventListener('load', registar);
}
