// Vendas: por promotor, dia, cliente, artigo, marca, ranking e documentos.
import { clientesDe, docsVenda, eNC, isCF, notasCredito, pendenteDe, pendentesDe, porPromotor, promotorRota, recebidoLiquido, vendasR } from '../calculos.js';
import { aguardar, pill, tabela, tiles } from '../componentes/blocos.js';
import { FV } from '../filtros.js';
import { dl, mkz, n0, pct } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { R } from './registo.js';
import { inR } from '../periodo.js';
import { DB, el, group, sum } from '../util.js';
import { colDocumento } from '../componentes/documento.js';

R['vendas-promotor'] = (pg) => {
  const V = vendasR(),
    F = docsVenda(V),
    todas = porPromotor(V),
    tot = sum(V, 'total');
  // Só as rotas com movimento no período e filtros escolhidos (prática dos relatórios de gestão: sem linhas a zero);
  // as rotas sem movimento ficam indicadas por baixo da tabela, para não se perder essa informação
  const comMovimento = (x) => x.docs > 0 || x.total || x.notas_credito || x.recebido || x.por_liquidar;
  const P = todas.filter(comMovimento),
    semMov = todas.filter((x) => !comMovimento(x));
  pg.append(
    tiles([
      { l: t('Vendas RTM'), v: mkz(tot), s: subVendas(V) },
      { l: t('Promotores com vendas'), v: n0(P.filter((x) => x.docs > 0).length) },
      { l: t('Venda média por documento'), v: mkz(F.length ? tot / F.length : null) },
      { l: t('Facturas pendentes'), v: mkz(sum(P, 'por_liquidar')), tone: sum(P, 'por_liquidar') > 0 ? 'warn' : null },
    ]),
  );
  pg.append(
    tabela({
      id: 'vp',
      titulo: t('Vendas por promotor'),
      nota: t('Documentos emitidos no Pinguim BO no período. Total: vendas líquidas (facturas − notas de crédito).'),
      rows: P,
      sort: 'total',
      total: true,
      cols: [
        { key: 'rota', label: t('Rota'), p: 4 },
        { key: 'promotor', label: t('Promotor'), p: 0 },
        { key: 'docs', label: t('Documentos'), type: 'n', sum: true },
        { key: 'clientes', label: t('Clientes'), type: 'n' },
        { key: 'dias', label: t('Dias com vendas'), type: 'n' },
        { key: 'total', label: t('Total'), type: 'bar', sum: true },
        ...(sum(P, 'notas_credito') ? [{ key: 'notas_credito', label: t('Notas de crédito'), type: 'kz', sum: true }] : []),
        { key: 'recebido', label: t('Recebido'), type: 'kz', sum: true },
        { key: 'por_liquidar', label: t('Facturas pendentes'), type: 'kz', sum: true },
        { key: 'medio', label: t('Média por documento'), type: 'kz' },
      ],
    }),
  );
  if (semMov.length)
    pg.append(
      el(
        'p',
        'note',
        t('Sem movimento no período e filtros escolhidos ({n}): {r}.', {
          n: n0(semMov.length),
          r: semMov
            .sort((a, b) => String(a.rota).localeCompare(String(b.rota)))
            .map((x) => x.promotor)
            .join(' · '),
        }),
      ),
    );
};

// Subtítulo do total de vendas: documentos de venda e, se houver, o valor das notas de crédito já descontado
function subVendas(V) {
  const nc = notasCredito(V),
    s = t('{n} documentos', { n: n0(docsVenda(V).length) });
  return nc ? s + ' · ' + t('notas de crédito: −{v}', { v: mkz(nc) }) : s;
}

