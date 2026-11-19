// Ficha do cliente (Clientes › Carteira de clientes): cinco separadores numa janela.
//  - Dados e localização, Visitas e tempos e Mapa: do OLAP (v1/clientes/ficha), lidos ao abrir — onde cada venda foi
//    feita, o promotor, o tempo no cliente e a deslocação até ao cliente seguinte;
//  - Vendas e Pagamentos: das vendas do Pinguim BO já carregadas para o período escolhido, com as mesmas regras de
//    todos os ecrãs (vendas sem IVA, notas de crédito, meios de cobrança efectivos). O OLAP não tem meios de pagamento.
// Servidor: nucleo/api/v1/cliente-ficha.php e nucleo/servicos/olap-clientes.php.
import { apiFetch } from '../api.js';
import { docsVenda, isCF, liquidoDe, nomeDia, notasCredito, porMeio, promotorRota, totalVendaDe } from '../calculos.js';
import { pill } from '../componentes/blocos.js';
import { barras, legenda } from '../componentes/graficos.js';
import { botao, janela } from '../componentes/janelas.js';
import { diaCurto, dl, ds, kz, n0, pct } from '../formatos.js';
import { t, tv } from '../i18n/i18n.js';
import { eNC, pendenteDe, recebidoEfectivoDe } from '../meios.js';
import { inR, st } from '../periodo.js';
import { DB, el, group, sum } from '../util.js';

const SEPARADORES = [
  ['dados', 'Dados e localização'],
  ['vendas', 'Vendas'],
  ['pagamentos', 'Pagamentos'],
  ['visitas', 'Visitas e tempos'],
  ['mapa', 'Mapa'],
];
const DO_OLAP = ['dados', 'visitas', 'mapa'];
const CATEGORIA = {
  dinheiro: 'Numerário',
  multicaixa: 'Multicaixa',
  transferencia: 'Transferência',
  outro: 'Outro',
  sem_meio: 'Sem meio indicado',
  compensacao: 'Compensação com nota de crédito',
};
const ULTIMOS_DOCS = 15;

/**
 * Documentos do cliente no período. As vendas identificam o cliente pelo identificador interno do Pinguim BO
 * (ENTITY.ID, que o OLAP devolve em cliente.id); a carteira usa o código (ENTITY.CODE). Conta o documento cujo
 * cliente_codigo é um dos dois ou, como recurso, com o mesmo NIF (nunca o Consumidor Final).
 */
export function vendasDoCliente(V, c, id = '') {
  const ids = new Set([String(c.codigo || ''), String(id || '')].filter(Boolean));
  const nif = String(c.nif || '');
  return V.filter((x) => ids.has(String(x.cliente_codigo || '')) || (nif !== '' && !isCF(nif) && String(x.nif) === nif));
}

// ── pequenas peças, fora do registo da página (a ficha não entra no Excel da página) ─────────────────────────────────
function quadros(lista) {
  const w = el('div', 'tiles cf-tiles');
  lista.forEach((x) => {
    const d = el('div', 'glass tile');
    d.append(el('span', 'l', x.l));
    const v = el('span', 'v', x.v);
    if (x.tone) v.style.color = `var(--${x.tone})`;
    d.append(v);
    if (x.s) d.append(el('span', 's', x.s));
    w.append(d);
  });
  return w;
}
function grelha(titulo, cols, rows, vazio) {
  const s = el('section', 'cf-bloco');
  s.append(el('h4', null, titulo));
  if (!rows.length) {
    s.append(el('p', 'note', vazio || t('Sem dados no período.')));
    return s;
  }
  const w = el('div', 'cf-scroll');
  const tb = el('table', 'cf-tab');
  const h = el('tr');
  cols.forEach((c) => h.append(el('th', c.n ? 'n' : null, c.l)));
  const th = el('thead');
  th.append(h);
  const tbd = el('tbody');
  rows.forEach((r) => {
    const tr = el('tr');
    cols.forEach((c) => {
      const v = c.f ? c.f(r) : r[c.k];
      const td = el('td', c.n ? 'n' : null);
      if (v instanceof Node) td.append(v);
      else td.textContent = v == null || v === '' ? '—' : String(v);
      tr.append(td);
    });
    tbd.append(tr);
  });
  tb.append(th, tbd);
  w.append(tb);
  s.append(w);
  return s;
}
function pares(titulo, lista) {
  const s = el('section', 'cf-bloco');
  s.append(el('h4', null, titulo));
  const dl2 = el('dl', 'cf-pares');
  lista.forEach(([k, v]) => {
    dl2.append(el('dt', null, k));
    const dd = el('dd');
    if (v instanceof Node) dd.append(v);
    else dd.textContent = v == null || v === '' ? '—' : String(v);
    dl2.append(dd);
  });
  s.append(dl2);
  return s;
}
const aviso = (txt, cls) => el('p', 'note cf-aviso' + (cls ? ' ' + cls : ''), txt);

