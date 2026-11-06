// Gestão › Apresentação do portal ao Conselho de Administração, em formato corporativo (diapositivos 16:9 com as cores
// da Naval, uma mensagem por diapositivo, números grandes, fonte e data em cada número, numeração e marca de
// confidencialidade), com dados reais e actualizada sozinha (API v1/apresentacao; refresca a cada 10 minutos):
//   1 capa · 2 sumário executivo · 3 desempenho comercial · 4 carteira de clientes · 5 automação e eficiência ·
//   6 governação, segurança e conformidade · 7 arquitectura e integrações · 8 roteiro · 9 visão 2027–2028 ·
//   10 recomendações e encerramento.
// Textos do roteiro e da visão: nucleo/recursos/roteiro.json (revisto em cada versão).
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { dl, n0, pct } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { atual } from '../perfil.js';
import { el } from '../util.js';
import { PAGES } from './catalogo.js';
import { R } from './registo.js';

const REFRESCAR_MS = 10 * 60 * 1000;
const L = 1280; // diapositivo 16:9, desenhado a 1280 × 720 e ajustado ao ecrã por escala
const A = 720;
let D = null;
let lidoEm = 0;
let aLer = false;
let cena = 0;
let relogio = 0;
let obs = null;
const MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
const MESES_LONGOS = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
const nf1 = new Intl.NumberFormat('pt-PT', { maximumFractionDigits: 1 });

/** Valor compacto: 30,5 M Kz; 850 mil Kz. */
const kzc = (v) => {
  if (v == null || isNaN(v)) return '—';
  const a = Math.abs(v);
  if (a >= 1e9) return nf1.format(v / 1e9) + ' mil M Kz';
  if (a >= 1e6) return nf1.format(v / 1e6) + ' M Kz';
  if (a >= 1e3) return nf1.format(v / 1e3) + ' mil Kz';
  return n0(v) + ' Kz';
};
const nomeMes = (m) => MESES[+m.slice(5, 7) - 1] + ' ' + m.slice(2, 4);
const mesLongo = (m) => MESES_LONGOS[+m.slice(5, 7) - 1] + ' de ' + m.slice(0, 4);
const variacao = (v) => (v == null ? null : (v >= 0 ? '▲ ' : '▼ ') + pct(Math.abs(v)));
const dia = (iso) => (iso ? dl(iso) : '');

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

// ── peças comuns ──
const h = (tag, cls, txt) => el(tag, cls, txt);
function diapositivo(nome, seccao, titulo, mensagem) {
  const s = h('section', 'cx-slide');
  s.dataset.nome = nome;
  const topo = h('header', 'cx-topo');
  topo.append(h('span', 'cx-marca', 'GRUPO NAVAL ANGOLA'), h('span', 'cx-seccao', seccao));
  s.append(topo);
  if (titulo) s.append(h('h2', 'cx-titulo', titulo));
  if (mensagem) s.append(h('p', 'cx-mensagem', mensagem));
  return s;
}
function kpi(valor, rotulo, nota, cls) {
  const d = h('div', 'cx-kpi' + (cls ? ' ' + cls : ''));
  d.append(h('b', null, valor), h('span', null, rotulo));
  if (nota) d.append(h('small', null, nota));
  return d;
}
const fonte = (txt) => h('p', 'cx-fonte', txt);
const semDados = () => h('p', 'cx-vazio', 'Disponível para quem acompanha as vendas.');

// ── 1. Capa ──
function capa() {
  const p = (D.roteiro && D.roteiro.projecto) || {};
  const s = h('section', 'cx-slide cx-capa');
  s.dataset.nome = 'Capa';
  const esq = h('div', 'cx-capa-esq');
  const logo = h('img');
  logo.src = '../assets/naval-emblema.png';
  logo.alt = '';
  esq.append(logo, h('b', null, 'GRUPO NAVAL ANGOLA'), h('span', null, 'Projectos Digitais'));
  const dir = h('div', 'cx-capa-dir');
  dir.append(
    h('div', 'cx-olho', p.publico || 'Conselho de Administração'),
    h('h1', null, p.titulo || 'Portal RTM'),
    h('p', 'cx-sub', p.subtitulo || ''),
    h('div', 'cx-traco'),
    h('p', 'cx-autor', p.apresentador || ''),
    h('p', 'cx-funcao', p.funcao || ''),
    h('p', 'cx-data', dl(D.hora)),
  );
  s.append(esq, dir);
  return s;
}

