// App de gestão «Naval Gestão» (gestao/index.html): telemóvel, só de leitura. Separadores Hoje, Rotas, Alertas e
// Assistente; os dados vêm da API central com o âmbito da conta (v1/sessao, v1/indicadores, v1/vendas/estado,
// v1/assistente) e actualizam-se a cada 5 minutos enquanto a app está aberta. Nada fica guardado no telefone.
//
// Desenho segundo as apps de gestão de referência (Shopify, Stripe, Power BI, Salesforce, SAP Fiori) e as regras da
// Apple (Human Interface Guidelines) e da Google (Material 3):
//  - um número principal por ecrã, com a comparação e a tendência ao lado (nunca um número sozinho);
//  - período em controlo segmentado (Hoje · Semana · Mês) e gráficos simples (barras e linha acumulada);
//  - «Precisa de atenção» logo no início: os alertas que pedem acção, com atalho para a lista completa;
//  - estado dos dados sempre visível (hora da actualização e ligação ao Pinguim BO) e aviso claro quando os valores
//    podem estar incompletos;
//  - variações com seta e sinal, não só com cor (WCAG 1.4.1); alvos de toque com pelo menos 44 px;
//  - puxar para actualizar, esqueleto durante a primeira leitura e detalhe da rota numa folha que sobe do fundo.
import { apiFetch, apiJson } from './api.js';
import { dl, n0, n1, setFmt } from './formatos.js';
import { sinal, textoAlerta, textoRico } from './gestao-textos.js';
import { LANG, mudarIdioma, t } from './i18n/i18n.js';
import { el, svg } from './util.js';
import { eNC, pendenteDe, recebidoDe, recebidoPorCategoria } from './meios.js';

const $ = (id) => document.getElementById(id);
const NS = 'http://www.w3.org/2000/svg';
// valores redondos no telemóvel (sem casas decimais); compactos nos cartões e nas listas
const kz = (v) => (v == null || isNaN(v) ? '—' : n0(Math.round(v)) + '\u00a0Kz');
const kzc = (v) => {
  if (v == null || isNaN(v)) return '—';
  const a = Math.abs(v);
  if (a >= 1e6) return n1(v / 1e6).replace(/[.,]0$/, '') + '\u00a0M\u00a0Kz';
  if (a >= 1e4) return n0(Math.round(v / 1e3)) + (t('mil') === 'mil' ? '\u00a0mil' : t('mil')) + '\u00a0Kz';
  return kz(v);
};
// POST com o código CSRF da sessão (sem os módulos do portal completo)
const postJson = (rota, corpo) =>
  apiFetch(rota, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...corpo, csrf: E.sessao && E.sessao.csrf }) }).then(
    (r) => r.json().catch(() => ({ ok: false, erro: 'Sem resposta do servidor.' })),
    () => ({ ok: false, erro: 'Sem resposta do servidor.' }),
  );
const E = {
  sessao: null,
  ind: null,
  fonte: null,
  erro: null,
  ao: null,
  aoEm: 0,
  aoLer: false,
  sep: 'hoje',
  periodo: 'hoje',
  ordemRotas: 'agora',
  conversa: [],
  aPensar: false,
  aLer: false,
};
const ICONE = { hoje: 'home', rotas: 'trophy', controlo: 'kpi', alertas: 'sino', assistente: 'ia' };
const SETA = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>';

// ───────────── peças ─────────────
/** Variação em «pastilha»: seta e sinal (não só cor). Sem base de comparação, diz isso mesmo. */
function pastilha(v) {
  if (v == null) return el('span', 'ag-var neutra', t('sem base de comparação'));
  const c = v > 0.005 ? 'sobe' : v < -0.005 ? 'desce' : 'neutra';
  const p = el('span', 'ag-var ' + c, (c === 'sobe' ? '▲ ' : c === 'desce' ? '▼ ' : '') + sinal(v));
  p.setAttribute('aria-label', (c === 'sobe' ? t('subida de') : c === 'desce' ? t('descida de') : t('variação de')) + ' ' + sinal(v));
  return p;
}
const linhaVar = (v, txt) => {
  const w = el('div', 'ag-comp');
  w.append(pastilha(v), el('span', null, txt));
  return w;
};
const barra = (frac) => {
  const b = el('div', 'ag-barra'),
    i = el('i');
  i.style.width = Math.max(0, Math.min(100, Math.round((frac || 0) * 100))) + '%';
  b.append(i);
  return b;
};
const cartao = (cls, ...filhos) => {
  const c = el('section', 'ag-cartao' + (cls ? ' ' + cls : ''));
  c.append(...filhos);
  return c;
};
const titulo = (txt, accao) => {
  const h = el('div', 'ag-sec');
  h.append(el('h2', null, txt));
  if (accao) h.append(accao);
  return h;
};
const botaoLigacao = (txt, fn) => {
  const b = el('button', 'ag-mais', txt);
  b.type = 'button';
  b.addEventListener('click', fn);
  return b;
};
const s = (tag, attrs) => {
  const n = document.createElementNS(NS, tag);
  Object.entries(attrs || {}).forEach(([k, v]) => n.setAttribute(k, v));
  return n;
};
function grafico(rotulo) {
  const g = s('svg', { viewBox: '0 0 320 96', preserveAspectRatio: 'none', role: 'img', 'aria-label': rotulo, class: 'ag-graf' });
  return g;
}
/** Barras (valores) com a última realçada; «fantasmas» por trás para comparar (semana passada). */
function barras(valores, rotulos, fantasmas, legenda) {
  const g = grafico(legenda);
  const max = Math.max(1, ...valores, ...(fantasmas || []));
  const n = valores.length,
    larg = 320 / n,
    w = Math.max(4, larg * 0.62);
  [0.5, 1].forEach((f) => g.append(s('line', { x1: 0, x2: 320, y1: 92 - 84 * f, y2: 92 - 84 * f, class: 'ag-gl' })));
  valores.forEach((v, i) => {
    const x = i * larg + (larg - w) / 2;
    if (fantasmas && fantasmas[i] != null) {
      const hf = Math.max(1.5, (84 * fantasmas[i]) / max);
      g.append(s('rect', { x: x - 2, y: 92 - hf, width: w + 4, height: hf, rx: 3, class: 'ag-fant' }));
    }
    if (v == null) return;
    const h = Math.max(v > 0 ? 3 : 1.5, (84 * v) / max);
    const r = s('rect', { x, y: 92 - h, width: w, height: h, rx: 3, class: i === n - 1 && !fantasmas ? 'ag-b on' : 'ag-b' });
    const tt = s('title');
    tt.textContent = (rotulos[i] || '') + ': ' + kz(v);
    r.append(tt);
    g.append(r);
  });
  return g;
}
/** Linha acumulada do período e do período anterior (tracejada), como no Shopify e no Stripe. */
function linhas(atual, anterior, legenda) {
  const g = grafico(legenda);
  const n = Math.max(atual.length, anterior.length, 2);
  const max = Math.max(1, ...atual, ...anterior);
  const pts = (a) => a.map((v, i) => ((i * 320) / (n - 1)).toFixed(1) + ',' + (92 - (84 * v) / max).toFixed(1)).join(' ');
  [0.5, 1].forEach((f) => g.append(s('line', { x1: 0, x2: 320, y1: 92 - 84 * f, y2: 92 - 84 * f, class: 'ag-gl' })));
  if (anterior.length > 1) g.append(s('polyline', { points: pts(anterior), class: 'ag-l-ant' }));
  if (atual.length > 1) g.append(s('polyline', { points: pts(atual), class: 'ag-l' }));
  if (atual.length) {
    const i = atual.length - 1;
    g.append(s('circle', { cx: ((i * 320) / (n - 1)).toFixed(1), cy: (92 - (84 * atual[i]) / max).toFixed(1), r: 4, class: 'ag-ponto' }));
  }
  return g;
}
const legenda = (...itens) => {
  const l = el('div', 'ag-legenda');
  itens.forEach(([cls, txt]) => {
    const x = el('span');
    x.append(el('i', cls), document.createTextNode(txt));
    l.append(x);
  });
  return l;
};
function segmentos(opcoes, actual, fn, rotulo) {
  const g = el('div', 'ag-seg');
  g.setAttribute('role', 'tablist');
  g.setAttribute('aria-label', rotulo);
  opcoes.forEach(([id, nome]) => {
    const b = el('button', null, nome);
    b.type = 'button';
    b.setAttribute('role', 'tab');
    b.setAttribute('aria-selected', id === actual ? 'true' : 'false');
    b.addEventListener('click', () => fn(id));
    g.append(b);
  });
  return g;
}

