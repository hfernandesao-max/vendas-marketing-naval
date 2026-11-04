// Script clássico (não módulo), corre antes de desenhar a página: var de propósito, para qualquer navegador.
/* eslint-disable no-var */
// Tema do portal (Sistema / Claro / Escuro), aplicado antes do primeiro desenho para não haver clarão.
// A escolha fica no navegador (vm-tema), partilhada pelo portal, pela página de escolha e pela página de entrada.
// «Sistema» segue a preferência do sistema operativo e muda sozinho quando ela muda.
(function () {
  var K = 'vm-tema';
  var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: light)') : null;
  function escolha() {
    try {
      var v = localStorage.getItem(K);
      return v === 'claro' || v === 'escuro' ? v : 'sistema';
    } catch (e) {
      return 'sistema';
    }
  }
  function aplicar() {
    var e = escolha();
    var claro = e === 'claro' || (e === 'sistema' && !!mq && mq.matches);
    document.documentElement.setAttribute('data-theme', claro ? 'light' : 'dark');
  }
  aplicar();
  // menu lateral (modo e posição) escolhido pela pessoa: aplicado já, para o primeiro desenho sair certo
  try {
    var m = JSON.parse(localStorage.getItem('vm-menu') || '{}');
    document.documentElement.setAttribute('data-barra', ['fixa', 'compacta', 'oculta'].indexOf(m.barra) >= 0 ? m.barra : 'fixa');
    document.documentElement.setAttribute('data-lado', m.lado === 'direita' ? 'direita' : 'esquerda');
  } catch (e) {
    document.documentElement.setAttribute('data-barra', 'fixa');
  }
  if (mq && mq.addEventListener) mq.addEventListener('change', aplicar);
  window.vmTema = {
    escolha: escolha,
    definir: function (v) {
      try {
        localStorage.setItem(K, v);
      } catch (e) {}
      aplicar();
    },
  };
})();