// ── 2. Sumário executivo ──
function sumario() {
  const r = D.roteiro || {};
  const n = D.negocio;
  const a = D.automacao || {};
  const tarefas = ['resumos', 'calculos', 'planos', 'fechos', 'versoes'].reduce((x, k) => x + (+a[k] || 0), 0);
  const grupos = [...new Set(PAGES.map((x) => x.grupo).filter(Boolean))];
  const s = diapositivo(
    'Sumário',
    'Sumário executivo',
    'Uma plataforma única para gerir a operação comercial',
    'Em produção, com dados reais do Pinguim BO, a funcionar todos os dias sem trabalho manual.',
  );
  const g = h('div', 'cx-quatro');
  const bloco = (n1, titulo, linhas) => {
    const b = h('div', 'cx-bloco');
    b.append(h('span', 'cx-num', n1), h('h3', null, titulo));
    const ul = h('ul');
    linhas.filter(Boolean).forEach((l) => ul.append(h('li', null, l)));
    b.append(ul);
    return b;
  };
  const fases = (r.fases || []).slice(0, 3).map((f) => f.titulo + ' · ' + dia(f.inicio));
  g.append(
    bloco('01', 'Ponto de partida', [r.ponto_partida || '']),
    bloco('02', 'O que foi entregue', [
      PAGES.length + ' páginas em ' + grupos.length + ' áreas de negócio',
      n0(D.novidades_total) + ' melhorias publicadas e testadas',
      'Instalação automática, sem paragens',
    ]),
    bloco('03', 'Resultados', [
      n ? kzc(n.meses.reduce((x, m) => x + m.total, 0)) + ' de vendas em 6 meses' : '',
      n ? n0(n.clientes_30) + ' clientes activos (30 dias)' : '',
      n0(tarefas) + ' tarefas automáticas em 30 dias',
    ]),
    bloco('04', 'Próximos passos', fases),
  );
  s.append(
    g,
    h(
      'div',
      'cx-base-visao',
      'Mensagem-chave: a operação comercial já é gerida com dados do próprio dia; as próximas fases levam o mesmo modelo ao terreno e ao resto do Grupo.',
    ),
    fonte('Fontes: Pinguim BO, registo de alterações do portal e rotina automática · dados de ' + dl(D.hora) + ', ' + String(D.hora || '').slice(11)),
  );
  return s;
}