// ───────────── dados derivados ─────────────
const serie = () => (E.ind && E.ind.serie) || [];
const valorDe = (d) => (serie().find((x) => x.d === d) || {}).t;
const somaDias = (iso, n) => {
  const d = new Date(iso + 'T12:00:00Z');
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
};
const diaSemana = (iso) => (new Date(iso + 'T12:00:00Z').getUTCDay() + 6) % 7; // 0 = segunda
const ROT_SEMANA = ['seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'];

/** Aviso quando os números podem estar incompletos (fonte sem ligação ou dias sem vendas registadas). */
function avisoDados() {
  if (typeof navigator !== 'undefined' && navigator.onLine === false) return t('Sem ligação à internet. Os valores mostrados podem não estar actualizados.');
  if (E.fonte && E.fonte.ok === false) return t('Pinguim BO sem ligação: os valores podem estar incompletos.');
  const d = E.ind;
  if (!d) return null;
  const ult = d.ultima_venda;
  const habitual = (d.ontem && d.ontem.habitual) || (d.mes && d.mes.anterior);
  if (habitual && (!ult || ult < d.ontem.dia))
    return ult
      ? t('Sem vendas registadas desde {d}. Confirme a sincronização dos telemóveis e a ligação ao Pinguim BO.', { d: dl(ult) })
      : t('Sem vendas registadas este mês. Confirme a sincronização dos telemóveis e a ligação ao Pinguim BO.');
  return null;
}

// ───────────── Hoje ─────────────
function heroi() {
  const d = E.ind;
  const p = E.periodo;
  const c = cartao('ag-heroi');
  if (p === 'hoje') {
    const h = d.hoje;
    c.append(el('div', 'ag-rotulo', t('Vendas de hoje')), el('div', 'ag-grande', kz(h.total)));
    if (h.previsto) {
      const prog = h.total / h.previsto;
      c.append(barra(prog), el('div', 'ag-nota', t('{p} da previsão do dia ({v})', { p: n0(Math.round(prog * 100)) + '%', v: kz(h.previsto) })));
    } else c.append(el('div', 'ag-nota', t('Sem previsão para hoje')));
    c.append(el('div', 'ag-nota', t('{n} documentos', { n: n0(h.documentos) })));
    const dias = Array.from({ length: 14 }, (_, i) => somaDias(h.dia, i - 13));
    c.append(
      barras(
        dias.map((x) => valorDe(x) || 0),
        dias.map(dl),
        null,
        t('Últimos 14 dias'),
      ),
      legenda(['ag-lg-b', t('Últimos 14 dias')], ['ag-lg-on', t('Hoje')]),
    );
  } else if (p === 'semana') {
    const w = d.semana;
    c.append(el('div', 'ag-rotulo', t('Vendas da semana')), el('div', 'ag-grande', kz(w.total)));
    c.append(linhaVar(w.variacao, t('face aos mesmos dias da semana passada ({v})', { v: kzc(w.anterior) })));
    const dias = Array.from({ length: 7 }, (_, i) => somaDias(w.de, i));
    const hoje = d.hoje.dia;
    c.append(
      barras(
        dias.map((x) => (x <= hoje ? valorDe(x) || 0 : null)),
        dias.map(dl),
        dias.map((x) => valorDe(somaDias(x, -7)) || 0),
        t('Esta semana e a semana passada'),
      ),
    );
    const eixo = el('div', 'ag-eixo');
    dias.forEach((x) => eixo.append(el('span', null, t(ROT_SEMANA[diaSemana(x)]))));
    c.append(eixo, legenda(['ag-lg-on', t('Esta semana')], ['ag-lg-f', t('Semana passada')]));
  } else {
    const m = d.mes;
    c.append(el('div', 'ag-rotulo', t('Vendas do mês')), el('div', 'ag-grande', kz(m.total)));
    c.append(linhaVar(m.variacao, t('face aos mesmos dias do mês passado ({v})', { v: kzc(m.anterior) })));
    const acum = (de, ate) => {
      let a = 0;
      return serie()
        .filter((x) => x.d >= de && x.d <= ate)
        .map((x) => (a += x.t));
    };
    const iniAnt = serie().length ? serie()[0].d : m.de;
    const fimAnt = somaDias(m.de, -1);
    c.append(
      linhas(acum(m.de, d.hoje.dia), acum(iniAnt, fimAnt), t('Acumulado do mês e do mês passado')),
      legenda(['ag-lg-l', t('Este mês')], ['ag-lg-la', t('Mês passado')]),
    );
  }
  return c;
}

function cartaoKpi(rotulo, valor, v, txt) {
  const c = cartao('ag-kpi', el('div', 'ag-rotulo', rotulo), el('div', 'ag-medio', valor));
  c.append(v === undefined ? el('div', 'ag-nota', txt) : linhaVar(v, txt));
  return c;
}
function kpis() {
  const d = E.ind;
  const g = el('div', 'ag-grelha');
  g.append(cartaoKpi(t('Ontem ({d})', { d: dl(d.ontem.dia) }), kzc(d.ontem.total), d.ontem.variacao, t('face ao habitual')));
  const cp = d.compras;
  if (cp) {
    const varM = (a, b) => (a != null && b ? a / b - 1 : null);
    g.append(
      cartaoKpi(t('Compras de clientes'), n0(cp.mes.compras), varM(cp.mes.compras, cp.mes_anterior.compras), t('face ao mês passado')),
      cartaoKpi(t('Valor médio por compra'), kzc(cp.mes.valor_medio), varM(cp.mes.valor_medio, cp.mes_anterior.valor_medio), t('face ao mês passado')),
      cartaoKpi(
        t('Artigos por compra'),
        cp.mes.artigos_por_compra == null ? '—' : n1(cp.mes.artigos_por_compra),
        varM(cp.mes.artigos_por_compra, cp.mes_anterior.artigos_por_compra),
        t('face ao mês passado'),
      ),
    );
  }
  return g;
}
function linhaAlerta(x, compacta) {
  const [tit, det] = textoAlerta(x);
  const b = el(compacta ? 'button' : 'article', 'ag-al ' + x.gravidade);
  if (compacta) {
    b.type = 'button';
    b.addEventListener('click', () => mudar('alertas'));
  }
  const ic = el('span', 'ag-al-ic', x.gravidade === 'critico' ? '!' : 'i');
  ic.setAttribute('aria-label', x.gravidade === 'critico' ? t('Crítico') : t('Aviso'));
  const tx = el('span', 'ag-al-tx');
  tx.append(el('strong', null, tit));
  if (!compacta && det) tx.append(el('span', null, det));
  b.append(ic, tx);
  if (compacta) b.insertAdjacentHTML('beforeend', SETA);
  return b;
}
function linhaRota(r, i, max, ordem) {
  const b = el('button', 'ag-rota');
  b.type = 'button';
  const v = ordem === 'hoje' ? r.hoje : r.mes;
  const nome = el('span', 'ag-rota-n');
  nome.append(el('strong', null, r.rota + (r.promotor ? ' · ' + r.promotor : '')));
  const sub = el('span', 'ag-rota-s');
  if (ordem === 'hoje') sub.append(document.createTextNode(r.previsto_hoje ? t('previsto {v}', { v: kzc(r.previsto_hoje) }) : t('sem previsão')));
  else sub.append(pastilha(r.variacao_mes));
  if (r.sequencia >= 3) sub.append(el('span', 'ag-selo', t('{n} dias acima do habitual', { n: r.sequencia })));
  nome.append(sub, barra(v / max));
  b.append(el('span', 'ag-pos', String(i + 1)), nome, el('span', 'ag-valor', kzc(v)));
  b.insertAdjacentHTML('beforeend', SETA);
  b.setAttribute('aria-label', t('Rota {r}', { r: r.rota }) + ': ' + kz(v));
  b.addEventListener('click', () => folhaRota(r.rota));
  return b;
}
function hoje() {
  const d = E.ind;
  const out = [
    segmentos(
      [
        ['hoje', t('Hoje')],
        ['semana', t('Semana')],
        ['mes', t('Mês')],
      ],
      E.periodo,
      (id) => {
        E.periodo = id;
        desenhar();
      },
      t('Período'),
    ),
  ];
  const vivo = E.periodo === 'hoje' ? cartaoVivo() : null;
  if (vivo) out.push(vivo);
  else out.push(heroi());
  const a = d.alertas;
  if (a && a.principais && a.principais.length) {
    out.push(
      titulo(
        t('Precisa de atenção'),
        botaoLigacao(t('Ver todos'), () => mudar('alertas')),
      ),
    );
    const l = el('div', 'ag-lista');
    a.principais.slice(0, 3).forEach((x) => l.append(linhaAlerta(x, true)));
    out.push(l);
  }
  out.push(titulo(t('Indicadores')), kpis());
  if (d.rotas.length) {
    out.push(
      titulo(
        t('Melhores rotas do mês'),
        botaoLigacao(t('Ver classificação'), () => mudar('rotas')),
      ),
    );
    const max = Math.max(...d.rotas.map((r) => r.mes), 1);
    const l = el('div', 'ag-lista');
    d.rotas.slice(0, 3).forEach((r, i) => l.append(linhaRota(r, i, max, 'mes')));
    out.push(l);
  }
  return out;
}

