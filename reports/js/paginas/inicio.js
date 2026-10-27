// Início: indicadores e painel de gráficos.
import { META_ROTA, isCF, pendenteDe, porPromotor, recebidoDe, temCad, temMeio, valorCat, vendasR } from '../calculos.js';
import { tiles } from '../componentes/blocos.js';
import { COR, cartaoGrafico, grafBarras, grafColunas, grafParte, kzc } from '../componentes/graficos.js';
import { diaCurto, dl, ds, kz, kz2, n0, pct } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { R } from './registo.js';
import { podeVer } from '../perfil.js';
import { hoje, inR } from '../periodo.js';
import { DB, el, group, sum } from '../util.js';

function painelInicio(pg) {
  const V = vendasR(),
    tot = sum(V, 'total'),
    liq = sum(V, recebidoDe),
    cards = [];
  const pode = (id) => podeVer(id);
  const nomeCli = (x) => (isCF(x.nif) ? t('Consumidor final') : x.cliente);
  // 1. Vendas por dia
  if (pode('vendas-dia') && V.length) {
    const dias = Object.values(group(V, 'data'))
      .sort((a, b) => (a[0].data < b[0].data ? -1 : 1))
      .map((rs) => ({ d: rs[0].data, v: sum(rs, 'total'), r: sum(rs, recebidoDe), n: rs.length }));
    cards.push(
      cartaoGrafico({
        titulo: t('Vendas por dia'),
        sub: t('{v} em {n} dias · média {m} por dia', { v: kzc(tot) + ' Kz', n: dias.length, m: kzc(tot / dias.length) + ' Kz' }),
        destino: 'vendas-dia',
        desenhar: (w) =>
          grafColunas(w, {
            itens: dias.map((x) => ({
              valor: x.v,
              rot: diaCurto(x.d),
              titulo: ds(x.d),
              linhas: [
                [kz(x.v), t('vendido')],
                [kz(x.r), t('recebido')],
                [n0(x.n), t('documentos')],
              ],
            })),
          }),
        colunas: [[t('Dia')], [t('Vendido'), 1], [t('Recebido'), 1], [t('Documentos'), 1]],
        linhas: dias.map((x) => [dl(x.d), kz(x.v), kz(x.r), n0(x.n)]),
      }),
    );
  }
  // 2. Vendas por promotor (clicar abre os documentos da rota)
  if (pode('vendas-promotor') && V.length) {
    const P = porPromotor(V)
      .filter((x) => x.total > 0)
      .sort((a, b) => b.total - a.total);
    const top = P[0];
    cards.push(
      cartaoGrafico({
        titulo: t('Vendas por promotor'),
        sub: top ? t('Maior venda: {p} ({q} do total)', { p: top.promotor, q: pct(tot ? top.total / tot : 0) }) : '',
        destino: 'vendas-promotor',
        desenhar: (w) =>
          grafBarras(w, {
            itens: P.map((x) => ({
              valor: x.total,
              rot: x.promotor,
              linhas: [
                [kz(x.total), t('vendido')],
                [kz(x.recebido), t('recebido')],
                [n0(x.docs), t('documentos')],
              ],
              href: podeVer('documentos') ? `documentos/dc?rota=${x.rota}` : null,
            })),
          }),
        colunas: [[t('Promotor')], [t('Vendido'), 1], [t('Recebido'), 1], [t('Documentos'), 1]],
        linhas: P.map((x) => [x.promotor, kz(x.total), kz(x.recebido), n0(x.docs)]),
      }),
    );
  }
  // 3. Como foi cobrado (parte do todo)
  if (pode('pagamentos') && V.length && temMeio()) {
    const din = valorCat(V, 'dinheiro'),
      mc = valorCat(V, 'multicaixa'),
      outros = Math.max(0, liq - din - mc),
      pend = Math.max(0, tot - liq);
    const partes = [
      { nome: t('Numerário'), valor: din, cor: COR.s1 },
      { nome: t('Multicaixa'), valor: mc, cor: COR.s2 },
      { nome: t('Outros meios'), valor: outros, cor: COR.s3 },
      { nome: t('Por receber'), valor: pend, cor: COR.neutro, href: podeVer('pendentes') ? 'pendentes' : null },
    ].filter((p) => p.valor > 0.005 || p.cor === COR.s1);
    cards.push(
      cartaoGrafico({
        titulo: t('Como foi cobrado'),
        sub: t('{p} das vendas já recebido', { p: pct(tot ? liq / tot : 0) }),
        destino: 'pagamentos',
        desenhar: (w) => grafParte(w, { partes }),
        colunas: [[t('Meio')], [t('Valor'), 1], [t('Parte'), 1]],
        linhas: partes.map((p) => [p.nome, kz(p.valor), pct(tot ? p.valor / tot : 0)]),
      }),
    );
  }
  // 4. Antiguidade da dívida (cores de risco, sempre com o rótulo)
  if (pode('pendentes')) {
    const hj = Date.parse(hoje()),
      P = V.filter((v) => pendenteDe(v) > 0.005).map((v) => ({ v: pendenteDe(v), d: Math.round((hj - Date.parse(v.data)) / 864e5) }));
    if (P.length) {
      const fx = [
        [t('0–7 dias'), (d) => d <= 7, COR.a1],
        [t('8–30 dias'), (d) => d > 7 && d <= 30, COR.a2],
        [t('Mais de 30 dias'), (d) => d > 30, COR.a3],
      ].map(([n, f, cor]) => {
        const rs = P.filter((x) => f(x.d));
        return { rot: n, valor: sum(rs, 'v'), n: rs.length, cor };
      });
      cards.push(
        cartaoGrafico({
          titulo: t('Antiguidade da dívida'),
          sub: t('{v} por receber em {n} facturas', { v: kzc(sum(P, 'v')) + ' Kz', n: n0(P.length) }),
          destino: 'pendentes',
          desenhar: (w) =>
            grafBarras(w, {
              itens: fx.map((x) => ({
                ...x,
                linhas: [
                  [kz(x.valor), t('por receber')],
                  [n0(x.n), t('facturas')],
                ],
                href: 'pendentes/pd-d',
              })),
            }),
          colunas: [[t('Antiguidade')], [t('Por receber'), 1], [t('Facturas'), 1]],
          linhas: fx.map((x) => [x.rot, kz(x.valor), n0(x.n)]),
        }),
      );
    }
  }
  // 5. Melhores clientes
  if (pode('vendas-cliente') && V.length) {
    const C = Object.values(
      group(
        V.filter((x) => !isCF(x.nif)),
        'nif',
      ),
    )
      .map((rs) => ({ nome: nomeCli(rs[0]), v: sum(rs, 'total'), n: rs.length, nif: rs[0].nif }))
      .sort((a, b) => b.v - a.v)
      .slice(0, 8);
    if (C.length)
      cards.push(
        cartaoGrafico({
          titulo: t('Melhores clientes'),
          sub: t('Os {n} clientes com mais compras no período (sem consumidor final)', { n: C.length }),
          destino: 'vendas-cliente',
          desenhar: (w) =>
            grafBarras(w, {
              itens: C.map((x) => ({
                valor: x.v,
                rot: x.nome,
                linhas: [
                  [kz(x.v), t('comprado')],
                  [n0(x.n), t('documentos')],
                ],
                href: podeVer('documentos') ? 'documentos/dc?cliente=' + encodeURIComponent(x.nif || x.nome) : null,
              })),
            }),
          colunas: [[t('Cliente')], [t('Comprado'), 1], [t('Documentos'), 1]],
          linhas: C.map((x) => [x.nome, kz(x.v), n0(x.n)]),
        }),
      );
  }
  // 6. Artigos mais vendidos (valor; quantidades para quem não vê valores)
  if (pode('vendas-artigo') && (DB.artigos || []).length) {
    const A = (DB.artigos || []).filter((x) => inR(x.data)),
      comValor = A.some((x) => x.total != null);
    const rows = Object.values(group(A, (x) => x.codigo + '|' + x.artigo))
      .map((ls) => ({ nome: ls[0].artigo, cod: ls[0].codigo, q: sum(ls, 'quantidade'), v: sum(ls, 'total') }))
      .sort((a, b) => (comValor ? b.v - a.v : b.q - a.q))
      .slice(0, 8);
    if (rows.length)
      cards.push(
        cartaoGrafico({
          titulo: t('Artigos mais vendidos'),
          sub: comValor ? t('Por valor vendido, com IVA') : t('Por quantidade vendida'),
          destino: 'vendas-artigo',
          desenhar: (w) =>
            grafBarras(w, {
              fmt: comValor ? kzc : n0,
              itens: rows.map((x) => ({
                valor: comValor ? x.v : x.q,
                rot: x.nome,
                linhas: [
                  [comValor ? kz(x.v) : n0(x.q), comValor ? t('vendido') : t('quantidade')],
                  [n0(x.q), t('quantidade')],
                  [x.cod, t('código')],
                ],
              })),
            }),
          colunas: [[t('Artigo')], [t('Código')], [t('Quantidade'), 1], ...(comValor ? [[t('Vendido'), 1]] : [])],
          linhas: rows.map((x) => [x.nome, x.cod, n0(x.q), ...(comValor ? [kz(x.v)] : [])]),
        }),
      );
  }
  // 7. Caixas carregadas por dia (plano de cargas)
  if (pode('registo-cargas') && (DB.cargas || []).some((x) => inR(x.data))) {
    const dias = Object.values(
      group(
        DB.cargas.filter((x) => inR(x.data)),
        'data',
      ),
    )
      .sort((a, b) => (a[0].data < b[0].data ? -1 : 1))
      .map((rs) => ({ d: rs[0].data, c: sum(rs, 'carga'), n: rs.length }));
    cards.push(
      cartaoGrafico({
        titulo: t('Caixas carregadas por dia'),
        sub: t('{c} caixas em {n} dias, segundo o plano de cargas', { c: n0(sum(dias, 'c')), n: dias.length }),
        destino: 'registo-cargas',
        desenhar: (w) =>
          grafColunas(w, {
            fmt: n0,
            itens: dias.map((x) => ({
              valor: x.c,
              rot: diaCurto(x.d),
              titulo: ds(x.d),
              linhas: [
                [n0(x.c), t('caixas')],
                [n0(x.n), t('carrinhas')],
              ],
            })),
          }),
        colunas: [[t('Dia')], [t('Caixas'), 1], [t('Carrinhas'), 1]],
        linhas: dias.map((x) => [dl(x.d), n0(x.c), n0(x.n)]),
      }),
    );
  }
  // 8. Novos clientes por dia (cadastros) com a meta da rede
  if (pode('cadastros-analise') && temCad()) {
    const D = Object.values(
      group(
        (DB.cad.cadastros_diarios || []).filter((x) => inR(x.dia)),
        'dia',
      ),
    )
      .sort((a, b) => (a[0].dia < b[0].dia ? -1 : 1))
      .map((rs) => ({ d: rs[0].dia, n: sum(rs, 'novos'), r: rs.length }));
    if (D.length) {
      const rotasN = new Set((DB.cad.cadastros_diarios || []).map((x) => x.rota)).size,
        meta = META_ROTA() * rotasN;
      cards.push(
        cartaoGrafico({
          titulo: t('Novos clientes por dia'),
          sub: t('{n} cadastros no período · meta diária da rede {m}', { n: n0(sum(D, 'n')), m: n0(meta) }),
          destino: 'cadastros-analise',
          desenhar: (w) =>
            grafColunas(w, {
              fmt: n0,
              ref: { valor: meta, rot: t('Meta {m}', { m: n0(meta) }) },
              itens: D.map((x) => ({
                valor: x.n,
                rot: diaCurto(x.d),
                titulo: ds(x.d),
                linhas: [
                  [n0(x.n), t('novos clientes')],
                  [n0(x.r), t('rotas com cadastros')],
                ],
              })),
            }),
          colunas: [[t('Dia')], [t('Novos clientes'), 1]],
          linhas: D.map((x) => [dl(x.d), n0(x.n)]),
        }),
      );
    }
  }
  if (!cards.length) {
    pg.append(el('div', 'glass empty', t('Sem dados para mostrar no período escolhido.')));
    return;
  }
  const grelha = el('div', 'viz-grid');
  grelha.append(...cards);
  pg.append(grelha);
}