// ── Dados e localização (carteira do portal + OLAP) ─────────────────────────────────────────────────────────────────
function painelDados(c, o) {
  const p = el('div', 'cf-painel');
  const cli = (o && o.cliente) || {};
  const loc = (o && o.localizacao) || {};
  const at = (o && o.atribuicao) || null;
  p.append(
    pares(t('Identificação'), [
      [t('Código'), c.codigo],
      [t('Cliente'), c.nome || cli.nome],
      [t('Nome fiscal'), cli.nome_fiscal],
      [t('NIF'), c.nif || cli.nif],
      [t('Tipo'), c.tipo ? tv(c.tipo) : null],
      [t('Tipologia'), cli.tipologia ? tv(cli.tipologia) : null],
      [t('Estado'), c.estado ? tv(c.estado) : null],
    ]),
  );
  const dias = at && at.dias_visita && at.dias_visita.length ? at.dias_visita.map(nomeDia).join(', ') : c.dias;
  p.append(
    pares(t('Rota e equipa'), [
      [t('Rota'), (at && at.rota) || c.rota],
      [t('Promotor'), promotorRota((at && at.rota) || c.rota)],
      [t('Vendedor no OLAP'), at && at.vendedor],
      [t('Coordenador'), at && at.coordenador],
      [t('Gestor'), at && at.gestor],
      [t('Distribuidor'), at && at.distribuidor],
      [t('Canal'), at && [at.canal, at.subcanal].filter(Boolean).join(' · ')],
      [t('Dias de visita'), dias],
      [t('Atribuído desde'), at && at.desde ? dl(at.desde) : null],
    ]),
  );
  let gps = null;
  if (loc.lat != null && loc.lng != null) {
    gps = el('span', 'cf-gps');
    const a = el('a', null, t('Abrir no mapa'));
    a.href = 'https://www.google.com/maps/search/?api=1&query=' + loc.lat + ',' + loc.lng;
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    gps.append(document.createTextNode(loc.lat.toFixed(6) + ', ' + loc.lng.toFixed(6) + ' · '), a);
  } else gps = pill(t(loc.gps_invalido ? 'Coordenadas fora de Angola' : 'Sem GPS'), 'p-warn');
  p.append(
    pares(t('Localização'), [
      [t('Município'), loc.municipio],
      [t('Cidade'), loc.cidade],
      [t('GPS'), o ? gps : null],
    ]),
  );
  return p;
}