// ── 3. Desempenho comercial ──
function grafico(meses) {
  const ns = 'http://www.w3.org/2000/svg';
  const W = 700,
    base = 250,
    topo = 30;
  const max = Math.max(1, ...meses.map((m) => m.total));
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', `0 0 ${W} 290`);
  svg.setAttribute('class', 'cx-barras');
  svg.setAttribute('role', 'img');
  svg.setAttribute('aria-label', 'Vendas por mês: ' + meses.map((m) => nomeMes(m.mes) + ' ' + kzc(m.total)).join('; '));
  const passo = W / meses.length;
  const larg = Math.min(64, passo * 0.55);
  const eixo = document.createElementNS(ns, 'line');
  [
    ['x1', 0],
    ['x2', W],
    ['y1', base],
    ['y2', base],
    ['class', 'cx-eixo'],
  ].forEach(([k, v]) => eixo.setAttribute(k, v));
  svg.append(eixo);
  meses.forEach((m, i) => {
    const alt = Math.max(m.total > 0 ? 3 : 0, ((base - topo) * Math.max(0, m.total)) / max);
    const x = i * passo + (passo - larg) / 2;
    const y = base - alt;
    const rr = Math.min(4, alt);
    const r = document.createElementNS(ns, 'path');
    r.setAttribute('d', `M${x},${base}V${y + rr}Q${x},${y} ${x + rr},${y}H${x + larg - rr}Q${x + larg},${y} ${x + larg},${y + rr}V${base}Z`);
    r.setAttribute('class', 'cx-barra' + (m.parcial ? ' parcial' : ''));
    const tt = document.createElementNS(ns, 'title');
    tt.textContent = nomeMes(m.mes) + ': ' + kzc(m.total) + (m.parcial ? ' (em curso)' : '');
    r.append(tt);
    const v = document.createElementNS(ns, 'text');
    v.setAttribute('x', x + larg / 2);
    v.setAttribute('y', y - 8);
    v.setAttribute('class', 'cx-valor');
    v.textContent = kzc(m.total).replace(' Kz', '');
    const l = document.createElementNS(ns, 'text');
    l.setAttribute('x', x + larg / 2);
    l.setAttribute('y', base + 24);
    l.setAttribute('class', 'cx-rotulo');
    l.textContent = nomeMes(m.mes) + (m.parcial ? '*' : '');
    svg.append(r, v, l);
  });
  return svg;
}
function desempenho() {
  const n = D.negocio;
  const u = n && n.ultimo_mes;
  const msg =
    n && u && u.variacao != null
      ? mesLongo(u.mes) + ' fechou com ' + kzc(u.total) + ', ' + (u.variacao >= 0 ? 'mais ' : 'menos ') + pct(Math.abs(u.variacao)) + ' do que o mês anterior.'
      : 'Vendas líquidas lidas directamente do Pinguim BO, já com as notas de crédito.';
  const s = diapositivo('Desempenho', 'Desempenho comercial', 'Vendas dos últimos 6 meses', msg);
  if (!n) {
    s.append(semDados());
    return s;
  }
  const linha = h('div', 'cx-kpis');
  const total6 = n.meses.reduce((x, m) => x + m.total, 0);
  linha.append(
    kpi(kzc(total6), 'vendas em 6 meses'),
    kpi(kzc(n.media_mensal), 'média mensal', 'meses completos'),
    kpi(
      u ? kzc(u.total) : '—',
      u ? 'vendas de ' + mesLongo(u.mes).toLowerCase() : 'último mês',
      variacao(u && u.variacao) ? variacao(u.variacao) + ' face ao mês anterior' : null,
      u && u.variacao < 0 ? 'desce' : 'sobe',
    ),
    kpi(kzc(n.mes.total), 'mês em curso, até hoje', variacao(n.mes.variacao) ? variacao(n.mes.variacao) + ' face ao mesmo período' : 'mês a começar'),
  );
  const corpo = h('div', 'cx-dois');
  const esq = h('div', 'cx-grafico');
  esq.append(grafico(n.meses));
  const dir = h('div', 'cx-lado');
  dir.append(
    kpi(n0(n.clientes_30), 'clientes activos', 'compraram nos últimos 30 dias'),
    kpi(kzc(n.mes.valor_medio), 'valor médio por compra', 'mês em curso'),
    kpi(n0(n.mes.rotas), 'rotas a vender', 'mês em curso'),
  );
  corpo.append(esq, dir);
  s.append(linha, corpo, fonte('Fonte: Pinguim BO · vendas líquidas em kwanzas · * mês em curso · dados de ' + dl(D.hora)));
  return s;
}

