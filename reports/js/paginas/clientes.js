// Clientes: carteira e cadastros (visão geral, análise e clientes registados).
import { draw } from '../app.js';
import { DATA_META, ESTADO_PT, META_ROTA, META_TOTAL, cadDiario, cadReais, diaUtil, isCF, nomeDia, promotorRota, temCad } from '../calculos.js';
import { REG, pill, secBox, tabela, tiles } from '../componentes/blocos.js';
import { barras, legenda } from '../componentes/graficos.js';
import { diaCurto, dl, ds, n0, n1, pct } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { R } from './registo.js';
import { addD, hoje, inR, st } from '../periodo.js';
import { DB, el, group, sum } from '../util.js';

R.clientes = (pg) => {
  const C = DB.clientes;
  const uni = Object.values(group(C, (x) => x.rota + '|' + (isCF(x.nif) ? 'CF|' + (x.codigo || x.nome) : x.nif))).map((rs) => ({
    ...rs[0],
    dias: [...new Set(rs.map((x) => nomeDia(x.dia)))].join(', '),
  }));
  const comprou = new Set(DB.vendas.filter((x) => inR(x.data)).map((x) => x.rota + '|' + x.nif));
  const rows = uni.map((x) => ({
    rota: x.rota,
    promotor: promotorRota(x.rota),
    codigo: x.codigo,
    nome: x.nome,
    nif: x.nif,
    tipo: x.tipo,
    dias: x.dias,
    estado: x.estado,
    gps: x.tem_gps,
    comprou: !isCF(x.nif) && comprou.has(x.rota + '|' + x.nif),
  }));
  const reais = rows.filter((x) => !isCF(x.nif));
  pg.append(
    tiles([
      { l: t('Clientes na carteira'), v: n0(reais.length), s: t('sem Consumidor Final') },
      { l: t('Activos'), v: n0(reais.filter((x) => x.estado === 'Ativo').length) },
      { l: t('Pendentes ou com erro'), v: n0(rows.filter((x) => x.estado !== 'Ativo').length), tone: 'warn' },
      { l: t('Sem GPS'), v: n0(reais.filter((x) => !x.gps).length) },
      {
        l: t('Compraram no período'),
        v: n0(reais.filter((x) => x.comprou).length),
        s: t('{p} da carteira', { p: pct(reais.length ? reais.filter((x) => x.comprou).length / reais.length : null) }),
      },
    ]),
  );
  const pr = Object.values(group(rows, 'rota')).map((rs) => {
    const r2 = rs.filter((x) => !isCF(x.nif));
    return {
      rota: rs[0].rota,
      promotor: promotorRota(rs[0].rota),
      clientes: r2.length,
      activos: r2.filter((x) => x.estado === 'Ativo').length,
      pendentes: rs.filter((x) => x.estado !== 'Ativo').length,
      sem_gps: r2.filter((x) => !x.gps).length,
      compraram: r2.filter((x) => x.comprou).length,
      cobertura: r2.length ? r2.filter((x) => x.comprou).length / r2.length : null,
    };
  });
  const secs = el('div', 'secs');
  secs.append(
    tabela({
      id: 'cl-r',
      titulo: t('Carteira por rota'),
      nota: t('Cobertura = clientes da carteira que compraram no período.'),
      rows: pr,
      sort: 'rota',
      dir: 1,
      total: true,
      cols: [
        { key: 'rota', label: t('Rota'), p: 4 },
        { key: 'promotor', label: t('Promotor'), p: 0 },
        { key: 'clientes', label: t('Clientes'), type: 'n', sum: true },
        { key: 'activos', label: t('Activos'), type: 'n', sum: true },
        { key: 'pendentes', label: t('Pendentes / erro'), type: 'n', sum: true },
        { key: 'sem_gps', label: t('Sem GPS'), type: 'n', sum: true },
        { key: 'compraram', label: t('Compraram'), type: 'n', sum: true },
        {
          key: 'cobertura',
          label: t('Cobertura'),
          type: 'pct',
          sum: (rs) => {
            const a = sum(rs, 'clientes');
            return a ? sum(rs, 'compraram') / a : null;
          },
        },
      ],
    }),
  );
  secs.append(
    tabela({
      id: 'cl-d',
      titulo: t('Clientes'),
      rows,
      sort: 'nome',
      dir: 1,
      cols: [
        { key: 'rota', label: t('Rota'), p: 4 },
        { key: 'promotor', label: t('Promotor'), p: 0 },
        { key: 'codigo', label: t('Código') },
        { key: 'nome', label: t('Cliente'), wrap: true },
        { key: 'nif', label: t('NIF') },
        { key: 'tipo', label: t('Tipo') },
        { key: 'dias', label: t('Dias de visita') },
        {
          key: 'estado',
          label: t('Estado'),
          xl: (r) => t(ESTADO_PT[r.estado] || r.estado),
          render: (v) => pill(t(ESTADO_PT[v] || v), v === 'Ativo' ? 'p-ok' : v === 'Erro' ? 'p-bad' : 'p-warn'),
        },
        {
          key: 'comprou',
          label: t('Comprou no período'),
          render: (v) => pill(t(v ? 'Sim' : 'Não'), v ? 'p-ok' : 'p-mut'),
          xl: (r) => t(r.comprou ? 'Sim' : 'Não'),
        },
      ],
    }),
  );
  pg.append(secs);
};
function cadSemDados(pg) {
  const box = el('div');
  box.append(
    el(
      'p',
      null,
      t(
        'Os cadastros vêm do repositório rtm-clientes (exportação «Clientes» do backoffice do RTM). No servidor, clone esse repositório ao lado da pasta do site ou indique o ficheiro em RTM_CLIENTES_JSON no .env.',
      ),
    ),
  );
  pg.append(secBox(t('Sem dados de cadastros'), '', box));
}

