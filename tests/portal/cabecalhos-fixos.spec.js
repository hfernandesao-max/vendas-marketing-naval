// Ao descer tabelas com muitas linhas: o cabeçalho da tabela (título, nota, Procurar, Excel) e o nome das colunas ficam
// fixos no topo do ecrã; a barra superior e os filtros da página não ficam presos.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

test('tabelas longas: cabeçalho da tabela e nome das colunas fixos no topo ao descer', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 600 });
  const erros = await abrir(page, '#documentos');
  await page.mouse.move(800, 400);
  for (let i = 0; i < 8; i++) await page.mouse.wheel(0, 250);
  const sec = page.locator('.sec-tab:visible').last();
  const cab = sec.locator('.sec-h');
  await expect.poll(async () => Math.round((await cab.boundingBox()).y)).toBe(0);
  await expect(cab.locator('h2')).toHaveText('Registo de documentos de venda');
  await expect(cab.locator('input.search')).toBeVisible();
  const altCab = (await cab.boundingBox()).height;
  const th = sec.locator('table thead th').first();
  expect(Math.abs((await th.boundingBox()).y - altCab)).toBeLessThanOrEqual(1);
  // a barra superior não fica presa
  expect((await page.locator('#barra-sup').boundingBox()).y).toBeLessThan(0);
  expect(erros).toEqual([]);
});