// ── 4. Carteira de clientes ──
function carteira() {
  const c = (D.automacao || {}).carteira;
  const msg =
    c && c.parado
      ? n0(c.parado) + ' clientes parados representam ' + kzc(c.valor_parados) + ' em cada ciclo de compra: são a primeira prioridade comercial.'
      : 'Cada cliente analisado todas as noites pelo seu próprio ritmo de compra.';
  const s = diapositivo('Clientes', 'Carteira de clientes', 'Saúde da carteira, cliente a cliente', msg);
  if (!c) {
    s.append(D.ve_valores ? h('p', 'cx-vazio', 'A primeira análise fica pronta na próxima noite.') : semDados());
    return s;
  }
  const tot = c.em_dia + c.a_abrandar + c.parado || 1;
  const partes = [
    ['em_dia', 'Em dia', c.em_dia],
    ['a_abrandar', 'A abrandar', c.a_abrandar],
    ['parado', 'Parados', c.parado],
  ];
  const barra = h('div', 'cx-segmentos');
  barra.setAttribute('role', 'img');
  barra.setAttribute('aria-label', partes.map((p) => p[1] + ' ' + p[2]).join(', '));
  const leg = h('div', 'cx-leg');
  partes.forEach(([k, nome, v]) => {
    if (v) {
      const seg = h('div', 'cx-seg ' + k);
      seg.style.flexGrow = String(v / tot);
      seg.append(h('span', null, pct(v / tot)));
      barra.append(seg);
    }
    const i = h('span', 'cx-leg-i');
    i.append(h('i', 'cx-seg ' + k), document.createTextNode(nome + ' · ' + n0(v) + ' clientes'));
    leg.append(i);
  });
  const k = h('div', 'cx-kpis');
  k.append(
    kpi(n0(tot), 'clientes analisados'),
    kpi(n0(c.encomendas), 'encomendas sugeridas prontas', 'para as visitas do dia'),
    kpi(kzc(c.valor_parados), 'a recuperar nos parados', 'compra habitual de cada um', c.valor_parados ? 'desce' : null),
    kpi(n0(c.anomalias), 'alertas a verificar', 'últimos 7 dias'),
  );
  const accoes = h('div', 'cx-tres');
  [
    ['Visitas do dia', 'Cada vendedor recebe a lista do dia, já pela ordem do percurso, com a ficha de cada cliente.'],
    ['Encomenda sugerida', 'Quantidades propostas pelo histórico de cada cliente: vende-se mais em cada visita.'],
    ['Alertas à gestão', 'Clientes a abrandar e parados chegam aos gestores antes de se perderem.'],
  ].forEach(([tt, tx]) => {
    const b = h('div', 'cx-accao');
    b.append(h('b', null, tt), h('span', null, tx));
    accoes.append(b);
  });
  s.append(
    barra,
    leg,
    k,
    h('div', 'cx-subtitulo', 'O que o portal faz com esta análise, sozinho'),
    accoes,
    fonte('Fonte: Pinguim BO · análise automática de ' + dl(c.calculado) + ' às ' + String(c.calculado || '').slice(11, 16)),
  );
  return s;
}

// ── 5. Automação e eficiência ──
function automacao() {
  const H = D.horas || {};
  const A2 = D.automacao || {};
  const tot = ['resumos', 'calculos', 'planos', 'fechos', 'versoes'].reduce((x, k) => x + (+A2[k] || 0), 0);
  const s = diapositivo(
    'Automação',
    'Automação e eficiência',
    'O portal trabalha sozinho, todos os dias',
    n0(tot) + ' tarefas feitas sem intervenção humana nos últimos 30 dias.',
  );
  const passos = [
    [H.instalar, 'Versões instaladas', A2.versoes],
    [H.calculo, 'Clientes analisados', A2.calculos],
    [H.resumo, 'Resumo aos gestores', A2.resumos],
    [H.plano, 'Cargas de amanhã', A2.planos],
    [H.carrinha, 'Carrinhas conferidas', null],
    [H.fecho, 'Fecho do dia', A2.fechos],
  ]
    .filter((p) => p[0])
    .sort((a, b) => a[0].localeCompare(b[0]));
  const linha = h('ol', 'cx-linha');
  passos.forEach(([hora, nome, n]) => {
    const li = h('li');
    li.append(h('b', null, hora), h('span', null, nome), h('small', null, n != null ? n0(n) + ' em 30 dias' : 'todos os dias'));
    linha.append(li);
  });
  const k = h('div', 'cx-kpis');
  k.append(
    kpi(n0(tot), 'tarefas automáticas', 'últimos 30 dias'),
    kpi(n0(A2.versoes), 'versões instaladas', 'testadas e sem paragens'),
    kpi('24 h', 'funcionamento', 'actualização de madrugada'),
    kpi('0', 'ficheiros Excel na origem', 'dados sempre das bases de dados'),
  );
  const tab = h('div', 'cx-antes');
  const cab = h('div', 'cx-antes-l cx-antes-cab');
  cab.append(h('span', null, 'Antes'), h('span', null, 'Agora, com o portal'));
  tab.append(cab);
  [
    ['Relatórios do dia feitos à mão', 'Resumo no correio de cada gestor às ' + (H.resumo || '07:00')],
    ['Plano de cargas montado em folhas de cálculo', 'Plano de amanhã proposto sozinho às ' + (H.plano || '18:00')],
    ['Fecho do dia por telefone e mensagens', 'Fecho por rota enviado às ' + (H.fecho || '20:00')],
    ['Actualizações com paragens e riscos', 'Versões testadas e instaladas de madrugada, com regresso automático'],
  ].forEach(([a, b]) => {
    const l = h('div', 'cx-antes-l');
    l.append(h('span', null, a), h('b', null, b));
    tab.append(l);
  });
  s.append(linha, k, tab, fonte('Fonte: registos da rotina automática do portal · horas configuradas no servidor'));
  return s;
}

