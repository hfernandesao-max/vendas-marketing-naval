// Detalhe do documento: clicar no número abre o painel com a representação, os artigos e os meios de cobrança.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
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
  await page.locator('.rel-tab', { hasText: 'Registo de documentos de venda' }).click();
  await page.getByRole('button', { name: 'Abrir o documento FR 1/7' }).first().click();
  const p = page.locator('dialog.doc-painel');
  await expect(p).toBeVisible();
  await expect(p.locator('h2')).toHaveText('FR 1/7');
  const f = p.locator('.doc-folha');
  await expect(f.locator('.df-tipo')).toHaveText('Factura-recibo');
  await expect(f.locator('.df-meta')).toContainText('CANTINA KIANDA');
  await expect(f.locator('.df-linhas tbody tr')).toHaveCount(1);
  await expect(f.locator('.df-linhas')).toContainText('Fraldas Bebé M');
  await expect(f.locator('.df-iva')).toContainText('14 %');
  await expect(f.locator('.df-pags')).toContainText('Multicaixa');
  await expect(f.locator('.df-grande')).toContainText(/11\s400,00/);
  // cabe no painel: sem deslocamento horizontal do painel
  const [sw, cw] = await p.evaluate((d) => [d.scrollWidth, d.clientWidth]);
  expect(sw).toBeLessThanOrEqual(cw);
  // a tabela das linhas também não desloca: a folha ajusta-se à janela
  const [tw, tc] = await f.locator('.df-tabw').evaluate((d) => [d.scrollWidth, d.clientWidth]);
  expect(tw).toBeLessThanOrEqual(tc);
  // exportar PDF: descarrega o documento em PDF
  const [ficheiro] = await Promise.all([page.waitForEvent('download'), p.getByRole('button', { name: 'Exportar PDF' }).click()]);
  expect(ficheiro.suggestedFilename()).toBe('FR_1_7.pdf');
  const caminho = await ficheiro.path();
  const bytes = readFileSync(caminho);
  expect(bytes.subarray(0, 5).toString()).toBe('%PDF-');
  await page.keyboard.press('Escape');
  await expect(p).toHaveCount(0);
  expect(erros).toEqual([]);
});

test('o documento ajusta-se à janela num ecrã pequeno, sem deslocação horizontal', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 800 });
  await abrir(page, '#documentos', { api: (r) => r.fulfill({ json: { vendas: V, artigos: A } }) });
  await page.locator('.rel-tab', { hasText: 'Registo de documentos de venda' }).first().click();
  await page.getByRole('button', { name: 'Abrir o documento FR 1/7' }).first().click();
  const p = page.locator('dialog.doc-painel');
  await expect(p.locator('.doc-folha')).toBeVisible();
  const [sw, cw] = await p.evaluate((d) => [d.scrollWidth, d.clientWidth]);
  expect(sw).toBeLessThanOrEqual(cw);
  const caixa = await p.locator('.doc-folha').boundingBox();
  expect(caixa.width).toBeLessThanOrEqual(390);
});