// ───────────── Rotas ─────────────
function rotas() {
  if (E.ordemRotas === 'agora' && !pode('inicio')) E.ordemRotas = 'mes';
  const ordem = E.ordemRotas;
  const opcoes = [
    ['hoje', t('Hoje')],
    ['mes', t('Mês até hoje')],
  ];
  if (pode('inicio')) opcoes.unshift(['agora', t('Agora')]);
  const out = [
    segmentos(
      opcoes,
      ordem,
      (id) => {
        E.ordemRotas = id;
        desenhar();
      },
      t('Ver'),
    ),
  ];
  if (ordem === 'agora') return out.concat(rotasAoVivo());
  const rs = E.ind.rotas.slice().sort((a, b) => (ordem === 'hoje' ? b.hoje - a.hoje : b.mes - a.mes));
  if (!rs.length) return out.concat(el('p', 'ag-vazio', t('Sem vendas no período.')));
  const cresce = rs.filter((r) => r.variacao_mes > 0.005).length,
    cai = rs.filter((r) => r.variacao_mes < -0.005).length;
  out.push(el('p', 'ag-resumo', t('{n} rotas · {a} a crescer · {b} a cair', { n: n0(rs.length), a: n0(cresce), b: n0(cai) })));
  const max = Math.max(...rs.map((r) => (ordem === 'hoje' ? r.hoje : r.mes)), 1);
  const l = el('div', 'ag-lista');
  rs.forEach((r, i) => l.append(linhaRota(r, i, max, ordem)));
  out.push(l);
  return out;
}
/** Detalhe da rota numa folha que sobe do fundo (padrão das apps iOS e Android). */
/**
 * Ficha da rota (folha que sobe do fundo, como no iOS): estado agora, vendas, stock na carrinha neste momento,
 * fecho do dia, últimas vendas e os últimos 14 dias. Actualiza-se sozinha com os dados ao vivo.
 */
function folhaRota(rota) {
  const r = (E.ind.rotas || []).find((x) => String(x.rota) === String(rota)) || { rota, promotor: '' };
  const vivo = E.ao && E.ao.vendas ? rotasAgora(E.ao.vendas).find((x) => String(x.rota) === String(rota)) : null;
  let f = $('ag-folha');
  if (!f) {
    f = el('dialog', 'ag-folha');
    f.id = 'ag-folha';
    f.addEventListener('click', (e) => {
      if (e.target === f) f.close();
    });
    document.body.append(f);
  }
  f.dataset.rota = String(rota);
  const cab = el('div', 'ag-folha-cab');
  const fechar = el('button', 'ag-mais', t('Fechar'));
  fechar.type = 'button';
  fechar.addEventListener('click', () => f.close());
  const tit = el('div');
  tit.append(el('h2', null, t('Rota {r}', { r: r.rota })));
  if (r.promotor || (vivo && vivo.promotor)) tit.append(el('p', 'ag-nota', r.promotor || vivo.promotor));
  cab.append(tit, fechar);
  const corpo = el('div', 'ag-folha-corpo');
  // agora
  if (vivo) {
    const [rot, cls] = ESTADO_ROTA[vivo.estado];
    const e = el('div', 'ag-folha-estado');
    e.append(
      el('span', 'ag-estado-rota ' + cls, t(rot)),
      document.createTextNode(vivo.docs ? ' ' + t('{n} doc.', { n: vivo.docs }) + ' · ' + t('última {q}', { q: haQuanto(vivo.min) }) : ''),
    );
    corpo.append(e);
  }
  const g = el('div', 'ag-grelha');
  g.append(
    cartaoKpi(t('Hoje'), kzc(vivo ? vivo.total : r.hoje || 0), undefined, r.previsto_hoje ? t('previsto {v}', { v: kzc(r.previsto_hoje) }) : t('sem previsão')),
    cartaoKpi(t('Ontem'), kzc(r.ontem || 0), r.habitual_ontem ? r.ontem / r.habitual_ontem - 1 : null, t('face ao habitual')),
    cartaoKpi(t('Mês até hoje'), kzc(r.mes || 0), r.variacao_mes, t('face ao mês passado')),
    cartaoKpi(t('Mês passado'), kzc(r.mes_anterior || 0), undefined, t('mesmos dias')),
  );
  corpo.append(g);
  // recebido hoje: dinheiro (a entregar) e Multicaixa
  const vr = E.ao && E.ao.vendas ? E.ao.vendas.filter((v) => String(v.rota) === String(rota)) : [];
  if (vr.length) {
    const rr = recebido(vr);
    corpo.append(titulo(t('Recebido hoje') + ' · ' + kzc(rr.total)), grelhaRecebido(rr));
  }
  // stock na carrinha neste momento
  if (pode('stock-carrinha')) {
    const st = stockDaRota(rota);
    corpo.append(titulo(t('Stock na carrinha agora')));
    if (!E.ao) corpo.append(esqueleto());
    else if (!st || !(st.itens || []).length) corpo.append(el('p', 'ag-vazio', t('Sem stock na carrinha no Pinguim BO.')));
    else {
      const res = el(
        'p',
        'ag-resumo',
        t('{a} artigos · {q} unidades', { a: n0((st.itens || []).length), q: n0(st.total_quantidade || 0) }) +
          (st.total_caixas ? ' · ' + t('{c} caixas', { c: n0(st.total_caixas) }) : '') +
          (st.armazem ? ' · ' + st.armazem : ''),
      );
      const l = el('div', 'ag-lista ag-feed');
      st.itens
        .slice()
        .sort((a, b) => (+b.quantidade || 0) - (+a.quantidade || 0))
        .forEach((x) => {
          const li = el('div', 'ag-feed-l');
          const tx = el('span', 'ag-feed-t');
          tx.append(el('strong', null, x.produto || x.codigo), el('span', null, x.codigo || ''));
          li.append(tx, el('span', 'ag-valor' + ((+x.quantidade || 0) < 0 ? ' desce' : ''), n0(+x.quantidade || 0)));
          l.append(li);
        });
      corpo.append(res, l);
    }
  }
  // fecho do dia (conciliação)
  const fe = fechoDaRota(rota);
  if (fe && FECHO[fe.fecho]) {
    const [rot, cls] = FECHO[fe.fecho];
    const p = el('div', 'ag-folha-estado');
    p.append(el('strong', null, t('Fecho do dia') + ': '), el('span', 'ag-estado-rota ' + cls, t(rot)));
    corpo.append(p);
  }
  // últimas vendas da rota
  const us =
    E.ao && E.ao.vendas
      ? E.ao.vendas
          .filter((v) => String(v.rota) === String(rota) && v.hora)
          .sort((a, b) => String(b.hora).localeCompare(String(a.hora)))
          .slice(0, 10)
      : [];
  if (us.length) {
    corpo.append(titulo(t('Últimas vendas')));
    const l = el('div', 'ag-lista ag-feed');
    us.forEach((v) => {
      const li = el('div', 'ag-feed-l');
      const tx = el('span', 'ag-feed-t');
      tx.append(
        el('strong', null, v.cliente || t('Consumidor final')),
        el('span', null, String(v.hora).slice(0, 5) + (v.nc ? ' · ' + t('nota de crédito') : '')),
      );
      li.append(tx, el('span', 'ag-valor' + (v.nc ? ' desce' : ''), kzc(+v.total || 0)));
      l.append(li);
    });
    corpo.append(l);
  }
  if (r.serie && r.serie.length) {
    const dias = Array.from({ length: r.serie.length }, (_, i) => somaDias(E.ind.hoje.dia, i - r.serie.length + 1));
    corpo.append(cartao('ag-heroi', el('div', 'ag-rotulo', t('Últimos 14 dias')), barras(r.serie, dias.map(dl), null, t('Últimos 14 dias'))));
  }
  if (r.sequencia >= 3) corpo.append(el('p', 'ag-selo', t('{n} dias acima do habitual', { n: r.sequencia })));
  f.replaceChildren(cab, corpo);
  if (!f.open) {
    f.showModal();
    if (!E.ao) carregarAgora(true);
  }
}

