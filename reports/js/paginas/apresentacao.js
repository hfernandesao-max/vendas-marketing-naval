// Gestão › Apresentação do portal, pensada para o Conselho de Administração (boas práticas de comunicação a
// conselhos: uma mensagem por ecrã, números grandes, pouco texto, datas concretas, sem explicações técnicas).
// Dados reais e actualizados sozinhos (API v1/apresentacao; refresca a cada 10 minutos com a página aberta):
//  - Negócio: vendas por mês e indicadores do mês, no âmbito de quem vê;
//  - Clientes: saúde da carteira calculada esta noite;
//  - Automação: o que o portal fez sozinho nos últimos 30 dias, à hora configurada;
//  - Futuro, calendário e recomendações: roteiro nucleo/recursos/roteiro.json, revisto em cada versão.
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { dl, n0, pct } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { atual } from '../perfil.js';
import { el } from '../util.js';
import { R } from './registo.js';

const REFRESCAR_MS = 10 * 60 * 1000;
let D = null;
let lidoEm = 0;
let aLer = false;
let cena = 0;
let relogio = 0;
const reduzir = () => window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
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
const variacao = (v) => (v == null ? '—' : (v >= 0 ? '▲ ' : '▼ ') + pct(Math.abs(v)));
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

const h = (tag, cls, txt) => el(tag, cls, txt);
function cena0(nome, olho, titulo, extra) {
  const s = h('section', 'apx-cena' + (extra ? ' ' + extra : ''));
  s.dataset.nome = nome;
  if (olho) s.append(h('div', 'apx-olho', olho));
  if (titulo) s.append(h('h2', null, titulo));
  return s;
}
function numero(valor, rotulo, cls) {
  const d = h('div', 'apx-num' + (cls ? ' ' + cls : ''));
  d.append(h('b', null, valor), h('span', null, rotulo));
  return d;
}
const nota = (txt) => h('p', 'apx-aviso', txt);
const semValores = () => nota('Disponível para quem acompanha as vendas.');

// ── 1. Abertura: a mensagem e três números ──
function cenaAbertura() {
  const s = cena0('Início', 'Grupo Naval · Portal RTM', null, 'apx-abertura');
  s.append(h('h1', null, 'Vender mais, com menos trabalho manual.'));
  const g = h('div', 'apx-numeros apx-tres');
  const n = D.negocio;
  const a = D.automacao || {};
  if (n) {
    g.append(
      numero(kzc(n.mes.total), 'vendas do mês'),
      numero(variacao(n.mes.variacao), 'face ao mês anterior', n.mes.variacao < 0 ? 'desce' : 'sobe'),
      numero(n0(n.clientes_30), 'clientes activos'),
    );
  } else {
    const tot = ['resumos', 'calculos', 'planos', 'fechos', 'versoes'].reduce((x, k) => x + (+a[k] || 0), 0);
    g.append(numero(n0(tot), 'tarefas automáticas'), numero(n0(a.calculos), 'análises de clientes'), numero(n0(a.resumos), 'resumos enviados'));
  }
  s.append(g, nota('Dados de ' + dl(D.hora) + ', ' + String(D.hora || '').slice(11) + '.'));
  return s;
}

// ── 2. Negócio: vendas por mês ──
function graficoMeses(meses) {
  const ns = 'http://www.w3.org/2000/svg';
  const W = 600,
    base = 220,
    topo = 30;
  const max = Math.max(1, ...meses.map((m) => m.total));
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', `0 0 ${W} 260`);
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
    r.setAttribute('d', `M${x},${base}V${y + rr}Q${x},${y} ${x + rr},${y}H${x + larg - rr}Q${x + larg},${y} ${x + larg},${y + rr}V${base}Z`);
    r.setAttribute('class', 'apx-barra' + (m.parcial ? ' parcial' : ''));
    const tt = document.createElementNS(ns, 'title');
    tt.textContent = `${nomeMes(m.mes)}${m.parcial ? ' (em curso)' : ''}: ${kzc(m.total)}`;
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
  const s = cena0('Negócio', 'Negócio', 'Vendas por mês');
  const n = D.negocio;
  if (!n) {
    s.append(semValores());
    return s;
  }
  const corpo = h('div', 'apx-dois');
  const esq = h('div', 'apx-col apx-grafico');
  esq.append(graficoMeses(n.meses), nota('* mês em curso, até hoje · valores em kwanzas'));
  const dir = h('div', 'apx-numeros apx-compacto');
  dir.append(
    numero(n0(n.mes.clientes), 'clientes este mês'),
    numero(kzc(n.mes.valor_medio), 'por compra'),
    numero(n0(n.mes.documentos), 'vendas este mês'),
    numero(n0(n.mes.rotas), 'rotas activas'),
  );
  corpo.append(esq, dir);
  s.append(corpo);
  return s;
}

// ── 3. Clientes: saúde da carteira ──
function cenaClientes() {
  const s = cena0('Clientes', 'Clientes', 'Saúde da carteira de clientes');
  const c = (D.automacao || {}).carteira;
  if (!c) {
    s.append(D.ve_valores ? nota('Primeira análise na próxima noite.') : semValores());
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
      seg.title = `${nome}: ${n0(v)} (${pct(v / tot)})`;
      barra.append(seg);
    }
    const i = h('span', 'apx-leg-i');
    i.append(h('i', 'apx-seg ' + k), document.createTextNode(`${nome} ${n0(v)} · ${pct(v / tot)}`));
    leg.append(i);
  });
  const g = h('div', 'apx-numeros apx-tres');
  g.append(
    numero(n0(c.encomendas), 'encomendas sugeridas prontas'),
    numero(kzc(c.valor_parados), 'a recuperar nos parados'),
    numero(n0(c.anomalias), 'alertas a verificar'),
  );
  s.append(barra, leg, g);
  if (c.calculado) s.append(nota('Actualizado em ' + dl(c.calculado) + '.'));
  return s;
}