R.inicio = (pg) => {
  const V = vendasR(),
    tot = sum(V, 'total'),
    liq = sum(V, recebidoDe);
  const din = valorCat(V, 'dinheiro'),
    mc = valorCat(V, 'multicaixa');
  if (['vendas-promotor', 'documentos', 'pagamentos'].some(podeVer))
    pg.append(
      tiles([
        { l: t('Vendas RTM'), v: kz2(tot), s: t('{n} documentos', { n: n0(V.length) }), cls: 'full-value' },
        { l: t('Recebido'), v: kz2(liq), s: t('{p} do total', { p: pct(tot ? liq / tot : null) }), cls: 'full-value' },
        { l: t('Em dinheiro'), v: temMeio() ? kz2(din) : '—', s: temMeio() ? '' : t('Falta o meio de cobrança'), cls: 'full-value' },
        { l: t('Em Multicaixa'), v: temMeio() ? kz2(mc) : '—', s: temMeio() ? '' : t('Falta o meio de cobrança'), cls: 'full-value' },
        { l: t('Facturas pendentes'), v: kz2(tot - liq), tone: tot - liq > 0 ? 'warn' : null, cls: 'full-value' },
        { l: t('Clientes com compras'), v: n0(new Set(V.map((x) => (isCF(x.nif) ? x.cliente : x.nif))).size) },
      ]),
    );
  painelInicio(pg);
};