R['vendas-dia'] = (pg) => {
  const V = vendasR();
  const dias = Object.values(group(V, 'data')).map((rs) => ({
    data: rs[0].data,
    docs: docsVenda(rs).length,
    promotores: new Set(docsVenda(rs).map((x) => x.rota)).size,
    total: sum(rs, 'total'),
    recebido: recebidoLiquido(rs),
  }));
  const secs = el('div', 'secs');
  secs.append(
    tabela({
      id: 'vd',
      titulo: t('Vendas por dia'),
      rows: dias,
      sort: 'data',
      total: true,
      search: false,
      cols: [
        { key: 'data', label: t('Dia'), type: 'date' },
        { key: 'docs', label: t('Documentos'), type: 'n', sum: true },
        { key: 'promotores', label: t('Promotores'), type: 'n' },
        { key: 'total', label: t('Total'), type: 'bar', sum: true },
        { key: 'recebido', label: t('Recebido'), type: 'kz', sum: true },
      ],
    }),
  );
  const ds2 = [...new Set(V.map((x) => x.data))].sort();
  const mat = Object.values(group(V, 'rota')).map((rs) => {
    const o = { rota: rs[0].rota, promotor: promotorRota(rs[0].rota), total: sum(rs, 'total') };
    ds2.forEach((d) => {
      o[d] = sum(
        rs.filter((x) => x.data === d),
        'total',
      );
    });
    return o;
  });
  secs.append(
    tabela({
      id: 'vd-mat',
      titulo: t('Promotor × dia'),
      rows: mat,
      sort: 'total',
      total: true,
      cols: [
        { key: 'promotor', label: t('Promotor'), p: 0 },
        ...ds2.map((d) => ({ key: d, label: dl(d).slice(0, 5), type: 'kz', sum: true, render: (v) => document.createTextNode(v ? mkz(v) : '—') })),
        { key: 'total', label: t('Total'), type: 'kz', sum: true },
      ],
    }),
  );
  pg.append(secs);
};

R['vendas-cliente'] = (pg) => {
  const V = vendasR();
  const rows = Object.values(group(V, (x) => (isCF(x.nif) ? 'CF|' + x.cliente : x.nif))).map((rs) => ({
    cliente: rs[0].cliente,
    nif: rs[0].nif,
    rota: [...new Set(rs.map((x) => x.rota))].join(', '),
    promotor: [...new Set(rs.map((x) => promotorRota(x.rota)))].join(', '),
    docs: docsVenda(rs).length,
    total: sum(rs, 'total'),
    ultima: docsVenda(rs)
      .map((x) => x.data)
      .sort()
      .pop(),
  }));
  const comCompras = rows.filter((x) => x.docs > 0).length;
  pg.append(
    tiles([
      { l: t('Clientes com compras'), v: n0(comCompras) },
      { l: t('Venda média por cliente'), v: mkz(comCompras ? sum(rows, 'total') / comCompras : null) },
      { l: t('Clientes com mais de uma compra'), v: n0(rows.filter((x) => x.docs > 1).length) },
    ]),
  );
  pg.append(
    tabela({
      id: 'vc',
      titulo: t('Vendas por cliente'),
      rows,
      sort: 'total',
      total: true,
      cols: [
        { key: 'cliente', label: t('Cliente'), wrap: true },
        { key: 'nif', label: t('NIF') },
        { key: 'rota', label: t('Rota'), p: 4 },
        { key: 'promotor', label: t('Promotor'), p: 0 },
        { key: 'docs', label: t('Documentos'), type: 'n', sum: true },
        { key: 'total', label: t('Total'), type: 'kz', sum: true },
        { key: 'ultima', label: t('Última compra'), type: 'date' },
      ],
    }),
  );
};