// ── 6. Governação, segurança e conformidade ──
function governanca() {
  const g = D.governanca || {};
  const c = (D.automacao || {}).carteira;
  const s = diapositivo(
    'Governação',
    'Governação, segurança e conformidade',
    'Controlo de quem vê o quê, e prova de tudo',
    'Acessos por perfil, verificação em dois passos e registo de todas as acções, segundo as normas internacionais.',
  );
  const grelha = h('div', 'cx-seis');
  grelha.append(
    kpi(n0(g.contas), 'contas activas', 'cada pessoa com a sua conta'),
    kpi(g.mfa_pct != null ? pct(g.mfa_pct) : '—', 'com verificação em dois passos', n0(g.mfa) + ' de ' + n0(g.contas) + ' contas'),
    kpi(n0(g.perfis), 'perfis de acesso', n0(g.recursos) + ' funções controladas pela matriz'),
    kpi(n0(g.registos_mes), 'acções registadas este mês', 'auditoria completa'),
    g.agt
      ? kpi(
          g.agt.pct != null ? pct(g.agt.pct) : '—',
          'documentos comunicados à AGT',
          n0(g.agt.erros) + ' com erro · mês em curso',
          g.agt.erros ? 'desce' : null,
        )
      : kpi('—', 'comunicação à AGT', 'visível a quem acompanha a AGT'),
    kpi(c ? n0(c.qualidade) : '—', 'tarefas de qualidade dos dados', 'corrigidas na origem'),
  );
  const normas = h('div', 'cx-normas');
  ['ISO/IEC 27001 · acessos', 'ITIL · gestão de versões', 'OWASP · segurança', 'Lei 22/11 · protecção de dados'].forEach((x) =>
    normas.append(h('span', null, x)),
  );
  s.append(grelha, normas, fonte('Fontes: contas e matriz de acessos do portal, registos de actividade e Pinguim BO'));
  return s;
}

// ── 7. Arquitectura e integrações ──
function arquitectura() {
  const sis = D.sistemas || [];
  const lig = sis.filter((x) => x.ligado).length;
  const s = diapositivo(
    'Arquitectura',
    'Arquitectura e integrações',
    'Um só portal ligado aos sistemas do Grupo',
    lig + ' de ' + sis.length + ' integrações activas; as restantes estão preparadas e arrancam com a configuração.',
  );
  const mapa = h('div', 'cx-arq');
  const centro = h('div', 'cx-centro');
  centro.append(h('b', null, 'Portal RTM'), h('span', null, 'Vendas · Clientes · Terreno · Logística · Gestão'));
  const lista = h('div', 'cx-sistemas');
  sis.forEach((x) => {
    const c = h('div', 'cx-sis' + (x.ligado ? ' on' : ''));
    c.append(h('b', null, x.nome), h('span', null, x.funcao), h('em', null, x.ligado ? 'Ligado' : 'Por ligar'));
    lista.append(c);
  });
  mapa.append(centro, lista);
  s.append(mapa, fonte('Estado das ligações lido na configuração do servidor do portal'));
  return s;
}

