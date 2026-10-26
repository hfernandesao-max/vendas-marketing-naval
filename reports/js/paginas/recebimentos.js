// Recebimentos: cobranças (dinheiro recebido das vendas, por meio de cobrança) e facturas pendentes.
import { isCF, pagosDe, pendenteDe, porMeio, promotorRota, recebidoDe, temMeio, valorCat, vendasR } from '../calculos.js';
import { pill, secBox, tabela, tiles } from '../componentes/blocos.js';
import { kz2, mkz, n0, pct } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { R } from './registo.js';
import { hoje } from '../periodo.js';
import { el, group, sum } from '../util.js';

// Facturas por receber, com a antiguidade da dívida (dias desde a emissão até hoje)
R.pendentes = (pg) => {
  const hj = Date.parse(hoje());
  const faixa = (d) => (d <= 7 ? '0–7 dias' : d <= 30 ? '8–30 dias' : 'Mais de 30 dias');
  const P = vendasR()
    .filter((v) => pendenteDe(v) > 0.005)
    .map((v) => {
      const dias = Math.max(0, Math.round((hj - Date.parse(v.data)) / 864e5));
      return { ...v, chave: v.documento, promotor: promotorRota(v.rota), pago: recebidoDe(v), pendente: pendenteDe(v), dias, faixa: faixa(dias) };
    });
  const tot = sum(P, 'pendente'),
    f = (n) => P.filter((x) => x.faixa === n);
  const pond = tot ? sum(P, (x) => x.pendente * x.dias) / tot : null;
  const cliKey = (x) => (isCF(x.nif) ? 'CF|' + x.cliente : x.nif || x.cliente);
  pg.append(
    tiles([
      { l: t('Por receber'), v: kz2(tot), s: t('{n} facturas', { n: n0(P.length) }), tone: tot > 0 ? 'warn' : null, cls: 'full-value' },
      { l: t('0–7 dias'), v: kz2(sum(f('0–7 dias'), 'pendente')), s: t('{n} facturas', { n: n0(f('0–7 dias').length) }), cls: 'full-value' },
      {
        l: t('8–30 dias'),
        v: kz2(sum(f('8–30 dias'), 'pendente')),
        s: t('{n} facturas', { n: n0(f('8–30 dias').length) }),
        tone: f('8–30 dias').length ? 'warn' : null,
        cls: 'full-value',
      },
      {
        l: t('Mais de 30 dias'),
        v: kz2(sum(f('Mais de 30 dias'), 'pendente')),
        s: t('{n} facturas', { n: n0(f('Mais de 30 dias').length) }),
        tone: f('Mais de 30 dias').length ? 'bad' : null,
        cls: 'full-value',
      },
      { l: t('Clientes com dívida'), v: n0(new Set(P.map(cliKey)).size) },
      { l: t('Antiguidade média'), v: pond == null ? '—' : t('{n} dias', { n: n0(Math.round(pond)) }), s: t('ponderada pelo valor') },
    ]),
  );
  pg.append(
    el(
      'p',
      'note',
      t(
        'Facturas emitidas no período escolhido que ainda não estão totalmente pagas; a antiguidade conta até hoje. Para ver toda a dívida em aberto, escolha «Tudo» no período.',
      ),
    ),
  );
  const pill2 = (v) => pill(t(v), v === '0–7 dias' ? 'p-ok' : v === '8–30 dias' ? 'p-warn' : 'p-bad');
  pg.append(
    tabela({
      id: 'pd-r',
      titulo: t('Por rota'),
      sort: 'pendente',
      total: true,
      search: false,
      rows: Object.values(group(P, 'rota')).map((rs) => ({
        chave: rs[0].rota,
        rota: rs[0].rota,
        promotor: promotorRota(rs[0].rota),
        n: rs.length,
        a: sum(
          rs.filter((x) => x.dias <= 7),
          'pendente',
        ),
        b: sum(
          rs.filter((x) => x.dias > 7 && x.dias <= 30),
          'pendente',
        ),
        c: sum(
          rs.filter((x) => x.dias > 30),
          'pendente',
        ),
        pendente: sum(rs, 'pendente'),
      })),
      cols: [
        { key: 'promotor', label: t('Promotor'), p: 0 },
        { key: 'n', label: t('Facturas'), type: 'n', sum: true },
        { key: 'a', label: t('0–7 dias'), type: 'kz', sum: true },
        { key: 'b', label: t('8–30 dias'), type: 'kz', sum: true },
        { key: 'c', label: t('Mais de 30 dias'), type: 'kz', sum: true },
        { key: 'pendente', label: t('Por receber'), type: 'bar', sum: true },
      ],
    }),
  );
  pg.append(
    tabela({
      id: 'pd-c',
      titulo: t('Por cliente'),
      sort: 'pendente',
      total: true,
      rows: Object.values(group(P, cliKey)).map((rs) => {
        const mx = Math.max(...rs.map((x) => x.dias));
        return {
          chave: cliKey(rs[0]),
          cliente: rs[0].cliente,
          nif: rs[0].nif,
          promotor: [...new Set(rs.map((x) => x.promotor))].join(', '),
          n: rs.length,
          antiga: rs.map((x) => x.data).sort()[0],
          dias: mx,
          faixa: faixa(mx),
          pendente: sum(rs, 'pendente'),
        };
      }),
      cols: [
        { key: 'cliente', label: t('Cliente'), p: 0, wrap: true },
        { key: 'nif', label: t('NIF') },
        { key: 'promotor', label: t('Promotor'), wrap: true },
        { key: 'n', label: t('Facturas'), type: 'n', sum: true },
        { key: 'antiga', label: t('Mais antiga'), type: 'date' },
        { key: 'dias', label: t('Dias'), type: 'n' },
        { key: 'faixa', label: t('Antiguidade'), render: pill2, xl: (r) => t(r.faixa) },
        { key: 'pendente', label: t('Por receber'), type: 'kz', sum: true },
      ],
    }),
  );
  pg.append(
    tabela({
      id: 'pd-d',
      titulo: t('Facturas por receber'),
      sort: 'dias',
      total: true,
      rows: P,
      cols: [
        { key: 'data', label: t('Dia'), type: 'date' },
        { key: 'documento', label: t('Documento'), nowrap: true, p: 0 },
        { key: 'tipo', label: t('Tipo') },
        { key: 'promotor', label: t('Promotor') },
        { key: 'cliente', label: t('Cliente'), wrap: true },
        { key: 'nif', label: t('NIF') },
        { key: 'total', label: t('Total'), type: 'kz', sum: true },
        { key: 'pago', label: t('Recebido'), type: 'kz', sum: true },
        { key: 'pendente', label: t('Por receber'), type: 'kz', sum: true },
        { key: 'dias', label: t('Dias'), type: 'n' },
        { key: 'faixa', label: t('Antiguidade'), render: pill2, xl: (r) => t(r.faixa) },
      ],
    }),
  );
};
R.pagamentos = (pg) => {
  const V = vendasR(),
    tot = sum(V, 'total'),
    liq = sum(V, recebidoDe),
    pend = tot - liq,
    meio = temMeio();
  const din = valorCat(V, 'dinheiro'),
    mc = valorCat(V, 'multicaixa'),
    pp0 = (v) => t('{p} do recebido', { p: pct(liq ? v / liq : null) });
  pg.append(
    tiles([
      { l: t('Valor recebido'), v: mkz(liq), s: t('Taxa de cobrança: {p}', { p: pct(tot ? liq / tot : null) }) },
      { l: t('Em dinheiro'), v: meio ? mkz(din) : '—', s: meio ? pp0(din) : t('Falta o meio de cobrança') },
      { l: t('Em Multicaixa'), v: meio ? mkz(mc) : '—', s: meio ? pp0(mc) : t('Falta o meio de cobrança') },
      {
        l: t('Facturas pendentes'),
        v: mkz(pend),
        tone: pend > 0.005 ? 'warn' : null,
        s: t('{n} documentos', { n: V.filter((x) => pendenteDe(x) > 0).length }),
      },
    ]),
  );
  const secs = el('div', 'secs');
  if (!meio) {
    const box = el('div');
    box.append(
      el(
        'p',
        null,
        t(
          'A exportação de vendas do Pinguim BO que temos indica só se cada documento está liquidado. Para separar o que foi recebido em dinheiro e em Multicaixa, a exportação tem de trazer o meio de cobrança de cada documento (ou o mapa de recebimentos do Pinguim BO).',
        ),
      ),
    );
    secs.append(secBox(t('Falta o meio de cobrança'), t('Assim que a exportação incluir esta coluna, os valores aparecem aqui sem mais alterações.'), box));
  }
  // Meios de pagamento tal como vêm do Pinguim BO
  const PM = porMeio(V);
  const NOMES_CAT = { dinheiro: 'Dinheiro', multicaixa: 'Multicaixa', transferencia: 'Transferência', outro: 'Outro' };
  secs.append(
    tabela({
      id: 'pg-meio',
      titulo: t('Por meio de cobrança'),
      nota: t('Dinheiro efectivamente recebido das vendas, por meio de cobrança, como registado no Pinguim BO (já sem o troco).'),
      rows: PM.map((o) => ({
        chave: o.meio,
        meio: o.meio,
        codigo: o.codigo,
        categoria: t(NOMES_CAT[o.categoria]),
        docs: o.docs,
        valor: o.valor,
        quota: liq ? o.valor / liq : null,
      })),
      sort: 'valor',
      total: true,
      search: false,
      cols: [
        { key: 'meio', label: t('Meio de cobrança') },
        { key: 'codigo', label: t('Código'), p: 0 },
        { key: 'categoria', label: t('Tipo'), p: 0 },
        { key: 'docs', label: t('Documentos'), type: 'n', sum: true },
        { key: 'valor', label: t('Valor recebido'), type: 'kz', sum: true },
        { key: 'quota', label: t('% do recebido'), type: 'pct' },
      ],
    }),
  );
  // Colunas por meio real presente no período
  const meios = PM.map((o) => o.meio);
  const linha = (rs, base) => {
    const o = { ...base, total: sum(rs, 'total'), recebido: sum(rs, recebidoDe) };
    meios.forEach((m, i) => {
      o['m' + i] = rs.reduce((s, x) => s + pagosDe(x).reduce((a, p) => a + ((p.meio || p.codigo || '?') === m ? +p.valor || 0 : 0), 0), 0);
    });
    o.por_liquidar = sum(rs, pendenteDe);
    return o;
  };
  const cols = (k) => [
    { key: k, label: t(k === 'data' ? 'Dia' : 'Promotor'), type: k === 'data' ? 'date' : undefined },
    { key: 'total', label: t('Total'), type: 'kz', sum: true },
    { key: 'recebido', label: t('Recebido'), type: 'kz', sum: true },
    ...meios.map((m, i) => ({ key: 'm' + i, label: t(m), type: 'kz', sum: true })),
    { key: 'por_liquidar', label: t('Facturas pendentes'), type: 'kz', sum: true },
  ];
  const pp = Object.values(group(V, 'rota')).map((rs) => linha(rs, { chave: rs[0].rota, rota: rs[0].rota, promotor: promotorRota(rs[0].rota) }));
  secs.append(tabela({ id: 'pg-prom', titulo: t('Por promotor'), rows: pp, sort: 'total', total: true, cols: cols('promotor') }));
  const pd = Object.values(group(V, 'data')).map((rs) => linha(rs, { chave: rs[0].data, data: rs[0].data }));
  secs.append(tabela({ id: 'pg-dia', titulo: t('Por dia'), rows: pd, sort: 'data', total: true, search: false, cols: cols('data') }));
  secs.append(
    tabela({
      id: 'pg-pend',
      titulo: t('Documentos com facturas pendentes'),
      rows: V.filter((x) => pendenteDe(x) > 0).map((x) => ({ ...x, promotor: promotorRota(x.rota), recebido: recebidoDe(x), em_falta: pendenteDe(x) })),
      sort: 'em_falta',
      total: true,
      search: false,
      cols: [
        { key: 'data', label: t('Dia'), type: 'date' },
        { key: 'documento', label: t('Documento'), nowrap: true },
        { key: 'promotor', label: t('Promotor'), p: 0 },
        { key: 'cliente', label: t('Cliente'), wrap: true },
        { key: 'total', label: t('Total'), type: 'kz', sum: true },
        { key: 'recebido', label: t('Recebido'), type: 'kz', sum: true, p: 1 },
        { key: 'em_falta', label: t('Em falta'), type: 'kz', sum: true },
      ],
    }),
  );
  pg.append(secs);
};
