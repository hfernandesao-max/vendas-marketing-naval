// Painel da sala comercial (ecrã de TV): ecrãs que rodam sozinhos a cada 20 segundos, dados a cada 2 minutos.
// Acesso só com a ligação criada no portal (Gestão › Painel da sala comercial). Reconhecimento positivo: mostra os
// melhores e quem mais subiu face a si próprio; nunca o último lugar.
(function () {
  'use strict';
  // o código vai no fragmento (#t=…): não chega aos registos do servidor; segue para a API num cabeçalho
  var codigo = new URLSearchParams(location.hash.slice(1)).get('t') || new URLSearchParams(location.search).get('t') || '';
  if (location.search.indexOf('t=') >= 0 && history.replaceState) history.replaceState(null, '', location.pathname + '#t=' + codigo);
  var API = '../api/index.php/v1/sala';
  var D = null;
  var ecra = 0;
  var erro = '';
  var nf = new Intl.NumberFormat('pt-PT', { maximumFractionDigits: 0 });
  var MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
  var DIAS = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];

  function $(id) {
    return document.getElementById(id);
  }
  function el(tag, cls, txt) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (txt != null) e.textContent = txt;
    return e;
  }
  function kz(v) {
    return nf.format(Math.round(v || 0)) + ' Kz';
  }
  function pct(v) {
    if (v == null) return '';
    var p = Math.round(v * 100);
    return (p > 0 ? '+' : p < 0 ? '−' : '') + Math.abs(p) + '%';
  }
  function barra(frac) {
    var b = el('div', 's-barra'),
      i = el('i');
    i.style.width = '0%';
    b.appendChild(i);
    setTimeout(function () {
      i.style.width = Math.max(0, Math.min(100, Math.round(frac * 100))) + '%';
    }, 60);
    return b;
  }
  function nomeRota(r) {
    var n = el('div', 's-nome');
    n.appendChild(el('strong', null, 'Rota ' + r.rota));
    if (r.promotor) n.appendChild(el('span', null, r.promotor));
    return n;
  }

  function ecraHoje() {
    var h = D.hoje;
    var out = [el('h1', null, 'Vendas de hoje')];
    out.push(el('div', 's-gigante', kz(h.total)));
    if (h.previsto) {
      out.push(barra(Math.min(1, h.total / h.previsto)));
      out.push(
        el('p', 's-sub', Math.round((h.total / h.previsto) * 100) + '% da previsão do dia (' + kz(h.previsto) + ') · ' + nf.format(h.documentos) + ' vendas'),
      );
    } else out.push(el('p', 's-sub', nf.format(h.documentos) + ' vendas'));
    var g = el('div', 's-grelha');
    [
      ['Ontem', D.ontem.total, D.ontem.variacao, 'face ao habitual'],
      ['Esta semana', D.semana.total, D.semana.variacao, 'face à semana passada'],
      ['Este mês', D.mes.total, D.mes.variacao, 'face ao mês passado'],
    ].forEach(function (x) {
      var c = el('div', 's-cartao');
      c.appendChild(el('div', 'r', x[0]));
      c.appendChild(el('div', 'v', kz(x[1])));
      if (x[2] != null && x[2] > 0) c.appendChild(el('div', 'r', pct(x[2]) + ' ' + x[3]));
      g.appendChild(c);
    });
    out.push(g);
    var c = D.compras && D.compras.hoje;
    if (c && c.compras)
      out.push(
        el(
          'p',
          's-sub',
          'Hoje: ' +
            nf.format(c.compras) +
            ' clientes compraram · ' +
            kz(c.valor_medio) +
            ' por compra' +
            (c.artigos_por_compra ? ' · ' + String(c.artigos_por_compra).replace('.', ',') + ' artigos por compra' : ''),
        ),
      );
    return out;
  }
  function ecraRanking(titulo, sub, campo, extra) {
    var rs = D.rotas
      .filter(function (r) {
        return r[campo] > 0;
      })
      .sort(function (a, b) {
        return b[campo] - a[campo];
      })
      .slice(0, 10);
    var out = [el('h1', null, titulo), el('p', 's-sub', sub)];
    if (!rs.length) {
      out.push(el('p', 's-msg', 'Ainda sem vendas registadas.'));
      return out;
    }
    var max = rs[0][campo];
    var lista = el('div', 's-rank');
    rs.forEach(function (r, i) {
      var l = el('div', 's-linha');
      l.appendChild(el('div', 's-pos', String(i + 1)));
      l.appendChild(nomeRota(r));
      l.appendChild(barra(r[campo] / max));
      l.appendChild(el('div', 's-valor', kz(r[campo])));
      l.appendChild(el('div', 's-var', extra(r)));
      lista.appendChild(l);
    });
    out.push(lista);
    return out;
  }
  function ecraDestaques() {
    var out = [el('h1', null, 'Destaques'), el('p', 's-sub', 'Cada rota comparada consigo própria: todas podem ganhar.')];
    var d = el('div', 's-destaques');
    function destaque(t, n, txt) {
      var c = el('div', 's-destaque');
      c.appendChild(el('div', 't', t));
      c.appendChild(el('div', 'n', n));
      c.appendChild(el('div', 'd', txt));
      d.appendChild(c);
    }
    var sobe = D.rotas
      .filter(function (r) {
        return r.mes_anterior > 0 && r.variacao_mes > 0;
      })
      .sort(function (a, b) {
        return b.variacao_mes - a.variacao_mes;
      })[0];
    if (sobe)
      destaque('Maior subida do mês', 'Rota ' + sobe.rota + (sobe.promotor ? ' · ' + sobe.promotor : ''), pct(sobe.variacao_mes) + ' face ao mês passado');
    var hoje = D.rotas.slice().sort(function (a, b) {
      return b.hoje - a.hoje;
    })[0];
    if (hoje && hoje.hoje > 0) destaque('Melhor de hoje', 'Rota ' + hoje.rota + (hoje.promotor ? ' · ' + hoje.promotor : ''), kz(hoje.hoje));
    var seq = D.rotas
      .filter(function (r) {
        return r.sequencia >= 3;
      })
      .sort(function (a, b) {
        return b.sequencia - a.sequencia;
      })[0];
    if (seq) destaque('Em sequência', 'Rota ' + seq.rota + (seq.promotor ? ' · ' + seq.promotor : ''), seq.sequencia + ' dias seguidos acima do habitual');
    var acima = D.rotas.filter(function (r) {
      return r.habitual_ontem > 0 && r.ontem > r.habitual_ontem;
    });
    if (acima.length)
      destaque(
        'Ontem acima do habitual',
        acima.length + (acima.length === 1 ? ' rota' : ' rotas'),
        acima
          .slice(0, 6)
          .map(function (r) {
            return r.rota;
          })
          .join(' · '),
      );
    if (!d.children.length) out.push(el('p', 's-msg', 'Os destaques aparecem com o andar do dia.'));
    else out.push(d);
    return out;
  }

  var ECRAS = [
    ecraHoje,
    function () {
      return ecraRanking('Classificação de hoje', 'Vendas de hoje até agora', 'hoje', function (r) {
        return r.previsto_hoje ? Math.round((r.hoje / r.previsto_hoje) * 100) + '% do previsto' : '';
      });
    },
    function () {
      return ecraRanking('Classificação do mês', MESES[new Date().getMonth()] + ' até hoje', 'mes', function (r) {
        return r.variacao_mes != null && r.variacao_mes > 0 ? pct(r.variacao_mes) : '';
      });
    },
    ecraDestaques,
  ];

  function desenhar() {
    var m = $('s-ecra');
    var novo = m.cloneNode(false);
    if (erro) novo.appendChild(el('p', 's-msg', erro));
    else if (!D) novo.appendChild(el('p', 's-msg', 'A carregar…'));
    else
      ECRAS[ecra % ECRAS.length]().forEach(function (x) {
        novo.appendChild(x);
      });
    m.parentNode.replaceChild(novo, m);
    var p = $('s-pontos');
    p.textContent = '';
    ECRAS.forEach(function (x, i) {
      p.appendChild(el('i', i === ecra % ECRAS.length ? 'on' : ''));
    });
  }
  function carregar() {
    fetch(API, { cache: 'no-store', credentials: 'omit', referrerPolicy: 'no-referrer', headers: { 'X-Painel-Codigo': codigo } })
      .then(function (r) {
        return r.json().then(function (j) {
          if (!r.ok || !j.ok) throw new Error(j.erro || 'HTTP ' + r.status);
          D = j;
          erro = '';
          $('s-act').textContent = 'Actualizado às ' + String(j.hora).slice(11, 16) + ' · vendas do Pinguim BO';
        });
      })
      .catch(function (e) {
        // sem rede: continua a mostrar os últimos dados; ligação inválida: mostra o motivo
        if (!D || /revogada|inválida/.test(String(e.message))) erro = String(e.message || e);
      })
      .then(desenhar);
  }
  function relogio() {
    var d = new Date();
    $('s-hora').textContent = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
    $('s-data').textContent = DIAS[d.getDay()] + ', ' + d.getDate() + ' de ' + MESES[d.getMonth()];
  }
  relogio();
  setInterval(relogio, 10000);
  carregar();
  setInterval(carregar, 120000);
  setInterval(function () {
    ecra++;
    desenhar();
  }, 20000);
  // um ecrã ligado dias seguidos: recarrega a página de madrugada (versões novas do painel)
  setInterval(function () {
    if (new Date().getHours() === 4 && performance.now() > 3600000) location.reload();
  }, 600000);
})();