// ── Vendas (Pinguim BO, período escolhido) ──────────────────────────────────────────────────────────────────────────
function painelVendas(V) {
  const p = el('div', 'cf-painel');
  const F = docsVenda(V);
  const liq = sum(V, liquidoDe);
  const nc = notasCredito(V);
  const ult = F.map((x) => x.data)
    .sort()
    .pop();
  p.append(
    quadros([
      { l: t('Vendas s/ IVA'), v: kz(liq), s: t('líquidas de notas de crédito') },
      { l: t('Com IVA'), v: kz(sum(F, totalVendaDe)) },
      { l: t('Documentos'), v: n0(F.length), s: nc ? t('notas de crédito: {v}', { v: kz(nc) }) : '' },
      { l: t('Média por documento'), v: kz(F.length ? liq / F.length : null), s: t('sem IVA') },
      { l: t('Última compra'), v: ult ? ds(ult) : '—' },
    ]),
  );
  if (!V.length) {
    p.append(aviso(t('Sem compras de {a} a {b}. Escolha outro período na barra de filtros.', { a: dl(st.de), b: dl(st.ate) })));
    return p;
  }
  // evolução: por mês quando o período tem mais de um mês; senão por dia
  const meses = [...new Set(V.map((x) => x.data.slice(0, 7)))].sort();
  const porMes = meses.length > 1;
  const chave = (x) => (porMes ? x.data.slice(0, 7) : x.data);
  const g = group(V, chave);
  const cats = Object.keys(g).sort();
  const box = el('section', 'cf-bloco');
  box.append(el('h4', null, porMes ? t('Vendas por mês') : t('Vendas por dia')));
  box.append(
    barras({
      cats: cats.map((k) => ({ txt: porMes ? k.slice(5, 7) + '/' + k.slice(2, 4) : diaCurto(k), titulo: porMes ? k : ds(k) })),
      series: [{ nome: t('Vendas s/ IVA'), vals: cats.map((k) => sum(g[k], liquidoDe)), cls: 'g-cur' }],
      altura: 180,
      esquerda: 72,
      rot: cats.length > 10,
    }),
  );
  box.append(legenda([['g-cur', t('Vendas s/ IVA')]]));
  p.append(box);
  const docs = [...V].sort((a, b) => (b.data + (b.hora || '')).localeCompare(a.data + (a.hora || ''))).slice(0, ULTIMOS_DOCS);
  p.append(
    grelha(
      t('Últimos documentos'),
      [
        { l: t('Data'), f: (x) => dl(x.data) + (x.hora ? ' ' + x.hora : '') },
        { l: t('Documento'), k: 'documento' },
        { l: t('Tipo'), f: (x) => tv(x.tipo) },
        { l: t('Rota'), k: 'rota' },
        { l: t('Vendas s/ IVA'), n: 1, f: (x) => kz(liquidoDe(x)) },
        { l: t('Com IVA'), n: 1, f: (x) => kz(totalVendaDe(x)) },
        { l: t('Meio de cobrança'), f: (x) => (eNC(x) ? '—' : x.meio_pagamento ? tv(x.meio_pagamento) : t('Sem meio indicado')) },
      ],
      docs,
    ),
  );
  if (V.length > ULTIMOS_DOCS) p.append(aviso(t('Mostram-se os {n} mais recentes de {m} documentos.', { n: ULTIMOS_DOCS, m: n0(V.length) })));
  return p;
}

