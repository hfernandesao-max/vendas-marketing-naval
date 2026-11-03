// Detalhe do documento: clicar no número abre o painel com a representação, os artigos e os meios de cobrança.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const V = [
  {
    chave: 'FR 1/7',
    data: '2026-10-15',
    hora: '10:20',
    rota: '1001',
    tipo: 'Factura-recibo',
    tipo_codigo: 'FR',
    documento: 'FR 1/7',
    cliente: 'CANTINA KIANDA',
    cliente_codigo: 'C7',
    nif: '5000000007',
    liquidado: true,
    total: 11400,
    valor_pago: 11400,
    pagamentos: [
      { codigo: 'NU', meio: 'Numerário', valor: 5000 },
      { codigo: 'MC', meio: 'Multicaixa', valor: 6400 },
    ],
    meio_pagamento: 'Numerário, Multicaixa',
    nc: false,
  },
];
const A = [
  {
    chave: 'FR 1/7|1',
    data: '2026-10-15',
    rota: '1001',
    documento: 'FR 1/7',
    codigo: 'FRM',
    artigo: 'Fraldas Bebé M',
    unidade: 'CX',
    quantidade: 2,
    preco_unitario: 5000,
    valor_sem_iva: 10000,
    desconto: 0,
    iva: 1400,
    total: 11400,
    nc: false,
  },
];

test('clicar no documento abre o painel com a representação, os artigos e os meios de cobrança', async ({ page }) => {
  const erros = await abrir(page, '#documentos', { api: (r) => r.fulfill({ json: { vendas: V, artigos: A } }) });
  await page.locator('.rel-tab', { hasText: 'Documentos Pinguim' }).click();
  await page.getByRole('button', { name: 'Abrir o documento FR 1/7' }).first().click();
  const p = page.locator('dialog.doc-painel');
  await expect(p).toBeVisible();
  await expect(p.locator('h2')).toHaveText('FR 1/7');
  await expect(p.locator('.doc-talao')).toContainText('CANTINA KIANDA');
  await expect(p.locator('.doc-talao')).toContainText('Fraldas Bebé M');
  await expect(p.locator('.doc-talao')).toContainText('Multicaixa');
  await expect(p.locator('.doc-resumo')).toContainText('Numerário, Multicaixa');
  await expect(p.locator('.doc-sec table')).toContainText('1400,00');
  await page.keyboard.press('Escape');
  await expect(p).toHaveCount(0);
  expect(erros).toEqual([]);
});
