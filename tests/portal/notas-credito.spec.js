// Total de vendas líquido: Total de Vendas Líquidas = Facturas − Notas de Crédito.
// As notas de crédito vêm do servidor com o total em negativo ('nc': true), sem pagamentos.
// Não contam como documentos, clientes, recebido nem pendentes; não entram no ficheiro SAP.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

let n = 0;
const factura = (total, extra = {}) => ({
  chave: 'F' + ++n,
  data: '2026-10-15',
  hora: '10:00',
  rota: '1001',
  tipo: 'Factura-recibo',
  tipo_codigo: 'FR',
  documento: 'FR 1/' + n,
  cliente: 'CLIENTE A',
  cliente_codigo: 'C1',
  nif: '5000000001',
  liquidado: true,
  total,
  valor_pago: total,
  pagamentos: [{ codigo: 'NU', meio: 'Numerário', valor: total }],
  meio_pagamento: 'Numerário',
  nc: false,
  ...extra,
});
const nota = (valor, extra = {}) => ({
  chave: 'N' + ++n,
  data: '2026-10-16',
  hora: '11:00',
  rota: '1001',
  tipo: 'Nota de crédito',
  tipo_codigo: 'NC',
  documento: 'NC 1/' + n,
  cliente: 'CLIENTE A',
  cliente_codigo: 'C1',
  nif: '5000000001',
  liquidado: true,
  total: -valor,
  valor_pago: 0,
  pagamentos: [],
  meio_pagamento: null,
  nc: true,
  ...extra,
});

// Valor de um quadro (tile) em Kz, como número (o rótulo está em .l, ou em .l-t quando há variação)
async function quadro(page, rotulo) {
  const r = new RegExp('^' + rotulo + '$');
  const v = await page
    .locator('.tile', { has: page.locator('.l:not(.l-var), .l-t', { hasText: r }) })
    .first()
    .locator('.v')
    .textContent({ timeout: 5000 });
  return Number(v.replace(/[^\d,-]/g, '').replace(',', '.'));
}
const api = (vendas) => (r) => r.fulfill({ json: { vendas, artigos: [] } });

const CENARIOS = [
  { nome: 'sem notas de crédito', vendas: () => [factura(10000)], esperado: 10000 },
  { nome: 'uma nota de crédito', vendas: () => [factura(10000), nota(2000)], esperado: 8000 },
  { nome: 'várias notas de crédito', vendas: () => [factura(10000), nota(1000), nota(500)], esperado: 8500 },
  { nome: 'nota de crédito parcial', vendas: () => [factura(6000), factura(4000), nota(250)], esperado: 9750 },
  { nome: 'sem vendas', vendas: () => [], esperado: 0 },
  // Filtro de período: a nota de crédito fora do período não desconta
  { nome: 'nota de crédito fora do período', vendas: () => [factura(10000), nota(2000, { data: '2026-09-30' })], esperado: 10000 },
];

for (const c of CENARIOS) {
  test(`total de vendas líquido: ${c.nome}`, async ({ page }) => {
    const erros = await abrir(page, '#documentos', { api: api(c.vendas()) });
    if (c.esperado) expect(await quadro(page, 'Vendas RTM')).toBeCloseTo(c.esperado, 2);
    else await expect(page.locator('#page')).toContainText(/0,00|Sem dados|sem dados|—/);
    expect(erros).toEqual([]);
  });
}

test('notas de crédito: mesmo total em todos os ecrãs; não contam como documentos, recebido nem pendentes', async ({ page }) => {
  const V = [factura(6000), factura(4000, { liquidado: false, valor_pago: 1000, pagamentos: [{ codigo: 'MC', meio: 'Multicaixa', valor: 1000 }] }), nota(1500)];
  await abrir(page, '#documentos', { api: api(V) });
  expect(await quadro(page, 'Vendas RTM')).toBeCloseTo(8500, 2);
  expect(await quadro(page, 'Documentos')).toBe(2); // só as facturas
  expect(await quadro(page, 'Recebido')).toBeCloseTo(7000, 2);
  expect(await quadro(page, 'Facturas pendentes')).toBeCloseTo(3000, 2); // o que falta na factura de 4000
  expect(await quadro(page, 'Clientes com compras')).toBe(1);
  expect(await quadro(page, 'Média por documento')).toBeCloseTo(4250, 2); // 8500 ÷ 2 facturas
  await expect(page.locator('.tile', { hasText: 'Vendas RTM' }).first()).toContainText('notas de crédito: −1500,00 Kz');
  // A nota de crédito aparece na lista de documentos, com o valor negativo e sem estado de pagamento
  const linhaNC = page.locator('tr', { hasText: 'NC 1/' });
  await expect(linhaNC).toContainText('Nota de crédito');
  await expect(linhaNC).toContainText('-1500,00');

  for (const pagina of ['#inicio', '#vendas-promotor']) {
    await page.evaluate((h) => (location.hash = h), pagina);
    await page.waitForTimeout(400);
    expect(await quadro(page, 'Vendas RTM'), pagina).toBeCloseTo(8500, 2);
  }
  await page.evaluate(() => (location.hash = '#pagamentos'));
  await page.waitForTimeout(400);
  expect(await quadro(page, 'Valor recebido')).toBeCloseTo(7000, 2);
  expect(await quadro(page, 'Facturas pendentes')).toBeCloseTo(3000, 2);
  await expect(page.locator('.tile', { hasText: 'Valor recebido' }).first()).toContainText('70,0%'); // 7000 ÷ 10 000 facturados
});

test('vendas por promotor: só as rotas com movimento; as outras indicadas por baixo; nunca «−0,00»', async ({ page }) => {
  const V = [nota(780000, { rota: '1011', documento: 'NC 9/1' })];
  const erros = await abrir(page, '#vendas-promotor', { api: api(V) });
  const linhas = page.locator('table tbody tr');
  await expect(linhas).toHaveCount(1);
  await expect(linhas.first()).toContainText('1011');
  await expect(page.locator('table')).not.toContainText('-0,00');
  await expect(page.locator('#page')).toContainText('Sem movimento no período e filtros escolhidos');
  expect(erros).toEqual([]);
});
