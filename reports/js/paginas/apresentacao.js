// Gestão › Apresentação do portal: experiência interactiva, cena a cena, focada no negócio e na automação, sempre
// com dados reais e actualizada sozinha (sem ninguém executar nada):
//  - Negócio: vendas do Pinguim BO por mês, mês até hoje face ao mesmo período do mês anterior, clientes activos,
//    documentos, rotas e valor médio por compra (API v1/apresentacao, no âmbito de quem vê; refrescada a cada 10 min).
//  - Automação: o que o portal fez sozinho nos últimos 30 dias e a carteira calculada esta noite.
//  - O dia automático: horas configuradas no servidor.  - Evolução: catálogo de páginas e registo de alterações.
//  - Futuro, fases e próximos passos: roteiro nucleo/recursos/roteiro.json, revisto em cada versão.
// Conteúdo em português (apresentação).
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { dl, n0, pct } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { atual } from '../perfil.js';
import { el } from '../util.js';
import { PAGES } from './catalogo.js';
import { R } from './registo.js';

const REFRESCAR_MS = 10 * 60 * 1000;
let D = null;
let lidoEm = 0;
let aLer = false;
let cena = 0;
let relogio = 0;
const reduzir = () => window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const ESTADOS = { concluido: 'Concluído', em_curso: 'Em curso', planeado: 'Planeado' };
const MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
const nf1 = new Intl.NumberFormat('pt-PT', { maximumFractionDigits: 1 });

/** Valor compacto para leitura à distância: 30,5 M Kz; 850 mil Kz; 900 Kz. */
const kzc = (v) => {
  if (v == null || isNaN(v)) return '—';
  const a = Math.abs(v);
  if (a >= 1e9) return nf1.format(v / 1e9) + ' mil M Kz';
  if (a >= 1e6) return nf1.format(v / 1e6) + ' M Kz';
  if (a >= 1e3) return nf1.format(v / 1e3) + ' mil Kz';
  return n0(v) + ' Kz';
};
const nomeMes = (m) => MESES[+m.slice(5, 7) - 1] + ' ' + m.slice(2, 4);

function ler() {
  if (aLer) return;
  aLer = true;
  apiJson('apresentacao').then((d) => {
    aLer = false;
    lidoEm = Date.now();
    D = d && d.ok ? d : D && !D.falhou ? D : { falhou: (d && d.erro) || t('Não foi possível ler os dados.') };
    if (atual === 'apresentacao') draw();
  });
}

const h = (tag, cls, txt) => el(tag, cls, txt);
function cabeca(s, olho, titulo) {
  s.append(h('div', 'apx-olho', olho), h('h2', null, titulo));
}
function numero(valor, rotulo, cls) {
  const d = h('div', 'apx-num' + (cls ? ' ' + cls : ''));
  d.append(h('b', null, valor), h('span', null, rotulo));
  return d;
}
function semValores(s) {
  s.append(h('p', 'apx-aviso', D.negocio_erro || 'Os números de negócio aparecem a quem tem acesso às páginas de vendas.'));
}
const variacao = (v) => (v == null ? '' : (v >= 0 ? '▲ ' : '▼ ') + pct(Math.abs(v)));

// ── Cenas ──
function cenaAbertura() {
  const s = h('section', 'apx-cena apx-abertura');
  s.dataset.nome = 'Início';
  s.append(
    h('div', 'apx-olho', 'Grupo Naval · Portal RTM'),
    h('h1', null, 'Vender mais, com menos trabalho manual.'),
    h('h1', 'apx-brilho', 'Dados reais, no próprio dia.'),
  );
  const g = h('div', 'apx-numeros apx-tres');
  const n = D.negocio;
  const a = D.automacao || {};
  if (n) {
    g.append(
      numero(kzc(n.mes.total), 'vendas do mês até hoje'),
      numero(variacao(n.mes.variacao) || '—', 'face ao mesmo período do mês anterior', n.mes.variacao < 0 ? 'desce' : 'sobe'),
      numero(n0(n.clientes_30), 'clientes com compras nos últimos 30 dias'),
    );
  } else {
    g.append(
      numero(n0(a.resumos), 'resumos do dia enviados sozinhos (30 dias)'),
      numero(n0(a.calculos), 'cálculos nocturnos da carteira (30 dias)'),
      numero(n0(a.versoes), 'versões instaladas sozinhas (30 dias)'),
    );
  }
  s.append(g, h('p', 'apx-aviso', 'Dados do Pinguim BO até ' + dl(D.hora) + ' ' + String(D.hora || '').slice(11) + (D.versao ? ' · versão ' + D.versao : '')));
  return s;
}