// ───────────── Alertas ─────────────
function alertas() {
  const a = E.ind.alertas;
  if (!a) return [el('p', 'ag-vazio', t('O seu perfil não tem acesso aos alertas.'))];
  const resumo = el('div', 'ag-contas');
  [
    ['critico', a.critico, t('Críticos')],
    ['aviso', a.aviso, t('Avisos')],
    ['info', a.info, t('Destaques')],
  ].forEach(([cls, n, nome]) => {
    const x = el('div', 'ag-conta ' + cls);
    x.append(el('strong', null, n0(n)), el('span', null, nome));
    resumo.append(x);
  });
  const out = [linhaNotificacoes(), ...caixaNotificacoes(), titulo(t('Alertas de hoje')), resumo];
  if (!a.principais.length) out.push(el('p', 'ag-vazio', t('Sem alertas: tudo dentro do habitual.')));
  const l = el('div', 'ag-lista');
  a.principais.forEach((x) => l.append(linhaAlerta(x, false)));
  out.push(l);
  const ver = el('a', 'ag-ligacao', t('Ver todos os alertas no portal'));
  ver.href = '../reports/#alertas';
  out.push(ver);
  return out;
}

// ───────────── Notificações no telemóvel ─────────────
// Como nas apps de referência (WhatsApp, apps dos bancos): pede a autorização só quando a pessoa carrega no botão,
// explica antes para que serve, deixa escolher o que recebe e as horas de silêncio, e mostra o número de por ler no
// ícone da app. No iPhone só funciona com a app no ecrã principal (iOS 16.4 ou mais recente).
const temPush = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
const instalada = () => matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
const ios = () => /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
const chaveBin = (s64) => Uint8Array.from(atob(s64.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s64.length + 3) % 4)), (c) => c.charCodeAt(0));
const registo = () => (temPush() ? navigator.serviceWorker.getRegistration('../').then((r) => r || navigator.serviceWorker.ready) : Promise.resolve(null));
function numeroNoIcone(n) {
  try {
    if (n > 0 && navigator.setAppBadge) navigator.setAppBadge(n).catch(() => {});
    else if (navigator.clearAppBadge) navigator.clearAppBadge().catch(() => {});
  } catch (e) {
    /* sem suporte */
  }
}
function carregarNotif() {
  const sub = registo().then(
    (r) => (r && r.pushManager ? r.pushManager.getSubscription() : null),
    () => null,
  );
  return sub.then((s) => {
    E.notifEndpoint = s ? s.endpoint : '';
    return apiJson('notificacoes' + (E.notifEndpoint ? '?endpoint=' + encodeURIComponent(E.notifEndpoint) : '')).then(
      (d) => {
        if (d && d.ok) {
          E.notif = d;
          numeroNoIcone(d.nao_lidas);
        }
        desenhar();
      },
      () => {},
    );
  });
}
function accaoNotif(corpo) {
  return postJson('notificacoes', { endpoint: E.notifEndpoint, ...corpo }).then((d) => {
    if (d && d.ok) {
      E.notif = d;
      E.notifErro = '';
    } else E.notifErro = (d && d.erro) || 'Sem resposta do servidor.';
    E.notifEstado = '';
    desenhar();
    const fn = $('ag-folha-notif');
    if (fn && fn.open) folhaNotificacoes();
    return d;
  });
}
async function ligarNotif() {
  E.notifEstado = 'a-ligar';
  E.notifErro = '';
  desenhar();
  try {
    const perm = await Notification.requestPermission();
    if (perm !== 'granted') {
      E.notifEstado = '';
      E.notifErro = perm === 'denied' ? 'As notificações estão bloqueadas nas definições do telefone para esta app.' : 'As notificações não foram autorizadas.';
      desenhar();
      return;
    }
    const r = await registo();
    let s = await r.pushManager.getSubscription();
    if (!s) s = await r.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: chaveBin(E.notif.chave) });
    E.notifEndpoint = s.endpoint;
    const d = await accaoNotif({ accao: 'subscrever', subscricao: s.toJSON(), idioma: LANG });
    if (d && d.ok) accaoNotif({ accao: 'testar' });
  } catch (e) {
    E.notifEstado = '';
    E.notifErro = 'Não foi possível ligar as notificações neste telefone.';
    desenhar();
  }
}
async function desligarNotif() {
  const r = await registo();
  const s = r && r.pushManager ? await r.pushManager.getSubscription() : null;
  const ep = s ? s.endpoint : E.notifEndpoint;
  if (s) await s.unsubscribe().catch(() => {});
  E.notifEndpoint = '';
  accaoNotif({ accao: 'cancelar', endpoint: ep });
}
function interruptor(ligado, rotulo, fn) {
  const b = el('button', 'ag-int');
  b.type = 'button';
  b.setAttribute('role', 'switch');
  b.setAttribute('aria-checked', String(!!ligado));
  b.setAttribute('aria-label', rotulo);
  b.append(el('span', 'ag-int-bola'));
  b.addEventListener('click', () => fn(!ligado));
  return b;
}
/** Linha «Notificações» (como nas Definições do iPhone): estado à direita; abre a folha com as opções. */
function linhaNotificacoes() {
  const N = E.notif;
  const sub = N && N.subscrito && typeof Notification !== 'undefined' && Notification.permission === 'granted';
  const b = el('button', 'ag-rota ag-notif-linha');
  b.type = 'button';
  const ic = el('span', 'ag-notif-ic');
  ic.innerHTML = svg('sino'); // nosemgrep: insecure-innerhtml -- ícone SVG estático do próprio código
  const nome = el('span', 'ag-rota-n');
  nome.append(el('strong', null, t('Notificações')), el('span', 'ag-rota-s', sub ? t('Ligadas neste telefone') : t('Desligadas · toque para ligar')));
  b.append(ic, nome);
  b.insertAdjacentHTML('beforeend', SETA);
  b.addEventListener('click', folhaNotificacoes);
  return b;
}
function folhaNotificacoes() {
  let f = $('ag-folha-notif');
  if (!f) {
    f = el('dialog', 'ag-folha');
    f.id = 'ag-folha-notif';
    f.addEventListener('click', (e) => {
      if (e.target === f) f.close();
    });
    document.body.append(f);
  }
  const cab = el('div', 'ag-folha-cab');
  const fechar = el('button', 'ag-mais', t('Fechar'));
  fechar.type = 'button';
  fechar.addEventListener('click', () => f.close());
  cab.append(el('h2', null, t('Notificações')), fechar);
  const corpo = el('div', 'ag-folha-corpo');
  corpo.append(cartaoNotificacoes());
  f.replaceChildren(cab, corpo);
  if (!f.open) f.showModal();
}
function cartaoNotificacoes() {
  const c = cartao('ag-notif');
  const cab = el('div', 'ag-notif-cab');
  const ic = el('span', 'ag-notif-ic');
  ic.innerHTML = svg('sino'); // nosemgrep: insecure-innerhtml -- ícone SVG estático do próprio código
  const tx = el('div');
  tx.append(el('strong', null, t('Notificações neste telefone')));
  c.append(cab);
  const N = E.notif;
  const sub = N && N.subscrito && typeof Notification !== 'undefined' && Notification.permission === 'granted';
  if (!temPush() || (ios() && !instalada())) {
    tx.append(
      el(
        'span',
        null,
        ios() && !instalada()
          ? t('No iPhone, as notificações precisam da app no ecrã principal: Partilhar › Adicionar ao ecrã principal.')
          : t('Este navegador não recebe notificações.'),
      ),
    );
    cab.append(ic, tx);
    return c;
  }
  if (!N) {
    tx.append(el('span', null, t('A carregar…')));
    cab.append(ic, tx);
    return c;
  }
  tx.append(
    el(
      'span',
      null,
      sub ? t('Ligadas. Recebe os alertas mesmo com a app fechada.') : t('Receba os alertas importantes mesmo com a app fechada, como no WhatsApp.'),
    ),
  );
  cab.append(ic, tx);
  if (!sub) {
    const b = el('button', 'ag-botao', E.notifEstado === 'a-ligar' ? t('A ligar…') : t('Ligar notificações'));
    b.type = 'button';
    b.disabled = E.notifEstado === 'a-ligar';
    b.addEventListener('click', ligarNotif);
    c.append(b);
  }
  if (E.notifErro) c.append(el('p', 'ag-notif-erro', t(E.notifErro)));
  if (sub) {
    const l = el('div', 'ag-notif-tipos');
    N.tipos.forEach((x) => {
      const li = el('div', 'ag-notif-tipo');
      const d = el('div');
      d.append(el('strong', null, t(x.nome)), el('span', null, t(x.descricao)));
      li.append(
        d,
        interruptor(x.activo, t(x.nome), (v) => accaoNotif({ accao: 'preferencias', tipos: { [x.id]: v } })),
      );
      l.append(li);
    });
    const sil = el('div', 'ag-notif-tipo');
    const d = el('div');
    d.append(el('strong', null, t('Horas de silêncio')), el('span', null, t('Das 20:00 às 07:00 só chegam as críticas; as outras ficam na lista abaixo.')));
    sil.append(
      d,
      interruptor(!!N.silencio, t('Horas de silêncio'), (v) => accaoNotif({ accao: 'preferencias', silencio: v ? { de: '20:00', ate: '07:00' } : false })),
    );
    l.append(sil);
    c.append(l);
    const acc = el('div', 'ag-notif-acc');
    const teste = el('button', 'ag-mais', t('Enviar um teste'));
    teste.type = 'button';
    teste.addEventListener('click', () => accaoNotif({ accao: 'testar' }));
    const desl = el('button', 'ag-mais', t('Desligar neste telefone'));
    desl.type = 'button';
    desl.addEventListener('click', desligarNotif);
    acc.append(teste, desl);
    c.append(acc);
  }
  return c;
}
const quando = (d) => {
  const x = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/.exec(String(d || ''));
  if (!x) return '';
  const hojeD = new Date();
  const mesmo = +x[1] === hojeD.getFullYear() && +x[2] === hojeD.getMonth() + 1 && +x[3] === hojeD.getDate();
  return (mesmo ? '' : x[3] + '-' + x[2] + ' ') + x[4] + ':' + x[5];
};
function caixaNotificacoes() {
  const N = E.notif;
  if (!N || !N.caixa || !N.caixa.length) return [];
  const l = el('div', 'ag-lista ag-feed');
  N.caixa.slice(0, 20).forEach((m) => {
    const li = el(m.url ? 'a' : 'div', 'ag-feed-l ag-msg' + (m.lida ? '' : ' nova') + ' ' + (m.gravidade || ''));
    if (m.url) li.href = m.url;
    const tx = el('span', 'ag-feed-t');
    const loc = (m.textos && m.textos[LANG]) || [m.titulo, m.corpo];
    tx.append(el('strong', null, loc[0]), el('span', null, loc[1]));
    li.append(tx, el('span', 'ag-msg-h', quando(m.data)));
    l.append(li);
  });
  const out = [
    titulo(t('Notificações recebidas'), N.nao_lidas ? el('span', 'ag-var neutra', t(N.nao_lidas === 1 ? '{n} nova' : '{n} novas', { n: N.nao_lidas })) : null),
    l,
  ];
  // ao ver a lista, ficam lidas (o número no ícone da app volta a zero)
  if (N.nao_lidas && !E.aMarcar) {
    E.aMarcar = true;
    setTimeout(
      () =>
        accaoNotif({ accao: 'lidas' }).then(() => {
          E.aMarcar = false;
          numeroNoIcone(0);
        }),
      2500,
    );
  }
  return out;
}