// ── Pagamentos: modo (meio de cobrança) e tipo (pronto pagamento ou a crédito) ──────────────────────────────────────
function painelPagamentos(V) {
  const p = el('div', 'cf-painel');
  const F = docsVenda(V);
  const total = sum(F, totalVendaDe);
  const recebido = sum(F, recebidoEfectivoDe);
  const pendente = sum(F, pendenteDe);
  const meios = porMeio(F).filter((m) => m.categoria !== 'compensacao');
  const somaCat = (c) =>
    sum(
      meios.filter((m) => m.categoria === c),
      'valor',
    );
  const baseMeios = sum(meios, 'valor');
  p.append(
    quadros([
      { l: t('Recebido'), v: kz(recebido), s: t('{p} do facturado', { p: pct(total ? recebido / total : null) }) },
      { l: t('Pendente'), v: kz(pendente), tone: pendente > 0.005 ? 'warn' : null, s: t('facturas por liquidar') },
      { l: t('Numerário'), v: pct(baseMeios ? somaCat('dinheiro') / baseMeios : null), s: kz(somaCat('dinheiro')) },
      { l: t('Multicaixa'), v: pct(baseMeios ? somaCat('multicaixa') / baseMeios : null), s: kz(somaCat('multicaixa')) },
    ]),
  );
  if (!F.length) {
    p.append(aviso(t('Sem compras de {a} a {b}. Escolha outro período na barra de filtros.', { a: dl(st.de), b: dl(st.ate) })));
    return p;
  }
  p.append(
    grelha(
      t('Modo de pagamento'),
      [
        { l: t('Meio de cobrança'), f: (m) => tv(m.meio) },
        { l: t('Categoria'), f: (m) => t(CATEGORIA[m.categoria] || m.categoria) },
        { l: t('Documentos'), n: 1, f: (m) => n0(m.docs) },
        { l: t('Valor'), n: 1, f: (m) => kz(m.valor) },
        { l: t('% do recebido'), n: 1, f: (m) => pct(baseMeios ? m.valor / baseMeios : null) },
      ],
      meios,
      t('Sem pagamentos registados no período.'),
    ),
  );
  // tipo de pagamento: a factura-recibo é paga no acto; a factura fica a crédito até ser liquidada
  const tipos = [
    ['pronto', t('Pronto pagamento (factura-recibo)'), F.filter((x) => x.tipo_codigo === 'FR' || x.tipo === 'Factura-recibo')],
    ['credito', t('A crédito (factura)'), F.filter((x) => !(x.tipo_codigo === 'FR' || x.tipo === 'Factura-recibo'))],
  ]
    .filter(([, , rs]) => rs.length)
    .map(([k, nome, rs]) => ({
      k,
      nome,
      docs: rs.length,
      valor: sum(rs, totalVendaDe),
      recebido: sum(rs, recebidoEfectivoDe),
      pendente: sum(rs, pendenteDe),
    }));
  p.append(
    grelha(
      t('Tipo de pagamento'),
      [
        { l: t('Tipo'), k: 'nome' },
        { l: t('Documentos'), n: 1, f: (r) => n0(r.docs) },
        { l: t('Com IVA'), n: 1, f: (r) => kz(r.valor) },
        { l: t('Recebido'), n: 1, f: (r) => kz(r.recebido) },
        { l: t('Pendente'), n: 1, f: (r) => (r.pendente > 0.005 ? pill(kz(r.pendente), 'p-warn') : kz(r.pendente)) },
      ],
      tipos,
    ),
  );
  // modo × mês: como o cliente paga ao longo do período
  const meses = [...new Set(F.map((x) => x.data.slice(0, 7)))].sort();
  if (meses.length > 1) {
    const linhas = meses.map((m) => {
      const ms = porMeio(F.filter((x) => x.data.slice(0, 7) === m));
      const o = { mes: m.slice(5, 7) + '/' + m.slice(0, 4) };
      ['dinheiro', 'multicaixa', 'transferencia', 'outro', 'sem_meio'].forEach(
        (c) =>
          (o[c] = sum(
            ms.filter((x) => x.categoria === c),
            'valor',
          )),
      );
      return o;
    });
    const usadas = ['dinheiro', 'multicaixa', 'transferencia', 'outro', 'sem_meio'].filter((c) => linhas.some((l) => Math.abs(l[c]) > 0.005));
    p.append(
      grelha(t('Modo de pagamento por mês'), [{ l: t('Mês'), k: 'mes' }, ...usadas.map((c) => ({ l: t(CATEGORIA[c]), n: 1, f: (l) => kz(l[c]) }))], linhas),
    );
  }
  return p;
}

