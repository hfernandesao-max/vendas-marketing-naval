// Terreno › Rotas propostas: fila de aprovação das rotas novas que o portal propõe todas as noites (rota cheia a
// dividir ou zona sem rota com muitos estabelecimentos), com o impacto em visitas e em peso. Ao aprovar, os clientes
// da zona passam para a rota nova no portal e sai a lista para registar no Pinguim BO.
// Servidor: nucleo/api/v1/rotas-propostas.php e nucleo/servicos/rotas-propostas.php.
import { apiJson } from '../api.js';
import { actualizarContagem } from '../contagens.js';
import { draw } from '../app.js';
import { download, pill, secBox, tiles } from '../componentes/blocos.js';
import { botao, entrada, msgErro } from '../componentes/janelas.js';
import { dl, kz, n0, pct } from '../formatos.js';
import { t, tv } from '../i18n/i18n.js';
import { atual } from '../perfil.js';
import { postJson } from '../servidor.js';
import { el, toast } from '../util.js';
import { R } from './registo.js';

let RP = null;
let aLer = false;
let filtro = 'pendente';
const COR = { pendente: 'p-warn', aprovada: 'p-serio', activa: 'p-ok', rejeitada: 'p-mut', substituida: 'p-mut' };
const TIPO = { divisao: 'Dividir uma rota cheia', zona: 'Rota nova numa zona sem rota' };

function ler() {
  if (aLer) return;
  aLer = true;
  apiJson('rotas-propostas').then((d) => {
    aLer = false;
    RP = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os dados.') };
    if (RP.propostas) actualizarContagem('rotas-propostas', RP.propostas.filter((p) => p.estado === 'pendente').length);
    if (atual === 'rotas-propostas') draw();
  });
}

/** Território (polígono [[lat, lng]…]) e clientes da proposta, desenhados no portal. */
function mapaTerritorio(p) {
  const pol = (p.territorio || []).filter((x) => Array.isArray(x) && x.length === 2);
  const cs = (p.clientes || []).filter((c) => c.lat != null && c.lng != null);
  const pts = [...pol.map(([lat, lng]) => ({ lat, lng })), ...cs];
  if (pts.length < 2) return null;
  const lat0 = pts.reduce((s, x) => s + x.lat, 0) / pts.length;
  const kx = Math.cos((lat0 * Math.PI) / 180) * 111320,
    ky = 110540;
  const xs = pts.map((x) => x.lng * kx),
    ys = pts.map((x) => -x.lat * ky);
  const W = 360,
    H = 220,
    M = 16;
  const lx = Math.max(...xs) - Math.min(...xs) || 1,
    ly = Math.max(...ys) - Math.min(...ys) || 1;
  const esc = Math.min((W - 2 * M) / lx, (H - 2 * M) / ly);
  const cx = (Math.max(...xs) + Math.min(...xs)) / 2,
    cy = (Math.max(...ys) + Math.min(...ys)) / 2;
  const px = (lng) => W / 2 + (lng * kx - cx) * esc,
    py = (lat) => H / 2 + (-lat * ky - cy) * esc;
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
  svg.setAttribute('class', 'rp-mapa');
  svg.setAttribute('role', 'img');
  svg.setAttribute('aria-label', t('Território proposto'));
  const mk = (tag, at) => {
    const e = document.createElementNS(ns, tag);
    Object.entries(at).forEach(([k, v]) => e.setAttribute(k, v));
    svg.append(e);
    return e;
  };
  mk('rect', { x: 0, y: 0, width: W, height: H, rx: 10, class: 'cf-mapa-fundo' });
  if (pol.length >= 3) mk('polygon', { points: pol.map(([lat, lng]) => px(lng) + ',' + py(lat)).join(' '), class: 'rp-territorio' });
  cs.forEach((c) => {
    const e = mk('circle', { cx: px(c.lng), cy: py(c.lat), r: 4, class: 'rp-cliente' });
    const tt = document.createElementNS(ns, 'title');
    tt.textContent = (c.n || c.c) + ' · ' + c.c;
    e.append(tt);
  });
  return svg;
}

function impacto(p) {
  const i = p.impacto || {};
  const linhas = [
    [t('Clientes que passam'), p.tipo === 'zona' ? '—' : n0(i.clientes)],
    [t('Potenciais clientes'), p.tipo === 'zona' ? n0(p.potenciais) : '—'],
    [t('Visitas por semana'), n0(i.visitas_semana) + ' / ' + n0(i.capacidade_visitas) + (i.ocupacao != null ? ' (' + pct(i.ocupacao) + ')' : '')],
  ];
  if (p.tipo === 'divisao') linhas.push([t('Ocupação da rota de origem'), pct(i.ocupacao_origem_antes) + ' → ' + pct(i.ocupacao_origem_depois)]);
  linhas.push([t('Vendas por semana (sem IVA)'), i.vendas_semana == null ? '—' : kz(i.vendas_semana)]);
  const kg =
    i.kg_dia == null
      ? p.tipo === 'zona'
        ? '—'
        : t('por saber: os artigos não têm peso no Pinguim BO')
      : n0(i.kg_dia) +
        ' kg' +
        (i.capacidade_kg ? ' / ' + n0(i.capacidade_kg) + ' kg (' + pct(i.carga) + ')' : '') +
        (i.artigos_sem_peso ? ' · ' + t('{n} artigos sem peso', { n: n0(i.artigos_sem_peso) }) : '');
  linhas.push([t('Carga por dia de visita'), kg]);
  const d = el('dl', 'cf-pares rp-impacto');
  linhas.forEach(([k, v]) => d.append(el('dt', null, k), el('dd', null, v)));
  return d;
}