function graficoMeses(meses) {
  const ns = 'http://www.w3.org/2000/svg';
  const W = 600,
    H = 260,
    base = 220,
    topo = 30;
  const max = Math.max(1, ...meses.map((m) => m.total));
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
  svg.setAttribute('class', 'apx-barras');
  svg.setAttribute('role', 'img');
  svg.setAttribute('aria-label', 'Vendas por mês: ' + meses.map((m) => nomeMes(m.mes) + ' ' + kzc(m.total)).join('; '));
  const passo = W / meses.length;
  const larg = Math.min(56, passo * 0.5);
  const linha = document.createElementNS(ns, 'line');
  [
    ['x1', 0],
    ['x2', W],
    ['y1', base],
    ['y2', base],
    ['class', 'apx-eixo'],
  ].forEach(([k, v]) => linha.setAttribute(k, v));
  svg.append(linha);
  meses.forEach((m, i) => {
    const alt = Math.max(m.total > 0 ? 4 : 0, ((base - topo) * Math.max(0, m.total)) / max);
    const x = i * passo + (passo - larg) / 2;
    const y = base - alt;
    const rr = Math.min(4, alt);
    const g = document.createElementNS(ns, 'g');
    const r = document.createElementNS(ns, 'path');
    // barra com cantos de 4 px só no topo, assente na base
    r.setAttribute('d', `M${x},${base}V${y + rr}Q${x},${y} ${x + rr},${y}H${x + larg - rr}Q${x + larg},${y} ${x + larg},${y + rr}V${base}Z`);
    r.setAttribute('class', 'apx-barra' + (m.parcial ? ' parcial' : ''));
    const tt = document.createElementNS(ns, 'title');
    tt.textContent = `${nomeMes(m.mes)}${m.parcial ? ' (até hoje)' : ''}: ${kzc(m.total)} · ${n0(m.documentos)} documentos · ${n0(m.clientes)} clientes`;
    r.append(tt);
    const v = document.createElementNS(ns, 'text');
    v.setAttribute('x', x + larg / 2);
    v.setAttribute('y', y - 8);
    v.setAttribute('class', 'apx-valor');
    v.textContent = kzc(m.total).replace(' Kz', '');
    const l = document.createElementNS(ns, 'text');
    l.setAttribute('x', x + larg / 2);
    l.setAttribute('y', base + 24);
    l.setAttribute('class', 'apx-rotulo');
    l.textContent = nomeMes(m.mes) + (m.parcial ? '*' : '');
    g.append(r, v, l);
    svg.append(g);
  });
  return svg;
}

function cenaNegocio() {
  const s = h('section', 'apx-cena');
  s.dataset.nome = 'Negócio';
  cabeca(s, 'O negócio, ao vivo', 'Vendas líquidas por mês, directamente do Pinguim BO.');
  const n = D.negocio;
  if (!n) {
    semValores(s);
    return s;
  }
  const corpo = h('div', 'apx-dois');
  const esq = h('div', 'apx-col apx-grafico');
  esq.append(graficoMeses(n.meses), h('p', 'apx-aviso', '* mês actual até hoje. Valores em kwanzas, já com as notas de crédito descontadas.'));
  const dir = h('div', 'apx-numeros apx-compacto');
  dir.append(
    numero(n0(n.mes.documentos), 'documentos de venda este mês'),
    numero(n0(n.mes.clientes), 'clientes que compraram este mês'),
    numero(n0(n.mes.rotas), 'rotas a vender este mês'),
    numero(kzc(n.mes.valor_medio), 'valor médio por compra'),
  );
  corpo.append(esq, dir);
  s.append(corpo);
  if (n.desde) s.append(h('p', 'apx-aviso', 'Base: ' + n0(n.dias_com_dados) + ' dias com vendas desde ' + dl(n.desde) + '. Cresce sozinha todos os dias.'));
  return s;
}

