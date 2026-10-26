// Regras de negócio comuns: vendas do período, pagamentos, pendentes, rotas, visitas e cadastros.
import { LANG, t } from './i18n/i18n.js';
import { INICIO_PINGUIM, addD, hoje, inR, st } from './periodo.js';
import { DB, group, sum } from './util.js';

export const vendasR = () => DB.vendas.filter((x) => inR(x.data));
// Estados da carteira vêm do Pinguim BO com a grafia nova; no ecrã mostram-se com a grafia antiga
export const ESTADO_PT = { Ativo: 'Activo', Inativo: 'Inactivo' };
export const nomeRota = (r) => (DB.mapRota[r] || {}).promotor || r;
// Nome do promotor sempre acompanhado do código da rota: "1001 · Andrea Santos"
export const promotorRota = (r, nome) => {
  const n = nome || nomeRota(r);
  return r && n && String(n) !== String(r) ? r + ' · ' + n : String(n || r || '—');
};
export const isCF = (nif) => !nif || /^9{9,}$/.test(String(nif));
const DIAS = ['DOMINGO', '2ª FEIRA', '3ª FEIRA', '4ª FEIRA', '5ª FEIRA', '6ª FEIRA', 'SÁBADO'];
export const diaSemana = (d) => DIAS[new Date(d + 'T12:00:00Z').getUTCDay()];
const DIA_EN = {
  DOMINGO: 'Sunday',
  '2ª FEIRA': 'Monday',
  '3ª FEIRA': 'Tuesday',
  '4ª FEIRA': 'Wednesday',
  '5ª FEIRA': 'Thursday',
  '6ª FEIRA': 'Friday',
  SÁBADO: 'Saturday',
};
export const nomeDia = (d) => (LANG === 'en' ? DIA_EN[d] || d : d);
// Pagamentos reais de cada documento, como vêm da API do Pinguim BO: [{codigo, meio, valor}].
// Um documento pode ser pago com vários meios; o recebido é a soma dos valores (já sem troco).
export function pagosDe(x) {
  if (Array.isArray(x.pagamentos)) return x.pagamentos;
  if (!x.liquidado) return [];
  return [{ codigo: x.meio_pagamento ? '' : '?', meio: x.meio_pagamento || 'Sem meio indicado', valor: +x.total || 0 }];
}
export const recebidoDe = (x) => (x.valor_pago != null ? +x.valor_pago || 0 : x.liquidado ? +x.total || 0 : 0);
export const pendenteDe = (x) => Math.max(0, (+x.total || 0) - recebidoDe(x));
export const temMeio = () => DB.vendas.some((x) => pagosDe(x).some((p) => p.codigo !== '?'));
// Agrupa cada meio em dinheiro, Multicaixa, transferência ou outro, pelo código e depois pelo nome
function catMeio(p) {
  const c = String(p.codigo || '').toUpperCase(),
    m = String(p.meio || '').toUpperCase();
  if (c === 'NU' || /NUMER|DINHEIRO|CASH/.test(m)) return 'dinheiro';
  if (c === 'MC' || /MULTI|TPA|CART|CARD/.test(m)) return 'multicaixa';
  if (c === 'TB' || /TRANSF/.test(m)) return 'transferencia';
  return 'outro';
}
export const valorCat = (V, cat) => V.reduce((s, x) => s + pagosDe(x).reduce((a, p) => a + (catMeio(p) === cat ? +p.valor || 0 : 0), 0), 0);
// Soma por meio de pagamento real (nome do Pinguim BO), ordenado do maior para o menor
export function porMeio(V) {
  const m = new Map();
  V.forEach((x) =>
    pagosDe(x).forEach((p) => {
      const k = p.meio || p.codigo || '?';
      const o = m.get(k) || { meio: k, codigo: p.codigo || '', categoria: catMeio(p), docs: new Set(), valor: 0 };
      o.valor += +p.valor || 0;
      o.docs.add(x.chave || x.documento);
      m.set(k, o);
    }),
  );
  return [...m.values()].map((o) => ({ ...o, docs: o.docs.size })).sort((a, b) => b.valor - a.valor);
}
export function porPromotor(V) {
  const vendasPorRota = group(V, 'rota');
  const rotas = new Map((DB.rotas || []).map((x) => [x.rota, promotorRota(x.rota, x.promotor)]));
  V.forEach((x) => {
    if (!rotas.has(x.rota)) rotas.set(x.rota, promotorRota(x.rota));
  });
  return [...rotas.entries()].map(([rota, promotor]) => {
    const rs = vendasPorRota[rota] || [];
    const tot = sum(rs, 'total'),
      liq = sum(rs, recebidoDe);
    return {
      rota,
      promotor,
      docs: rs.length,
      clientes: new Set(rs.map((x) => (isCF(x.nif) ? x.cliente : x.nif))).size,
      dias: new Set(rs.map((x) => x.data)).size,
      total: tot,
      recebido: liq,
      por_liquidar: tot - liq,
      medio: rs.length ? tot / rs.length : 0,
    };
  });
}

