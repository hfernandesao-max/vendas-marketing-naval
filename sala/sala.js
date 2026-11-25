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
      // acima de +300 % a referência é pequena demais (rota nova ou sem histórico): não é uma subida a destacar
      return r.variacao_mes > 0 && r.variacao_mes <= 3;
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
        return r.mes_anterior > 0 && r.variacao_mes > 0 && r.variacao_mes <= 3;
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

  // ── Ecrãs de gestão avançados ────────────────────────────────────────────────────────────────────────────
  // Gráficos em SVG (escala própria, sem bibliotecas): linhas, curva, dispersão e mapa; legenda sempre em texto.
  var SVGNS = 'http://www.w3.org/2000/svg';
  function svg(tag, at, txt) {
    var e = document.createElementNS(SVGNS, tag);
    Object.keys(at || {}).forEach(function (k) {
      e.setAttribute(k, at[k]);
    });
    if (txt != null) e.textContent = txt;
    return e;
  }
  function quadro(cls) {
    var s = svg('svg', { viewBox: '0 0 1000 520', preserveAspectRatio: 'none', class: 's-svg ' + (cls || '') });
    s.setAttribute('role', 'img');
    return s;
  }
  function pct(v) {
    return Math.round((v || 0) * 100) + ' %';
  }
  function pctSinal(v) {
    var p = Math.round((v || 0) * 100);
    return (p > 0 ? '+' : p < 0 ? '−' : '') + Math.abs(p) + ' %';
  }
  function legenda(itens) {
    var l = el('div', 's-leg');
    itens.forEach(function (x) {
      var i = el('span', 's-leg-i ' + x[0]);
      i.appendChild(el('i'));
      i.appendChild(document.createTextNode(x[1]));
      l.appendChild(i);
    });
    return l;
  }
  // linhas: series [{cls, v: [número|null]}] sobre os rótulos do eixo x
  function graficoLinhas(rotulos, series, rot) {
    var s = quadro('s-linhas');
    var max = 1;
    series.forEach(function (x) {
      x.v.forEach(function (v) {
        if (v != null) max = Math.max(max, v);
      });
    });
    max *= 1.08;
    var X = function (i) {
        return 40 + (i * 920) / Math.max(1, rotulos.length - 1);
      },
      Y = function (v) {
        return 470 - (v / max) * 440;
      };
    [0.25, 0.5, 0.75, 1].forEach(function (f) {
      s.appendChild(svg('line', { x1: 40, x2: 960, y1: Y(max * f), y2: Y(max * f), class: 's-svg-grade' }));
    });
    s.appendChild(svg('line', { x1: 40, x2: 960, y1: 470, y2: 470, class: 's-svg-eixo' }));
    rotulos.forEach(function (r, i) {
      if (i % 2 === 0) s.appendChild(svg('text', { x: X(i), y: 510, class: 's-svg-rot', 'text-anchor': 'middle' }, r));
    });
    series.forEach(function (x) {
      var d = '';
      var ult = null;
      x.v.forEach(function (v, i) {
        if (v == null) return;
        d += (d ? 'L' : 'M') + X(i).toFixed(1) + ' ' + Y(v).toFixed(1);
        ult = [X(i), Y(v), v];
      });
      if (!d) return;
      s.appendChild(svg('path', { d: d, class: 's-svg-l ' + x.cls }));
      if (ult && x.ponto) {
        s.appendChild(svg('circle', { cx: ult[0], cy: ult[1], r: 9, class: 's-svg-p ' + x.cls }));
        s.appendChild(svg('text', { x: Math.min(ult[0] + 14, 900), y: ult[1] - 14, class: 's-svg-v' }, rot(ult[2])));
      }
    });
    return s;
  }

  // 1. Ritmo acumulado × objectivo: a linha de hoje contra a do objectivo e a de um dia normal
  function ecraAcumulado() {
    var a = D.acumulado;
    if (
      !a ||
      !a.horas ||
      !a.horas.some(function (h) {
        return h.habitual != null;
      })
    )
      return [el('p', 's-msg', 'O ritmo acumulado aparece com as primeiras semanas de vendas por hora.')];
    var aberta = D.local === 'aberta';
    var fmt = aberta
      ? function (v) {
          return v + '';
        }
      : function (v) {
          return kzc(v).replace(' Kz', '');
        };
    var ref =
      a.ref.tipo === 'mesmo_dia'
        ? 'média das últimas ' + a.ref.dias + ' ' + DIAS[new Date().getDay()].replace('-feira', '') + 's'
        : 'média dos últimos ' + a.ref.dias + ' dias com vendas';
    var out = [
      el(
        'p',
        's-legenda',
        (aberta
          ? 'Vendas acumuladas ao longo do dia (100 = ' + (a.base === 'habitual' ? 'dia normal' : 'objectivo do dia') + ')'
          : 'Vendas acumuladas ao longo do dia, sem IVA') +
          ' · dia normal = ' +
          ref,
      ),
    ];
    var g = el('div', 's-grelha-graf');
    var caixa = el('div', 's-graf-caixa');
    caixa.appendChild(
      graficoLinhas(
        a.horas.map(function (h) {
          return h.h + 'h';
        }),
        [
          {
            cls: 'normal',
            v: a.horas.map(function (h) {
              return h.habitual;
            }),
          },
          {
            cls: 'obj',
            v: a.horas.map(function (h) {
              return h.objectivo;
            }),
          },
          {
            cls: 'hoje',
            ponto: true,
            v: a.horas.map(function (h) {
              return h.hoje;
            }),
          },
        ],
        fmt,
      ),
    );
    caixa.appendChild(
      legenda([
        ['hoje', 'Hoje'],
        ['obj', 'Objectivo'],
        ['normal', 'Dia normal'],
      ]),
    );
    g.appendChild(caixa);
    var lado = el('section', 's-coluna');
    if (a.ritmo != null) {
      var r = Math.round(a.ritmo * 100);
      var n = numero(
        r >= 0 ? 'À frente do ritmo' : 'Atrás do ritmo',
        (r >= 0 ? '▲ ' : '▼ ') + Math.abs(r) + ' %',
        el('div', 's-dd', 'face ao objectivo a esta hora'),
        r >= 0 ? 'bom' : 'atencao',
      );
      lado.appendChild(n);
    } else lado.appendChild(numero('Ritmo', '—', el('div', 's-dd', 'ainda cedo: aparece a partir de 10 % do dia')));
    if (!aberta) lado.appendChild(numero('Hoje até agora', kzc(a.total)));
    else {
      var ult = a.horas
        .filter(function (h) {
          return h.hoje != null;
        })
        .pop();
      if (ult && a.base === 'objectivo') lado.appendChild(numero('Do objectivo do dia', ult.hoje + ' %'));
    }
    g.appendChild(lado);
    out.push(g);
    return out;
  }

  // 2. Previsão de fecho: o dia pelo ritmo e o mês (até ontem + hoje estimado + a previsão dos dias que faltam)
  function ecraPrevisao() {
    var p = D.previsao;
    if (!p) return [el('p', 's-msg', 'A previsão aparece com as primeiras semanas de vendas.')];
    var aberta = D.local === 'aberta';
    var esq = el('section', 's-hoje');
    esq.appendChild(el('div', 's-rot', 'Hoje deve fechar em'));
    var prog = p.dia.progresso;
    esq.appendChild(el('div', 's-gigante', aberta ? (prog != null ? pct(prog) + ' do objectivo' : '—') : kz(p.dia.estimado)));
    if (prog != null) esq.appendChild(barra(Math.min(1, prog), pct(prog) + ' do objectivo'));
    var ln = el('p', 's-meta');
    if (!aberta && p.dia.objectivo) ln.appendChild(document.createTextNode(pct(prog) + ' do objectivo (' + kzc(p.dia.objectivo) + ')'));
    if (!aberta && p.dia.minimo != null) ln.appendChild(document.createTextNode(' · entre ' + kzc(p.dia.minimo) + ' e ' + kzc(p.dia.maximo)));
    ln.appendChild(
      el('span', 's-dd', ' · ' + (p.dia.pelo_ritmo ? 'pelo ritmo de hoje face a um dia normal' : 'pela previsão do dia (ainda cedo para usar o ritmo)')),
    );
    esq.appendChild(ln);
    var dir = el('section', 's-coluna');
    var m = p.mes;
    dir.appendChild(
      numero(
        MESES[new Date().getMonth()].replace(/^./, function (c) {
          return c.toUpperCase();
        }) + ' deve fechar em',
        aberta ? '' : kzc(m.estimado),
        m.variacao != null
          ? variacao(m.variacao, 'face a ' + MESES[new Date(m.ref.de + 'T12:00:00').getMonth()])
          : el('div', 's-dd', 'sem o mês anterior completo para comparar'),
      ),
    );
    dir.appendChild(numero('Dias de trabalho que faltam', nf.format(m.dias_resto), el('div', 's-dd', 'depois de hoje, sem domingos nem feriados')));
    var g = el('div', 's-grelha-hoje');
    g.appendChild(esq);
    g.appendChild(dir);
    return [g];
  }

  // 3. Mapa das rotas (só sala interna): territórios, percurso de hoje e a última posição de cada carrinha
  var CORES = ['#7ccbff', '#f7cf5e', '#8ef0b0', '#ffb36b', '#c9a7ff', '#ff8fa3', '#6ee7e7', '#d9f99d', '#fda4af', '#93c5fd', '#fcd34d', '#a7f3d0'];
  function ecraMapa() {
    var m = D.mapa;
    if (!m || !m.rotas || !m.rotas.length) return [el('p', 's-msg', 'O mapa aparece quando as carrinhas começam a enviar a localização (GPS das vendas).')];
    var pts = [];
    m.rotas.forEach(function (r) {
      pts = pts.concat(r.percurso, [r.pos]);
    });
    Object.keys(m.territorios || {}).forEach(function (k) {
      pts = pts.concat(m.territorios[k]);
    });
    var la = pts.map(function (p) {
        return p[0];
      }),
      lo = pts.map(function (p) {
        return p[1];
      });
    var a0 = Math.min.apply(null, la),
      a1 = Math.max.apply(null, la),
      o0 = Math.min.apply(null, lo),
      o1 = Math.max.apply(null, lo);
    var kx = Math.cos(((a0 + a1) / 2) * (Math.PI / 180));
    var w = Math.max((o1 - o0) * kx, 1e-4),
      h = Math.max(a1 - a0, 1e-4);
    var esc = Math.min(1600 / w, 760 / h);
    var X = function (p) {
        return 20 + (p[1] - o0) * kx * esc + (1600 - w * esc) / 2;
      },
      Y = function (p) {
        return 20 + (a1 - p[0]) * esc + (760 - h * esc) / 2;
      };
    var s = svg('svg', { viewBox: '0 0 1640 800', class: 's-svg s-mapa' });
    s.setAttribute('role', 'img');
    s.setAttribute('aria-label', 'Mapa das rotas de hoje');
    Object.keys(m.territorios || {}).forEach(function (k) {
      var t = m.territorios[k];
      if (t.length > 2)
        s.appendChild(
          svg('polygon', {
            points: t
              .map(function (p) {
                return X(p).toFixed(0) + ',' + Y(p).toFixed(0);
              })
              .join(' '),
            class: 's-mapa-terr',
          }),
        );
    });
    m.rotas.forEach(function (r, i) {
      var c = CORES[i % CORES.length];
      s.appendChild(
        svg('polyline', {
          points: r.percurso
            .map(function (p) {
              return X(p).toFixed(0) + ',' + Y(p).toFixed(0);
            })
            .join(' '),
          class: 's-mapa-perc',
          stroke: c,
        }),
      );
    });
    m.rotas.forEach(function (r, i) {
      var c = CORES[i % CORES.length];
      s.appendChild(svg('circle', { cx: X(r.pos), cy: Y(r.pos), r: 14, fill: c, class: 's-mapa-pos' }));
      s.appendChild(svg('text', { x: X(r.pos) + 20, y: Y(r.pos) + 10, class: 's-mapa-rot' }, r.rota));
    });
    var g = el('div', 's-grelha-graf');
    var caixa = el('div', 's-graf-caixa');
    caixa.appendChild(s);
    g.appendChild(caixa);
    var lado = el('section', 's-coluna');
    var vend = 0,
      prova = 0;
    m.rotas.forEach(function (r) {
      vend += r.vendas;
      prova += r.prova;
    });
    lado.appendChild(numero('Vendas com GPS', nf.format(vend), el('div', 's-dd', vend ? pct(prova / vend) + ' junto ao cliente (prova de visita)' : '')));
    var l = el('ol', 's-mini s-mapa-lista');
    m.rotas.slice(0, 3).forEach(function (r, i) {
      var li = el('li');
      var n = el('div', 's-nome');
      var b = el('strong', null, 'Rota ' + r.rota);
      b.style.color = CORES[i % CORES.length];
      n.appendChild(b);
      if (r.promotor) n.appendChild(el('span', null, r.promotor));
      li.appendChild(n);
      li.appendChild(el('b', null, r.hora));
      l.appendChild(li);
    });
    lado.appendChild(l);
    g.appendChild(lado);
    return [el('p', 's-legenda', nf.format(m.rotas.length) + ' carrinhas no terreno · percurso de hoje e última posição · zonas das rotas a sombreado'), g];
  }

  // 4. Funil das visitas: do plano à compra acima da média (onde se perde a venda)
  function ecraFunil() {
    var f = D.funil;
    if (!f || !f.etapas || !f.etapas[0].n) return [el('p', 's-msg', 'O funil aparece com o plano de visitas de hoje.')];
    var base = f.etapas[0].n;
    var out = [el('p', 's-legenda', 'Clientes do plano de hoje, passo a passo · % face ao plano')];
    var l = el('div', 's-funil');
    f.etapas.forEach(function (e, i) {
      var linha = el('div', 's-funil-l');
      linha.appendChild(el('span', 's-funil-n', e.nome));
      var b = el('div', 's-funil-b');
      var i2 = el('i');
      i2.style.width = Math.max(2, Math.round((e.n / base) * 100)) + '%';
      i2.appendChild(el('b', null, nf.format(e.n)));
      b.appendChild(i2);
      linha.appendChild(b);
      linha.appendChild(el('span', 's-funil-p', i ? pct(e.n / base) : '100 %'));
      l.appendChild(linha);
    });
    out.push(l);
    if (f.fora_plano) out.push(el('p', 's-meta', '+ ' + nf.format(f.fora_plano) + ' clientes compraram fora do plano'));
    return out;
  }

  // 5. Mapa de calor: dia da semana × hora nas últimas 4 semanas (100 = hora mais forte)
  function ecraCalor() {
    var c = D.calor;
    if (!c || !c.linhas || !c.linhas.length) return [el('p', 's-msg', 'O mapa de calor aparece com as primeiras semanas de vendas por hora.')];
    var out = [el('p', 's-legenda', 'Vendas por dia da semana e hora · ' + periodo(c.de, c.ate) + ' · mais escuro = mais vendas')];
    var t = el('div', 's-calor');
    t.appendChild(el('span'));
    for (var k = 6; k <= 20; k++) t.appendChild(el('span', 's-calor-h', k % 2 === 0 ? k + 'h' : ''));
    var hojeD = DIA_CURTO[new Date().getDay()];
    c.linhas.forEach(function (l) {
      t.appendChild(el('span', 's-calor-d' + (l.dia === hojeD ? ' hoje' : ''), l.dia));
      l.v.forEach(function (v) {
        var x = el('span', 's-calor-c');
        x.style.setProperty('--i', (v / 100).toFixed(2));
        if (v >= 85) x.textContent = '●';
        t.appendChild(x);
      });
    });
    out.push(t);
    if (c.pico) out.push(el('p', 's-meta', 'Hora mais forte: ' + c.pico.dia + ' às ' + c.pico.h + 'h · ● as horas de pico (85 % ou mais)'));
    return out;
  }

  // 6. Artigos que mais sobem e mais descem (sala interna): últimos 7 dias face às 4 semanas anteriores
  function ecraArtigos() {
    var a = D.artigos;
    if (!a) return [el('p', 's-msg', 'Os artigos em destaque aparecem com 5 semanas de vendas.')];
    var out = [el('p', 's-legenda', periodo(a.actual.de, a.actual.ate) + ' × média semanal de ' + periodo(a.ref.de, a.ref.ate))];
    var g = el('div', 's-grelha-hoje s-duas');
    [
      ['▲ Sobem', a.sobem, 'sobe'],
      ['▼ Descem', a.descem, 'desce'],
    ].forEach(function (x) {
      var c = el('section', 's-coluna');
      c.appendChild(el('div', 's-rot', x[0]));
      var l = el('ol', 's-mini');
      if (!x[1].length) l.appendChild(el('li', 's-dd', 'Nenhum artigo com variação relevante.'));
      x[1].forEach(function (y) {
        var li = el('li');
        var n = el('div', 's-nome');
        n.appendChild(el('strong', null, y.n));
        n.appendChild(el('span', null, kzc(y.t) + ' na semana'));
        li.appendChild(n);
        li.appendChild(el('b', 's-delta ' + x[2], pctSinal(y.var)));
        l.appendChild(li);
      });
      c.appendChild(l);
      g.appendChild(c);
    });
    out.push(g);
    return out;
  }

  // 7. Pareto dos clientes (sala interna, sem nomes): quantos fazem 80 % e quantos grandes estão parados
  function ecraPareto() {
    var p = D.pareto;
    if (!p) return [el('p', 's-msg', 'A concentração dos clientes aparece com mais clientes com compras.')];
    var out = [el('p', 's-legenda', 'Clientes com compras de ' + periodo(p.de, p.ate) + ' · do maior para o menor')];
    var g = el('div', 's-grelha-graf');
    var caixa = el('div', 's-graf-caixa');
    var rot = [0].concat(
      p.curva.map(function (x) {
        return x.p;
      }),
    );
    caixa.appendChild(
      graficoLinhas(
        rot.map(function (x) {
          return x + ' %';
        }),
        [
          {
            cls: 'obj',
            v: rot.map(function () {
              return 80;
            }),
          },
          {
            cls: 'hoje',
            ponto: false,
            v: [0].concat(
              p.curva.map(function (x) {
                return Math.round(x.v * 100);
              }),
            ),
          },
        ],
        function (v) {
          return v + ' %';
        },
      ),
    );
    caixa.appendChild(
      legenda([
        ['hoje', '% das vendas (↑) por % dos clientes (→)'],
        ['obj', '80 % das vendas'],
      ]),
    );
    g.appendChild(caixa);
    var lado = el('section', 's-coluna');
    lado.appendChild(
      numero('Fazem 80 % das vendas', nf.format(p.n80) + ' clientes', el('div', 's-dd', pct(p.parte80) + ' dos ' + nf.format(p.clientes) + ' com compras')),
    );
    lado.appendChild(
      numero(
        'Desses, sem comprar há 14 dias',
        nf.format(p.parados),
        el('div', 's-dd', p.parados ? pct(p.parados_peso) + ' das vendas em risco · ver a lista no portal' : 'todos activos ✔'),
        p.parados ? 'atencao' : 'bom',
      ),
    );
    g.appendChild(lado);
    out.push(g);
    return out;
  }

  // 8. Quadrante das rotas (sala interna): compras por dia × valor médio por compra, com as medianas
  function ecraQuadrante() {
    var q = D.quadrante;
    if (!q || !q.rotas || q.rotas.length < 2) return [el('p', 's-msg', 'O quadrante aparece com vendas de várias rotas.')];
    var s = quadro('s-quad');
    var mx = 1,
      my = 1;
    q.rotas.forEach(function (r) {
      mx = Math.max(mx, r.compras_dia);
      my = Math.max(my, r.valor_medio);
    });
    mx *= 1.12;
    my *= 1.15;
    var X = function (v) {
        return 60 + (v / mx) * 900;
      },
      Y = function (v) {
        return 470 - (v / my) * 440;
      };
    s.appendChild(svg('line', { x1: X(q.mediana.compras_dia), x2: X(q.mediana.compras_dia), y1: 20, y2: 470, class: 's-svg-med' }));
    s.appendChild(svg('line', { x1: 60, x2: 960, y1: Y(q.mediana.valor_medio), y2: Y(q.mediana.valor_medio), class: 's-svg-med' }));
    s.appendChild(svg('line', { x1: 60, x2: 960, y1: 470, y2: 470, class: 's-svg-eixo' }));
    s.appendChild(svg('line', { x1: 60, x2: 60, y1: 20, y2: 470, class: 's-svg-eixo' }));
    s.appendChild(svg('text', { x: 950, y: 50, class: 's-svg-q', 'text-anchor': 'end' }, 'mais compras · cesto maior'));
    s.appendChild(svg('text', { x: 75, y: 50, class: 's-svg-q' }, 'aumentar compras'));
    s.appendChild(svg('text', { x: 950, y: 455, class: 's-svg-q', 'text-anchor': 'end' }, 'aumentar o cesto'));
    s.appendChild(svg('text', { x: 510, y: 510, class: 's-svg-rot', 'text-anchor': 'middle' }, 'compras por dia de venda →'));
    q.rotas.forEach(function (r) {
      s.appendChild(svg('circle', { cx: X(r.compras_dia), cy: Y(r.valor_medio), r: 11, class: 's-svg-p hoje' }));
      s.appendChild(svg('text', { x: X(r.compras_dia) + 15, y: Y(r.valor_medio) + 8, class: 's-svg-v' }, r.rota));
    });
    var g = el('div', 's-grelha-graf');
    var caixa = el('div', 's-graf-caixa');
    caixa.appendChild(s);
    g.appendChild(caixa);
    var lado = el('section', 's-coluna');
    lado.appendChild(numero('Compras por dia (mediana)', nf1.format(q.mediana.compras_dia)));
    lado.appendChild(numero('Valor médio por compra (mediana)', kzc(q.mediana.valor_medio)));
    lado.appendChild(el('p', 's-dd', 'Cada ponto é uma rota (28 dias); as linhas são as medianas.'));
    g.appendChild(lado);
    return [el('p', 's-legenda', 'Desde ' + periodo(q.de, q.de) + ' · ↑ valor médio por compra'), g];
  }

  // 9. Cobertura do stock (sala interna): os artigos que acabam primeiro ao ritmo de venda
  function ecraStock() {
    var s = D.stock;
    if (!s || !s.lista || !s.lista.length) return [el('p', 's-msg', 'A cobertura do stock aparece quando o stock dos armazéns está disponível.')];
    var out = [el('p', 's-legenda', 'Stock dos armazéns ÷ venda média por dia (' + s.dias_venda + ' dias de venda) · os que acabam primeiro')];
    var g = el('div', 's-grelha-hoje');
    var t = el('div', 's-stock');
    s.lista.forEach(function (x) {
      var l = el('div', 's-stock-l ' + (x.stock <= 0 ? 'rutura' : x.dias < 3 ? 'critico' : x.dias < 7 ? 'atencao' : 'ok'));
      l.appendChild(el('span', 's-stock-n', x.n));
      l.appendChild(el('span', 's-stock-q', nf.format(x.stock) + ' em stock · ' + nf1.format(x.dia) + '/dia'));
      l.appendChild(el('b', null, x.stock <= 0 ? '✖ ruptura' : nf1.format(x.dias) + ' dias'));
      t.appendChild(l);
    });
    g.appendChild(t);
    var lado = el('section', 's-coluna');
    lado.appendChild(numero('Em ruptura', nf.format(s.ruptura), el('div', 's-dd', 'artigos com venda e sem stock'), s.ruptura ? 'atencao' : 'bom'));
    lado.appendChild(numero('Menos de 3 dias', nf.format(s.criticos), el('div', 's-dd', 'de ' + nf.format(s.artigos) + ' artigos com venda')));
    g.appendChild(lado);
    out.push(g);
    return out;
  }

  // 10. Saúde da operação (sala interna): o que espera por alguém, com os atrasos
  function ecraOperacao() {
    var o = D.operacao;
    if (!o) return [el('p', 's-msg', 'A saúde da operação aparece logo que o servidor lê os pendentes.')];
    var out = [el('p', 's-legenda', 'O que espera por alguém agora · ✔ em dia · ● por fazer · ▲ atrasado')];
    var l = el('div', 's-oper');
    o.linhas.forEach(function (x) {
      var c = el('div', 's-oper-l ' + (x.atraso ? 'atraso' : x.n ? 'pendente' : 'ok'));
      c.appendChild(el('span', 's-oper-i', x.atraso ? '▲' : x.n ? '●' : '✔'));
      c.appendChild(el('span', 's-oper-n', x.nome));
      c.appendChild(el('b', null, x.n ? nf.format(x.n) + (x.atraso ? ' · ' + nf.format(x.atraso) + ' atrasado' + (x.atraso === 1 ? '' : 's') : '') : 'em dia'));
      l.appendChild(c);
    });
    out.push(l);
    return out;
  }

  // 11. Recordes: melhor dia de sempre, melhor dia igual ao de hoje, posição de ontem e do mês
  function ecraRecordes() {
    var r = D.recordes;
    if (!r) return [el('p', 's-msg', 'Os recordes aparecem com as primeiras semanas de vendas.')];
    var aberta = D.local === 'aberta';
    var out = [el('p', 's-legenda', 'Desde ' + periodo(r.desde, r.desde) + ' · ' + nf.format(r.dias) + ' dias com vendas')];
    var d = el('div', 's-destaques');
    function cartao(ic, t, n, txt) {
      var c = el('div', 's-destaque');
      c.appendChild(el('div', 's-ico', ic));
      var b = el('div');
      b.appendChild(el('div', 's-rot', t));
      b.appendChild(el('div', 's-dn', n));
      if (txt) b.appendChild(el('div', 's-dd', txt));
      c.appendChild(b);
      d.appendChild(c);
    }
    var h = r.hoje;
    if (h.bate_recorde) cartao('🏆', 'Hoje', 'Recorde batido!', 'o melhor dia de sempre');
    else if (h.face_recorde != null) cartao('🎯', 'Hoje face ao recorde', pct(h.face_recorde), 'do melhor dia de sempre');
    cartao('🏆', 'Melhor dia de sempre', periodo(r.melhor_dia.d, r.melhor_dia.d), aberta ? '' : kzc(r.melhor_dia.t));
    if (r.melhor_igual)
      cartao(
        '★',
        'Melhor ' + DIAS[new Date().getDay()],
        periodo(r.melhor_igual.d, r.melhor_igual.d),
        h.bate_igual ? 'hoje já o bateu ✔' : aberta ? '' : kzc(r.melhor_igual.t),
      );
    if (r.ontem && r.ontem.posicao <= 10) cartao('▲', 'Ontem', r.ontem.posicao + '.º melhor dia', 'de ' + nf.format(r.ontem.de));
    if (r.melhor_mes)
      cartao(
        '📅',
        'Melhor mês',
        MESES[+r.melhor_mes.m.slice(5, 7) - 1] + ' ' + r.melhor_mes.m.slice(0, 4),
        (aberta ? '' : kzc(r.melhor_mes.t) + ' · ') + 'este mês vai em ' + r.mes_posicao + '.º de ' + (r.meses + 1),
      );
    out.push(d);
    return out;
  }

  // 12. Contagem decrescente do mês: dias que faltam e o ritmo diário para igualar o mês passado e o recorde
  function ecraContagem() {
    var c = D.contagem;
    if (!c) return [el('p', 's-msg', 'A contagem do mês aparece com as primeiras vendas do mês.')];
    var aberta = D.local === 'aberta';
    var esq = el('section', 's-hoje');
    esq.appendChild(el('div', 's-rot', 'Faltam até ao fim de ' + MESES[new Date().getMonth()]));
    esq.appendChild(el('div', 's-gigante', c.dias_trabalho + (c.dias_trabalho === 1 ? ' dia' : ' dias') + ' de trabalho'));
    esq.appendChild(el('p', 's-meta', 'contando com hoje · ' + c.dias_calendario + ' dias de calendário'));
    if (!aberta && c.media_dia) esq.appendChild(el('p', 's-meta', 'Média diária do mês até ontem: ' + kzc(c.media_dia)));
    var dir = el('section', 's-coluna');
    function meta(rot, m) {
      if (!m) return;
      if (m.atingido) dir.appendChild(numero(rot, '✔ já conseguido', null, 'bom'));
      else
        dir.appendChild(
          numero(
            rot,
            aberta ? (m.face_media != null ? pctSinal(m.face_media) : '—') : kzc(m.por_dia) + ' por dia',
            el('div', 's-dd', m.face_media != null ? (aberta ? 'por dia face à média actual' : pctSinal(m.face_media) + ' face à média diária actual') : ''),
          ),
        );
    }
    meta('Para igualar o mês passado', c.igualar);
    if (c.recorde) meta('Para bater o melhor mês (' + MESES[+c.recorde.m.slice(5, 7) - 1] + ')', c.recorde);
    if (!dir.children.length) dir.appendChild(el('p', 's-dd', 'As metas aparecem com o primeiro mês completo de vendas.'));
    var g = el('div', 's-grelha-hoje');
    g.appendChild(esq);
    g.appendChild(dir);
    return [g];
  }

  // 13. Momentos do dia: um ecrã de celebração quando acontece algo (objectivo, recorde), mostrado uma vez
  var momentos = [];
  var VISTOS = 'sala-momentos';
  function lerVistos() {
    try {
      var v = JSON.parse(localStorage.getItem(VISTOS) || '{}');
      return v && v.dia === new Date().toDateString() ? v.ids || [] : [];
    } catch (e) {
      return [];
    }
  }
  function marcarVisto(id) {
    try {
      var ids = lerVistos();
      ids.push(id);
      localStorage.setItem(VISTOS, JSON.stringify({ dia: new Date().toDateString(), ids: ids }));
    } catch (e) {
      /* sem armazenamento: mostra só nesta sessão */
    }
  }
  var vistosSessao = {};
  function novosMomentos() {
    if (!D || !D.momentos || !D.config || (D.config.ecras || []).indexOf('momentos') < 0) return;
    var vistos = lerVistos();
    D.momentos.forEach(function (m) {
      if (vistosSessao[m.id] || vistos.indexOf(m.id) >= 0) return;
      vistosSessao[m.id] = true;
      momentos.push(m);
    });
  }
  function ecraMomento(m) {
    var c = el('div', 's-momento ' + m.tipo);
    c.appendChild(el('div', 's-momento-ico', m.tipo === 'recorde' ? '🏆' : m.tipo === 'rota' ? '★' : '🎯'));
    c.appendChild(el('div', 's-momento-t', m.titulo));
    if (m.promotor && D.local !== 'aberta') c.appendChild(el('div', 's-momento-n', 'Parabéns, ' + m.promotor + '!'));
    else c.appendChild(el('div', 's-momento-n', 'Parabéns, equipa!'));
    return [c];
  }

  var ECRAS = {
    hoje: {
      titulo: 'Vendas de hoje',
      f: function () {
        return D.local === 'aberta' ? ecraHojeAberta() : ecraHoje();
      },
    },
    acumulado: { titulo: 'Ritmo acumulado × objectivo', f: ecraAcumulado },
    previsao: { titulo: 'Previsão de fecho', f: ecraPrevisao },
    comparacoes: { titulo: 'Comparações', f: ecraComparacoes },
    calor: { titulo: 'Mapa de calor das vendas', f: ecraCalor },
    funil: { titulo: 'Funil das visitas', f: ecraFunil },
    mapa: { titulo: 'Rotas no mapa', f: ecraMapa },
    pareto: { titulo: 'Concentração dos clientes', f: ecraPareto },
    artigos: { titulo: 'Artigos em destaque', f: ecraArtigos },
    quadrante: { titulo: 'Quadrante das rotas', f: ecraQuadrante },
    stock: { titulo: 'Cobertura do stock', f: ecraStock },
    operacao: { titulo: 'Saúde da operação', f: ecraOperacao },
    recordes: { titulo: 'Recordes', f: ecraRecordes },
    contagem: { titulo: 'Contagem do mês', f: ecraContagem },
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
        ? [
            'hoje',
            'acumulado',
            'previsao',
            'comparacoes',
            'ritmo',
            'calor',
            'tendencia',
            'visitas',
            'funil',
            'rotas',
            'clientes',
            'recordes',
            'contagem',
            'destaques',
          ]
        : [
            'hoje',
            'acumulado',
            'previsao',
            'comparacoes',
            'ritmo',
            'calor',
            'tendencia',
            'visitas',
            'funil',
            'rotas',
            'mapa',
            'clientes',
            'pareto',
            'artigos',
            'quadrante',
            'stock',
            'operacao',
            'recordes',
            'contagem',
            'rank-hoje',
            'rank-mes',
            'destaques',
          ]);
    // os momentos do dia não rodam: aparecem quando acontecem
    var l = c.filter(function (k) {
      return ECRAS[k];
    });
    return l.length ? l : ['hoje'];
  }
  function segundos() {
    return (D && D.config && D.config.segundos) || 20;
  }

  function desenhar(momento) {
    var m = $('s-ecra');
    var novo = m.cloneNode(false);
    var L = lista();
    var k = L[ecra % L.length];
    $('s-titulo').textContent = momento ? 'Momento do dia' : D && !erro ? ECRAS[k].titulo : 'Painel da sala comercial';
    var conteudo;
    if (erro) conteudo = [el('p', 's-msg', erro)];
    else if (!D) conteudo = [el('p', 's-msg', 'A carregar…')];
    else if (momento) conteudo = ecraMomento(momento);
    else {
      // um ecrã que falhe (dados inesperados) não pára a rotação: mostra a mensagem e segue
      try {
        conteudo = ECRAS[k].f();
      } catch (e) {
        conteudo = [el('p', 's-msg', 'Este ecrã ficará disponível com os próximos dados.')];
      }
    }
    conteudo.forEach(function (x) {
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
    novosMomentos();
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
  var versao = '';
  function carregar() {
    if (previa) return;
    fetch(API, { cache: 'no-store', credentials: 'omit', referrerPolicy: 'no-referrer', headers: { 'X-Painel-Codigo': codigo } })
      .then(function (r) {
        return r.json().then(function (j) {
          if (!r.ok || !j.ok) throw new Error(j.erro || 'HTTP ' + r.status);
          var primeiro = !D;
          // versão nova do painel instalada no servidor: recarrega para a usar (só uma vez por versão)
          if (j.versao) {
            if (!versao) versao = j.versao;
            else if (j.versao !== versao) {
              location.reload();
              return;
            }
          }
          D = j;
          erro = '';
          recebido = Date.now();
          novosMomentos();
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
      var mo = momentos.shift();
      if (mo) {
        marcarVisto(mo.id);
        desenhar(mo);
      } else {
        ecra++;
        desenhar();
      }
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
