// Famílias de páginas: uma só entrada no menu, separadores no topo da página (cada um com o seu endereço e filtros);
// explicações longas das secções numa linha, com «Saber mais».
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

test.use({ viewport: { width: 1440, height: 900 } });

test('Vendas: uma entrada no menu e separadores por promotor, dia, cliente e artigo', async ({ page }) => {
  const erros = await abrir(page, '#vendas-dia');
  const area = page.locator('#navl-vendas');
  await expect(area.locator('a[href="#vendas-promotor"] > span:first-of-type')).toHaveText('Vendas');
  await expect(area.locator('a[href="#vendas-promotor"]')).toHaveClass(/on/);
  await expect(area.locator('a[href="#vendas-dia"]')).toHaveCount(0);
  const tabs = page.locator('#page .fam-tabs .fam-tab');
  await expect(tabs).toHaveText(['Vendas por promotor', 'Vendas por dia', 'Vendas por cliente', 'Vendas por artigo', 'Vendas por promotor e artigo']);
  await expect(page.locator('.fam-tab.on')).toHaveText('Vendas por dia');
  await expect(page.locator('.fam-tab.on')).toHaveAttribute('aria-current', 'page');
  await tabs.filter({ hasText: 'Vendas por cliente' }).click();
  await expect(page).toHaveURL(/#vendas-cliente/);
  await expect(page.locator('h1')).toHaveText('Vendas por cliente');
  await expect(page.locator('.fam-tab.on')).toHaveText('Vendas por cliente');
  // página sem família: sem separadores
  await page.goto(page.url().replace(/#.*$/, '#documentos'));
  await expect(page.locator('#page .fam-tabs')).toHaveCount(0);
  expect(erros).toEqual([]);
});

test('explicação longa da secção: uma linha e «Saber mais» abre o resto', async ({ page }) => {
  await abrir(page, '#vendas-promotor');
  const p = page.locator('#page .sec-h p.nota-curta').first();
  await expect(p).toBeVisible();
  const fechada = (await p.boundingBox()).height;
  const b = page.locator('#page .nota-mais').first();
  await expect(b).toHaveText('Saber mais');
  await expect(b).toHaveAttribute('aria-expanded', 'false');
  await b.click();
  await expect(b).toHaveText('Mostrar menos');
  expect((await p.boundingBox()).height).toBeGreaterThan(fechada);
});