// ───────────── Assistente ─────────────
const SUGESTOES = [
  'Quanto vendemos ontem face ao habitual?',
  'Que rotas estão a crescer e quais estão a cair este mês?',
  'Há algum alerta crítico hoje? O que devo fazer primeiro?',
];
function perguntar(p) {
  p = String(p || '').trim();
  if (!p || E.aPensar) return;
  const historico = E.conversa.filter((m) => !m.erro).map((m) => ({ papel: m.papel, texto: m.texto }));
  E.conversa.push({ papel: 'pessoa', texto: p });
  E.aPensar = true;
  desenhar();
  postJson('assistente', { pergunta: p, historico, idioma: LANG }).then((r) => {
    E.aPensar = false;
    E.conversa.push(r.ok ? { papel: 'assistente', texto: r.resposta } : { papel: 'assistente', texto: t(r.erro || 'Sem resposta do servidor.'), erro: true });
    desenhar();
  });
}
function assistente() {
  const out = [];
  if (!E.conversa.length) {
    out.push(
      el(
        'p',
        'ag-nota ag-aviso-ia',
        t('As respostas usam só os dados do portal a que tem acesso. Confirme sempre os números importantes nas páginas indicadas como fonte.'),
      ),
    );
    const sg = el('div', 'ag-sug');
    SUGESTOES.forEach((x) => {
      const b = el('button', 'ag-chip', t(x));
      b.type = 'button';
      b.addEventListener('click', () => perguntar(t(x)));
      sg.append(b);
    });
    out.push(sg);
  }
  E.conversa.forEach((m) => {
    const b = el('div', 'ag-msg ' + (m.papel === 'pessoa' ? 'eu' : 'ia'));
    if (m.papel === 'pessoa') b.append(el('p', null, m.texto));
    else b.append(textoRico(m.texto));
    out.push(b);
  });
  if (E.aPensar) out.push(el('div', 'ag-msg ia ag-a-pensar', t('A analisar os dados…')));
  const f = el('form', 'ag-form');
  const ta = el('textarea');
  ta.rows = 1;
  ta.maxLength = 1500;
  ta.placeholder = t('Escreva a pergunta');
  ta.setAttribute('aria-label', t('Pergunta ao assistente'));
  ta.disabled = E.aPensar;
  const b = el('button', 'ag-enviar');
  b.type = 'submit';
  b.disabled = E.aPensar;
  b.setAttribute('aria-label', t('Perguntar'));
  b.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>'; // nosemgrep: insecure-innerhtml -- ícone estático
  f.addEventListener('submit', (e) => {
    e.preventDefault();
    perguntar(ta.value);
  });
  f.append(ta, b);
  out.push(f);
  return out;
}

// ───────────── estrutura ─────────────
// ───────────── Agora (operação em tempo real) ─────────────
// O que está a acontecer no terreno neste momento, como nos painéis «live» das apps de referência (Shopify Live View,
// Uber Fleet, Salesforce Field Service): vendas do dia a entrar, rotas activas e paradas, últimas vendas e o fecho das
// carrinhas. Actualiza-se a cada minuto enquanto este separador está aberto e a app está no ecrã.
const PARADA_MIN = 60; // sem vendas há mais de 60 minutos, em horário de trabalho
const hojeIso = () => {
  const d = new Date();
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
};
const minutosDesde = (hora) => {
  if (!hora) return null;
  const [h, m] = String(hora).split(':').map(Number);
  const agoraD = new Date();
  return agoraD.getHours() * 60 + agoraD.getMinutes() - (h * 60 + m);
};
const haQuanto = (min) =>
  min == null ? '—' : min < 1 ? t('agora mesmo') : min < 60 ? t('há {n} min', { n: min }) : t('há {h} h {m} min', { h: Math.floor(min / 60), m: min % 60 });

function carregarAgora(forcar) {
  if (E.aoLer || (!forcar && E.ao && Date.now() - E.aoEm < 55000)) return;
  E.aoLer = true;
  const dia = hojeIso().replace(/-/g, '');
  // v1/vendas devolve a lista de documentos (ou {vendas} quando vêm também as linhas de artigos)
  const vendas = apiJson('vendas?start_date=' + dia + '&end_date=' + dia).then(
    (d) => (Array.isArray(d) ? d : d && Array.isArray(d.vendas) ? d.vendas : null),
    () => null,
  );
  // stock de cada carrinha neste momento (Pinguim BO, dbo.GET_STOCK)
  const stock = pode('stock-carrinha')
    ? apiJson('stock?data=' + hojeIso()).then(
        (d) => (d && Array.isArray(d.carrinhas) ? d.carrinhas : null),
        () => null,
      )
    : Promise.resolve(null);
  const carr = pode('conciliacao-carrinha')
    ? apiJson('painel-carrinha?data=' + hojeIso()).then(
        (d) => (d && d.ok ? d : null),
        () => null,
      )
    : Promise.resolve(null);
  Promise.all([vendas, carr, stock]).then(([v, c, st]) => {
    E.aoLer = false;
    if (v === null && !E.ao) E.ao = { erro: true };
    else if (v !== null) E.ao = { vendas: v, carrinhas: c, stock: st, hora: new Date() };
    E.aoEm = Date.now();
    if (E.sep === 'hoje' || E.sep === 'rotas' || E.sep === 'controlo') desenhar();
    const f = $('ag-folha');
    if (f && f.open && f.dataset.rota) folhaRota(f.dataset.rota);
  });
}