R.cadastros = (pg) => {
  if (!temCad()) {
    cadSemDados(pg);
    return;
  }
  const C = cadReais().filter((x) => x.dia_registo <= st.ate),
    M = META_ROTA(),
    { rotas, dias, v, rede } = cadDiario();
  const ult = dias[dias.length - 1],
    ant = dias[dias.length - 2],
    metaRede = M * rotas.length;
  const naMeta = rotas.filter((r) => v(r, ult) >= M).length;
  // dias úteis que faltam até à data da meta (a partir do dia seguinte ao último com dados)
  let rest = 0;
  for (let d = addD(ult || hoje(), 1); d <= DATA_META(); d = addD(d, 1)) if (diaUtil(d)) rest++;
  const falta = Math.max(0, META_TOTAL() - C.length),
    ritmo = rest && rotas.length ? Math.ceil(falta / (rest * rotas.length)) : null;
  const act = C.filter((x) => x.estado === 'Activo').length,
    pend = C.filter((x) => x.estado === 'Pendente').length,
    corr = C.filter((x) => /^Corrigir/.test(x.situacao)).length;
  pg.append(
    tiles([
      {
        l: t('Clientes registados'),
        v: t('{a} de {b}', { a: n0(C.length), b: n0(META_TOTAL()) }),
        s: t('{p} concluído · meta {d}', { p: pct(C.length / META_TOTAL()), d: dl(DATA_META()) }),
        cls: 'full-value',
      },
      { l: t('Activos'), v: n0(act), s: t('{p} validados', { p: pct(C.length ? act / C.length : null) }) },
      { l: t('Por validar'), v: n0(pend), s: t('{n} com NIF a corrigir', { n: n0(corr) }), tone: pend ? 'warn' : null },
      {
        l: ult ? t('Novos em {d}', { d: ds(ult) }) : t('Novos'),
        v: n0(ult ? rede(ult) : 0),
        s: t('meta {m}', { m: n0(metaRede) }),
        tone: ult && rede(ult) >= metaRede ? 'ok' : 'warn',
      },
      { l: t('Rotas na meta'), v: `${naMeta}/${rotas.length}`, s: t('no último dia (≥{m})', { m: M }) },
      { l: t('Para chegar aos {n}', { n: n0(META_TOTAL()) }), v: ritmo == null ? '—' : n0(ritmo), s: t('por rota/dia · {n} dias úteis', { n: rest }) },
    ]),
  );
  // Destaques: rotas na meta em pelo menos metade dos dias e rotas que nunca chegaram à meta
  // Média e dias na meta contam só os dias em que a rota registou clientes
  const stat = rotas.map((r) => {
    const vs = dias.map((d) => v(r, d)).filter((x) => x > 0);
    return { r, media: vs.length ? vs.reduce((a, b) => a + b, 0) / vs.length : 0, meta: vs.filter((x) => x >= M).length, ativos: vs.length };
  });
  const chips = (lista, cls) => {
    const w = el('div', 'chips');
    if (!lista.length) w.append(el('span', 'note', '—'));
    lista.forEach((s) => w.append(pill(promotorRota(s.r), cls)));
    return w;
  };
  const dest = el('div', 'grid2');
  dest.append(
    secBox(
      t('Na meta em pelo menos metade dos dias'),
      t('Rotas com {m} ou mais novos clientes em ≥50% dos dias com cadastros.', { m: M }),
      chips(
        stat.filter((s) => s.ativos && s.meta / s.ativos >= 0.5),
        'p-ok',
      ),
    ),
  );
  dest.append(
    secBox(
      t('Nunca chegaram à meta'),
      t('Rotas que ainda não registaram {m} clientes num só dia.', { m: M }),
      chips(
        stat.filter((s) => s.meta === 0),
        'p-bad',
      ),
    ),
  );
  pg.append(dest);
  // Matriz rota × dia
  const ver = dias.slice(-14),
    mat = el('div', 'hm-wrap'),
    tb = el('table', 'hm');
  const hr = el('tr');
  hr.append(el('th', 'hm-r', t('Rota')));
  ver.forEach((d) => hr.append(el('th', null, diaCurto(d))));
  hr.append(el('th', 'n', t('Média')), el('th', 'n', t('Dias na meta')));
  const th = el('thead');
  th.append(hr);
  tb.append(th);
  const tbd = el('tbody');
  [...stat]
    .sort((a, b) => b.media - a.media)
    .forEach((s) => {
      const tr = el('tr');
      tr.append(el('td', 'hm-r', promotorRota(s.r)));
      ver.forEach((d) => {
        const x = v(s.r, d),
          td = el('td');
        const c = el('span', 'hm-c ' + (x >= M ? 'hm-ok' : x > 0 ? 'hm-bx' : 'hm-0'), x ? String(x) : '');
        c.title = `${promotorRota(s.r)} · ${dl(d)}: ${n0(x)}`;
        td.append(c);
        tr.append(td);
      });
      tr.append(el('td', 'n', n1(s.media)), el('td', 'n', `${s.meta}/${s.ativos}`));
      tbd.append(tr);
    });
  tb.append(tbd);
  mat.append(tb);
  requestAnimationFrame(() => {
    mat.scrollLeft = mat.scrollWidth;
  }); // em ecrã estreito mostra primeiro os dias mais recentes
  const secM = secBox(
    t('Cadastros por rota e por dia'),
    t('Novos clientes por dia útil (últimos {n}). Verde: meta de {m} atingida; vermelho: abaixo da meta. Média e dias na meta contam os dias com cadastros.', {
      n: ver.length,
      m: M,
    }),
    mat,
  );
  secM.querySelector('.sec-h').append(
    legenda([
      ['hm-ok', '≥' + M],
      ['hm-bx', '1–' + (M - 1)],
      ['hm-0', '0'],
    ]),
  );
  pg.append(secM);
  REG.tabelas.push({
    id: 'cad-matriz',
    titulo: t('Cadastros por rota e por dia'),
    cols: [
      { key: 'rota', label: t('Rota') },
      ...ver.map((d) => ({ key: d, label: dl(d), type: 'n', sum: true })),
      { key: 'media', label: t('Média'), type: 'n' },
    ],
    rows: stat.map((s) => {
      const o = { chave: s.r, rota: promotorRota(s.r), media: +s.media.toFixed(1) };
      ver.forEach((d) => {
        o[d] = v(s.r, d);
      });
      return o;
    }),
    total: true,
  });
  // Último dia vs dia anterior, por rota; e a rede por dia
  const g2 = el('div', 'grid2');
  if (ult) {
    const cats = [...rotas].sort((a, b) => v(b, ult) - v(a, ult)).map((r) => ({ txt: r, titulo: promotorRota(r) }));
    const sec = secBox(
      t('{a} vs {b}', { a: ds(ult), b: ant ? ds(ant) : '—' }),
      t('Novos clientes por rota no último dia e no dia anterior.'),
      barras({
        cats,
        meta: M,
        series: [
          { nome: ant ? ds(ant) : '', vals: cats.map((c) => (ant ? v(c.txt, ant) : 0)), cls: 'g-prev' },
          { nome: ds(ult), vals: cats.map((c) => v(c.txt, ult)), cls: 'g-cur' },
        ],
      }),
    );
    sec.querySelector('.sec-h').append(
      legenda([
        ['g-prev', t('Dia anterior')],
        ['g-cur', t('Último dia')],
        ['g-meta-l', t('Meta {m}', { m: M })],
      ]),
    );
    g2.append(sec);
  }
  const ultimos = dias.slice(-20);
  const secR = secBox(
    t('Rede por dia'),
    t('Novos clientes de todas as rotas por dia útil.'),
    barras({
      cats: ultimos.map((d) => ({ txt: diaCurto(d), titulo: ds(d) })),
      meta: metaRede,
      rot: ultimos.length > 10,
      series: [{ nome: t('Novos clientes'), vals: ultimos.map(rede), cls: 'g-cur' }],
    }),
  );
  secR.querySelector('.sec-h').append(
    legenda([
      ['g-cur', t('Novos clientes')],
      ['g-meta-l', t('Meta {m}/dia', { m: metaRede })],
    ]),
  );
  g2.append(secR);
  pg.append(g2);
  REG.tabelas.push({
    id: 'cad-rede',
    titulo: t('Rede por dia'),
    cols: [
      { key: 'dia', label: t('Dia'), type: 'date' },
      { key: 'novos', label: t('Novos clientes'), type: 'n', sum: true },
      { key: 'meta', label: t('Meta'), type: 'n' },
    ],
    rows: dias.map((d) => ({ chave: d, dia: d, novos: rede(d), meta: metaRede })),
    total: true,
  });
  pg.append(
    el(
      'p',
      'note',
      t('Dados do RTM até {d}. A data de registo é a da primeira exportação em que o cliente aparece; registos na Foodtec não contam.', {
        d: dl(DB.cad.dados_ate) + ' ' + String(DB.cad.dados_ate || '').slice(11, 16),
      }),
    ),
  );
};

