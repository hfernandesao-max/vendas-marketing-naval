// Vendas: por promotor, dia, cliente, artigo, promotor e artigo, e documentos de venda.
// Base de valor (prática internacional, IFRS 15): o desempenho mede-se em vendas líquidas SEM IVA (facturas − notas de
// crédito, já com os descontos); o valor com IVA aparece ao lado. Cobranças e dívida ficam com IVA (é o que o cliente
// paga). A antiga «Classificação» entrou nas páginas por promotor e por cliente (posição, quota e classe ABC).

/** Posição, quota no total e classe ABC (Pareto: A até 80% do valor acumulado, B até 95%, C o resto). */
function classificar(rows, tot) {
  let acc = 0;
  return rows
    .slice()
    .sort((a, b) => b.total - a.total || b.docs - a.docs)
    .map((r, i) => {
      acc += r.total > 0 ? r.total : 0;
      const q = tot > 0 ? acc / tot : 1;
      return { ...r, pos: i + 1, quota: tot ? r.total / tot : null, abc: r.total <= 0 ? 'C' : q <= 0.8 + 1e-9 || i === 0 ? 'A' : q <= 0.95 + 1e-9 ? 'B' : 'C' };
    });
}
const posCol = { key: 'pos', label: '#', type: 'n', p: 0, render: (v) => el('b', null, String(v)) };
const abcCol = { key: 'abc', label: t('Classe'), p: 3, render: (v) => pill(v, v === 'A' ? 'p-ok' : v === 'B' ? 'p-warn' : 'p-mut') };
const NOTA_BASE = 'Vendas líquidas sem IVA (facturas − notas de crédito, já com os descontos); com IVA ao lado. Cobranças e pendentes com IVA.';
import {
  clientesDe,
  docsVenda,
  eNC,
  isCF,
  liquidoDe,
  notasCredito,
  pendenteDe,
  pendentesDe,
  porPromotor,
  promotorRota,
  recebidoLiquido,
  vendasR,
} from '../calculos.js';
import { aguardar, pill, tabela, tiles } from '../componentes/blocos.js';
import { FV } from '../filtros.js';
import { dl, mkz, n0, n1, pct } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { R } from './registo.js';
import { inR } from '../periodo.js';
import { DB, el, group, sum } from '../util.js';
import { colDocumento } from '../componentes/documento.js';
import { avisoRectificacoes } from '../componentes/rectificacoes.js';