/** Rotas do dia a partir das vendas: total, documentos, última venda e estado (activa, parada, sem vendas). */
function rotasAgora(vendas) {
  const m = new Map();
  (E.ind.rotas || []).forEach((r) => m.set(r.rota, { rota: r.rota, promotor: r.promotor, total: 0, docs: 0, ultima: '' }));
  vendas.forEach((v) => {
    const x = m.get(v.rota) || { rota: v.rota, promotor: v.promotor || '', total: 0, docs: 0, ultima: '' };
    x.total += +v.total || 0;
    if (!v.nc) x.docs++;
    if ((v.hora || '') > x.ultima) x.ultima = v.hora || '';
    m.set(v.rota, x);
  });
  const h = new Date().getHours();
  const trabalho = h >= 8 && h < 18;
  return [...m.values()]
    .map((x) => {
      const min = x.ultima ? minutosDesde(x.ultima) : null;
      x.min = min;
      x.estado = !x.docs ? (trabalho ? 'sem' : 'fora') : trabalho && min > PARADA_MIN ? 'parada' : 'activa';
      return x;
    })
    .sort((a, b) => ({ parada: 0, sem: 1, activa: 2, fora: 3 })[a.estado] - { parada: 0, sem: 1, activa: 2, fora: 3 }[b.estado] || b.total - a.total);
}

const ESTADO_ROTA = {
  activa: ['Activa', 'ok'],
  parada: ['Parada', 'aviso'],
  sem: ['Sem vendas', 'aviso'],
  fora: ['Sem vendas', 'neutra'],
};

/** Recebido de um conjunto de documentos: total líquido, em dinheiro, em Multicaixa, outros e por receber. */
function recebido(V) {
  const c = recebidoPorCategoria(V);
  const nc = -V.filter(eNC).reduce((a, x) => a + (+x.total || 0), 0);
  const total = V.reduce((a, x) => a + recebidoDe(x), 0) - nc;
  return {
    total,
    dinheiro: c.dinheiro,
    multicaixa: c.multicaixa,
    outros: c.transferencia + c.outro + c.sem_meio,
    por_receber: V.reduce((a, x) => a + pendenteDe(x), 0),
  };
}
/** Grelha «Recebido»: dinheiro, Multicaixa, outros e por receber (o que cada promotor tem de entregar e o que entrou no terminal). */
function grelhaRecebido(r) {
  const g = el('div', 'ag-grelha');
  g.append(
    cartaoKpi(t('Em dinheiro'), kzc(r.dinheiro), undefined, r.total > 0 ? t('{p} do recebido', { p: Math.round((r.dinheiro / r.total) * 100) + '%' }) : ''),
    cartaoKpi(
      t('Em Multicaixa'),
      kzc(r.multicaixa),
      undefined,
      r.total > 0 ? t('{p} do recebido', { p: Math.round((r.multicaixa / r.total) * 100) + '%' }) : '',
    ),
    cartaoKpi(t('Outros meios'), kzc(r.outros), undefined, t('transferências e sem meio')),
    cartaoKpi(t('Por receber'), kzc(r.por_receber), undefined, t('facturas por liquidar')),
  );
  return g;
}

/** Cartão «Ao vivo» do separador Hoje: vendas de hoje a entrar, documentos, valor médio e a última venda. */
function cartaoVivo() {
  if (!pode('inicio')) return null;
  carregarAgora(false);
  if (!E.ao || E.ao.erro) return null;
  const vendas = E.ao.vendas;
  const total = vendas.reduce((a, v) => a + (+v.total || 0), 0);
  const docs = vendas.filter((v) => !v.nc).length;
  const ult = vendas.reduce((a, v) => ((v.hora || '') > a ? v.hora : a), '');
  const c = cartao('ag-heroi ag-vivo');
  const cab = el('div', 'ag-vivo-cab');
  cab.append(el('i', 'ag-pulso'), el('span', null, t('Ao vivo · actualiza a cada minuto')));
  c.append(cab, el('div', 'ag-rotulo', t('Vendas de hoje')), el('div', 'ag-grande', kz(total)));
  const g = el('div', 'ag-mini');
  const mini = (rot, val) => {
    const d = el('div');
    d.append(el('b', null, val), el('span', null, rot));
    return d;
  };
  g.append(
    mini(t('documentos'), n0(docs)),
    mini(t('valor médio'), docs ? kzc(total / docs) : '—'),
    mini(t('última venda'), ult ? haQuanto(minutosDesde(ult)) : '—'),
  );
  c.append(g);
  const r = recebido(vendas);
  const g2 = el('div', 'ag-mini');
  g2.append(mini(t('recebido'), kzc(r.total)), mini(t('em dinheiro'), kzc(r.dinheiro)), mini(t('em Multicaixa'), kzc(r.multicaixa)));
  c.append(g2);
  return c;
}

const stockDaRota = (rota) => ((E.ao && E.ao.stock) || []).find((c) => String(c.rota) === String(rota)) || null;
const fechoDaRota = (rota) => ((E.ao && E.ao.carrinhas && E.ao.carrinhas.rotas) || []).find((c) => String(c.rota) === String(rota)) || null;
const FECHO = {
  por_contar: ['Por contar', 'aviso'],
  contagem_parcial: ['Contagem a meio', 'aviso'],
  por_validar: ['Por validar', 'aviso'],
  por_aprovar: ['Por aprovar', 'aviso'],
  por_fechar: ['Por aprovação final', 'aviso'],
  aprovada: ['Fechada', 'ok'],
  fechada_auto: ['Fechada', 'ok'],
};

/** Rotas em tempo real (segmento «Agora» do separador Rotas): estado, última venda, stock na carrinha e fecho. */
function rotasAoVivo() {
  carregarAgora(false);
  if (!E.ao) return [esqueleto()];
  if (E.ao.erro) {
    const b = el('button', 'ag-botao', t('Tentar de novo'));
    b.type = 'button';
    b.addEventListener('click', () => {
      E.ao = null;
      carregarAgora(true);
      desenhar();
    });
    return [cartao('ag-erro', el('p', null, t('Não foi possível ler as vendas de hoje.')), b)];
  }
  const rs = rotasAgora(E.ao.vendas);
  const out = [];
  const nPar = rs.filter((r) => r.estado === 'parada' || r.estado === 'sem').length;
  const C = E.ao.carrinhas;
  const porContar = C && C.rotas ? C.rotas.filter((r) => ['por_contar', 'por_validar', 'por_aprovar', 'por_fechar'].includes(r.fecho)).length : 0;
  out.push(
    el(
      'p',
      'ag-resumo',
      t('{n} rotas · {a} activas · {p} a pedir atenção', { n: n0(rs.length), a: n0(rs.filter((r) => r.estado === 'activa').length), p: n0(nPar) }) +
        (C && C.rotas ? ' · ' + t('{c} carrinhas por fechar', { c: n0(porContar) }) : ''),
    ),
  );
  const l = el('div', 'ag-lista');
  rs.forEach((r) => {
    const [rot, cls] = ESTADO_ROTA[r.estado];
    const b = el('button', 'ag-rota ag-rota-ao');
    b.type = 'button';
    const nome = el('span', 'ag-rota-n');
    nome.append(el('strong', null, r.rota + (r.promotor ? ' · ' + r.promotor : '')));
    const sub = el('span', 'ag-rota-s');
    sub.append(el('span', 'ag-estado-rota ' + cls, t(rot)));
    if (r.docs) sub.append(document.createTextNode(' ' + t('{n} doc.', { n: r.docs }) + ' · ' + t('última {q}', { q: haQuanto(r.min) })));
    const st = stockDaRota(r.rota);
    if (st)
      sub.append(
        el(
          'span',
          'ag-rota-stock',
          t('Na carrinha: {a} artigos · {q} un.', { a: n0(st.n_artigos || (st.itens || []).length), q: n0(st.total_quantidade || 0) }),
        ),
      );
    nome.append(sub);
    b.append(nome, el('span', 'ag-valor', kzc(r.total)));
    b.insertAdjacentHTML('beforeend', SETA);
    b.setAttribute('aria-label', t('Rota {r}', { r: r.rota }) + ': ' + t(rot) + ', ' + kz(r.total));
    b.addEventListener('click', () => folhaRota(r.rota));
    l.append(b);
  });
  if (!rs.length) l.append(el('p', 'ag-vazio', t('Sem rotas com vendas hoje.')));
  out.push(l, el('p', 'ag-nota ag-nota-c', t('Actualizado às {h}', { h: E.ao.hora.toTimeString().slice(0, 5) })));
  return out;
}