function cenaCarteira() {
  const s = h('section', 'apx-cena');
  s.dataset.nome = 'Carteira';
  cabeca(s, 'Automação comercial', 'Todas as noites o portal analisa cada cliente.');
  const c = (D.automacao || {}).carteira;
  if (!c) {
    if (!D.ve_valores) semValores(s);
    else s.append(h('p', 'apx-aviso', 'A primeira análise da carteira fica pronta na próxima noite, às ' + (D.horas.calculo || '04:00') + '.'));
    return s;
  }
  const partes = [
    ['em_dia', 'Em dia', c.em_dia],
    ['a_abrandar', 'A abrandar', c.a_abrandar],
    ['parado', 'Parados', c.parado],
  ];
  const tot = c.em_dia + c.a_abrandar + c.parado || 1;
  const barra = h('div', 'apx-segmentos');
  barra.setAttribute('role', 'img');
  barra.setAttribute('aria-label', `Clientes: ${c.em_dia} em dia, ${c.a_abrandar} a abrandar, ${c.parado} parados`);
  const leg = h('div', 'apx-leg');
  partes.forEach(([k, nome, v]) => {
    if (v) {
      const seg = h('div', 'apx-seg ' + k);
      seg.style.flexGrow = String(v / tot);
      seg.title = `${nome}: ${n0(v)} clientes (${pct(v / tot)})`;
      barra.append(seg);
    }
    const i = h('span', 'apx-leg-i');
    i.append(h('i', 'apx-seg ' + k), document.createTextNode(`${nome} ${n0(v)} (${pct(v / tot)})`));
    leg.append(i);
  });
  const g = h('div', 'apx-numeros apx-quatro');
  g.append(
    numero(n0(c.encomendas), 'clientes com encomenda sugerida pronta'),
    numero(kzc(c.valor_parados), 'compra habitual dos parados, a recuperar'),
    numero(n0(c.anomalias), 'anomalias detectadas (7 dias)'),
    numero(n0(c.qualidade), 'tarefas de qualidade dos dados'),
  );
  s.append(barra, leg, g);
  if (c.calculado)
    s.append(
      h(
        'p',
        'apx-aviso',
        'Calculado sozinho em ' + dl(c.calculado) + ' às ' + c.calculado.slice(11, 16) + '. Cada vendedor recebe a ficha de visita de cada cliente.',
      ),
    );
  return s;
}

