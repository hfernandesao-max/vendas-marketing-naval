// Painel da sala comercial (ecrã de TV, 16:9, visto a 3–6 m). Regras seguidas (ecrãs de parede / «wallboards»):
//  - uma mensagem por ecrã, no máximo 6 números, o principal em destaque (canto superior esquerdo, o maior);
//  - letra medida pela altura do ecrã (vh), nunca abaixo de ~3 % da altura (legível a 5 m num ecrã de 55");
//  - contraste de texto ≥ 7:1 (WCAG 2.2 AAA); estado sempre com símbolo e palavra (▲ / ▼), nunca só com a cor;
//  - real contra o objectivo (previsão do dia) com barra e percentagem; valores grandes abreviados (1,2 M Kz);
//  - margens de segurança de 5 % (área útil dos televisores, EBU R 95); disposição igual em todos os ecrãs;
//  - rotação a ritmo fixo com barra de progresso e nome do ecrã seguinte; frescura dos dados sempre visível e
//    aviso quando os dados têm mais de 10 minutos; sem piscar (WCAG 2.3.1) e sem animação com «reduzir movimento»;
//  - protecção do ecrã: deslocação mínima periódica (marcação em ecrãs OLED/plasma) e brilho reduzido fora de horas;
//  - reconhecimento positivo: mostra os melhores e quem mais subiu face a si próprio; nunca o último lugar;
//  - sem clientes, documentos nem NIF (a API só devolve totais por rota);
//  - ecrã num espaço aberto (open space, recepção: visitantes podem ver): a API só envia percentagens, tendências e
//    contagens, sem valores em Kz, sem nomes e sem classificações; o painel mostra-os assim (ecraHojeAberta…);
//  - gráficos de barras simples (uma série, valor por cima, sem eixos a decifrar): ritmo por hora, 14 dias, visitas;
//  - percentagens absurdas (referência muito pequena) aparecem como «muito acima/abaixo do habitual», sem o número;
//  - pré-visualização no portal (#previa): os dados chegam da página do portal (postMessage), sem código de ligação.
// Acesso só com a ligação criada no portal (Gestão › Painel da sala comercial).
(function () {
  'use strict';
  // o código vai no fragmento (#t=…): não chega aos registos do servidor; segue para a API num cabeçalho
  var codigo = new URLSearchParams(location.hash.slice(1)).get('t') || new URLSearchParams(location.search).get('t') || '';
  if (location.search.indexOf('t=') >= 0 && history.replaceState) history.replaceState(null, '', location.pathname + '#t=' + codigo);
  var API = '../api/index.php/v1/sala';
  var D = null;
  var ecra = 0;
  var erro = '';
  var recebido = 0; // hora (ms) da última resposta boa
  var nf = new Intl.NumberFormat('pt-PT', { maximumFractionDigits: 0 });
  var nf1 = new Intl.NumberFormat('pt-PT', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  var nf2 = new Intl.NumberFormat('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  var MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
  var DIAS = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
  var VELHOS = 10 * 60000; // dados com mais de 10 minutos: aviso no rodapé
  var reduzir = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function $(id) {
    return document.getElementById(id);
  }
  function el(tag, cls, txt) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (txt != null) e.textContent = txt;
    return e;
  }
  // Kz por extenso (número principal) e abreviado (restantes): 30 539 500 Kz · 30,5 M Kz · 780 mil Kz
  function kz(v) {
    return nf.format(Math.round(v || 0)) + ' Kz';
  }
  function kzc(v) {
    var a = Math.abs(v || 0);
    if (a >= 1e9) return nf2.format(v / 1e9) + ' mM Kz';
    if (a >= 1e6) return (a >= 1e8 ? nf1 : nf2).format(v / 1e6) + ' M Kz';
    if (a >= 1e4) return nf.format(Math.round(v / 1e3)) + ' mil Kz';
    return kz(v);
  }
  // variação com símbolo e palavra (nunca só a cor)
  function variacao(v, ref) {
    var s = el('span', 's-delta');
    if (v == null || !isFinite(v)) return s;
    var p = Math.round(v * 100);
    s.className = 's-delta ' + (p > 0 ? 'sobe' : p < 0 ? 'desce' : 'igual');
    // referência muito pequena (ex.: início de actividade): o número não diz nada a quem olha
    if (Math.abs(p) > 300) s.appendChild(el('b', null, p > 0 ? '▲ muito acima' : '▼ muito abaixo'));
    else s.appendChild(el('b', null, (p > 0 ? '▲ +' : p < 0 ? '▼ −' : '= ') + Math.abs(p) + ' %'));
    s.appendChild(document.createTextNode(' ' + ref));
    return s;
  }
  function barra(frac, rotulo) {
    var b = el('div', 's-barra'),
      i = el('i');
    b.setAttribute('role', 'img');
    if (rotulo) b.setAttribute('aria-label', rotulo);
    i.style.width = Math.max(0, Math.min(100, Math.round(frac * 100))) + '%';
    b.appendChild(i);
    return b;
  }
  function nomeRota(r) {
    var n = el('div', 's-nome');
    n.appendChild(el('strong', null, 'Rota ' + r.rota));
    if (r.promotor) n.appendChild(el('span', null, r.promotor));
    return n;
  }
  function numero(rotulo, valor, extra, cls) {
    var c = el('div', 's-num' + (cls ? ' ' + cls : ''));
    c.appendChild(el('div', 's-rot', rotulo));
    if (valor !== '') c.appendChild(el('div', 's-val', valor));
    if (extra) c.appendChild(extra);
    return c;
  }

  // 1. Hoje: vendas do dia contra a previsão; ontem, semana e mês à direita; a compra média por baixo
  function ecraHoje() {
    var h = D.hoje;
    var esq = el('section', 's-hoje');
    esq.appendChild(el('div', 's-rot', 'Vendas de hoje até agora'));
    esq.appendChild(el('div', 's-gigante', kz(h.total)));
    if (h.previsto) {
      var p = h.total / h.previsto;
      esq.appendChild(barra(Math.min(1, p), Math.round(p * 100) + ' % da previsão do dia'));
      var lin = el('p', 's-meta');
      lin.appendChild(el('b', null, Math.round(p * 100) + ' %'));
      lin.appendChild(document.createTextNode(' da previsão do dia (' + kzc(h.previsto) + ')' + (p >= 1 ? ' · objectivo atingido ✔' : '')));
      esq.appendChild(lin);
    }
    var c = (D.compras && D.compras.hoje) || {};
    var g = el('div', 's-tres');
    g.appendChild(numero('Vendas', nf.format(h.documentos || 0)));
    g.appendChild(numero('Clientes com compras', nf.format(c.compras || 0)));
    g.appendChild(numero('Valor médio por compra', c.valor_medio ? kzc(c.valor_medio) : '—'));
    esq.appendChild(g);
    var dir = colunaComparacoes();
    if (!dir) {
      dir = el('section', 's-coluna');
      dir.appendChild(numero('Ontem', kzc(D.ontem.total), variacao(D.ontem.variacao, 'face ao habitual')));
      dir.appendChild(numero('Esta semana', kzc(D.semana.total), variacao(D.semana.variacao, 'face à semana passada')));
      dir.appendChild(numero('Este mês', kzc(D.mes.total), variacao(D.mes.variacao, 'face ao mês passado')));
    }
    var grelha = el('div', 's-grelha-hoje');
    grelha.appendChild(esq);
    grelha.appendChild(dir);
    return [grelha];
  }

  // coluna dos comparativos do ecrã «Vendas de hoje»: ontem, semana e mês, com as datas (null sem comparativos)
  function colunaComparacoes() {
    var o = comparacao('ontem_semana'),
      s = comparacao('semana'),
      m = comparacao('mes');
    if (!o && !s && !m) return null;
    var dir = el('section', 's-coluna');
    if (o) dir.appendChild(cartaoComparacao('Ontem × semana passada', o));
    if (s) dir.appendChild(cartaoComparacao('Semana até ontem', s));
    if (m) dir.appendChild(cartaoComparacao('Mês até ontem', m));
    return dir;
  }

  // 1b. Hoje num espaço aberto: progresso da equipa em percentagem e tendências (sem valores em Kz)
  function ecraHojeAberta() {
    var h = D.hoje || {};
    var esq = el('section', 's-hoje');
    esq.appendChild(el('div', 's-rot', 'Equipa RTM · objectivo de hoje'));
    if (h.progresso != null) {
      esq.appendChild(el('div', 's-gigante', h.progresso > 3 ? '> 300 %' : Math.round(h.progresso * 100) + ' %'));
      esq.appendChild(barra(Math.min(1, h.progresso), Math.round(h.progresso * 100) + ' % do objectivo do dia'));
      esq.appendChild(el('p', 's-meta', h.progresso >= 1 ? 'Objectivo do dia atingido ✔' : 'do objectivo do dia'));
    } else esq.appendChild(el('div', 's-gigante', '—'));
    var g = el('div', 's-tres');
    g.appendChild(numero('Vendas', nf.format(h.documentos || 0)));
    g.appendChild(numero('Clientes com compras', nf.format((D.compras && D.compras.hoje && D.compras.hoje.compras) || 0)));
    esq.appendChild(g);
    var dir = colunaComparacoes();
    if (!dir) {
      dir = el('section', 's-coluna');
      dir.appendChild(numero('Ontem', '', variacao(D.ontem.variacao, 'face ao habitual')));
      dir.appendChild(numero('Esta semana', '', variacao(D.semana.variacao, 'face à semana passada')));
      dir.appendChild(numero('Este mês', '', variacao(D.mes.variacao, 'face ao mês passado')));
    }
    var grelha = el('div', 's-grelha-hoje');
    grelha.appendChild(esq);
    grelha.appendChild(dir);
    return [grelha];
  }

  // 4b. Destaques num espaço aberto: por rota, sem nomes nem valores
  function ecraDestaquesAberta() {
    var out = [el('p', 's-legenda', 'Cada rota comparada consigo própria: todas podem ganhar.')];
    var d = el('div', 's-destaques');
    function destaque(icone, t, n, txt) {
      var c = el('div', 's-destaque');
      c.appendChild(el('div', 's-ico', icone));
      var b = el('div');
      b.appendChild(el('div', 's-rot', t));
      b.appendChild(el('div', 's-dn', n));
      b.appendChild(el('div', 's-dd', txt));
      c.appendChild(b);
      d.appendChild(c);
    }
    var R = D.rotas || [];
    var sobe = R.filter(function (r) {
      return r.variacao_mes > 0;
    }).sort(function (a, b) {
      return b.variacao_mes - a.variacao_mes;
    })[0];
    if (sobe) destaque('▲', 'Maior subida do mês', 'Rota ' + sobe.rota, '+' + Math.round(sobe.variacao_mes * 100) + ' % face ao mês passado');
    var obj = R.filter(function (r) {
      return r.progresso_hoje >= 1;
    });
    if (obj.length)
      destaque(
        '★',
        'Objectivo de hoje atingido',
        obj.length + (obj.length === 1 ? ' rota' : ' rotas'),
        obj
          .slice(0, 6)
          .map(function (r) {
            return r.rota;
          })
          .join(' · '),
      );
    var seq = R.filter(function (r) {
      return r.sequencia >= 3;
    }).sort(function (a, b) {
      return b.sequencia - a.sequencia;
    })[0];
    if (seq) destaque('●', 'Em sequência', 'Rota ' + seq.rota, seq.sequencia + ' dias seguidos acima do habitual');
    var acima = R.filter(function (r) {
      return r.ontem_acima;
    });
    if (acima.length)
      destaque(
        '✔',
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

  // Gráfico de barras (uma série): valor por cima, rótulo por baixo, a barra «destaque» em dourado
  function grafico(pontos, opc) {
    opc = opc || {};
    var g = el('div', 's-graf');
    var max = Math.max.apply(
      null,
      pontos
        .map(function (p) {
          return p.v || 0;
        })
        .concat([opc.ref || 0, 1]),
    );
    if (opc.ref) {
      var r = el('i', 's-graf-ref');
      r.style.bottom = (opc.ref / max) * 100 + '%';
      r.setAttribute('data-l', opc.refRotulo || '');
      g.appendChild(r);
    }
    pontos.forEach(function (p) {
      var c = el('div', 's-graf-col' + (p.destaque ? ' destaque' : ''));
      var b = el('div', 's-graf-b');
      b.style.height = Math.max(p.v > 0 ? 1.5 : 0, ((p.v || 0) / max) * 100) + '%';
      if (p.topo) b.appendChild(el('span', 's-graf-v', p.topo));
      c.appendChild(b);
      c.appendChild(el('span', 's-graf-r', p.rot));
      g.appendChild(c);
    });
    return g;
  }
  var DIA_CURTO = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
  var MES_CURTO = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
  // período legível: «ter 24 nov», «18–24 nov», «28 out – 3 nov», «1 jan – 24 nov 2025»
  function periodo(de, ate) {
    var a = new Date(de + 'T12:00:00'),
      b = new Date(ate + 'T12:00:00');
    if (isNaN(a.getTime()) || isNaN(b.getTime())) return de + ' – ' + ate;
    var ano = b.getFullYear() !== new Date().getFullYear() ? ' ' + b.getFullYear() : '';
    if (de === ate) return DIA_CURTO[a.getDay()] + ' ' + a.getDate() + ' ' + MES_CURTO[a.getMonth()] + ano;
    if (a.getMonth() === b.getMonth() && a.getFullYear() === b.getFullYear()) return a.getDate() + '–' + b.getDate() + ' ' + MES_CURTO[b.getMonth()] + ano;
    return a.getDate() + ' ' + MES_CURTO[a.getMonth()] + ' – ' + b.getDate() + ' ' + MES_CURTO[b.getMonth()] + ano;
  }
  function comparacao(id) {
    var c = D.comparacoes && D.comparacoes.linhas;
    if (!c) return null;
    for (var i = 0; i < c.length; i++) if (c[i].id === id) return c[i];
    return null;
  }
  // cartão de um comparativo: o valor (só sala interna), a variação e as datas dos dois períodos
  function cartaoComparacao(rotulo, c) {
    var extra = el('div', 's-comp-ref');
    if (c.ref) {
      extra.appendChild(variacao(c.variacao, ''));
      extra.appendChild(el('span', 's-comp-datas', periodo(c.actual.de, c.actual.ate) + ' × ' + periodo(c.ref.de, c.ref.ate)));
    } else extra.appendChild(el('span', 's-comp-datas', curto(c.motivo)));
    return numero(rotulo, D.local === 'aberta' || c.actual.t == null ? '' : kzc(c.actual.t), extra);
  }

  // motivo curto para o ecrã: «sem histórico · desde 03-11» (a data completa fica no portal)
  function curto(m) {
    return String(m || 'sem histórico').replace(/dados desde (\d\d-\d\d)-\d{4}/, 'desde $1');
  }

  // 1c. Comparações: todos os comparativos de gestão, cada um com as datas dos dois períodos
  function ecraComparacoes() {
    var c = D.comparacoes;
    if (!c || !c.linhas || !c.linhas.length) return [el('p', 's-msg', 'Os comparativos aparecem com o primeiro dia de vendas fechado.')];
    var aberta = D.local === 'aberta';
    var out = [
      el('p', 's-legenda', 'Dias fechados (até ontem) face ao período de comparação' + (c.inicio ? ' · dados desde ' + periodo(c.inicio, c.inicio) : '')),
    ];
    var t = el('div', 's-comp' + (aberta ? ' aberta' : ''));
    c.linhas.forEach(function (x) {
      var l = el('div', 's-comp-l' + (x.ref ? '' : ' sem'));
      l.appendChild(el('span', 's-comp-n', x.nome));
      l.appendChild(el('span', 's-comp-datas', periodo(x.actual.de, x.actual.ate) + (x.ref ? ' × ' + periodo(x.ref.de, x.ref.ate) : '')));
      if (!aberta) l.appendChild(el('span', 's-comp-v', (x.actual.t != null ? kzc(x.actual.t) : '—') + (x.ref ? ' × ' + kzc(x.ref.t) : '')));
      if (x.ref) l.appendChild(variacao(x.variacao, ''));
      else l.appendChild(el('span', 's-comp-motivo', curto(x.motivo)));
      t.appendChild(l);
    });
    out.push(t);
    return out;
  }
  function diaCurto(d) {
    var x = new Date(d + 'T12:00:00');
    return isNaN(x.getTime()) ? String(d).slice(8, 10) : DIA_CURTO[x.getDay()] + ' ' + x.getDate();
  }

  // 5. Ritmo do dia: vendas por hora (contagens, iguais nos dois locais)
  function ecraRitmo() {
    var hs = D.horas || [];
    var agora = String(new Date().getHours()).padStart(2, '0');
    var total = 0,
      pico = null;
    hs.forEach(function (x) {
      total += x.n;
      if (!pico || x.n > pico.n) pico = x;
    });
    var out = [el('p', 's-legenda', 'Vendas por hora, hoje · a hora actual em destaque')];
    var g = el('div', 's-grelha-graf');
    g.appendChild(
      grafico(
        hs.map(function (x) {
          return { rot: x.h + 'h', v: x.n, topo: x.n ? nf.format(x.n) : '', destaque: x.h === agora };
        }),
      ),
    );
    var lado = el('section', 's-coluna');
    lado.appendChild(numero('Vendas hoje', nf.format(total)));
    lado.appendChild(numero('Hora de maior movimento', pico && pico.n ? pico.h + ':00' : '—'));
    g.appendChild(lado);
    out.push(g);
    return out;
  }

  // 6. Últimos 14 dias: em Kz (sala interna) ou em índice face à média (open space)
  function ecraTendencia() {
    var sr = D.serie || [];
    var aberta = D.local === 'aberta';
    var hoje = sr.length ? sr[sr.length - 1].d : '';
    var out = [el('p', 's-legenda', aberta ? 'Vendas de cada dia face à média dos últimos 14 dias (100 = média)' : 'Vendas líquidas de cada dia, sem IVA')];
    var pontos = sr.map(function (x) {
      var v = aberta ? x.indice || 0 : x.t;
      return { rot: diaCurto(x.d), v: v, topo: v ? (aberta ? String(v) : kzc(v).replace(' Kz', '')) : '', destaque: x.d === hoje };
    });
    out.push(grafico(pontos, aberta ? { ref: 100, refRotulo: 'média' } : {}));
    return out;
  }

  // 7. Visitas de hoje: plano do dia, quantos já compraram e compras fora do plano
  function ecraVisitas() {
    var v = D.visitas;
    if (!v || !v.planeados) return [el('p', 's-msg', 'O plano de visitas de hoje aparece logo que as rotas começam.')];
    var esq = el('section', 's-hoje');
    esq.appendChild(el('div', 's-rot', 'Clientes do plano de hoje que já compraram'));
    esq.appendChild(el('div', 's-gigante', Math.round((v.conversao || 0) * 100) + ' %'));
    esq.appendChild(barra(Math.min(1, v.conversao || 0), Math.round((v.conversao || 0) * 100) + ' % do plano com compra'));
    var g = el('div', 's-tres');
    g.appendChild(numero('No plano', nf.format(v.planeados)));
    g.appendChild(numero('Já compraram', nf.format(v.compraram)));
    g.appendChild(numero('Compras fora do plano', nf.format(v.fora_plano)));
    esq.appendChild(g);
    if (D.local === 'aberta' || !(v.melhores || []).length) return [esq];
    var dir = el('section', 's-coluna');
    dir.appendChild(el('div', 's-rot', 'Melhor conversão do plano'));
    var l = el('ol', 's-mini');
    v.melhores.forEach(function (x) {
      var li = el('li');
      li.appendChild(nomeRota(x));
      li.appendChild(el('b', null, Math.round(x.conversao * 100) + ' %'));
      l.appendChild(li);
    });
    dir.appendChild(l);
    var grelha = el('div', 's-grelha-hoje');
    grelha.appendChild(esq);
    grelha.appendChild(dir);
    return [grelha];
  }

  // 8. Rotas no terreno: quantas já venderam hoje e quais atingiram o objectivo (nunca as que ficaram atrás)
  function ecraRotas() {
    var t = D.terreno || { rotas: 0, a_vender: 0, objectivo: [] };
    var esq = el('section', 's-hoje');
    esq.appendChild(el('div', 's-rot', 'Rotas com vendas hoje'));
    esq.appendChild(el('div', 's-gigante', t.a_vender + ' / ' + t.rotas));
    esq.appendChild(barra(t.rotas ? t.a_vender / t.rotas : 0, t.a_vender + ' de ' + t.rotas + ' rotas a vender'));
    var dir = el('section', 's-coluna');
    dir.appendChild(numero('Objectivo de hoje atingido', nf.format(t.objectivo.length) + (t.objectivo.length === 1 ? ' rota' : ' rotas')));
    var chips = el('div', 's-chips');
    t.objectivo.slice(0, 12).forEach(function (x) {
      var c = el('span', 's-chip', '✔ ' + x.rota + (x.promotor ? ' · ' + x.promotor.split(' ')[0] : ''));
      chips.appendChild(c);
    });
    if (!t.objectivo.length) chips.appendChild(el('span', 's-dd', 'A primeira rota a chegar ao objectivo aparece aqui.'));
    dir.appendChild(chips);
    var grelha = el('div', 's-grelha-hoje');
    grelha.appendChild(esq);
    grelha.appendChild(dir);
    return [grelha];
  }

  // 9. Clientes: com compras hoje e no mês (face ao mês passado); valor médio só na sala interna
  function ecraClientes() {
    var c = D.clientes || {};
    var esq = el('section', 's-hoje');
    esq.appendChild(el('div', 's-rot', 'Clientes com compras hoje'));
    esq.appendChild(el('div', 's-gigante', nf.format(c.hoje || 0)));
    var dir = el('section', 's-coluna');
    var vm = c.mes_anterior > 0 ? c.mes / c.mes_anterior - 1 : null;
    dir.appendChild(numero('Compras este mês', nf.format(c.mes || 0), variacao(vm, 'face ao mês passado (completo)')));
    dir.appendChild(numero('Mês passado', nf.format(c.mes_anterior || 0)));
    if (D.local !== 'aberta' && c.valor_medio_mes) dir.appendChild(numero('Valor médio por compra (mês)', kzc(c.valor_medio_mes)));
    var grelha = el('div', 's-grelha-hoje');
    grelha.appendChild(esq);
    grelha.appendChild(dir);
    return [grelha];
  }

  // 2 e 3. Classificações: as 8 primeiras (cabem a 5 m), barra proporcional, valor e indicador da rota
  function ecraRanking(campo, legenda, extra) {
    var rs = D.rotas
      .filter(function (r) {
        return r[campo] > 0;
      })
      .sort(function (a, b) {
        return b[campo] - a[campo];
      })
      .slice(0, 8);
    var out = [el('p', 's-legenda', legenda)];
    if (!rs.length) {
      out.push(el('p', 's-msg', 'Ainda sem vendas registadas.'));
      return out;
    }
    var max = rs[0][campo];
    var lista = el('ol', 's-rank');
    rs.forEach(function (r, i) {
      var l = el('li', 's-linha');
      l.appendChild(el('div', 's-pos' + (i < 3 ? ' podio' : ''), String(i + 1)));
      l.appendChild(nomeRota(r));
      l.appendChild(barra(r[campo] / max));
      l.appendChild(el('div', 's-valor', kzc(r[campo])));
      var x = extra(r);
      l.appendChild(x || el('div', 's-delta'));
      lista.appendChild(l);
    });
    out.push(lista);
    return out;
  }

  // 4. Destaques: cada rota comparada consigo própria (todas podem ganhar)
  function ecraDestaques() {
    var out = [el('p', 's-legenda', 'Cada rota comparada consigo própria: todas podem ganhar.')];
    var d = el('div', 's-destaques');
    function destaque(icone, t, n, txt) {
      var c = el('div', 's-destaque');
      c.appendChild(el('div', 's-ico', icone));
      var b = el('div');
      b.appendChild(el('div', 's-rot', t));
      b.appendChild(el('div', 's-dn', n));
      b.appendChild(el('div', 's-dd', txt));
      c.appendChild(b);
      d.appendChild(c);
    }
    var nome = function (r) {
      return 'Rota ' + r.rota + (r.promotor ? ' · ' + r.promotor : '');
    };
    var sobe = D.rotas
      .filter(function (r) {
        return r.mes_anterior > 0 && r.variacao_mes > 0;
      })
      .sort(function (a, b) {
        return b.variacao_mes - a.variacao_mes;
      })[0];
    if (sobe) destaque('▲', 'Maior subida do mês', nome(sobe), '+' + Math.round(sobe.variacao_mes * 100) + ' % face ao mês passado');
    var hoje = D.rotas.slice().sort(function (a, b) {
      return b.hoje - a.hoje;
    })[0];
    if (hoje && hoje.hoje > 0) destaque('★', 'Melhor de hoje', nome(hoje), kzc(hoje.hoje));
    var seq = D.rotas
      .filter(function (r) {
        return r.sequencia >= 3;
      })
      .sort(function (a, b) {
        return b.sequencia - a.sequencia;
      })[0];
    if (seq) destaque('●', 'Em sequência', nome(seq), seq.sequencia + ' dias seguidos acima do habitual');
    var acima = D.rotas.filter(function (r) {
      return r.habitual_ontem > 0 && r.ontem > r.habitual_ontem;
    });
    if (acima.length)
      destaque(
        '✔',
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

  var ECRAS = {
    hoje: {
      titulo: 'Vendas de hoje',
      f: function () {
        return D.local === 'aberta' ? ecraHojeAberta() : ecraHoje();
      },
    },
    comparacoes: { titulo: 'Comparações', f: ecraComparacoes },
    ritmo: { titulo: 'Ritmo do dia', f: ecraRitmo },
    tendencia: { titulo: 'Últimos 14 dias', f: ecraTendencia },
    visitas: { titulo: 'Visitas de hoje', f: ecraVisitas },
    rotas: { titulo: 'Rotas no terreno', f: ecraRotas },
    clientes: { titulo: 'Clientes', f: ecraClientes },
    'rank-hoje': {
      titulo: 'Classificação de hoje',
      f: function () {
        return ecraRanking('hoje', 'Vendas de hoje até agora · % da previsão de cada rota', function (r) {
          if (!r.previsto_hoje) return null;
          var p = Math.round((r.hoje / r.previsto_hoje) * 100);
          var s = el('div', 's-delta ' + (p >= 100 ? 'sobe' : 'igual'));
          s.appendChild(el('b', null, (p >= 100 ? '✔ ' : '') + p + ' %'));
          return s;
        });
      },
    },
    'rank-mes': {
      titulo: 'Classificação do mês',
      f: function () {
        return ecraRanking('mes', MESES[new Date().getMonth()] + ' até hoje · subida face ao mês passado', function (r) {
          // reconhecimento positivo: só as subidas
          return r.variacao_mes != null && r.variacao_mes > 0 ? variacao(r.variacao_mes, '') : null;
        });
      },
    },
    destaques: {
      titulo: 'Destaques',
      f: function () {
        return D.local === 'aberta' ? ecraDestaquesAberta() : ecraDestaques();
      },
    },
  };
  // ecrãs e ordem escolhidos no portal (Gestão › Painel da sala comercial); omissão: os quatro
  function lista() {
    var c =
      (D && D.config && D.config.ecras) ||
      (D && D.local === 'aberta'
        ? ['hoje', 'comparacoes', 'ritmo', 'tendencia', 'visitas', 'rotas', 'clientes', 'destaques']
        : ['hoje', 'comparacoes', 'ritmo', 'tendencia', 'visitas', 'rotas', 'clientes', 'rank-hoje', 'rank-mes', 'destaques']);
    return c.filter(function (k) {
      return ECRAS[k];
    });
  }
  function segundos() {
    return (D && D.config && D.config.segundos) || 20;
  }

  function desenhar() {
    var m = $('s-ecra');
    var novo = m.cloneNode(false);
    var L = lista();
    var k = L[ecra % L.length];
    $('s-titulo').textContent = D && !erro ? ECRAS[k].titulo : 'Painel da sala comercial';
    if (erro) novo.appendChild(el('p', 's-msg', erro));
    else if (!D) novo.appendChild(el('p', 's-msg', 'A carregar…'));
    else
      ECRAS[k].f().forEach(function (x) {
        novo.appendChild(x);
      });
    m.parentNode.replaceChild(novo, m);
    // rodapé: posição, ecrã seguinte e barra do tempo que falta
    var p = $('s-pontos');
    p.textContent = '';
    L.forEach(function (x, i) {
      p.appendChild(el('i', i === ecra % L.length ? 'on' : ''));
    });
    $('s-seguinte').textContent = L.length > 1 ? 'A seguir: ' + ECRAS[L[(ecra + 1) % L.length]].titulo : '';
    var t = $('s-tempo');
    t.style.animation = 'none';
    void t.offsetWidth;
    t.style.animation = reduzir || L.length < 2 ? 'none' : 'tempo ' + segundos() + 's linear forwards';
    frescura();
  }
  // frescura dos dados: hora da última leitura; aviso se passarem 10 minutos sem dados novos
  function frescura() {
    var a = $('s-act');
    if (!D) return;
    var velho = Date.now() - recebido > VELHOS;
    a.className = velho ? 'velho' : '';
    a.textContent =
      (velho ? '⚠ Sem dados novos desde as ' : (D.local === 'aberta' ? 'Equipa RTM' : 'Vendas do Pinguim BO') + ' · actualizado às ') +
      String(D.hora).slice(11, 16);
  }
  // pré-visualização no portal (#previa): os dados vêm da página do portal (mesma origem), sem código de ligação
  var previa = /(^|[#&])previa(=|&|$)/.test(location.hash);
  function receber(j) {
    var primeiro = !D;
    D = j;
    erro = '';
    recebido = Date.now();
    if (primeiro) desenhar();
    else frescura();
  }
  if (previa) {
    window.addEventListener('message', function (ev) {
      if (ev.origin !== location.origin || !ev.data || ev.data.tipo !== 'sala-dados') return;
      if (ev.data.dados && ev.data.dados.ok) receber(ev.data.dados);
      else {
        erro = (ev.data.dados && ev.data.dados.erro) || 'Sem dados.';
        desenhar();
      }
    });
    if (window.parent !== window) window.parent.postMessage({ tipo: 'sala-pronta' }, location.origin);
  }
  function carregar() {
    if (previa) return;
    fetch(API, { cache: 'no-store', credentials: 'omit', referrerPolicy: 'no-referrer', headers: { 'X-Painel-Codigo': codigo } })
      .then(function (r) {
        return r.json().then(function (j) {
          if (!r.ok || !j.ok) throw new Error(j.erro || 'HTTP ' + r.status);
          var primeiro = !D;
          D = j;
          erro = '';
          recebido = Date.now();
          if (primeiro) desenhar();
          else frescura();
        });
      })
      .catch(function (e) {
        // sem rede: continua a mostrar os últimos dados (com o aviso de frescura); ligação inválida: mostra o motivo
        if (!D || /revogada|inválida/.test(String(e.message))) {
          erro = String(e.message || e);
          desenhar();
        } else frescura();
      });
  }
  function relogio() {
    var d = new Date();
    $('s-hora').textContent = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
    $('s-data').textContent = DIAS[d.getDay()] + ', ' + d.getDate() + ' de ' + MESES[d.getMonth()];
    // fora do horário comercial (antes das 06:00 e depois das 21:00): brilho reduzido
    document.body.classList.toggle('noite', d.getHours() < 6 || d.getHours() >= 21);
  }
  relogio();
  setInterval(relogio, 10000);
  carregar();
  setInterval(carregar, 120000);
  // rotação ao ritmo escolhido no portal (lido em cada volta)
  (function rodar() {
    setTimeout(function () {
      ecra++;
      desenhar();
      rodar();
    }, segundos() * 1000);
  })();
  // protecção do ecrã: desloca o conteúdo alguns píxeis a cada 4 minutos (imperceptível a quem olha)
  var passo = 0;
  setInterval(function () {
    passo = (passo + 1) % 4;
    document.body.style.setProperty('--desvio-x', [0, 3, 0, -3][passo] + 'px');
    document.body.style.setProperty('--desvio-y', [0, 2, -2, 0][passo] + 'px');
  }, 240000);
  // um ecrã ligado dias seguidos: recarrega a página de madrugada (versões novas do painel)
  setInterval(function () {
    if (new Date().getHours() === 4 && performance.now() > 3600000) location.reload();
  }, 600000);
})();