/** Lista dos clientes a registar no Pinguim BO (rota actual → rota nova), em CSV para abrir no Excel. */
function listaPinguim(p) {
  const nova = (p.decisao && p.decisao.rota_nova) || '';
  const atrib = new Set((p.decisao && p.decisao.atribuidos) || p.clientes.map((c) => c.c));
  const cs = p.clientes.filter((c) => atrib.has(c.c));
  const box = el('div', 'rp-lista');
  if (!cs.length) return box;
  const tb = el('table', 'cf-tab');
  const h = el('tr');
  [t('Código'), t('Cliente'), t('Rota actual'), t('Rota nova')].forEach((x) => h.append(el('th', null, x)));
  const th = el('thead');
  th.append(h);
  const bd = el('tbody');
  cs.forEach((c) => {
    const tr = el('tr');
    [c.c, c.n || '—', c.rota || '—', nova].forEach((x) => tr.append(el('td', null, x)));
    bd.append(tr);
  });
  tb.append(th, bd);
  const csv = () => {
    const q = (s) => '"' + String(s == null ? '' : s).replace(/"/g, '""') + '"';
    const linhas = [[t('Código'), t('Cliente'), t('Rota actual'), t('Rota nova')], ...cs.map((c) => [c.c, c.n, c.rota, nova])];
    download(
      p.id + '_rota-' + nova + '_clientes.csv',
      new Blob(['﻿' + linhas.map((l) => l.map(q).join(';')).join('\r\n')], { type: 'text/csv;charset=utf-8' }),
    );
  };
  box.append(el('h4', null, t('Para registar no Pinguim BO ({n} clientes)', { n: n0(cs.length) })), botao(t('Descarregar a lista (CSV)'), 'ghost sm', csv));
  const w = el('div', 'cf-scroll');
  w.append(tb);
  box.append(w);
  return box;
}

function decidir(p, box) {
  const f = el('div', 'rp-decidir');
  const rota = entrada('text', '', { maxLength: 20, placeholder: t('ex.: 1015') });
  rota.setAttribute('aria-label', t('Código da rota nova') + ' · ' + p.id);
  const prom = entrada('text', '', { maxLength: 80, placeholder: t('Nome do promotor') });
  prom.setAttribute('aria-label', t('Promotor da rota nova') + ' · ' + p.id);
  const nota = entrada('text', '', { maxLength: 300, placeholder: t('Nota (obrigatória para rejeitar)') });
  nota.setAttribute('aria-label', t('Nota da decisão') + ' · ' + p.id);
  const erro = msgErro();
  const enviar = (accao, ok) => {
    erro.hidden = true;
    return postJson('rotas-propostas', { accao, id: p.id, rota_nova: rota.value, promotor: prom.value, nota: nota.value }).then((x) => {
      if (!x.ok) {
        erro.hidden = false;
        erro.textContent = tv(x.erro || 'Não foi possível gravar.');
        return;
      }
      const conf = (x.conflitos || []).length;
      toast(
        t(ok, { n: n0(x.atribuidos || 0), r: x.rota_nova || '' }) +
          (conf ? ' ' + t('{n} clientes já estavam noutra rota nova e ficaram como estavam.', { n: n0(conf) }) : ''),
        6000,
      );
      RP = null;
      ler();
      draw();
    });
  };
  const campos = el('div', 'rp-campos');
  campos.append(el('label', 'campo', t('Código da rota nova')), rota, el('label', 'campo', t('Promotor')), prom, el('label', 'campo', t('Nota')), nota);
  [...campos.querySelectorAll('label')].forEach((l, k) => (l.htmlFor = 'rp-' + p.id + '-' + k));
  rota.id = 'rp-' + p.id + '-0';
  prom.id = 'rp-' + p.id + '-1';
  nota.id = 'rp-' + p.id + '-2';
  const acc = el('div', 'cc-acc');
  acc.append(
    botao(t('Rejeitar'), 'ghost', () => enviar('rejeitar', 'Proposta rejeitada.')),
    botao(t('Aprovar e atribuir os clientes'), null, () => enviar('aprovar', 'Rota {r} aprovada: {n} clientes atribuídos no portal.')),
  );
  f.append(campos, erro, acc);
  box.append(f);
}

function cartao(p) {
  const c = el('article', 'gst-alerta rp-proposta g-' + (p.estado === 'pendente' ? 'aviso' : 'info'));
  c.dataset.id = p.id;
  const h = el('div', 'cc-cab');
  h.append(
    el('b', null, p.id + ' · ' + t(TIPO[p.tipo] || p.tipo) + (p.origem_rota ? ' · ' + t('rota {r}', { r: p.origem_rota }) : '')),
    pill(t(p.estado_nome || p.estado), COR[p.estado] || 'p-mut'),
  );
  c.append(h, el('p', 'note', tv(p.motivo) + ' ' + t('Proposta de {d}.', { d: dl(p.criada) })));
  const corpo = el('div', 'rp-corpo');
  const mapa = mapaTerritorio(p);
  if (mapa) corpo.append(mapa);
  corpo.append(impacto(p));
  c.append(corpo);
  if (p.decisao) {
    const d = p.decisao;
    const txt =
      d.accao === 'aprovar'
        ? t('Aprovada por {p} em {d}: rota {r}', { p: d.nome || d.por, d: dl(String(d.em).slice(0, 10)), r: d.rota_nova }) +
          (d.promotor ? ' · ' + d.promotor : '')
        : t('Rejeitada por {p} em {d}', { p: d.nome || d.por, d: dl(String(d.em).slice(0, 10)) });
    c.append(el('p', 'note rp-decisao', txt + (d.nota ? ' · ' + d.nota : '')));
    if ((d.conflitos || []).length)
      c.append(
        el(
          'p',
          'aviso',
          t('{n} clientes já estavam noutra rota nova e ficaram como estavam: {l}', {
            n: n0(d.conflitos.length),
            l: d.conflitos.map((x) => x.c + ' (' + x.rota + ')').join(', '),
          }),
        ),
      );
  }
  if (p.estado === 'aprovada') c.append(listaPinguim(p));
  if (p.estado === 'pendente' && RP.decide) decidir(p, c);
  else if (p.estado === 'pendente') c.append(el('p', 'note cc-espera', t('Aguarda a decisão da Direcção, do Comercial ou da GeoMobilidade.')));
  return c;
}

R['rotas-propostas'] = (pg) => {
  if (!RP) {
    pg.append(el('div', 'glass empty', t('A carregar…')));
    ler();
    return;
  }
  if (RP.falhou) {
    pg.append(el('div', 'glass empty', tv(RP.falhou)));
    return;
  }
  const ps = RP.propostas || [];
  const n = (e) => ps.filter((p) => p.estado === e).length;
  pg.append(
    tiles([
      { l: t('Pendentes de aprovação'), v: n0(n('pendente')), tone: n('pendente') ? 'warn' : null, s: t('propostas desta noite e anteriores') },
      { l: t('Aprovadas, por registar'), v: n0(n('aprovada')), tone: n('aprovada') ? 'serio' : null, s: t('registar a rota no Pinguim BO') },
      { l: t('Activas'), v: n0(n('activa')), s: t('já com vendas na rota nova') },
      { l: t('Rejeitadas ou substituídas'), v: n0(n('rejeitada') + n('substituida')) },
    ]),
  );
  const seg = el('div', 'rel-tabs rel-tabs-2 rp-filtro');
  seg.setAttribute('role', 'tablist');
  [
    ['pendente', 'Pendentes'],
    ['aprovada', 'Aprovadas'],
    ['todas', 'Todas'],
  ].forEach(([k, nome]) => {
    const b = el('button', 'rel-tab' + (filtro === k ? ' on' : ''), t(nome));
    b.type = 'button';
    b.setAttribute('role', 'tab');
    b.setAttribute('aria-selected', filtro === k ? 'true' : 'false');
    b.addEventListener('click', () => ((filtro = k), draw()));
    seg.append(b);
  });
  const lista = el('div', 'gst-lista');
  const vis = ps.filter((p) => filtro === 'todas' || p.estado === filtro || (filtro === 'aprovada' && p.estado === 'activa'));
  vis.forEach((p) => lista.append(cartao(p)));
  if (!vis.length)
    lista.append(
      el(
        'p',
        'note',
        filtro === 'pendente' ? t('Sem propostas pendentes: nenhuma rota está cheia nem há zonas sem rota com muitos estabelecimentos.') : t('Sem propostas.'),
      ),
    );
  const box = secBox(
    t('Rotas propostas'),
    t(
      'Todas as noites o portal propõe uma rota nova quando uma rota passa 14 dias seguidos acima do limite de visitas ou quando uma zona sem rota tem muitos estabelecimentos. Ao aprovar, os clientes passam para a rota nova no portal (visitas do dia já; percursos optimizados a partir da noite seguinte); a rota tem de ser registada no Pinguim BO e a proposta fica activa quando as vendas já a mostram.',
    ),
    lista,
  );
  box.querySelector('.sec-h').after(seg);
  pg.append(box);
};
