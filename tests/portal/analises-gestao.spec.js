// Inteligência de negócio › Análises de gestão: RFM, coortes, CLV, preço-volume-mix, ABC-XYZ, cesto, concentração,
// produtividade e cobertura de stock, com os dados do serviço (tests/dados/analises-exemplo.json, gerado pelo próprio
// naval_ag_analises com dados fictícios).
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { abrir } from './dados.js';

const A = JSON.parse(readFileSync('tests/dados/analises-exemplo.json', 'utf8'));

test('análises de gestão: um separador por análise, ligações às fichas e sem erros', async ({ page }) => {
  await page.route('**/v1/analises**', (r) => r.fulfill({ json: A }));
  const erros = await abrir(page, '#analises-gestao');
  const pg = page.locator('#page');
  await expect(pg.getByText('Valor anual em risco')).toBeVisible();
  await expect(pg.locator('.rel-tabs a')).toHaveCount(8);
  const SEP = {
    rfm: ['Segmentação RFM dos clientes', 'Clientes por segmento'],
    retencao: ['Coortes de retenção', 'Valor do cliente (CLV) por rota'],
    pvm: ['Ponte preço-volume-mix', 'Preço, volume e mix por rota', 'Artigos com maior efeito do preço'],
    'abc-xyz': ['Matriz ABC-XYZ dos artigos', 'Artigos por classe'],
    cesto: ['Cesto de compras: artigos que se vendem juntos'],
    concentracao: ['Concentração das vendas (90 dias)', 'Dependência dos maiores clientes, por rota'],
    produtividade: ['Produtividade das rotas face à melhor prática'],
    stock: ['Cobertura de stock'],
  };
  for (const [k, titulos] of Object.entries(SEP)) {
    await page.evaluate((x) => (location.hash = x), '#analises-gestao/' + k);
    for (const titulo of titulos) await expect(pg.getByRole('heading', { name: titulo, exact: true })).toBeVisible();
    if (k === 'abc-xyz') await expect(pg.locator('.ag-cel')).toHaveCount(9);
    if (k === 'stock') await expect(page.locator('#s-ag-stock tbody tr').first()).toContainText('Rotura');
    if (k === 'produtividade') await expect(page.locator('#s-ag-prod .lig-rota').first()).toBeVisible();
    if (k === 'rfm') await expect(pg.getByText('Campeões').first()).toBeVisible();
  }
  // o mosaico abre o separador da análise
  await pg.locator('.tile', { hasText: 'Potencial das rotas' }).click();
  await expect(page).toHaveURL(/#analises-gestao\/produtividade/);
  expect(erros).toEqual([]);
});

test('análises de gestão: sem stock (sem acesso) não há o separador da cobertura', async ({ page }) => {
  await page.route('**/v1/analises**', (r) => r.fulfill({ json: { ...A, cobertura: null, com_stock: false } }));
  await abrir(page, '#analises-gestao/rfm');
  await expect(page.locator('#page').getByRole('heading', { name: 'Segmentação RFM dos clientes' })).toBeVisible();
  await expect(page.locator('#page .rel-tabs a')).toHaveCount(7);
  await expect(page.locator('#page .rel-tabs')).not.toContainText('Cobertura de stock');
});

test('análises de gestão: em tigrínia, sem textos por traduzir', async ({ page }) => {
  await page.route('**/v1/analises**', (r) => r.fulfill({ json: A }));
  await page.addInitScript(() => {
    localStorage.setItem('vm-lang', 'ti');
    globalThis.__vmSemTraducao = {};
  });
  const erros = await abrir(page, '#analises-gestao', { manterMemoria: true });
  await expect(page.locator('#page').getByText('ኣብ ሓደጋ ዘሎ ዓመታዊ ዋጋ')).toBeVisible();
  const falta = await page.evaluate(() => Object.keys(globalThis.__vmSemTraducao));
  expect(falta.filter((s) => !/^[A-Z]{2,4}$/.test(s))).toEqual([]);
  expect(erros).toEqual([]);
});
