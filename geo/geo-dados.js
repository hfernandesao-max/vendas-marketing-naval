// Script clássico, antes dos scripts das páginas de Zonas e Distribuidores.
/* eslint-disable no-var */
// Dados das páginas GEO pela API do portal, com a mesma lógica do resto do portal:
//  - clientes com coordenadas, rota, dia de visita e estado lidos do Pinguim BO (v1/geo-clientes), nunca de ficheiros;
//  - zonas de entrega guardadas no servidor (v1/geo-zonas), partilhadas por todos e com registo de quem alterou,
//    em vez de ficarem só no navegador de quem as desenhou.
(function () {
  var base = new URL('../', document.currentScript ? document.currentScript.src : location.href); // pasta geo/
  var API = new URL('../api/index.php/v1/', base).href;
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var token = null;
  var ultimas = '[]';
  function pedirToken() {
    return fetch(API + 'auth/token', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: '{}',
    }).then(function (r) {
      return r.json().then(function (d) {
        if (!r.ok || !d.access_token) throw new Error(d.erro || 'Sessão terminada. Entre de novo.');
        token = d.access_token;
      });
    });
  }
  function api(rota, init) {
    var ir = function () {
      var o = init || {};
      return fetch(API + rota, {
        method: o.method || 'GET',
        credentials: 'same-origin',
        headers: Object.assign({ Authorization: 'Bearer ' + token }, o.body ? { 'Content-Type': 'application/json' } : {}),
        body: o.body,
      });
    };
    return (token ? Promise.resolve() : pedirToken())
      .then(ir)
      .then(function (r) {
        if (r.status !== 401) return r;
        return pedirToken().then(ir);
      })
      .then(function (r) {
        return r.json().catch(function () {
          return { ok: false, erro: 'Resposta inválida do servidor.' };
        });
      });
  }
  var temporizador = null;
  window.NavalGeo = {
    /** Clientes com coordenadas (estado: Activo / Inactivo / Todos), no formato das páginas do mapa. */
    clientes: function (estado) {
      return api('geo-clientes').then(function (d) {
        if (!d || !d.ok) throw new Error((d && d.erro) || 'Não foi possível ler os clientes.');
        return (d.clientes || []).filter(function (c) {
          return !estado || estado === 'Todos' || String(c.Estado).toLowerCase() === String(estado).toLowerCase();
        });
      });
    },
    /** Zonas guardadas no servidor. */
    zonas: function () {
      return api('geo-zonas').then(function (d) {
        var z = d && d.ok ? d.zonas || [] : [];
        ultimas = JSON.stringify(z);
        return z;
      });
    },
    /** Guarda as zonas no servidor (agrupa as alterações seguidas num só pedido). */
    gravarZonas: function (dados) {
      ultimas = JSON.stringify(dados || []);
      clearTimeout(temporizador);
      temporizador = setTimeout(function () {
        api('geo-zonas', { method: 'POST', body: JSON.stringify({ zonas: JSON.parse(ultimas), csrf: csrf }) }).then(function (d) {
          if (d && d.ok === false) console.warn('[zonas] ' + (d.erro || 'não guardadas'));
        });
      }, 600);
    },
    /** Zonas actuais (texto JSON), para exportar uma cópia. */
    zonasActuais: function () {
      return ultimas;
    },
  };
})();