// ── 8. Roteiro ──
/** Cronograma (Gantt) das fases, com as datas de início e fim. */
function gantt(lista) {
  const fases = (lista || []).filter((f) => f.inicio && f.fim);
  if (!fases.length) return null;
  {
    const ms = (d) => new Date(d + 'T12:00:00Z').getTime();
    const ini = Math.min(...fases.map((f) => ms(f.inicio)));
    const fim = Math.max(...fases.map((f) => ms(f.fim)));
    const pos = (d) => ((ms(d) - ini) / (fim - ini || 1)) * 100;
    const g = h('div', 'cx-gantt');
    const eixo = h('div', 'cx-meses');
    const d0 = new Date(ini);
    for (let d = new Date(Date.UTC(d0.getUTCFullYear(), d0.getUTCMonth(), 1)); d.getTime() <= fim; d.setUTCMonth(d.getUTCMonth() + 1)) {
      const m = h('span', null, MESES[d.getUTCMonth()] + ' ' + String(d.getUTCFullYear()).slice(2));
      m.style.left = Math.max(0, ((d.getTime() - ini) / (fim - ini || 1)) * 100) + '%';
      eixo.append(m);
    }
    g.append(eixo);
    fases.forEach((f) => {
      const fila = h('div', 'cx-fila');
      const b = h('div', 'cx-fase ' + (f.estado || 'planeado'));
      const largura = Math.max(2, pos(f.fim) - pos(f.inicio));
      b.style.left = pos(f.inicio) + '%';
      b.style.width = largura + '%';
      const texto = [h('b', null, f.titulo), h('small', null, dia(f.inicio) + ' – ' + dia(f.fim))];
      if (largura < 28 && pos(f.fim) < 72) {
        // fase curta: o texto fica ao lado da barra, sem cortes
        const rotulo = h('div', 'cx-fase-rotulo');
        rotulo.style.left = 'calc(' + pos(f.fim) + '% + 12px)';
        rotulo.append(...texto);
        fila.append(b, rotulo);
      } else {
        b.append(...texto);
        fila.append(b);
      }
      g.append(fila);
    });
    return g;
  }
}
function roteiro() {
  const r = D.roteiro || {};
  const s = diapositivo('Roteiro', 'Roteiro', 'O que vem a seguir, com datas', 'Três fases, cada uma a começar com resultados visíveis para a gestão.');
  const g = gantt(r.fases);
  if (g) s.append(g);
  const ini = h('div', 'cx-iniciativas');
  [...(r.futuro || []), ...(r.ia || [])]
    .sort((a, b) => String(a.data).localeCompare(String(b.data)))
    .forEach((x) => {
      const c = h('div', 'cx-ini');
      c.append(h('em', null, x.estado === 'em_curso' ? 'Em curso' : dia(x.data)), h('b', null, x.titulo), h('span', null, x.resumo));
      ini.append(c);
    });
  s.append(ini, fonte('Roteiro revisto em ' + dia(r.actualizado)));
  return s;
}