// Ranking dos melhores clientes e promotores, por valor vendido no período
R.ranking = (pg) => {
  const V = vendasR(),
    tot = sum(V, 'total');
  const pos = (rows) => rows.sort((a, b) => b.total - a.total || b.docs - a.docs).map((r, i) => ({ ...r, pos: i + 1, quota: tot ? r.total / tot : null }));
  // Clientes identificados por NIF; o Consumidor Final fica de fora do ranking
  const CL = pos(
    Object.values(
      group(
        V.filter((x) => !isCF(x.nif)),
        'nif',
      ),
    ).map((rs) => ({
      chave: rs[0].nif,
      cliente: rs[0].cliente,
      nif: rs[0].nif,
      promotor: [...new Set(rs.map((x) => promotorRota(x.rota)))].join(', '),
      docs: docsVenda(rs).length,
      total: sum(rs, 'total'),
      medio: docsVenda(rs).length ? sum(rs, 'total') / docsVenda(rs).length : null,
      ultima: docsVenda(rs)
        .map((x) => x.data)
        .sort()
        .pop(),
    })),
  );
  const PR = pos(
    Object.values(group(V, 'rota')).map((rs) => ({
      chave: rs[0].rota,
      promotor: promotorRota(rs[0].rota),
      docs: docsVenda(rs).length,
      clientes: clientesDe(docsVenda(rs)),
      total: sum(rs, 'total'),
      medio: docsVenda(rs).length ? sum(rs, 'total') / docsVenda(rs).length : null,
      recebido: sum(rs, 'total') ? recebidoLiquido(rs) / sum(rs, 'total') : null,
    })),
  );
  const top10 = CL.slice(0, 10),
    q10 = sum(top10, 'total'),
    cf = sum(
      V.filter((x) => isCF(x.nif)),
      'total',
    );
  pg.append(
    tiles([
      { l: t('Melhor promotor'), v: PR[0] ? PR[0].promotor : '—', s: PR[0] ? mkz(PR[0].total) : '', cls: 'nome' },
      { l: t('Melhor cliente'), v: CL[0] ? CL[0].cliente : '—', s: CL[0] ? mkz(CL[0].total) : '', cls: 'nome' },
      { l: t('Peso dos 10 melhores clientes'), v: pct(tot ? q10 / tot : null), s: t('{n} clientes com NIF', { n: n0(CL.length) }) },
      { l: t('Consumidor Final'), v: mkz(cf), s: t('{p} do total', { p: pct(tot ? cf / tot : null) }) },
    ]),
  );
  const posCol = { key: 'pos', label: '#', type: 'n', p: 0, render: (v) => el('b', null, String(v)) };
  pg.append(
    tabela({
      id: 'rk-prom',
      titulo: t('Classificação dos promotores'),
      nota: t('Ordenado pelo valor vendido no período, com IVA.'),
      rows: PR,
      sort: 'total',
      total: true,
      search: false,
      cols: [
        posCol,
        { key: 'promotor', label: t('Promotor'), p: 0 },
        { key: 'total', label: t('Total'), type: 'bar', sum: true },
        { key: 'quota', label: t('% do total'), type: 'pct' },
        { key: 'docs', label: t('Documentos'), type: 'n', sum: true },
        { key: 'clientes', label: t('Clientes'), type: 'n' },
        { key: 'medio', label: t('Média por documento'), type: 'kz' },
        { key: 'recebido', label: t('% recebido'), type: 'pct' },
      ],
    }),
  );
  pg.append(
    tabela({
      id: 'rk-cli',
      titulo: t('Classificação dos clientes'),
      nota: t('Clientes identificados por NIF, sem Consumidor Final. Ordenado pelo valor comprado no período, com IVA.'),
      rows: CL,
      sort: 'total',
      total: true,
      cols: [
        posCol,
        { key: 'cliente', label: t('Cliente'), wrap: true, p: 0 },
        { key: 'nif', label: t('NIF') },
        { key: 'promotor', label: t('Promotor') },
        { key: 'total', label: t('Total'), type: 'bar', sum: true },
        { key: 'quota', label: t('% do total'), type: 'pct' },
        { key: 'docs', label: t('Documentos'), type: 'n', sum: true },
        { key: 'medio', label: t('Média por documento'), type: 'kz' },
        { key: 'ultima', label: t('Última compra'), type: 'date' },
      ],
    }),
  );
};