function cenaDia() {
  const s = h('section', 'apx-cena apx-dois');
  s.dataset.nome = 'O dia';
  const H = D.horas;
  const A = D.automacao || {};
  const EV = [
    [
      H.instalar,
      'Actualização do portal',
      'Versões novas testadas e instaladas sozinhas, com cópia de segurança e regresso automático se algo falhar.',
      A.versoes,
      'versões instaladas',
    ],
    [
      H.calculo,
      'Cálculo da noite',
      'Ritmo de compra de cada cliente, encomenda sugerida, risco de crédito, anomalias e qualidade dos dados.',
      A.calculos,
      'noites calculadas',
    ],
    [H.resumo, 'Resumo do dia anterior', 'Cada gestor recebe no correio o que correu bem e o que precisa de atenção hoje.', A.resumos, 'resumos enviados'],
    [
      H.plano,
      'Plano de cargas proposto',
      'O plano de amanhã fica preparado pela previsão e pelo peso de cada viatura. A Logística só confirma.',
      A.planos,
      'planos propostos',
    ],
    [H.carrinha, 'Conciliação da carrinha', 'Aviso ao armazém das carrinhas por contar e por aprovar.', null, ''],
    [
      H.fecho,
      'Fecho do dia',
      'Por rota: vendas, recebido por meio de cobrança, notas de crédito, valores em aberto e estado da carrinha.',
      A.fechos,
      'fechos enviados',
    ],
  ]
    .filter((e) => e[0])
    .sort((a, b) => a[0].localeCompare(b[0]));
  const most = h('div', 'apx-mostrador');
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', '0 0 200 200');
  svg.setAttribute('aria-hidden', 'true');
  const circ = (attrs) => {
    const c = document.createElementNS(ns, 'circle');
    Object.entries(attrs).forEach(([k, v]) => c.setAttribute(k, v));
    return c;
  };
  const arco = circ({
    cx: 100,
    cy: 100,
    r: 70,
    fill: 'none',
    class: 'apx-arco',
    'stroke-width': 10,
    'stroke-linecap': 'round',
    transform: 'rotate(-90 100 100)',
    'stroke-dasharray': '0 440',
  });
  const tx = document.createElementNS(ns, 'text');
  [
    ['x', 100],
    ['y', 106],
    ['text-anchor', 'middle'],
    ['class', 'apx-hora-centro'],
  ].forEach(([k, v]) => tx.setAttribute(k, v));
  tx.textContent = '24 h';
  svg.append(circ({ cx: 100, cy: 100, r: 70, fill: 'none', class: 'apx-anel', 'stroke-width': 10 }), arco, tx);
  most.append(svg);
  const ev = h('div', 'apx-evento');
  ev.setAttribute('aria-live', 'polite');
  const evH = h('b');
  const evT = h('h3');
  const evD = h('p');
  const evN = h('div', 'apx-conta');
  ev.append(evH, evT, evD, evN);
  const botoes = [];
  const escolher = (i) => {
    const [hr, tt, dd, n, rot] = EV[i];
    evH.textContent = hr;
    evT.textContent = tt;
    evD.textContent = dd;
    evN.textContent = n != null && rot ? `${n0(n)} ${rot} nos últimos ${A.dias || 30} dias` : '';
    botoes.forEach((b, k) => b.classList.toggle('on', k === i));
    const [a, m] = hr.split(':').map(Number);
    arco.setAttribute('stroke-dasharray', (((a + m / 60) / 24) * 440).toFixed(1) + ' 440');
    tx.textContent = hr;
  };
  EV.forEach(([hr], i) => {
    const [a, m] = hr.split(':').map(Number);
    const ang = ((a + m / 60) / 24) * 2 * Math.PI - Math.PI / 2;
    const b = h('button', 'apx-hora', hr);
    b.type = 'button';
    b.style.left = 50 + 43 * Math.cos(ang) + '%';
    b.style.top = 50 + 43 * Math.sin(ang) + '%';
    b.addEventListener('click', () => escolher(i));
    botoes.push(b);
    most.append(b);
  });
  const dir = h('div', 'apx-col');
  const tot = ['resumos', 'calculos', 'planos', 'fechos', 'versoes'].reduce((s0, k) => s0 + (+A[k] || 0), 0);
  dir.append(
    h('div', 'apx-olho', 'O portal trabalha sozinho'),
    h('h2', null, 'Um dia completo de trabalho automático.'),
    ev,
    h(
      'p',
      'apx-aviso',
      `${n0(tot)} tarefas feitas sozinhas nos últimos ${A.dias || 30} dias. Horas configuradas neste servidor: toque numa hora do mostrador.`,
    ),
  );
  s.append(most, dir);
  if (EV.length) escolher(0);
  return s;
}

function cenaEvolucao() {
  const s = h('section', 'apx-cena');
  s.dataset.nome = 'Evolução';
  const grupos = [...new Set(PAGES.map((p) => p.grupo).filter(Boolean))];
  cabeca(s, 'Sempre a evoluir', 'Uma plataforma que cresce a cada versão.');
  const g = h('div', 'apx-numeros apx-quatro');
  g.append(
    numero(n0(PAGES.length), 'páginas e funções'),
    numero(n0(grupos.length), 'áreas: ' + grupos.join(', ').toLowerCase()),
    numero(n0(D.novidades_total), 'melhorias publicadas'),
    numero(n0(D.perfis), 'perfis de acesso, com verificação em dois passos'),
  );
  const lista = h('div', 'apx-novidades');
  (D.novidades || []).slice(0, 6).forEach((n) => {
    const c = h('article', 'apx-nov');
    c.append(h('small', null, n.data), h('h3', null, n.titulo));
    if (n.itens && n.itens[0]) c.append(h('p', null, n.itens[0].replace(/\*\*/g, '')));
    lista.append(c);
  });
  s.append(g, lista);
  return s;
}

function cartao(item, ia) {
  const b = h('button', 'apx-cartao' + (ia ? ' apx-ia' : ''));
  b.type = 'button';
  b.setAttribute('aria-expanded', 'false');
  const cab = h('div', 'apx-cab');
  cab.append(h('h3', null, item.titulo), h('span', 'apx-estado ' + (item.estado || 'planeado'), ESTADOS[item.estado] || 'Planeado'));
  const mais = h('p', 'apx-mais', item.detalhe);
  mais.hidden = true;
  b.append(cab, h('p', 'apx-det', item.resumo), mais);
  b.addEventListener('click', () => {
    mais.hidden = !mais.hidden;
    b.setAttribute('aria-expanded', mais.hidden ? 'false' : 'true');
  });
  return b;
}

