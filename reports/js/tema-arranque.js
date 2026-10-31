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