// Filtro de rota nos documentos (fica lembrado neste navegador)
R.documentos = (pg) => {
  // A rota escolhe-se na barra de filtros; com uma só rota, a página mostra os documentos dessa rota
  const todos = vendasR(),
    rotaDoc = FV.rota.length === 1 ? FV.rota[0] : '';
  const rotas = [...new Set(todos.map((x) => x.rota))].filter(Boolean).sort((a, b) => String(a).localeCompare(String(b), 'pt', { numeric: true }));
  const porRota = group(todos, 'rota');
  const rotulo = (r) => promotorRota(r);
  const V = todos,
    F = docsVenda(V),
    nc = notasCredito(V);
  const tot = sum(V, 'total'),
    liq = recebidoLiquido(V),
    pend = pendentesDe(V),
    cli = clientesDe(F);
  const sRotas = rotaDoc ? promotorRota(rotaDoc) : t('{n} rotas', { n: rotas.length });
  pg.append(
    tiles([
      { l: rotaDoc ? t('Vendas da rota') : t('Vendas RTM'), v: mkz(tot), s: nc ? sRotas + ' · ' + t('notas de crédito: −{v}', { v: mkz(nc) }) : sRotas },
      { l: t('Documentos'), v: n0(F.length), s: t('{n} dias com vendas', { n: new Set(F.map((x) => x.data)).size }) },
      { l: t('Recebido'), v: mkz(liq), s: t('{p} do total', { p: pct(tot ? liq / tot : null) }) },
      {
        l: t('Facturas pendentes'),
        v: mkz(pend),
        tone: pend > 0.005 ? 'warn' : null,
        s: t('{n} documentos', { n: V.filter((x) => pendenteDe(x) > 0).length }),
      },
      { l: t('Clientes com compras'), v: n0(cli) },
      { l: t('Média por documento'), v: mkz(F.length ? tot / F.length : 0) },
    ]),
  );

  // Resumo por rota (só com todas as rotas)
  if (!rotaDoc) {
    pg.append(
      tabela({
        id: 'dc-rota',
        titulo: t('Vendas por rota'),
        nota: t('Escolha uma rota na barra de filtros para ver só os documentos dessa rota.'),
        sort: 'total',
        total: true,
        rows: rotas.map((r) => {
          const rs = porRota[r] || [],
            fs = docsVenda(rs),
            tt = sum(rs, 'total'),
            rc = recebidoLiquido(rs);
          return {
            chave: r,
            rota: r,
            promotor: promotorRota(r),
            docs: fs.length,
            clientes: clientesDe(fs),
            total: tt,
            recebido: rc,
            pendente: pendentesDe(rs),
            medio: fs.length ? tt / fs.length : 0,
          };
        }),
        cols: [
          { key: 'rota', label: t('Rota') },
          { key: 'promotor', label: t('Promotor'), p: 0 },
          { key: 'docs', label: t('Documentos'), type: 'n', sum: true },
          { key: 'clientes', label: t('Clientes'), type: 'n' },
          { key: 'total', label: t('Total'), type: 'bar', sum: true },
          { key: 'recebido', label: t('Recebido'), type: 'kz', sum: true },
          { key: 'pendente', label: t('Facturas pendentes'), type: 'kz', sum: true },
          { key: 'medio', label: t('Média por documento'), type: 'kz' },
        ],
      }),
    );
  }

  pg.append(
    tabela({
      id: 'dc',
      titulo: rotaDoc ? t('Documentos da rota {r}', { r: rotulo(rotaDoc) }) : t('Documentos Pinguim'),
      rows: V.map((x) => ({ ...x, promotor: promotorRota(x.rota) })),
      sort: 'data',
      total: true,
      cols: [
        { key: 'data', label: t('Dia'), type: 'date' },
        { key: 'hora', label: t('Hora') },
        colDocumento(),
        { key: 'tipo', label: t('Tipo') },
        { key: 'rota', label: t('Rota'), p: 4 },
        { key: 'promotor', label: t('Promotor'), p: 0 },
        { key: 'cliente', label: t('Cliente'), wrap: true },
        { key: 'nif', label: t('NIF') },
        { key: 'meio_pagamento', label: t('Meio de cobrança') },
        {
          key: 'liquidado',
          label: t('Pago'),
          p: 2,
          get: (r) => t(estadoDoc(r)),
          render: (v, r) => {
            const e = r ? estadoDoc(r) : v ? 'Pago' : 'Pendente';
            return pill(t(e), e === 'Pago' ? 'p-ok' : e === 'Pendente' ? 'p-warn' : 'p-mut');
          },
          xl: (r) => t(estadoDoc(r)),
        },
        { key: 'total', label: t('Total'), type: 'kz', sum: true },
      ],
    }),
  );
};
// Estado do documento: as notas de crédito não se pagam nem ficam pendentes
const estadoDoc = (r) => (eNC(r) ? 'Nota de crédito' : r.liquidado && pendenteDe(r) <= 0.005 ? 'Pago' : 'Pendente');