function cenaFuturo() {
  const s = h('section', 'apx-cena');
  s.dataset.nome = 'Futuro';
  const r = D.roteiro || {};
  const g1 = h('div', 'apx-grelha4');
  const g2 = h('div', 'apx-grelha4');
  (r.futuro || []).forEach((x) => g1.append(cartao(x, false)));
  (r.ia || []).forEach((x) => g2.append(cartao(x, true)));
  cabeca(s, 'O que vem a seguir', 'Ver, prever, decidir e controlar. E crescer com IA.');
  s.append(g1, g2);
  return s;
}

function cenaFases() {
  const s = h('section', 'apx-cena');
  s.dataset.nome = 'Fases';
  const r = D.roteiro || {};
  const f = h('div', 'apx-fases');
  (r.fases || []).forEach((x) => {
    const d = h('div', 'apx-fase ' + (x.estado || 'planeado'));
    d.append(h('small', null, x.quando + ' · ' + (ESTADOS[x.estado] || 'Planeado')), h('h3', null, x.titulo), h('p', null, x.texto));
    f.append(d);
  });
  const dec = h('div', 'apx-decis');
  (r.proximos || []).forEach((x) => {
    const d = h('div');
    d.append(h('b', null, x.etiqueta), document.createTextNode(x.texto));
    if (x.quando) d.append(h('small', null, x.quando));
    dec.append(d);
  });
  cabeca(s, 'Calendário', 'Fases e próximos passos.');
  s.append(f, h('div', 'apx-olho apx-ambar', 'Próximos passos propostos'), dec);
  if (r.actualizado) s.append(h('p', 'apx-aviso', 'Roteiro actualizado em ' + dl(r.actualizado) + '.'));
  return s;
}

R.apresentacao = (pg) => {
  if (!D) {
    pg.append(el('div', 'glass empty', t('A carregar…')));
    ler();
    return;
  }
  if (D.falhou) {
    pg.append(el('div', 'glass empty', t(D.falhou)));
    return;
  }
  // dados sempre frescos: volta a ler a cada 10 minutos enquanto a página está aberta
  clearTimeout(relogio);
  relogio = setTimeout(() => atual === 'apresentacao' && ler(), Math.max(1000, REFRESCAR_MS - (Date.now() - lidoEm)));
  const palco = h('div', 'apx');
  const cenas = [cenaAbertura(), cenaNegocio(), cenaCarteira(), cenaDia(), cenaEvolucao(), cenaFuturo(), cenaFases()];
  const area = h('div', 'apx-cenas');
  cenas.forEach((c) => area.append(c));
  const base = h('div', 'apx-base');
  const sep = h('div', 'apx-pontos');
  sep.setAttribute('role', 'tablist');
  const ir = (i) => {
    cena = Math.max(0, Math.min(cenas.length - 1, i));
    cenas.forEach((c, k) => {
      c.classList.toggle('activa', k === cena);
      c.setAttribute('aria-hidden', k === cena ? 'false' : 'true');
    });
    [...sep.children].forEach((b, k) => {
      b.classList.toggle('on', k === cena);
      b.setAttribute('aria-selected', k === cena ? 'true' : 'false');
    });
  };
  cenas.forEach((c, i) => {
    const b = h('button', 'apx-ponto', String(i + 1).padStart(2, '0') + ' ' + c.dataset.nome);
    b.type = 'button';
    b.setAttribute('role', 'tab');
    b.addEventListener('click', () => ir(i));
    sep.append(b);
  });
  const nav = h('div', 'apx-botoes');
  const botao = (txt, rotulo, f) => {
    const b = h('button', 'apx-btn', txt);
    b.type = 'button';
    if (rotulo) b.setAttribute('aria-label', rotulo);
    b.addEventListener('click', f);
    return b;
  };
  nav.append(
    botao('‹', 'Cena anterior', () => ir(cena - 1)),
    botao('›', 'Cena seguinte', () => ir(cena + 1)),
    botao('Ecrã inteiro', '', () => (palco.requestFullscreen ? palco.requestFullscreen().catch(() => {}) : null)),
  );
  base.append(sep, nav);
  palco.append(area, base);
  palco.tabIndex = 0;
  palco.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowRight' || e.key === 'PageDown') ir(cena + 1);
    if (e.key === 'ArrowLeft' || e.key === 'PageUp') ir(cena - 1);
  });
  if (reduzir()) palco.classList.add('apx-quieto');
  pg.append(palco);
  ir(cena);
};