// ── Visitas (OLAP) ──────────────────────────────────────────────────────────────────────────────────────────────────
function painelVisitas(o) {
  const p = el('div', 'cf-painel');
  const r = o.resumo_visitas || {};
  p.append(
    quadros([
      { l: t('Visitas'), v: n0(r.total), s: t('últimos {n} dias', { n: o.janela_dias }) },
      { l: t('Com venda'), v: n0(r.com_venda), s: pct(r.total ? r.com_venda / r.total : null) },
      { l: t('Presenciais'), v: n0(r.presenciais), s: t('remotas: {n}', { n: n0(r.remotas) }) },
      { l: t('Distância mediana ao cliente'), v: r.distancia_mediana == null ? '—' : n0(r.distancia_mediana) + ' m', s: t('GPS da visita × GPS do cliente') },
      { l: t('Tempo no cliente'), v: minutos(r.permanencia_mediana_min), s: t('mediana · visitas com fecho: {n}', { n: n0(r.com_tempo || 0) }) },
      { l: t('Deslocação até ao seguinte'), v: minutos(r.deslocacao_mediana_min), s: t('mediana, mesmo promotor e dia') },
      { l: t('Última visita'), v: r.ultima ? ds(r.ultima) : '—' },
    ]),
  );
  if (!r.total) {
    p.append(aviso(t('Sem visitas registadas no OLAP nos últimos {n} dias.', { n: o.janela_dias })));
    return p;
  }
  p.append(
    grelha(
      t('Resultado das visitas'),
      [
        { l: t('Resultado ou motivo de não venda'), f: (x) => tv(x.resultado) },
        { l: t('Visitas'), n: 1, f: (x) => n0(x.n) },
        { l: t('% das visitas'), n: 1, f: (x) => pct(r.total ? x.n / r.total : null) },
      ],
      r.por_resultado || [],
    ),
  );
  const MODO = { presencial: 'Presencial', remota: 'Remota', outra: '—' };
  p.append(
    grelha(
      t('Histórico de visitas'),
      [
        { l: t('Data'), f: (x) => dl(x.data) + (x.hora ? ' ' + x.hora : '') },
        { l: t('Promotor'), f: (x) => x.vendedor || x.rota },
        { l: t('Resultado'), f: (x) => pill(tv(x.resultado), x.venda ? 'p-ok' : 'p-mut') },
        { l: t('Modo'), f: (x) => (MODO[x.modo] === '—' ? '—' : t(MODO[x.modo])) + (x.sem_gps ? ' · ' + t('sem GPS') : '') },
        { l: t('Valor'), n: 1, f: (x) => (x.valor == null ? '—' : kz(x.valor)) },
        { l: t('No cliente'), n: 1, f: (x) => minutos(x.permanencia_min) },
        { l: t('Até ao cliente seguinte'), f: ateSeguinte },
        { l: t('Distância'), n: 1, f: (x) => (x.distancia == null ? '—' : n0(x.distancia) + ' m') },
      ],
      o.visitas || [],
    ),
  );
  p.append(
    aviso(
      t(
        'No cliente: da abertura ao fecho da visita na aplicação (só quando o fecho é no mesmo dia e até 4 h). Até ao cliente seguinte: do fecho desta visita ao início da seguinte do mesmo promotor no mesmo dia, em linha recta; sem fecho registado, o intervalo entre os inícios.',
      ),
    ),
  );
  return p;
}
const minutos = (m) => (m == null ? '—' : m >= 60 ? Math.floor(m / 60) + ' h ' + String(m % 60).padStart(2, '0') + ' min' : m + ' min');
const metros = (m) => (m == null ? '' : m >= 1000 ? (m / 1000).toFixed(1).replace('.', ',') + ' km' : n0(m) + ' m');
function ateSeguinte(x) {
  if (!x.proximo_cliente && x.intervalo_min == null) return '—';
  const w = el('span', 'cf-seg');
  const tempo = x.proximo_mesmo_sitio
    ? t('mesmo sítio')
    : x.deslocacao_min != null
      ? minutos(x.deslocacao_min)
      : x.intervalo_min != null
        ? t('{m} entre inícios', { m: minutos(x.intervalo_min) })
        : '—';
  w.append(el('b', null, tempo));
  const det = [metros(x.proximo_m), x.proximo_cliente].filter(Boolean).join(' · ');
  if (det) w.append(el('span', 'note', det));
  return w;
}

