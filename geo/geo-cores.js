// Cores das rotas na GeoMobilidade: uma paleta qualitativa de 11 cores bem distintas (uma por vendedor), sempre a mesma
// cor para a mesma rota em todas as páginas e perfis (pelo código da rota, não pela ordem da lista). Vermelho e âmbar
// ficam livres para os avisos (zonas sem rota, fora da geofence) e para os potenciais clientes.
(function () {
  'use strict';
  var PALETA = ['#1f77b4', '#2ca02c', '#9467bd', '#17becf', '#8c564b', '#e377c2', '#393b79', '#bcbd22', '#637939', '#7f7f7f', '#ad6f00'];
  function indice(codigo) {
    var s = String(codigo == null ? '' : codigo);
    var n = parseInt(s, 10);
    if (String(n) === s) return ((n % PALETA.length) + PALETA.length) % PALETA.length;
    var h = 0;
    for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
    return h % PALETA.length;
  }
  window.NavalCores = {
    PALETA: PALETA,
    /** Cor de uma rota ('' = sem rota: cinzento). */
    rota: function (codigo) {
      return codigo === '' || codigo == null ? '#9aa3b2' : PALETA[indice(codigo)];
    },
    /** Cor pela posição numa lista (distribuidores): repete a paleta. */
    posicao: function (i) {
      return PALETA[i % PALETA.length];
    },
    AVISO: '#c0262d',
    POTENCIAL: '#f5b547',
  };
})();