// ── 4. Automação: o dia que o portal trabalha sozinho ──
function cenaAutomacao() {
  const s = cena0('Automação', 'Automação', 'O que o portal faz sozinho, todos os dias');
  const H = D.horas || {};
  const A = D.automacao || {};
  const passos = [
    [H.instalar, 'Portal actualizado', A.versoes],
    [H.calculo, 'Clientes analisados', A.calculos],
    [H.resumo, 'Resumo aos gestores', A.resumos],
    [H.plano, 'Cargas de amanhã', A.planos],
    [H.carrinha, 'Carrinhas conferidas', null],
    [H.fecho, 'Fecho do dia', A.fechos],
  ]
    .filter((p) => p[0])
    .sort((a, b) => a[0].localeCompare(b[0]));
  const linha = h('ol', 'apx-linha');
  passos.forEach(([hora, nome, n]) => {
    const li = h('li', 'apx-passo');
    li.append(h('b', 'apx-hora-t', hora), h('span', 'apx-passo-n', nome), h('small', null, n != null ? n0(n) + ' em 30 dias' : 'todos os dias'));
    linha.append(li);
  });
  const tot = ['resumos', 'calculos', 'planos', 'fechos', 'versoes'].reduce((x, k) => x + (+A[k] || 0), 0);
  s.append(linha, h('div', 'apx-numeros apx-um'));
  s.lastChild.append(numero(n0(tot), 'tarefas feitas sozinhas nos últimos 30 dias'));
  return s;
}

// ── 5. Futuro: o que vem a seguir, com data ──
function cartao(item, ia) {
  const c = h('article', 'apx-cartao' + (ia ? ' apx-ia' : ''));
  const cab = h('div', 'apx-cab');
  cab.append(h('h3', null, item.titulo), h('span', 'apx-estado ' + (item.estado || 'planeado'), item.estado === 'em_curso' ? 'Em curso' : dia(item.data)));
  c.append(cab, h('p', 'apx-det', item.resumo));
  return c;
}
function cenaFuturo() {
  const s = cena0('Futuro', 'O que vem a seguir', 'Ver, prever, decidir, controlar. E crescer com IA.');
  const r = D.roteiro || {};
  const g1 = h('div', 'apx-grelha4');
  const g2 = h('div', 'apx-grelha4');
  (r.futuro || []).forEach((x) => g1.append(cartao(x, false)));
  (r.ia || []).forEach((x) => g2.append(cartao(x, true)));
  s.append(g1, g2);
  return s;
}

// ── 6. Calendário e recomendações (para conhecimento) ──
function cenaCalendario() {
  const s = cena0('Calendário', 'Calendário', 'Fases e recomendações');
  const r = D.roteiro || {};
  const fases = (r.fases || []).filter((f) => f.inicio && f.fim);
  if (fases.length) {
    const ms = (d) => new Date(d + 'T12:00:00Z').getTime();
    const ini = Math.min(...fases.map((f) => ms(f.inicio)));
    const fim = Math.max(...fases.map((f) => ms(f.fim)));
    const pos = (d) => ((ms(d) - ini) / (fim - ini || 1)) * 100;
    const g = h('div', 'apx-gantt');
    g.setAttribute('role', 'img');
    g.setAttribute('aria-label', fases.map((f) => `${f.titulo}: ${dia(f.inicio)} a ${dia(f.fim)}`).join('; '));
    const eixo = h('div', 'apx-meses');
    const d0 = new Date(ini);
    for (let d = new Date(Date.UTC(d0.getUTCFullYear(), d0.getUTCMonth(), 1)); d.getTime() <= fim; d.setUTCMonth(d.getUTCMonth() + 1)) {
      const p = Math.max(0, ((d.getTime() - ini) / (fim - ini || 1)) * 100);
      const m = h('span', null, MESES[d.getUTCMonth()] + ' ' + String(d.getUTCFullYear()).slice(2));
      m.style.left = p + '%';
      eixo.append(m);
    }
    g.append(eixo);
    fases.forEach((f) => {
      const fila = h('div', 'apx-fila');
      const b = h('div', 'apx-barra-f ' + (f.estado || 'planeado'));
      b.style.left = pos(f.inicio) + '%';
      b.style.width = Math.max(8, pos(f.fim) - pos(f.inicio)) + '%';
      b.append(h('b', null, f.titulo), h('small', null, dia(f.inicio) + ' – ' + dia(f.fim)));
      fila.append(b);
      g.append(fila);
    });
    const hoje = new Date().toISOString().slice(0, 10);
    if (ms(hoje) >= ini && ms(hoje) <= fim) {
      const l = h('i', 'apx-hoje');
      l.style.left = pos(hoje) + '%';
      l.title = 'Hoje';
      g.append(l);
    }
    s.append(g);
  }
  const rec = (r.proximos || []).slice().sort((a, b) => String(a.data).localeCompare(String(b.data)));
  if (rec.length) {
    s.append(h('div', 'apx-olho apx-ambar', 'Recomendações · para conhecimento do Conselho'));
    const lista = h('div', 'apx-decis');
    rec.forEach((x) => {
      const d = h('div');
      d.append(h('b', null, x.etiqueta), document.createTextNode(x.texto));
      if (x.data) d.append(h('small', null, dia(x.data)));
      lista.append(d);
    });
    s.append(lista);
  }
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
  const cenas = [cenaAbertura(), cenaNegocio(), cenaClientes(), cenaAutomacao(), cenaFuturo(), cenaCalendario()];
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