// ── Mapa (OLAP): o cliente e o sítio de cada visita, desenhado no portal, sem serviço externo ───────────────────────
function painelMapa(c, o, fechar) {
  const p = el('div', 'cf-painel');
  const loc = o.localizacao || {};
  const vis = (o.visitas || []).filter((v) => v.lat != null && v.lng != null);
  const pts = [...(loc.lat != null ? [{ lat: loc.lat, lng: loc.lng }] : []), ...vis];
  if (!pts.length) {
    p.append(aviso(t('Sem coordenadas do cliente nem das visitas no OLAP.')));
    return p;
  }
  // projecção plana (equirectangular) à volta do centro: a área de um cliente são poucos quilómetros
  const lat0 = pts.reduce((s, x) => s + x.lat, 0) / pts.length;
  const kx = Math.cos((lat0 * Math.PI) / 180) * 111320,
    ky = 110540;
  const xs = pts.map((x) => x.lng * kx),
    ys = pts.map((x) => -x.lat * ky);
  const minX = Math.min(...xs),
    maxX = Math.max(...xs),
    minY = Math.min(...ys),
    maxY = Math.max(...ys);
  const lado = Math.max(maxX - minX, maxY - minY, 200); // pelo menos 200 m de lado
  const W = 640,
    H = 340,
    M = 28,
    esc = Math.min((W - 2 * M) / Math.max(maxX - minX, lado * 0.6), (H - 2 * M) / Math.max(maxY - minY, lado * 0.6));
  const cx = (minX + maxX) / 2,
    cy = (minY + maxY) / 2;
  const px = (lng) => W / 2 + (lng * kx - cx) * esc,
    py = (lat) => H / 2 + (-lat * ky - cy) * esc;
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
  svg.setAttribute('class', 'cf-mapa');
  svg.setAttribute('role', 'img');
  svg.setAttribute('aria-label', t('Mapa do cliente e das visitas'));
  const mk = (tag, at, titulo) => {
    const e = document.createElementNS(ns, tag);
    Object.entries(at).forEach(([k, v]) => e.setAttribute(k, v));
    if (titulo) {
      const tt = document.createElementNS(ns, 'title');
      tt.textContent = titulo;
      e.append(tt);
    }
    svg.append(e);
    return e;
  };
  mk('rect', { x: 0, y: 0, width: W, height: H, class: 'cf-mapa-fundo', rx: 12 });
  // escala: 50 m, 100 m, 200 m, 500 m, 1 km… a ocupar até um quarto da largura
  const passo = [50, 100, 200, 500, 1000, 2000, 5000, 10000, 20000].find((m) => m * esc >= W / 8) || 20000;
  mk('line', { x1: M, x2: M + passo * esc, y1: H - 14, y2: H - 14, class: 'cf-mapa-escala' });
  const te = mk('text', { x: M, y: H - 20, class: 'cf-mapa-txt' });
  te.textContent = metros(passo);
  // linhas do cliente ao sítio de cada visita (a distância da prova de visita)
  if (loc.lat != null) vis.forEach((v) => mk('line', { x1: px(loc.lng), y1: py(loc.lat), x2: px(v.lng), y2: py(v.lat), class: 'cf-mapa-liga' }));
  // o cliente primeiro e as visitas por cima: uma venda feita à porta do cliente continua visível
  if (loc.lat != null) {
    const s = 9;
    mk('rect', { x: px(loc.lng) - s, y: py(loc.lat) - s, width: 2 * s, height: 2 * s, rx: 3, class: 'cf-mapa-cliente' }, c.nome || c.codigo);
  }
  vis.forEach((v) =>
    mk(
      'circle',
      { cx: px(v.lng), cy: py(v.lat), r: 7, class: v.venda ? 'cf-mapa-venda' : 'cf-mapa-visita' },
      [
        dl(v.data) + (v.hora ? ' ' + v.hora : ''),
        v.vendedor,
        tv(v.resultado),
        v.valor != null ? kz(v.valor) : '',
        v.distancia != null ? n0(v.distancia) + ' m' : '',
      ]
        .filter(Boolean)
        .join(' · '),
    ),
  );
  const box = el('section', 'cf-bloco');
  box.append(el('h4', null, t('Cliente e sítio de cada visita')));
  box.append(svg);
  box.append(
    legenda([
      ['cf-l-cliente', t('Cliente (GPS do registo)')],
      ['cf-l-venda', t('Visita com venda')],
      ['cf-l-visita', t('Visita sem venda')],
    ]),
  );
  if (loc.lat == null) box.append(aviso(t('O cliente não tem GPS no OLAP: mostram-se só as visitas.')));
  p.append(box);
  // percurso completo de cada dia (mapa interactivo do Terreno do dia, com as paragens e os tempos)
  const dias = [...new Map(vis.map((v) => [v.data + '|' + (v.rota || ''), v])).values()];
  if (dias.length) {
    const lista = el('section', 'cf-bloco');
    lista.append(el('h4', null, t('Percurso do promotor nesse dia')));
    const ul = el('ul', 'cf-dias');
    dias.slice(0, 10).forEach((v) => {
      const li = el('li');
      const a = el('a', null, ds(v.data) + (v.rota ? ' · ' + t('rota {r}', { r: v.rota }) : ''));
      a.href = '#geo-terreno?data=' + v.data + (v.rota ? '&rota=' + encodeURIComponent(v.rota) : '');
      a.addEventListener('click', () => fechar());
      li.append(a);
      ul.append(li);
    });
    lista.append(ul);
    p.append(lista);
  }
  return p;
}