// ───────────── Controlo de gestão ─────────────
// O que as equipas de controlo das grandes empresas seguem todos os dias: fecho previsto do mês, recebido por rota e
// por meio, clientes (activos, novos, por recuperar), artigos, produtividade das rotas e riscos de concentração.
const pc = (v) => (v == null ? '—' : Math.round(v * 100) + '%');
function controlo() {
  const C = E.ind.controlo;
  const out = [];
  if (!C) return [el('p', 'ag-vazio', t('Sem dados de controlo neste período.'))];
  // 1. fecho previsto do mês
  const f = C.fecho;
  const c = cartao('ag-heroi');
  c.append(
    el('div', 'ag-rotulo', t('Fecho previsto do mês')),
    el('div', 'ag-grande', kz(f.previsto)),
    linhaVar(f.variacao, t('face ao mês passado ({v})', { v: kzc(f.mes_anterior) })),
  );
  c.append(barra(f.progresso), el('p', 'ag-nota', t('{v} já vendidos · dia {d} de {n}', { v: kzc(f.ate_hoje), d: f.dias_passados, n: f.dias_mes })));
  out.push(c);
  // 1b. objectivo automático do mês (sempre a crescer) e o ritmo para lá chegar
  const O = E.ind.objectivos && E.ind.objectivos.total;
  if (O) {
    const co = cartao('ag-objectivo');
    co.append(
      el('div', 'ag-rotulo', t('Objectivo do mês')),
      el('div', 'ag-grande', kz(O.objectivo)),
      el(
        'p',
        'ag-nota',
        t('{v} realizados · {p} do objectivo', { v: kzc(O.realizado), p: O.cumprimento == null ? '—' : Math.round(O.cumprimento * 100) + '%' }),
      ),
      barra(O.cumprimento),
      el(
        'p',
        'ag-nota' + (O.estado === 'abaixo' ? ' ag-mau' : ''),
        O.dias_restantes
          ? t('Para lá chegar: {v} por dia útil ({n} dias) · ao ritmo actual fecha em {p}', {
              v: kzc(O.necessario_dia),
              n: O.dias_restantes,
              p: kzc(O.projeccao),
            })
          : t('Mês fechado: {p} do objectivo', { p: O.cumprimento == null ? '—' : Math.round(O.cumprimento * 100) + '%' }),
      ),
    );
    out.push(co);
  }
  // 2. recebido hoje por rota (dinheiro e Multicaixa), ao vivo
  if (pode('inicio')) {
    carregarAgora(false);
    if (E.ao && E.ao.vendas) {
      const V = E.ao.vendas;
      const r = recebido(V);
      out.push(titulo(t('Recebido hoje') + ' · ' + kzc(r.total)), grelhaRecebido(r));
      const porRota = {};
      V.forEach((v) => (porRota[v.rota] = porRota[v.rota] || []).push(v));
      const l = el('div', 'ag-lista ag-feed');
      Object.entries(porRota)
        .map(([rt, vs]) => ({ rota: rt, promotor: vs[0].promotor || '', ...recebido(vs) }))
        .sort((a, b) => b.total - a.total)
        .forEach((x) => {
          const li = el('button', 'ag-feed-l ag-rec');
          li.type = 'button';
          const tx = el('span', 'ag-feed-t');
          tx.append(
            el('strong', null, x.rota + (x.promotor ? ' · ' + x.promotor : '')),
            el(
              'span',
              null,
              t('Dinheiro {d} · Multicaixa {m}', { d: kzc(x.dinheiro), m: kzc(x.multicaixa) }) +
                (x.por_receber > 0.5 ? ' · ' + t('por receber {v}', { v: kzc(x.por_receber) }) : ''),
            ),
          );
          li.append(tx, el('span', 'ag-valor', kzc(x.total)));
          li.addEventListener('click', () => folhaRota(x.rota));
          l.append(li);
        });
      out.push(l);
    }
  }
  // 3. clientes
  const k = C.clientes;
  out.push(titulo(t('Clientes do mês')));
  const g = el('div', 'ag-grelha');
  g.append(
    cartaoKpi(t('Com compras'), n0(k.activos), k.activos_anterior ? k.activos / k.activos_anterior - 1 : null, t('face ao mês passado')),
    cartaoKpi(t('Novos'), n0(k.novos), undefined, t('sem compras nos 60 dias antes')),
    cartaoKpi(t('Por recuperar'), n0(k.por_recuperar), undefined, t('compraram no mês passado, ainda não este')),
    cartaoKpi(t('Valor em risco'), kzc(k.valor_por_recuperar), undefined, t('o que compraram no mês passado')),
  );
  out.push(g);
  if (k.recuperar && k.recuperar.length) {
    const l = el('div', 'ag-lista ag-feed');
    k.recuperar.forEach((x) => {
      const li = el('div', 'ag-feed-l');
      const tx = el('span', 'ag-feed-t');
      tx.append(
        el('strong', null, x.nome || x.codigo),
        el('span', null, t('Rota {r}', { r: x.rota }) + ' · ' + t('{n} compras no mês passado', { n: x.compras })),
      );
      li.append(tx, el('span', 'ag-valor', kzc(x.valor)));
      l.append(li);
    });
    out.push(el('p', 'ag-resumo', t('Maiores clientes por recuperar')), l);
  }
  // 4. artigos que mais vendem
  if (C.artigos && C.artigos.length) {
    out.push(titulo(t('Artigos que mais vendem')));
    const l = el('div', 'ag-lista ag-feed');
    C.artigos.forEach((a) => {
      const li = el('div', 'ag-feed-l');
      const tx = el('span', 'ag-feed-t');
      const sub = el('span');
      sub.append(
        document.createTextNode(n0(a.quantidade) + ' ' + (a.unidade || t('un.')) + ' · ' + pc(a.peso) + ' ' + t('das vendas') + ' '),
        pastilha(a.variacao),
      );
      tx.append(el('strong', null, a.nome || a.codigo), sub);
      li.append(tx, el('span', 'ag-valor', kzc(a.valor)));
      l.append(li);
    });
    out.push(l);
  }
  // 5. produtividade das rotas
  if (C.rotas && C.rotas.length) {
    out.push(titulo(t('Produtividade das rotas')));
    const l = el('div', 'ag-lista ag-feed');
    C.rotas.forEach((r) => {
      const li = el('button', 'ag-feed-l ag-rec');
      li.type = 'button';
      const tx = el('span', 'ag-feed-t');
      tx.append(
        el('strong', null, r.rota + (r.promotor ? ' · ' + r.promotor : '')),
        el(
          'span',
          null,
          t('{d} dias · {p} doc./dia · valor médio {v} · {c} clientes', {
            d: r.dias,
            p: String(r.docs_dia).replace('.', ','),
            v: kzc(r.valor_medio),
            c: n0(r.clientes),
          }) + (r.peso_nc > 0.05 ? ' · ' + t('notas de crédito {p}', { p: pc(r.peso_nc) }) : ''),
        ),
      );
      li.append(tx, el('span', 'ag-valor', kzc(r.total)));
      li.addEventListener('click', () => folhaRota(r.rota));
      l.append(li);
    });
    out.push(l);
  }
  // 6. riscos
  const R0 = C.riscos;
  out.push(titulo(t('Riscos')));
  const gr = el('div', 'ag-grelha');
  gr.append(
    cartaoKpi(t('5 maiores clientes'), pc(R0.top5_clientes), undefined, t('das vendas do mês')),
    cartaoKpi(t('Maior rota'), R0.maior_rota ? pc(R0.maior_rota.peso) : '—', undefined, R0.maior_rota ? t('Rota {r}', { r: R0.maior_rota.rota }) : ''),
    cartaoKpi(t('Notas de crédito'), n0(R0.notas_credito), undefined, t('{p} dos documentos', { p: pc(R0.peso_notas_credito) })),
  );
  out.push(gr);
  return out;
}

const SEPARADORES = [
  ['hoje', 'Hoje', hoje],
  ['rotas', 'Rotas', rotas],
  ['controlo', 'Controlo', controlo],
  ['alertas', 'Alertas', alertas],
  ['assistente', 'Assistente', assistente],
];
const pode = (r) => !!(E.sessao && (E.sessao.permissoes || {})[r]);
const disponiveis = () => SEPARADORES.filter(([id]) => id !== 'assistente' || pode('assistente'));

