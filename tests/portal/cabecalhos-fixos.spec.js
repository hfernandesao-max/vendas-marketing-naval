// Ao descer páginas com muitas linhas: o nome da página (barra superior) e o nome das colunas ficam sempre visíveis.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

test('tabelas longas: barra superior com o nome da página e cabeçalho das colunas fixos ao descer', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 600 });
  const erros = await abrir(page, '#documentos');
  await page.locator('.rel-tab', { hasText: 'Registo de documentos de venda' }).click();
  await page.mouse.move(800, 400);
  for (let i = 0; i < 8; i++) await page.mouse.wheel(0, 250);
  await expect(page.locator('#titulo-fixo')).toHaveClass(/ver/);
  await expect(page.locator('#titulo-fixo')).toHaveText('Documentos');
  const barra = await page.locator('#barra-sup').boundingBox();
  expect(barra.y).toBe(0);
  const th = page.locator('.sec:visible table thead th').last();
  await expect.poll(async () => Math.round((await th.boundingBox()).y)).toBe(Math.round(barra.height));
  expect(erros).toEqual([]);
});
