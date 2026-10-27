// Dados simulados do Pinguim BO para os testes do portal: 4 rotas, 6 documentos por rota no mês,
// facturas-recibo e facturas, numerário e Multicaixa, algumas por pagar, e linhas de artigo.
export const HOJE = new Date('2026-10-26T12:00:00+01:00');

let n = 0;
const documento = (rota, k, i) => {
  n++;
  const total = 1000 * (k + i + 1),
    pago = k % 3 ? total : 0,
    meio = k % 2 ? 'MC' : 'NU';
  return {
    chave: 'D' + n,
    data: '2026-10-' + String(10 + k * 3).padStart(2, '0'),
    hora: '10:00',
    rota,
    tipo: k === 5 ? 'Factura' : 'Factura-recibo',
    documento: `FR R${rota}/2026/${n}`,
    cliente: `CLIENTE ${rota}-${k}`,
    cliente_codigo: 'C' + n,
    nif: '5001784' + String(n).padStart(3, '0'),
    liquidado: pago >= total,
    total,
    valor_pago: pago,
    pagamentos: pago ? [{ codigo: meio, meio: meio === 'MC' ? 'Multicaixa' : 'Numerário', valor: pago }] : [],
    meio_pagamento: null,
  };
};
export const vendas = [];
['1001', '1002', '1003', '1004'].forEach((r, i) => {
  for (let k = 0; k < 6; k++) vendas.push(documento(r, k, i));
});
export const artigos = vendas.map((v, i) => ({
  chave: v.documento + '|1',
  data: v.data,
  rota: v.rota,
  promotor: v.rota,
  documento: v.documento,
  codigo: i % 2 ? '10000009' : '10000010',
  artigo: i % 2 ? 'Fraldas' : 'Sabão',
  unidade: 'CAR',
  quantidade: 2,
  preco_unitario: 0,
  valor_sem_iva: Math.round((v.total / 1.14) * 100) / 100,
  desconto: 0,
  iva: Math.round((v.total - v.total / 1.14) * 100) / 100,
  total: v.total,
}));

/** Abre o portal com a API do Pinguim BO simulada e a data fixa; período: o mês (ou opcoes.periodo; null = o de omissão, hoje). */
export async function abrir(page, hash = '', opcoes = {}) {
  await page.route('**/data/api.php*', opcoes.api || ((r) => r.fulfill({ json: { vendas, artigos } })));
  await page.addInitScript(
    ({ limpar, periodo }) => {
      try {
        if (periodo) sessionStorage.setItem('vm-periodo', JSON.stringify(periodo));
        if (limpar)
          Object.keys(localStorage)
            .filter((k) => k.startsWith('vm-') && k !== 'vm-filtro')
            .forEach((k) => localStorage.removeItem(k));
      } catch (e) {
        /* sem armazenamento */
      }
    },
    { limpar: !opcoes.manterMemoria, periodo: opcoes.periodo === undefined ? { preset: 'mes' } : opcoes.periodo },
  );
  await page.clock.setFixedTime(HOJE);
  const erros = [];
  page.on('pageerror', (e) => erros.push(e.message));
  await page.goto('/reports/index.html' + hash);
  await page.waitForSelector('#page > *');
  await page.waitForTimeout(300);
  return erros;
}