R['cadastros-analise'] = (pg) => {
  if (!temCad()) {
    cadSemDados(pg);
    return;
  }
  const M = META_ROTA(),
    { rotas, v } = cadDiario();
  const todos = [...new Set((DB.cad.cadastros_diarios || []).map((x) => x.dia))].filter(diaUtil).sort();
  // Período escolhido e período anterior com o mesmo número de dias de calendário
  const nDias = Math.round((new Date(st.ate) - new Date(st.de)) / 864e5) + 1,
    pAte = addD(st.de, -1),
    pDe = addD(st.de, -nDias);
  const dP = todos.filter((d) => d >= st.de && d <= st.ate),
    dA = todos.filter((d) => d >= pDe && d <= pAte);
  const soma = (ds2, r) => ds2.reduce((s, d) => s + (r ? v(r, d) : rotas.reduce((a, rr) => a + v(rr, d), 0)), 0);
  const tp = soma(dP),
    ta = soma(dA),
    med = dP.length && rotas.length ? tp / (dP.length * rotas.length) : 0;
  pg.append(
    tiles([
      { l: t('Cadastros no período'), v: n0(tp), s: `${dl(st.de)} – ${dl(st.ate)}` },
      { l: t('Período anterior'), v: n0(ta), s: dA.length ? `${dl(pDe)} – ${dl(pAte)}` : t('sem dados') },
      { l: t('Variação'), v: (tp - ta > 0 ? '+' : '') + n0(tp - ta), s: ta ? pct((tp - ta) / ta) : '—', tone: tp - ta < 0 ? 'bad' : tp - ta > 0 ? 'ok' : null },
      {
        l: t('Média por rota/dia útil'),
        v: n1(med),
        s: t('meta {m} · {p} · {n} dias úteis', { m: M, p: pct(med / M), n: dP.length }),
        tone: med >= M ? 'ok' : 'warn',
      },
    ]),
  );
  const n = Math.max(dP.length, dA.length),
    cats = [],
    vp = [],
    va = [];
  for (let i = 0; i < n; i++) {
    const d = dP[i],
      a = dA[i];
    cats.push({ txt: d ? diaCurto(d) : '', titulo: (d ? ds(d) : '—') + (a ? ' / ' + ds(a) : '') });
    vp.push(d ? soma([d]) : 0);
    va.push(a ? soma([a]) : 0);
  }
  const sec = secBox(
    t('Cadastros por dia'),
    t('Cada barra do período ao lado do dia correspondente do período anterior.'),
    barras({
      cats,
      meta: M * rotas.length,
      rot: n > 10,
      series: [
        { nome: t('Período anterior'), vals: va, cls: 'g-prev' },
        { nome: t('Período'), vals: vp, cls: 'g-cur' },
      ],
    }),
  );
  sec.querySelector('.sec-h').append(
    legenda([
      ['g-prev', t('Período anterior')],
      ['g-cur', t('Período')],
      ['g-meta-l', t('Meta')],
    ]),
  );
  pg.append(sec);
  const metaBar = (p) => {
    const w = el('span', 'mbar'),
      tr = el('span', 'trk'),
      b = el('i');
    b.style.width = Math.min(100, Math.round((p || 0) * 100)) + '%';
    if ((p || 0) >= 1) b.className = 'ok';
    tr.append(b);
    w.append(tr, document.createTextNode(pct(p)));
    return w;
  };
  pg.append(
    tabela({
      id: 'cad-rota',
      titulo: t('Por rota no período'),
      nota: t('% da meta = média diária da rota ÷ {m}.', { m: M }),
      sort: 'periodo',
      total: true,
      search: false,
      rows: rotas.map((r) => {
        const a = soma(dP, r),
          b = soma(dA, r),
          m = dP.length ? a / dP.length : 0;
        return { chave: r, promotor: promotorRota(r), periodo: a, anterior: b, var: a - b, media: m, meta: m / M };
      }),
      cols: [
        { key: 'promotor', label: t('Promotor'), p: 0 },
        { key: 'periodo', label: t('Período'), type: 'n', sum: true },
        { key: 'anterior', label: t('Anterior'), type: 'n', sum: true },
        {
          key: 'var',
          label: t('Variação'),
          type: 'n',
          sum: true,
          render: (x) => {
            const s = el('span', x > 0 ? 'up' : x < 0 ? 'down' : null, (x > 0 ? '+' : '') + n0(x));
            return s;
          },
        },
        { key: 'media', label: t('Média por dia'), type: 'n', render: (x) => document.createTextNode(n1(x)), xlfmt: '0.0' },
        { key: 'meta', label: t('% da meta'), type: 'pct', render: (x) => metaBar(x) },
      ],
    }),
  );
};