function mudar(id) {
  E.sep = id;
  try {
    history.replaceState(null, '', '#' + id);
  } catch (e) {
    /* sem histórico */
  }
  desenhar();
  window.scrollTo(0, 0);
}
function esqueleto() {
  const w = el('div', 'ag-esq');
  w.setAttribute('aria-label', t('A carregar…'));
  ['ag-esq-seg', 'ag-esq-heroi', 'ag-esq-l', 'ag-esq-l', 'ag-esq-l'].forEach((c) => w.append(el('div', c)));
  return w;
}
function estado() {
  const est = $('ag-estado');
  if (!est) return;
  est.replaceChildren();
  if (!E.ind) {
    est.textContent = t('A carregar…');
    return;
  }
  const ok = !(E.fonte && E.fonte.ok === false) && navigator.onLine !== false;
  est.append(el('i', 'ag-ponto-est ' + (ok ? 'ok' : 'erro')), document.createTextNode(t('Actualizado às {h}', { h: String(E.ind.hora).slice(11, 16) })));
}
function desenhar() {
  const c = $('ag-conteudo');
  const nav = $('ag-separadores');
  const nAl = E.ind && E.ind.alertas ? E.ind.alertas.critico + E.ind.alertas.aviso : 0;
  nav.replaceChildren(
    ...disponiveis().map(([id, nome]) => {
      const b = el('button');
      b.type = 'button';
      const ic = el('span', 'ag-tab-ic');
      ic.innerHTML = svg(ICONE[id]); // nosemgrep: insecure-innerhtml -- ícone SVG estático do próprio código
      if (id === 'alertas' && nAl) ic.append(el('b', 'ag-badge', nAl > 99 ? '99+' : String(nAl)));
      b.append(ic, el('span', null, t(nome)));
      if (id === E.sep) b.setAttribute('aria-current', 'page');
      if (id === 'alertas' && nAl) b.setAttribute('aria-label', t(nome) + ': ' + t('{n} por tratar', { n: nAl }));
      b.addEventListener('click', () => mudar(id));
      return b;
    }),
  );
  $('ag-actualizar').setAttribute('aria-label', t('Actualizar'));
  $('ag-actualizar').classList.toggle('a-ler', E.aLer);
  estado();
  const sep = disponiveis().find(([id]) => id === E.sep) || disponiveis()[0];
  $('ag-h1').textContent = t(sep[1]);
  if (E.erro && !E.ind) {
    const tentar = el('button', 'ag-botao', t('Tentar de novo'));
    tentar.type = 'button';
    tentar.addEventListener('click', () => carregar(true));
    c.replaceChildren(cartao('ag-erro', el('p', null, t(E.erro)), tentar));
    return;
  }
  if (!E.ind) {
    c.replaceChildren(esqueleto());
    return;
  }
  const filhos = [];
  const av = avisoDados();
  if (av && sep[0] !== 'assistente') {
    const f = el('div', 'ag-faixa');
    f.setAttribute('role', 'status');
    f.append(el('span', 'ag-al-ic', '!'), el('span', null, av));
    filhos.push(f);
  }
  c.replaceChildren(...filhos, ...sep[2]());
}

function carregar(fresco) {
  if (E.aLer) return Promise.resolve();
  E.aLer = true;
  desenhar();
  // o estado do Pinguim BO chega quando chegar: os números aparecem logo que os indicadores respondem
  const fonte = apiJson('vendas/estado').then(
    (f) => (E.fonte = f && typeof f.ok === 'boolean' ? f : null),
    () => (E.fonte = null),
  );
  fonte.then(() => {
    if (E.ind) desenhar();
  });
  const ind = apiJson('indicadores' + (fresco ? '?fresco=1' : '')).then(
    (d) => {
      if (d && d.ok) {
        E.ind = d;
        E.erro = null;
      } else E.erro = (d && d.erro) || 'Não foi possível ler os dados.';
    },
    () => (E.erro = 'Não foi possível ler os dados.'),
  );
  return ind.then(() => {
    E.aLer = false;
    desenhar();
  });
}

// Puxar para actualizar (no topo da página): indicador que acompanha o dedo e actualiza ao largar.
function puxarParaActualizar() {
  const ind = $('ag-puxar');
  let y0 = null,
    dy = 0;
  window.addEventListener(
    'touchstart',
    (e) => {
      y0 = window.scrollY <= 0 && E.sep !== 'assistente' ? e.touches[0].clientY : null;
      dy = 0;
    },
    { passive: true },
  );
  window.addEventListener(
    'touchmove',
    (e) => {
      if (y0 == null) return;
      dy = Math.max(0, Math.min(110, e.touches[0].clientY - y0));
      ind.style.height = dy * 0.6 + 'px';
      ind.textContent = dy > 70 ? t('Largue para actualizar') : dy > 12 ? t('Puxe para actualizar') : '';
    },
    { passive: true },
  );
  window.addEventListener('touchend', () => {
    if (y0 != null && dy > 70) {
      ind.textContent = t('A actualizar…');
      carregar(true).then(() => {
        ind.style.height = '0';
        ind.textContent = '';
      });
    } else {
      ind.style.height = '0';
      ind.textContent = '';
    }
    y0 = null;
  });
}

// Velocidade: a sessão e os indicadores pedem-se ao mesmo tempo (não um depois do outro)
carregar();
apiJson('sessao').then((s) => {
  if (!s || !s.login) {
    location.href = '../geo/login.php?redirect=../gestao/';
    return;
  }
  E.sessao = s;
  const pedido = location.hash.replace('#', '') === 'agora' ? 'rotas' : location.hash.replace('#', '');
  if (SEPARADORES.some(([id]) => id === pedido)) E.sep = pedido;
  carregarNotif();
  aplicarIdioma();
});

/**
 * Idioma da app = o do telefone (como nas apps nativas: segue Definições › Geral › Idioma e região), entre os que a
 * app tem (português, inglês, tigrínia), pela ordem de preferência do telefone. Se o telefone estiver noutro idioma,
 * fica o da conta no portal e, sem esse, o português. Muda sozinha quando se muda o idioma do telefone.
 */
function idiomaDoTelefone() {
  const lista = (navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language || '']).map(
    (x) => String(x).toLowerCase().split(/[-_]/)[0],
  );
  return lista.find((x) => x === 'pt' || x === 'en' || x === 'ti') || '';
}
function aplicarIdioma() {
  const l = idiomaDoTelefone() || (E.sessao && E.sessao.idioma) || 'pt';
  const p = l !== LANG ? mudarIdioma(l).then(setFmt) : Promise.resolve();
  p.then(() => {
    document.documentElement.lang = { pt: 'pt-PT', en: 'en', ti: 'ti' }[LANG] || 'pt-PT';
    desenhar();
    actualizarIdiomaNotif();
  });
}
/** Este telefone já recebe notificações: o servidor guarda o idioma dele, para as enviar já traduzidas. */
function actualizarIdiomaNotif() {
  registo()
    .then((r) => (r && r.pushManager ? r.pushManager.getSubscription() : null))
    .then((sub) => {
      if (!sub || (E.notif && E.notif.idioma === LANG)) return;
      postJson('notificacoes', { accao: 'subscrever', subscricao: sub.toJSON(), idioma: LANG }).then((d) => {
        if (d && d.ok) E.notif = d;
      });
    })
    .catch(() => {});
}
window.addEventListener('languagechange', () => {
  if (E.sessao) aplicarIdioma();
});
$('ag-actualizar').addEventListener('click', () => {
  if (E.sep === 'hoje' || E.sep === 'rotas' || E.sep === 'controlo') carregarAgora(true);
  carregar(true);
});
puxarParaActualizar();
window.addEventListener('online', desenhar);
window.addEventListener('offline', desenhar);
// separador «Agora»: a cada minuto, só com ele aberto e a app no ecrã
setInterval(() => {
  if (document.visibilityState === 'visible' && (E.sep === 'hoje' || E.sep === 'rotas' || E.sep === 'controlo') && E.ind && pode('inicio')) carregarAgora(true);
}, 60000);
// actualiza a cada 5 minutos enquanto a app está visível
setInterval(() => {
  if (document.visibilityState === 'visible' && E.ind) carregar();
}, 300000);
// ao tocar numa notificação com a app aberta, o telefone muda o endereço: abre o separador certo
window.addEventListener('hashchange', () => {
  const id = location.hash.replace('#', '') === 'agora' ? 'rotas' : location.hash.replace('#', '');
  if (id !== E.sep && SEPARADORES.some(([x]) => x === id)) mudar(id);
});
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState !== 'visible') return;
  if (E.sessao) carregarNotif();
  versaoNova();
  if (E.ind && Date.now() - new Date(E.ind.hora.replace(' ', 'T')).getTime() > 300000) carregar();
});
// Actualizações transparentes: a app vem sempre do servidor; se uma versão nova for instalada com a app aberta,
// recarrega-se sozinha quando volta ao ecrã (sem perder uma pergunta a meio no assistente).
function versaoNova() {
  if (!E.sessao || !E.sessao.versao) return;
  apiJson('sessao').then((s) => {
    if (!s || !s.versao || s.versao === E.sessao.versao) return;
    const ta = document.querySelector('.ag-form textarea');
    if (E.aPensar || (ta && ta.value.trim())) return;
    location.reload();
  });
}
setInterval(() => {
  if (document.visibilityState === 'visible') versaoNova();
}, 900000);
