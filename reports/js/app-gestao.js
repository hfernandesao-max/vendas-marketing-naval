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
const E = { sessao: null, ind: null, fonte: null, erro: null, sep: 'hoje', periodo: 'hoje', ordemRotas: 'mes', conversa: [], aPensar: false, aLer: false };
const ICONE = { hoje: 'home', rotas: 'trophy', alertas: 'sino', assistente: 'ia' };
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
  b.addEventListener('click', () => folhaRota(r));
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
    heroi(),
  ];
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
  const ordem = E.ordemRotas;
  const rs = E.ind.rotas.slice().sort((a, b) => (ordem === 'hoje' ? b.hoje - a.hoje : b.mes - a.mes));
  const out = [
    segmentos(
      [
        ['mes', t('Mês até hoje')],
        ['hoje', t('Hoje')],
      ],
      ordem,
      (id) => {
        E.ordemRotas = id;
        desenhar();
      },
      t('Ordenar por'),
    ),
  ];
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
function folhaRota(r) {
  let f = $('ag-folha');
  if (!f) {
    f = el('dialog', 'ag-folha');
    f.id = 'ag-folha';
    f.addEventListener('click', (e) => {
      if (e.target === f) f.close();
    });
    document.body.append(f);
  }
  const cab = el('div', 'ag-folha-cab');
  const fechar = el('button', 'ag-mais', t('Fechar'));
  fechar.type = 'button';
  fechar.addEventListener('click', () => f.close());
  cab.append(el('h2', null, t('Rota {r}', { r: r.rota })), fechar);
  const corpo = el('div', 'ag-folha-corpo');
  if (r.promotor) corpo.append(el('p', 'ag-nota', r.promotor));
  const g = el('div', 'ag-grelha');
  g.append(
    cartaoKpi(t('Hoje'), kzc(r.hoje), undefined, r.previsto_hoje ? t('previsto {v}', { v: kzc(r.previsto_hoje) }) : t('sem previsão')),
    cartaoKpi(t('Ontem'), kzc(r.ontem), r.habitual_ontem ? r.ontem / r.habitual_ontem - 1 : null, t('face ao habitual')),
    cartaoKpi(t('Mês até hoje'), kzc(r.mes), r.variacao_mes, t('face ao mês passado')),
    cartaoKpi(t('Mês passado'), kzc(r.mes_anterior), undefined, t('mesmos dias')),
  );
  corpo.append(g);
  if (r.serie && r.serie.length) {
    const dias = Array.from({ length: r.serie.length }, (_, i) => somaDias(E.ind.hoje.dia, i - r.serie.length + 1));
    const c = cartao('ag-heroi', el('div', 'ag-rotulo', t('Últimos 14 dias')), barras(r.serie, dias.map(dl), null, t('Últimos 14 dias')));
    corpo.append(c);
  }
  if (r.sequencia >= 3) corpo.append(el('p', 'ag-selo', t('{n} dias acima do habitual', { n: r.sequencia })));
  f.replaceChildren(cab, corpo);
  if (!f.open) f.showModal();
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
  const out = [resumo];
  if (!a.principais.length) out.push(el('p', 'ag-vazio', t('Sem alertas: tudo dentro do habitual.')));
  const l = el('div', 'ag-lista');
  a.principais.forEach((x) => l.append(linhaAlerta(x, false)));
  out.push(l);
  const ver = el('a', 'ag-ligacao', t('Ver todos os alertas no portal'));
  ver.href = '../reports/#alertas';
  out.push(ver);
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
const SEPARADORES = [
  ['hoje', 'Hoje', hoje],
  ['rotas', 'Rotas', rotas],
  ['alertas', 'Alertas', alertas],
  ['assistente', 'Assistente', assistente],
];
const disponiveis = () => SEPARADORES.filter(([id]) => id !== 'assistente' || (E.sessao && (E.sessao.permissoes || {}).assistente));

function mudar(id) {
  E.sep = id;
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
  const fonte = apiJson('vendas/estado').then(
    (f) => (E.fonte = f && typeof f.ok === 'boolean' ? f : null),
    () => (E.fonte = null),
  );
  const ind = apiJson('indicadores' + (fresco ? '?fresco=1' : '')).then(
    (d) => {
      if (d && d.ok) {
        E.ind = d;
        E.erro = null;
      } else E.erro = (d && d.erro) || 'Não foi possível ler os dados.';
    },
    () => (E.erro = 'Não foi possível ler os dados.'),
  );
  return Promise.all([fonte, ind]).then(() => {
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

apiJson('sessao').then((s) => {
  if (!s || !s.login) {
    location.href = '../geo/login.php?redirect=../gestao/';
    return;
  }
  E.sessao = s;
  const l = s.idioma && s.idioma !== LANG ? mudarIdioma(s.idioma).then(setFmt) : Promise.resolve();
  l.then(() => {
    document.documentElement.lang = { pt: 'pt-PT', en: 'en', ti: 'ti' }[LANG] || 'pt-PT';
    desenhar();
    carregar();
  });
});
$('ag-actualizar').addEventListener('click', () => carregar(true));
puxarParaActualizar();
window.addEventListener('online', desenhar);
window.addEventListener('offline', desenhar);
// actualiza a cada 5 minutos enquanto a app está visível
setInterval(() => {
  if (document.visibilityState === 'visible' && E.ind) carregar();
}, 300000);
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState !== 'visible') return;
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