// ── 9. Visão 2027–2028 ──
// ── Projecto estratégico: frota ligada (GPS Quatenus) ──
// Caso de negócio segundo as boas práticas (benefícios financeiros e operacionais, linha de base, metas e fases):
// o valor de cada visita a mais calcula-se com os dados reais do mês (compra média e rotas activas).
function valorVisita() {
  const f = (D.roteiro || {}).frota || {};
  const m = (D.negocio || {}).mes || {};
  if (!m.valor_medio || !m.rotas) return null;
  const mensal = m.valor_medio * m.rotas * (f.dias_uteis || 22) * (f.visitas_extra || 1);
  return { mensal, anual: mensal * 12, compra: m.valor_medio, rotas: m.rotas, dias: f.dias_uteis || 22, extra: f.visitas_extra || 1 };
}
function frota() {
  const f = (D.roteiro || {}).frota || {};
  const v = valorVisita();
  const s = diapositivo(
    'Frota ligada',
    'Projecto estratégico · ' + (f.titulo || 'Frota ligada'),
    f.subtitulo || 'GPS das carrinhas integrado no portal',
    v
      ? 'Cada visita a mais por rota e por dia vale cerca de ' + kzc(v.mensal) + ' de vendas por mês, com a frota que já temos.'
      : 'Mais vendas por rota e menos custo por entrega, com prova no terreno.',
  );
  const tres = h('div', 'cx-tres');
  (f.beneficios || []).forEach((b) => {
    const c = h('div', 'cx-accao cx-benef');
    const ul = h('ul');
    (b.itens || []).forEach((x) => ul.append(h('li', null, x)));
    c.append(h('b', null, b.titulo), ul);
    tres.append(c);
  });
  s.append(tres);
  if (v) {
    const k = h('div', 'cx-kpis');
    k.append(
      kpi(kzc(v.compra), 'compra média por cliente', 'este mês'),
      kpi(n0(v.rotas), 'rotas activas', 'este mês'),
      kpi('+' + kzc(v.mensal), 'vendas por mês', '+' + v.extra + ' visita por rota · ' + v.dias + ' dias úteis', 'sobe'),
      kpi('+' + kzc(v.anual), 'vendas por ano', 'mesmo cálculo × 12 meses', 'sobe'),
    );
    s.append(h('div', 'cx-subtitulo', 'Valor de cada visita a mais, com os dados reais'), k);
  } else if (!D.ve_valores) s.append(semDados());
  if (f.investimento) s.append(h('p', 'cx-governacao', f.investimento));
  s.append(fonte('Fonte: Pinguim BO (compra média e rotas do mês) · os ganhos em custos medem-se contra a linha de base do 1.º mês'));
  return s;
}
function frotaPlano() {
  const f = (D.roteiro || {}).frota || {};
  const m = (D.negocio || {}).mes || {};
  const s = diapositivo(
    'Frota · metas',
    'Projecto estratégico · ' + (f.titulo || 'Frota ligada'),
    'Como medimos o resultado, e quando',
    'Indicadores com linha de base e meta a 6 meses, revistos todos os meses com a gestão.',
  );
  const tab = h('div', 'cx-antes cx-metas');
  const cab = h('div', 'cx-antes-l cx-antes-cab');
  cab.append(h('span', null, 'Indicador'), h('span', null, 'Hoje'), h('span', null, 'Meta a 6 meses'));
  tab.append(cab);
  (f.indicadores || []).forEach((x) => {
    const l = h('div', 'cx-antes-l');
    const base = x.base === 'visitas' ? (m.visitas_rota_dia != null ? nf1.format(m.visitas_rota_dia) + ' por rota' : 'Medido no 1.º mês') : x.base;
    l.append(h('span', null, x.nome), h('span', null, base), h('b', null, x.meta));
    tab.append(l);
  });
  s.append(tab);
  const g = gantt(f.fases);
  if (g) s.append(g);
  if (f.governacao) s.append(h('p', 'cx-governacao', f.governacao));
  return s;
}
function conformidade() {
  const lg = ((D.roteiro || {}).frota || {}).legal || {};
  const s = diapositivo('Conformidade legal', 'Projecto estratégico · Conformidade legal', lg.titulo || 'O que diz a lei', lg.mensagem || '');
  const g = h('div', 'cx-lei');
  const esq = h('div', 'cx-lei-col');
  esq.append(h('div', 'cx-subtitulo', 'O que diz a lei'));
  (lg.lei || []).forEach((x) => {
    const l = h('div', 'cx-lei-l');
    l.append(h('b', null, x.ref), h('span', null, x.texto));
    esq.append(l);
  });
  const dir = h('div', 'cx-lei-col');
  dir.append(h('div', 'cx-subtitulo', 'O que fazemos para ficar tudo oficial'));
  (lg.passos || []).forEach((x, i) => {
    const l = h('div', 'cx-lei-p');
    const tx = h('span', null, x.texto);
    l.append(h('span', 'cx-num', String(i + 1).padStart(2, '0')), tx, h('em', null, dia(x.data)));
    tx.append(h('small', null, x.quem));
    dir.append(l);
  });
  g.append(esq, dir);
  s.append(g);
  if (lg.nota) s.append(fonte(lg.nota));
  return s;
}
function visao() {
  const v = (D.roteiro || {}).visao || {};
  const s = diapositivo(
    'Visão',
    'Visão',
    v.titulo || 'Plano Tecnológico',
    'Do Portal RTM a uma plataforma de dados e gestão para todo o Grupo · ' + (v.periodo || ''),
  );
  const g = h('div', 'cx-pilares');
  (v.pilares || []).forEach((p, i) => {
    const b = h('div', 'cx-bloco cx-pilar');
    b.append(h('span', 'cx-num', String(i + 1).padStart(2, '0')), h('h3', null, p.titulo), h('p', null, p.texto));
    g.append(b);
  });
  s.append(
    g,
    h('div', 'cx-base-visao', 'O Portal RTM é a primeira peça em produção: a mesma arquitectura, segurança e automação estendem-se às restantes empresas.'),
  );
  return s;
}

// ── 10. Recomendações e encerramento ──
function fecho() {
  const r = D.roteiro || {};
  const p = r.projecto || {};
  const s = diapositivo(
    'Recomendações',
    'Recomendações · para conhecimento do Conselho',
    'Próximos passos recomendados',
    'Cada recomendação segue os circuitos habituais de aprovação da empresa.',
  );
  const tab = h('div', 'cx-recs');
  (r.proximos || [])
    .slice()
    .sort((a, b) => String(a.data).localeCompare(String(b.data)))
    .forEach((x, i) => {
      const l = h('div', 'cx-rec');
      l.append(h('span', 'cx-num', String(i + 1).padStart(2, '0')), h('b', null, x.etiqueta), h('span', null, x.texto), h('em', null, dia(x.data)));
      tab.append(l);
    });
  const fim = h('div', 'cx-obrigado');
  fim.append(h('b', null, 'Obrigado'), h('span', null, [p.apresentador, p.funcao].filter(Boolean).join(' · ')));
  s.append(tab, fim);
  return s;
}

