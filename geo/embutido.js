// Script clássico (não módulo), no <head> de cada página GEO, antes de desenhar.
/* eslint-disable no-var */
// As páginas GEO abrem dentro do portal (mesmo menu, mesmo tema, mesma sessão: nada de nova entrada).
//  - Dentro do portal (quadro da própria origem): esconde a barra própria do GEO; o menu é o do portal.
//  - Aberta sozinha (endereço antigo, favorito, ligação directa): passa para o portal, na mesma página.
// O tema segue a escolha feita no portal (vm-tema), também quando muda com a página aberta.
(function () {
  var s = document.currentScript;
  var rota = (s && s.getAttribute('data-rota')) || 'geo-rotas';
  var dentro = false;
  try {
    dentro = window.self !== window.top && window.top.location.origin === window.location.origin;
  } catch (e) {
    dentro = false;
  }
  // página de entrada dentro do quadro (sessão terminada): abre-se no portal inteiro
  if (rota === 'login') {
    if (window.self !== window.top) window.top.location.replace(location.href);
    return;
  }
  if (!dentro) {
    var portal = new URL('../reports/#' + rota, s ? s.src : location.href);
    location.replace(portal.href);
    return;
  }
  document.documentElement.classList.add('geo-embutido');
  // tema do portal (Sistema / Claro / Escuro)
  function tema() {
    var v = 'sistema';
    try {
      v = localStorage.getItem('vm-tema') || 'sistema';
    } catch (e) {}
    var claro = v === 'claro' || (v !== 'escuro' && window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches);
    document.documentElement.setAttribute('data-theme', claro ? 'light' : 'dark');
  }
  tema();
  window.addEventListener('storage', function (e) {
    if (e.key === 'vm-tema') tema();
  });
})();