R['vendas-artigo'] = (pg) => {
  const A = (DB.artigos || []).filter((x) => inR(x.data));
  const rows = Object.values(group(A, (x) => x.codigo + '|' + x.artigo)).map((ls) => ({
    codigo: ls[0].codigo,
    artigo: ls[0].artigo,
    unidade: [...new Set(ls.map((x) => x.unidade).filter(Boolean))].join(', '),
    docs: new Set(docsVenda(ls).map((x) => x.documento)).size,
    quantidade: sum(ls, 'quantidade'),
    valor_sem_iva: sum(ls, 'valor_sem_iva'),
    desconto: sum(ls, 'desconto'),
    iva: sum(ls, 'iva'),
    total: sum(ls, 'total'),
  }));
  // Preço unitário médio sem IVA (média ponderada: valor líquido sem IVA ÷ quantidade), a métrica de
  // preço habitual na análise de vendas (ASP); com várias unidades de venda no mesmo artigo não se calcula
  rows.forEach((r) => {
    r.preco_unit = r.quantidade && !r.unidade.includes(',') ? r.valor_sem_iva / r.quantidade : null;
  });
  const precoMedio = (rs) => {
    const q = sum(
      rs.filter((r) => r.preco_unit != null),
      'quantidade',
    );
    return q
      ? sum(
          rs.filter((r) => r.preco_unit != null),
          'valor_sem_iva',
        ) / q
      : null;
  };
  pg.append(
    tiles([
      { l: t('Artigos vendidos'), v: n0(rows.length), s: t('{n} linhas de venda', { n: n0(A.length) }) },
      { l: t('Qtd.'), v: n0(sum(rows, 'quantidade')) },
      { l: t('Valor s/ IVA'), v: mkz(sum(rows, 'valor_sem_iva')), s: t('vendas líquidas, sem IVA') },
      { l: t('Valor c/ IVA'), v: mkz(sum(rows, 'total')) },
      { l: t('Desconto'), v: mkz(sum(rows, 'desconto')) },
    ]),
  );
  pg.append(
    tabela({
      id: 'va',
      titulo: t('Vendas por artigo'),
      nota: t(
        'Linhas de venda do Pinguim BO no período, já descontadas as notas de crédito. Preço unit. s/ IVA: preço médio ponderado (valor s/ IVA ÷ quantidade), já com os descontos.',
      ),
      rows,
      sort: 'total',
      total: true,
      cols: [
        { key: 'codigo', label: t('Código') },
        { key: 'artigo', label: t('Artigo'), wrap: true },
        { key: 'unidade', label: t('Un.') },
        { key: 'quantidade', label: t('Qtd.'), type: 'n', sum: true },
        { key: 'preco_unit', label: t('Preço unit. s/ IVA'), type: 'kz', sum: precoMedio },
        { key: 'valor_sem_iva', label: t('Valor s/ IVA'), type: 'kz', sum: true },
        { key: 'desconto', label: t('Desconto'), type: 'kz', sum: true },
        { key: 'iva', label: t('IVA'), type: 'kz', sum: true },
        { key: 'total', label: t('Valor c/ IVA'), type: 'bar', sum: true },
        { key: 'docs', label: t('Documentos'), type: 'n', sum: true },
      ],
    }),
  );
};
R['vendas-marca'] = (pg) =>
  aguardar(pg, t('Para mostrar vendas por promotor e marca, a exportação de linhas de venda do Pinguim BO deve trazer:'), [
    'Documento, data e rota',
    'Artigo e marca',
    'Quantidade',
    'Valor sem IVA e com IVA',
    'Desconto',
  ]);