// ── palco: diapositivo 16:9 ajustado ao espaço, navegação e ecrã inteiro ──
function ajustar(palco) {
  const area = palco.querySelector('.cx-area');
  if (!area) return;
  const cheio = document.fullscreenElement === palco;
  const largura = area.clientWidth;
  const altura = cheio ? innerHeight - 70 : Math.max(320, innerHeight - area.getBoundingClientRect().top - 90);
  const k = Math.min(largura / L, altura / A);
  area.style.height = Math.round(A * k) + 'px';
  area.querySelectorAll('.cx-slide').forEach((x) => {
    x.style.transform = 'translateX(' + Math.max(0, (largura - L * k) / 2) + 'px) scale(' + k + ')';
  });
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
  clearTimeout(relogio);
  relogio = setTimeout(() => atual === 'apresentacao' && ler(), Math.max(1000, REFRESCAR_MS - (Date.now() - lidoEm)));
  const palco = h('div', 'cx');
  const area = h('div', 'cx-area');
  const slides = [
    capa(),
    sumario(),
    desempenho(),
    carteira(),
    automacao(),
    governanca(),
    arquitectura(),
    roteiro(),
    frota(),
    frotaPlano(),
    conformidade(),
    visao(),
    fecho(),
  ];
  slides.forEach((x, i) => {
    if (i) {
      const rod = h('footer', 'cx-rodape');
      rod.append(h('span', null, 'Confidencial · Portal RTM · ' + dl(D.hora)), h('span', null, i + 1 + ' / ' + slides.length));
      x.append(rod);
    }
    area.append(x);
  });
  const base = h('div', 'cx-nav');
  const sep = h('div', 'cx-pontos');
  sep.setAttribute('role', 'tablist');
  const ir = (i) => {
    cena = Math.max(0, Math.min(slides.length - 1, i));
    slides.forEach((x, k) => {
      x.classList.toggle('activa', k === cena);
      x.setAttribute('aria-hidden', k === cena ? 'false' : 'true');
    });
    [...sep.children].forEach((b, k) => {
      b.classList.toggle('on', k === cena);
      b.setAttribute('aria-selected', k === cena ? 'true' : 'false');
    });
  };
  slides.forEach((x, i) => {
    const b = h('button', 'cx-ponto', String(i + 1).padStart(2, '0') + ' ' + x.dataset.nome);
    b.type = 'button';
    b.setAttribute('role', 'tab');
    b.addEventListener('click', () => ir(i));
    sep.append(b);
  });
  const botoes = h('div', 'cx-botoes');
  const botao = (txt, rotulo, f) => {
    const b = h('button', 'cx-btn', txt);
    b.type = 'button';
    if (rotulo) b.setAttribute('aria-label', rotulo);
    b.addEventListener('click', f);
    return b;
  };
  botoes.append(
    botao('‹', 'Diapositivo anterior', () => ir(cena - 1)),
    botao('›', 'Diapositivo seguinte', () => ir(cena + 1)),
    botao('Ecrã inteiro', '', () => (palco.requestFullscreen ? palco.requestFullscreen().catch(() => {}) : null)),
  );
  base.append(sep, botoes);
  palco.append(area, base);
  palco.tabIndex = 0;
  palco.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowRight' || e.key === 'PageDown' || e.key === ' ') {
      e.preventDefault();
      ir(cena + 1);
    }
    if (e.key === 'ArrowLeft' || e.key === 'PageUp') ir(cena - 1);
  });
  pg.append(palco);
  ir(cena);
  if (obs) obs.disconnect();
  if (window.ResizeObserver) {
    obs = new ResizeObserver(() => (palco.isConnected ? ajustar(palco) : obs.disconnect()));
    obs.observe(palco);
  }
  palco.addEventListener('fullscreenchange', () => requestAnimationFrame(() => ajustar(palco)));
  requestAnimationFrame(() => ajustar(palco));
};