// Visitas marcadas: cada cliente activo da carteira tem um dia da semana de visita na sua rota.
// Conta como visitado quando há um documento do Pinguim BO nesse dia, na mesma rota, para esse
// cliente (pelo código do cliente; sem código, pelo NIF). Só contam dias já terminados desde o
// arranque do Pinguim BO.
export function visitasPlaneadas() {
  const fim = [st.ate, addD(hoje(), -1)].sort()[0],
    ini = [st.de, INICIO_PINGUIM].sort()[1],
    out = [];
  if (ini > fim) return out;
  const act = DB.clientes.filter((x) => x.estado === 'Ativo' && !isCF(x.nif));
  const porDiaRota = group(
    DB.vendas.filter((x) => x.data >= ini && x.data <= fim),
    (x) => x.data + '|' + x.rota,
  );
  for (let d = ini; d <= fim; d = addD(d, 1)) {
    const ds3 = diaSemana(d),
      vistos = new Set();
    act
      .filter((c) => c.dia === ds3)
      .forEach((c) => {
        const k = c.rota + '|' + c.codigo;
        if (vistos.has(k)) return;
        vistos.add(k);
        const docs = (porDiaRota[d + '|' + c.rota] || []).filter((x) =>
          x.cliente_codigo ? String(x.cliente_codigo) === String(c.codigo) : String(x.nif) === String(c.nif),
        );
        out.push({ data: d, rota: c.rota, codigo: c.codigo, cliente: c.nome, nif: c.nif, visitado: docs.length > 0, total: sum(docs, 'total') });
      });
  }
  return out;
}

// Histórico de auditoria: verificações automáticas a cada documento do Pinguim BO, por dia
export function ocorrencias(V) {
  const hj = hoje(),
    lim = addD(hj, -3),
    out = [];
  // Possível duplicado: mesma rota, mesmo cliente com NIF, mesmo dia e mesmo valor
  const g = group(
    V.filter((x) => !isCF(x.nif)),
    (x) => [x.rota, x.nif, x.data, (+x.total).toFixed(2)].join('|'),
  );
  Object.values(g)
    .filter((rs) => rs.length > 1)
    .forEach((rs) => rs.forEach((x) => out.push({ x, tipo: 'dup', txt: t('Possível duplicado ({n} documentos iguais)', { n: rs.length }) })));
  V.forEach((x) => {
    if (!(+x.total > 0)) out.push({ x, tipo: 'valor', txt: t('Valor nulo ou negativo') });
    if (pendenteDe(x) > 0.005 && x.data < lim) out.push({ x, tipo: 'pend', txt: t('Pendente há mais de 3 dias') });
    if (recebidoDe(x) > 0 && !pagosDe(x).some((p) => p.codigo !== '?')) out.push({ x, tipo: 'meio', txt: t('Recebido sem meio de recebimento indicado') });
  });
  return out;
}

const FERIADOS = ['2026-11-02', '2026-11-11', '2026-12-25'];
export const diaUtil = (d) => new Date(d + 'T12:00:00Z').getUTCDay() !== 0 && !FERIADOS.includes(d);
export const temCad = () => !!(DB.cad && Array.isArray(DB.cad.cadastros));
export const cadReais = () => (temCad() ? DB.cad.cadastros.filter((x) => x.foodtec !== 'Sim') : []);
export const META_ROTA = () => (DB.cad && DB.cad.meta && DB.cad.meta.diaria_rota) || 14;
export const META_TOTAL = () => (DB.cad && DB.cad.meta && DB.cad.meta.total) || 10000;
export const DATA_META = () => (DB.cad && DB.cad.meta && DB.cad.meta.data) || '2026-12-31';
// Matriz rota × dia com os novos clientes (dias úteis até à data escolhida)
export function cadDiario() {
  const D = (DB.cad.cadastros_diarios || []).filter((x) => x.dia <= st.ate);
  const rotas = [...new Set(D.map((x) => x.rota))].sort((a, b) => a.localeCompare(b, 'pt', { numeric: true }));
  const dias = [...new Set(D.map((x) => x.dia))].filter(diaUtil).sort();
  const m = {};
  D.forEach((x) => {
    m[x.rota + '|' + x.dia] = (m[x.rota + '|' + x.dia] || 0) + (+x.novos || 0);
  });
  const v = (r, d) => m[r + '|' + d] || 0;
  return { rotas, dias, v, rede: (d) => rotas.reduce((s, r) => s + v(r, d), 0) };
}
