// Meios de cobrança e recebido de cada documento: a mesma regra no portal, na app de gestão e no ficheiro para o SAP.
// Sem dependências (a app importa este módulo sem trazer o resto do portal).

export const eNC = (x) => x.nc === true;

/** Pagamentos do documento tal como vêm do Pinguim BO (rodapé), ou um só «sem meio indicado» se só se sabe que está liquidado. */
export function pagosDe(x) {
  if (Array.isArray(x.pagamentos)) return x.pagamentos;
  if (!x.liquidado) return [];
  return [{ codigo: x.meio_pagamento ? '' : '?', meio: x.meio_pagamento || 'Sem meio indicado', valor: +x.total || 0 }];
}
export const recebidoDe = (x) => (eNC(x) ? 0 : x.valor_pago != null ? +x.valor_pago || 0 : x.liquidado ? +x.total || 0 : 0);
export const pendenteDe = (x) => (eNC(x) ? 0 : Math.max(0, (+x.total || 0) - recebidoDe(x)));

// Agrupa cada meio em dinheiro, Multicaixa, transferência ou outro, pelo código e depois pelo nome (a mesma regra em
// todos os ecrãs e no ficheiro para o SAP, para os valores serem sempre iguais aos do Início)
export function catMeio(p) {
  const c = String(p.codigo || '').toUpperCase(),
    m = String(p.meio || '').toUpperCase();
  // compensação factura × nota de crédito (sem dinheiro): soma zero, fica fora dos meios de cobrança
  if (c === 'CMP') return 'compensacao';
  // liquidado no Pinguim BO sem rodapé de pagamento: excepção a corrigir na origem, nunca «outro meio»
  if (c === '?') return 'sem_meio';
  if (c === 'NU' || /NUMER|DINHEIRO|CASH/.test(m)) return 'dinheiro';
  if (c === 'MC' || /MULTI|TPA|CART|CARD/.test(m)) return 'multicaixa';
  if (c === 'TB' || /TRANSF/.test(m)) return 'transferencia';
  return 'outro';
}

/** Recebido por categoria de meio num conjunto de documentos: {dinheiro, multicaixa, transferencia, outro, sem_meio}. */
export function recebidoPorCategoria(V) {
  const o = { dinheiro: 0, multicaixa: 0, transferencia: 0, outro: 0, sem_meio: 0 };
  V.forEach((x) =>
    pagosDe(x).forEach((p) => {
      const c = catMeio(p);
      if (c in o) o[c] += +p.valor || 0;
    }),
  );
  return o;
}