let cadRota = '',
  cadSit = '';
R['cadastros-clientes'] = (pg) => {
  if (!temCad()) {
    cadSemDados(pg);
    return;
  }
  const todos = DB.cad.cadastros.filter((x) => x.dia_registo >= st.de && x.dia_registo <= st.ate);
  const rotas = [...new Set(todos.map((x) => x.rota))].sort((a, b) => a.localeCompare(b, 'pt', { numeric: true }));
  const sits = [...new Set(todos.map((x) => x.situacao))].sort();
  const bar = el('div', 'glass filtro');
  const mkSel = (id, lab, opts, val, set) => {
    const l = el('label', null, lab);
    l.htmlFor = id;
    const s = el('select');
    s.id = id;
    [['', t('Todas')], ...opts].forEach(([v2, tx]) => {
      const o = el('option', null, tx);
      o.value = v2;
      s.append(o);
    });
    s.value = val;
    s.addEventListener('change', () => {
      set(s.value);
      draw();
    });
    bar.append(l, s);
  };
  mkSel(
    'f-cad-rota',
    t('Rota'),
    rotas.map((r) => [r, promotorRota(r)]),
    cadRota,
    (x) => {
      cadRota = x;
    },
  );
  mkSel(
    'f-cad-sit',
    t('Situação'),
    sits.map((x) => [x, t(x)]),
    cadSit,
    (x) => {
      cadSit = x;
    },
  );
  pg.append(bar);
  const V = todos.filter((x) => (!cadRota || x.rota === cadRota) && (!cadSit || x.situacao === cadSit));
  const cnt = (re) => V.filter((x) => re.test(x.situacao)).length;
  pg.append(
    tiles([
      { l: t('Registados no período'), v: n0(V.length), s: `${dl(st.de)} – ${dl(st.ate)}` },
      { l: t('Clientes válidos'), v: n0(cnt(/^Cliente válido/)), tone: 'ok' },
      { l: t('NIF a corrigir'), v: n0(cnt(/^Corrigir/)), tone: cnt(/^Corrigir/) ? 'warn' : null },
      { l: t('A excluir (Foodtec)'), v: n0(cnt(/^Excluir/)), tone: cnt(/^Excluir/) ? 'bad' : null },
    ]),
  );
  const sitCls = (s) => (/^Cliente válido/.test(s) ? 'p-ok' : /^Excluir/.test(s) ? 'p-bad' : 'p-warn');
  pg.append(
    tabela({
      id: 'cad-cli',
      titulo: t('Clientes registados'),
      nota: t('Data de registo = primeira exportação do RTM em que o cliente aparece.'),
      sort: 'dia_registo',
      total: false,
      rows: V.map((x) => ({ ...x, chave: x.id + '|' + x.rota, promotor: promotorRota(x.rota), hora: String(x.data_registo || '').slice(11, 16) })),
      cols: [
        { key: 'cliente', label: t('Cliente'), wrap: true },
        { key: 'promotor', label: t('Promotor'), p: 0 },
        { key: 'tipo', label: t('Tipo') },
        { key: 'nif', label: t('NIF') },
        { key: 'municipio', label: t('Município') },
        { key: 'estado', label: t('Estado'), render: (x) => pill(t(x), x === 'Activo' ? 'p-ok' : 'p-warn'), xl: (r) => t(r.estado) },
        { key: 'dia_registo', label: t('Data de registo'), type: 'date' },
        { key: 'hora', label: t('Hora'), p: 5 },
        { key: 'situacao', label: t('Situação'), render: (x) => pill(t(x), sitCls(x)), xl: (r) => t(r.situacao) },
      ],
    }),
  );
};