R['vendas-promotor'] = (pg) => {
  const V = vendasR(),
    F = docsVenda(V),
    todas = porPromotor(V),
    tot = sum(V, liquidoDe),
    totIva = sum(V, 'total');
  // Só as rotas com movimento no período e filtros escolhidos (prática dos relatórios de gestão: sem linhas a zero);
  // as rotas sem movimento ficam indicadas por baixo da tabela, para não se perder essa informação
  const comMovimento = (x) => x.docs > 0 || x.total || x.notas_credito || x.recebido || x.por_liquidar;
  const P = classificar(todas.filter(comMovimento), tot),
    semMov = todas.filter((x) => !comMovimento(x));
  pg.append(
    tiles([
      { l: t('Vendas líquidas s/ IVA'), v: mkz(tot), s: t('com IVA: {v}', { v: mkz(totIva) }) + ' · ' + subVendas(V) },
      { l: t('Promotores com vendas'), v: n0(P.filter((x) => x.docs > 0).length), s: P[0] ? t('melhor: {p}', { p: P[0].promotor }) : '' },
      { l: t('Venda média por documento'), v: mkz(F.length ? tot / F.length : null), s: t('sem IVA') },
      { l: t('Facturas pendentes'), v: mkz(sum(P, 'por_liquidar')), tone: sum(P, 'por_liquidar') > 0 ? 'warn' : null },
    ]),
  );
  const rect = avisoRectificacoes();
  if (rect) pg.append(rect);
  pg.append(
    tabela({
      id: 'vp',
      titulo: t('Vendas por promotor'),
      nota: t('Documentos emitidos no Pinguim BO no período, por posição.') + ' ' + t(NOTA_BASE),
      rows: P,
      sort: 'total',
      total: true,
      cols: [
        posCol,
        { key: 'rota', label: t('Rota'), p: 4 },
        { key: 'promotor', label: t('Promotor'), p: 0 },
        { key: 'docs', label: t('Documentos'), type: 'n', sum: true },
        { key: 'clientes', label: t('Clientes'), type: 'n' },
        { key: 'dias', label: t('Dias com vendas'), type: 'n' },
        { key: 'total', label: t('Vendas s/ IVA'), type: 'bar', sum: true },
        { key: 'quota', label: t('% do total'), type: 'pct', p: 3 },
        { key: 'total_iva', label: t('Com IVA'), type: 'kz', sum: true, p: 4 },
        ...(sum(P, 'notas_credito') ? [{ key: 'notas_credito', label: t('Notas de crédito'), type: 'kz', sum: true }] : []),
        { key: 'recebido', label: t('Recebido'), type: 'kz', sum: true },
        { key: 'recebido_pct', label: t('% recebido'), type: 'pct', p: 4 },
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
    total: sum(rs, liquidoDe),
    total_iva: sum(rs, 'total'),
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
        { key: 'total', label: t('Vendas s/ IVA'), type: 'bar', sum: true },
        { key: 'total_iva', label: t('Com IVA'), type: 'kz', sum: true },
        { key: 'recebido', label: t('Recebido'), type: 'kz', sum: true },
      ],
    }),
  );
  const ds2 = [...new Set(V.map((x) => x.data))].sort();
  const mat = Object.values(group(V, 'rota')).map((rs) => {
    const o = { rota: rs[0].rota, promotor: promotorRota(rs[0].rota), total: sum(rs, liquidoDe) };
    ds2.forEach((d) => {
      o[d] = sum(
        rs.filter((x) => x.data === d),
        liquidoDe,
      );
    });
    return o;
  });
  secs.append(
    tabela({
      id: 'vd-mat',
      titulo: t('Promotor × dia'),
      nota: t('Vendas líquidas sem IVA.'),
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
  // inclui a antiga «Classificação dos clientes»: posição, quota e classe ABC; o Consumidor Final fica à parte
  const V = vendasR(),
    tot = sum(V, liquidoDe);
  const todos = Object.values(group(V, (x) => (isCF(x.nif) ? 'CF|' + x.cliente : x.nif))).map((rs) => {
    const fs = docsVenda(rs),
      liq = sum(rs, liquidoDe);
    return {
      chave: isCF(rs[0].nif) ? 'CF|' + rs[0].cliente : rs[0].nif,
      cf: isCF(rs[0].nif),
      cliente: rs[0].cliente,
      nif: rs[0].nif,
      rota: [...new Set(rs.map((x) => x.rota))].join(', '),
      promotor: [...new Set(rs.map((x) => promotorRota(x.rota)))].join(', '),
      docs: fs.length,
      total: liq,
      total_iva: sum(rs, 'total'),
      medio: fs.length ? liq / fs.length : null,
      ultima: fs
        .map((x) => x.data)
        .sort()
        .pop(),
    };
  });
  const comNif = todos.filter((x) => !x.cf),
    totNif = sum(comNif, 'total'),
    rows = classificar(comNif, totNif),
    cf = sum(
      todos.filter((x) => x.cf),
      'total',
    );
  const comCompras = rows.filter((x) => x.docs > 0).length;
  const q10 = sum(rows.slice(0, 10), 'total');
  const nA = rows.filter((x) => x.abc === 'A').length;
  pg.append(
    tiles([
      { l: t('Clientes com compras'), v: n0(comCompras), s: t('com NIF; classe A: {n} clientes', { n: n0(nA) }) },
      { l: t('Melhor cliente'), v: rows[0] ? rows[0].cliente : '—', s: rows[0] ? mkz(rows[0].total) : '', cls: 'nome' },
      { l: t('Peso dos 10 melhores clientes'), v: pct(totNif ? q10 / totNif : null), s: t('concentração da carteira') },
      { l: t('Venda média por cliente'), v: mkz(comCompras ? totNif / comCompras : null), s: t('sem IVA') },
      { l: t('Consumidor Final'), v: mkz(cf), s: t('{p} do total', { p: pct(tot ? cf / tot : null) }) },
    ]),
  );
  pg.append(
    tabela({
      id: 'vc',
      titulo: t('Vendas por cliente'),
      nota:
        t('Clientes identificados por NIF, por posição; classe ABC pelo valor acumulado (A até 80%, B até 95%). Consumidor Final à parte.') +
        ' ' +
        t(NOTA_BASE),
      rows,
      sort: 'total',
      total: true,
      cols: [
        posCol,
        { key: 'cliente', label: t('Cliente'), wrap: true, p: 0 },
        { key: 'nif', label: t('NIF') },
        { key: 'rota', label: t('Rota'), p: 4 },
        { key: 'promotor', label: t('Promotor'), p: 2 },
        abcCol,
        { key: 'docs', label: t('Documentos'), type: 'n', sum: true },
        { key: 'total', label: t('Vendas s/ IVA'), type: 'bar', sum: true },
        { key: 'quota', label: t('% do total'), type: 'pct', p: 3 },
        { key: 'total_iva', label: t('Com IVA'), type: 'kz', sum: true, p: 4 },
        { key: 'medio', label: t('Média por documento'), type: 'kz', p: 4 },
        { key: 'ultima', label: t('Última compra'), type: 'date' },
      ],
    }),
  );
};

// A antiga «Classificação» (fora do menu) abre a página por promotor, que já tem a posição e a quota
R.ranking = (pg) => R['vendas-promotor'](pg);

// Filtro de rota nos documentos (fica lembrado neste navegador)
R.documentos = (pg) => {
  // A rota escolhe-se na barra de filtros; com uma só rota, a página mostra os documentos dessa rota
  const todos = vendasR(),
    rotaDoc = FV.rota.length === 1 ? FV.rota[0] : '';
  const rotas = [...new Set(todos.map((x) => x.rota))].filter(Boolean).sort((a, b) => String(a).localeCompare(String(b), 'pt', { numeric: true }));
  const rotulo = (r) => promotorRota(r);
  const V = todos,
    F = docsVenda(V),
    nc = notasCredito(V);
  const tot = sum(V, liquidoDe),
    totIva = sum(V, 'total'),
    liq = recebidoLiquido(V),
    pend = pendentesDe(V),
    cli = clientesDe(F);
  const sRotas = rotaDoc ? promotorRota(rotaDoc) : t('{n} rotas', { n: rotas.length });
  pg.append(
    tiles([
      {
        l: t('Vendas líquidas s/ IVA'),
        v: mkz(tot),
        s: t('com IVA: {v}', { v: mkz(totIva) }) + ' · ' + (nc ? sRotas + ' · ' + t('notas de crédito: −{v}', { v: mkz(nc) }) : sRotas),
      },
      { l: t('Documentos'), v: n0(F.length), s: t('{n} dias com vendas', { n: new Set(F.map((x) => x.data)).size }) },
      { l: t('Recebido'), v: mkz(liq), s: t('{p} do total com IVA', { p: pct(totIva ? liq / totIva : null) }) },
      {
        l: t('Facturas pendentes'),
        v: mkz(pend),
        tone: pend > 0.005 ? 'warn' : null,
        s: t('{n} documentos', { n: V.filter((x) => pendenteDe(x) > 0).length }),
      },
      { l: t('Clientes com compras'), v: n0(cli) },
      { l: t('Média por documento'), v: mkz(F.length ? tot / F.length : 0), s: t('sem IVA') },
    ]),
  );

  // o resumo por rota está em «Vendas por promotor» (escolha uma rota na barra de filtros para ver os seus documentos)
  pg.append(
    tabela({
      id: 'dc',
      titulo: rotaDoc ? t('Documentos da rota {r}', { r: rotulo(rotaDoc) }) : t('Registo de documentos de venda'),
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
        { key: 'liquido', label: t('Sem IVA'), type: 'kz', sum: true, p: 3 },
        { key: 'total', label: t('Total c/ IVA'), type: 'kz', sum: true },
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
      sort: 'valor_sem_iva',
      total: true,
      cols: [
        { key: 'codigo', label: t('Código') },
        { key: 'artigo', label: t('Artigo'), wrap: true },
        { key: 'unidade', label: t('Un.') },
        { key: 'quantidade', label: t('Qtd.'), type: 'n', sum: true },
        { key: 'preco_unit', label: t('Preço unit. s/ IVA'), type: 'kz', sum: precoMedio },
        { key: 'valor_sem_iva', label: t('Valor s/ IVA'), type: 'bar', sum: true },
        { key: 'desconto', label: t('Desconto'), type: 'kz', sum: true },
        { key: 'iva', label: t('IVA'), type: 'kz', sum: true },
        { key: 'total', label: t('Valor c/ IVA'), type: 'kz', sum: true },
        { key: 'docs', label: t('Documentos'), type: 'n', sum: true },
      ],
    }),
  );
};
// Vendas por promotor e artigo (mix de vendas por vendedor): a análise que as equipas comerciais das grandes empresas
// de bens de consumo usam para gerir o sortido de cada rota — o que cada promotor vende, com que peso, a que preço,
// em quantos clientes (distribuição numérica) e que artigos fortes da empresa ainda não vende (lacunas de sortido).
const TOP_MATRIZ = 8;
R['vendas-promotor-artigo'] = (pg) => {
  const A = (DB.artigos || []).filter((x) => inR(x.data));
  if (!A.length) {
    pg.append(el('div', 'glass empty', t('Sem linhas de venda no período e filtros escolhidos.')));
    return;
  }
  // Cliente de cada documento (as linhas de artigo trazem só o documento): Consumidor Final pelo nome, os outros pelo NIF
  const cliDoc = new Map((DB.vendas || []).map((v) => [v.documento, isCF(v.nif) ? 'n:' + v.cliente : v.nif]));
  const chaveArt = (x) => x.codigo + '|' + x.artigo;
  const docsDe = (ls) => new Set(docsVenda(ls).map((x) => x.documento));
  const clientesDeLinhas = (ls) => new Set([...docsDe(ls)].map((d) => cliDoc.get(d)).filter(Boolean));

  // Totais por artigo (empresa) e por promotor
  const porArtigo = Object.values(group(A, chaveArt)).map((ls) => ({
    chave: chaveArt(ls[0]),
    codigo: ls[0].codigo,
    artigo: ls[0].artigo,
    valor: sum(ls, 'valor_sem_iva'),
    quantidade: sum(ls, 'quantidade'),
    unidades: new Set(ls.map((x) => x.unidade).filter(Boolean)),
  }));
  porArtigo.sort((a, b) => b.valor - a.valor);
  const artigo = new Map(porArtigo.map((a) => [a.chave, a]));
  porArtigo.forEach((a) => {
    a.preco = a.quantidade && a.unidades.size <= 1 ? a.valor / a.quantidade : null;
  });
  const totalEmpresa = sum(porArtigo, 'valor');
  const promotores = Object.values(group(A, 'rota')).map((ls) => ({
    rota: ls[0].rota,
    promotor: promotorRota(ls[0].rota),
    linhas: ls,
    valor: sum(ls, 'valor_sem_iva'),
    docs: docsDe(ls).size,
    clientes: clientesDeLinhas(ls).size,
  }));
  promotores.sort((a, b) => b.valor - a.valor);

  // Linhas promotor × artigo, com o peso no promotor, a quota no artigo, o preço face à média da empresa,
  // a presença nos documentos do promotor e a classe ABC dentro do promotor (80% / 95% do valor)
  const linhas = [];
  promotores.forEach((p) => {
    const ls = Object.values(group(p.linhas, chaveArt)).map((xs) => {
      const a = artigo.get(chaveArt(xs[0])),
        valor = sum(xs, 'valor_sem_iva'),
        quantidade = sum(xs, 'quantidade'),
        unidades = new Set(xs.map((x) => x.unidade).filter(Boolean)),
        preco = quantidade && unidades.size <= 1 ? valor / quantidade : null,
        desconto = sum(xs, 'desconto'),
        bruto = valor + desconto;
      return {
        rota: p.rota,
        promotor: p.promotor,
        codigo: xs[0].codigo,
        artigo: xs[0].artigo,
        unidade: [...unidades].join(', '),
        quantidade,
        valor_sem_iva: valor,
        total: sum(xs, 'total'),
        desconto,
        desconto_pct: bruto > 0 ? desconto / bruto : null,
        preco_unit: preco,
        preco_var: preco != null && a.preco ? preco / a.preco - 1 : null,
        peso_promotor: p.valor ? valor / p.valor : null,
        quota_artigo: a.valor ? valor / a.valor : null,
        docs: docsDe(xs).size,
        presenca: p.docs ? docsDe(xs).size / p.docs : null,
        clientes: clientesDeLinhas(xs).size,
      };
    });
    ls.sort((a, b) => b.valor_sem_iva - a.valor_sem_iva);
    let acum = 0;
    ls.forEach((l) => {
      acum += l.valor_sem_iva;
      const ate = p.valor > 0 ? (acum - l.valor_sem_iva) / p.valor : 1;
      l.abc = ate < 0.8 ? 'A' : ate < 0.95 ? 'B' : 'C';
    });
    p.artigos = ls.length;
    p.artigosA = ls.filter((l) => l.abc === 'A').length;
    p.ls = ls;
    linhas.push(...ls);
  });

  // Indicadores de topo
  const nArt = porArtigo.length,
    top3 = totalEmpresa > 0 ? sum(porArtigo.slice(0, 3), 'valor') / totalEmpresa : null,
    sortidoMedio = promotores.length ? sum(promotores, 'artigos') / promotores.length : null,
    clientes = new Set([...docsDe(A)].map((d) => cliDoc.get(d)).filter(Boolean)).size;
  const fortes = porArtigo.slice(0, Math.min(10, nArt));
  const lacunas = fortes.map((a) => {
    const sem = promotores.filter((p) => !p.ls.some((l) => chaveArt(l) === a.chave));
    return { ...a, com: promotores.length - sem.length, sem };
  });
  const nLacunas = sum(lacunas, (x) => x.sem.length);
  pg.append(
    tiles([
      { l: t('Valor s/ IVA'), v: mkz(totalEmpresa), s: t('vendas líquidas, sem IVA') },
      { l: t('Promotores com vendas'), v: n0(promotores.length), s: t('{n} clientes', { n: n0(clientes) }) },
      { l: t('Artigos vendidos'), v: n0(nArt), s: t('sortido médio por promotor: {n}', { n: n1(sortidoMedio) }) },
      { l: t('Peso dos 3 maiores artigos'), v: pct(top3), s: t('concentração do sortido') },
      {
        l: t('Lacunas de sortido'),
        v: n0(nLacunas),
        s: t('artigos fortes por vender, por promotor'),
        tone: nLacunas ? 'warn' : null,
      },
    ]),
  );

  // 1. Matriz promotor × artigo (valor s/ IVA): os maiores artigos da empresa em colunas e o resto em «Outros»
  const colunas = porArtigo.slice(0, TOP_MATRIZ),
    outros = nArt > TOP_MATRIZ;
  const matriz = promotores.map((p) => {
    const r = { rota: p.rota, promotor: p.promotor, total: p.valor };
    colunas.forEach(
      (a, i) =>
        (r['a' + i] =
          sum(
            p.ls.filter((l) => chaveArt(l) === a.chave),
            'valor_sem_iva',
          ) || null),
    );
    if (outros) r.outros = p.valor - colunas.reduce((s, a, i) => s + (r['a' + i] || 0), 0) || null;
    return r;
  });
  const maxCel = Math.max(0, ...matriz.flatMap((r) => colunas.map((a, i) => r['a' + i] || 0)));
  const celula = (v, r) => {
    const s = el('span', 'pa-cel', v ? mkz(v) : '—');
    if (v && maxCel) s.style.setProperty('--a', (0.06 + 0.4 * (v / maxCel)).toFixed(3));
    if (v && r.total) s.title = t('{p} do promotor', { p: pct(v / r.total) });
    return s;
  };
  pg.append(
    tabela({
      id: 'vpa-matriz',
      titulo: t('Matriz promotor × artigo'),
      nota: t(
        'Valor s/ IVA de cada promotor nos {n} artigos que mais vendem na empresa; quanto mais escuro, maior o valor. Passe o rato por uma célula para ver o peso no promotor.',
        { n: n0(colunas.length) },
      ),
      rows: matriz,
      sort: 'total',
      total: true,
      cols: [
        { key: 'promotor', label: t('Promotor'), p: 0 },
        ...colunas.map((a, i) => ({ key: 'a' + i, label: a.artigo, type: 'kz', sum: true, render: celula, p: i < 3 ? 2 : 5 })),
        ...(outros ? [{ key: 'outros', label: t('Outros artigos'), type: 'kz', sum: true, p: 5 }] : []),
        { key: 'total', label: t('Total'), type: 'bar', sum: true, p: 1 },
      ],
    }),
  );

  // 2. Resumo do sortido de cada promotor
  pg.append(
    tabela({
      id: 'vpa-sortido',
      titulo: t('Sortido por promotor'),
      nota: t('Artigos A: os que somam 80% do valor do promotor (análise ABC). Clientes: os que compraram ao promotor no período (distribuição numérica).'),
      rows: promotores.map((p) => ({
        rota: p.rota,
        promotor: p.promotor,
        artigos: p.artigos,
        artigos_a: p.artigosA,
        principal: p.ls[0] ? p.ls[0].artigo : '',
        peso_principal: p.ls[0] ? p.ls[0].peso_promotor : null,
        docs: p.docs,
        clientes: p.clientes,
        linhas_doc: p.docs ? docsVenda(p.linhas).length / p.docs : null,
        valor: p.valor,
        peso: totalEmpresa ? p.valor / totalEmpresa : null,
      })),
      sort: 'valor',
      total: true,
      cols: [
        { key: 'promotor', label: t('Promotor'), p: 0 },
        { key: 'artigos', label: t('Artigos'), type: 'n' },
        { key: 'artigos_a', label: t('Artigos A'), type: 'n', p: 4 },
        { key: 'principal', label: t('Artigo principal'), wrap: true, p: 3 },
        { key: 'peso_principal', label: t('Peso do principal'), type: 'pct', p: 4 },
        { key: 'clientes', label: t('Clientes'), type: 'n', p: 3 },
        { key: 'docs', label: t('Documentos'), type: 'n', sum: true, p: 4 },
        { key: 'linhas_doc', label: t('Artigos por documento'), type: 'n', p: 5, render: (v) => document.createTextNode(n1(v)) },
        { key: 'valor', label: t('Valor s/ IVA'), type: 'bar', sum: true, p: 1 },
        { key: 'peso', label: t('Peso na empresa'), type: 'pct', sum: true, p: 2 },
      ],
    }),
  );

  // 3. Lacunas de sortido: os artigos fortes da empresa que algum promotor ainda não vendeu no período
  const comLacunas = lacunas.filter((x) => x.sem.length);
  if (comLacunas.length)
    pg.append(
      tabela({
        id: 'vpa-lacunas',
        titulo: t('Lacunas de sortido'),
        nota: t(
          'Dos {n} artigos que mais vendem na empresa, os promotores que não os venderam no período: oportunidades de venda a acompanhar com cada rota.',
          { n: n0(fortes.length) },
        ),
        rows: comLacunas.map((a) => ({
          codigo: a.codigo,
          artigo: a.artigo,
          peso: totalEmpresa ? a.valor / totalEmpresa : null,
          com: a.com,
          n_sem: a.sem.length,
          sem: a.sem.map((p) => p.promotor).join(' · '),
        })),
        sort: 'peso',
        cols: [
          { key: 'codigo', label: t('Código'), p: 5 },
          { key: 'artigo', label: t('Artigo'), wrap: true, p: 0 },
          { key: 'peso', label: t('Peso na empresa'), type: 'pct', p: 3 },
          { key: 'com', label: t('Promotores que vendem'), type: 'n', p: 4 },
          { key: 'n_sem', label: t('Promotores sem venda'), type: 'n', p: 2 },
          { key: 'sem', label: t('Sem venda no período'), wrap: true, p: 1 },
        ],
      }),
    );

  // 4. Detalhe: uma linha por promotor e artigo
  pg.append(
    tabela({
      id: 'vpa',
      titulo: t('Detalhe por promotor e artigo'),
      nota: t(
        'Linhas de venda do Pinguim BO no período, já descontadas as notas de crédito. Preço unit. s/ IVA: média ponderada (valor s/ IVA ÷ quantidade). Face à média: diferença para o preço médio do artigo na empresa. Presença: documentos do promotor com o artigo.',
      ),
      rows: linhas,
      sort: 'valor_sem_iva',
      total: true,
      cols: [
        { key: 'promotor', label: t('Promotor'), p: 0 },
        { key: 'codigo', label: t('Código'), p: 6 },
        { key: 'artigo', label: t('Artigo'), wrap: true, p: 0 },
        { key: 'abc', label: t('Classe'), p: 4, render: (v) => pill(v, v === 'A' ? 'p-ok' : v === 'B' ? 'p-warn' : 'p-mut') },
        { key: 'unidade', label: t('Un.'), p: 6 },
        { key: 'quantidade', label: t('Qtd.'), type: 'n', sum: true, p: 3 },
        { key: 'preco_unit', label: t('Preço unit. s/ IVA'), type: 'kz', p: 4 },
        {
          key: 'preco_var',
          label: t('Face à média'),
          type: 'pct',
          p: 5,
          render: (v) => document.createTextNode(v == null ? '—' : (v > 0 ? '+' : '') + pct(v)),
        },
        { key: 'desconto', label: t('Desconto'), type: 'kz', sum: true, p: 5 },
        { key: 'desconto_pct', label: t('% desconto'), type: 'pct', p: 6 },
        { key: 'valor_sem_iva', label: t('Valor s/ IVA'), type: 'bar', sum: true, p: 1 },
        { key: 'total', label: t('Valor c/ IVA'), type: 'kz', sum: true, p: 5 },
        { key: 'peso_promotor', label: t('Peso no promotor'), type: 'pct', p: 2 },
        { key: 'quota_artigo', label: t('Quota no artigo'), type: 'pct', p: 4 },
        { key: 'clientes', label: t('Clientes'), type: 'n', p: 3 },
        { key: 'presenca', label: t('Presença'), type: 'pct', p: 5 },
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