/** Abre a ficha do cliente; c: linha da carteira {codigo, nome, nif, rota, tipo, estado, dias}. */
export function abrirFicha(c, inicial = 'dados') {
  return janela(c.nome || c.codigo || t('Cliente'), [c.codigo, promotorRota(c.rota)].filter(Boolean).join(' · '), (b, ft, fechar, d) => {
    d.classList.add('dlg-largo', 'cf-dlg');
    const doPeriodo = (DB.vendas || []).filter((x) => inR(x.data));
    // até chegar o identificador interno do OLAP, só o código e o NIF; depois, também o identificador das vendas
    let V = vendasDoCliente(doPeriodo, c);
    let olap = null; // {ok, …} ou {erro}
    const lista = el('div', 'rel-tabs rel-tabs-2 cf-sep');
    lista.setAttribute('role', 'tablist');
    lista.setAttribute('aria-label', t('Ficha do cliente'));
    const area = el('div', 'cf-area');
    const botoes = {};
    const paineis = {};
    const desenhar = (id) => {
      const x = paineis[id];
      x.replaceChildren();
      if (id === 'vendas') x.append(painelVendas(V));
      else if (id === 'pagamentos') x.append(painelPagamentos(V));
      else if (!olap) x.append(aviso(t('A ler o OLAP…')));
      else if (olap.erro && id !== 'dados') x.append(aviso(tv(olap.erro), 'cf-erro'));
      else if (id === 'visitas') x.append(painelVisitas(olap));
      else if (id === 'mapa') x.append(painelMapa(c, olap, fechar));
      else {
        if (olap.erro) x.append(aviso(t('Dados do OLAP indisponíveis: {e} Mostram-se os da carteira do portal.', { e: tv(olap.erro) }), 'cf-erro'));
        x.append(painelDados(c, olap.erro ? null : olap));
      }
      if (id === 'vendas' || id === 'pagamentos')
        x.append(aviso(t('Fonte: Pinguim BO, de {a} a {b} (período da barra de filtros).', { a: dl(st.de), b: dl(st.ate) })));
      else if (olap && !olap.erro) x.append(aviso(t('Fonte: OLAP.')));
    };
    const mostrar = (id) => {
      SEPARADORES.forEach(([k]) => {
        const on = k === id;
        botoes[k].classList.toggle('on', on);
        botoes[k].setAttribute('aria-selected', on ? 'true' : 'false');
        botoes[k].tabIndex = on ? 0 : -1;
        paineis[k].hidden = !on;
      });
    };
    SEPARADORES.forEach(([k, nome], i) => {
      const bt = el('button', 'rel-tab cf-tab-b', t(nome));
      bt.type = 'button';
      bt.id = 'cf-t-' + k;
      bt.setAttribute('role', 'tab');
      bt.setAttribute('aria-controls', 'cf-p-' + k);
      bt.addEventListener('click', () => mostrar(k));
      // setas entre separadores (padrão de separadores da WAI-ARIA)
      bt.addEventListener('keydown', (e) => {
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
        e.preventDefault();
        const j = (i + (e.key === 'ArrowRight' ? 1 : SEPARADORES.length - 1)) % SEPARADORES.length;
        mostrar(SEPARADORES[j][0]);
        botoes[SEPARADORES[j][0]].focus();
      });
      botoes[k] = bt;
      lista.append(bt);
      const pn = el('div', 'cf-p');
      pn.id = 'cf-p-' + k;
      pn.setAttribute('role', 'tabpanel');
      pn.setAttribute('aria-labelledby', bt.id);
      paineis[k] = pn;
      area.append(pn);
      desenhar(k);
    });
    b.append(lista, area);
    mostrar(SEPARADORES.some(([k]) => k === inicial) ? inicial : 'dados');
    ft.append(botao(t('Fechar'), 'ghost', fechar));
    if (!c.codigo) {
      olap = { erro: 'Cliente sem código no Pinguim BO.' };
      DO_OLAP.forEach(desenhar);
      return;
    }
    // o erro do servidor (sem OLAP, cliente sem registo) mostra-se tal como vem; sem resposta, uma mensagem geral
    apiFetch('clientes/ficha?codigo=' + encodeURIComponent(c.codigo))
      .then((r) => r.json())
      .catch(() => null)
      .then((x) => {
        olap = x && x.ok ? x : { erro: (x && x.erro) || 'Não foi possível ler o OLAP.' };
        if (!d.open) return;
        DO_OLAP.forEach(desenhar);
        if (olap.cliente && olap.cliente.id) {
          V = vendasDoCliente(doPeriodo, c, olap.cliente.id);
          ['vendas', 'pagamentos'].forEach(desenhar);
        }
      });
  });
}
