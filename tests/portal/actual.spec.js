// Informação actual: o portal abre no dia de hoje, mostra a hora da actualização e actualiza sozinho.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

test('abre sempre em «Hoje», mesmo sem vendas no dia, e sem erros', async ({ page }) => {
  for (const pg of ['#inicio', '#vendas-dia', '#pagamentos', '#pendentes', '#clientes']) {
    const erros = await abrir(page, pg, { periodo: null });
    expect(erros, pg).toEqual([]);
    await expect(page.locator('#presets button.on')).toHaveText('Hoje');
    await expect(page.locator('#de')).toHaveValue('2026-10-26');
    await expect(page.locator('#ate')).toHaveValue('2026-10-26');
  }
  await expect(page.locator('#actual')).toContainText('Informação actual');
  await expect(page.locator('#actual')).toContainText('actualiza sozinha');
});

test('a escolha de período vale só na sessão; «Ontem» é um período fechado', async ({ page }) => {
  await abrir(page, '#vendas-dia', { periodo: null });
  await page.click('#presets button:has-text("Ontem")');
  await expect(page.locator('#de')).toHaveValue('2026-10-25');
  await expect(page.locator('#actual')).toContainText('Período fechado');
  expect(await page.evaluate(() => localStorage.getItem('vm-filtro'))).toBeNull();
  expect(await page.evaluate(() => JSON.parse(sessionStorage.getItem('vm-periodo')).preset)).toBe('ontem');
});

test('actualiza sozinho com o período aberto e pelo botão «Actualizar»', async ({ page }) => {
  let pedidos = 0;
  page.on('request', (r) => {
    if (r.url().includes('api.php?start_date')) pedidos++;
  });
  await abrir(page, '#inicio', { periodo: null });
  const antes = pedidos;
  await page.locator('#actual button').click();
  await expect.poll(() => pedidos).toBeGreaterThan(antes);
  const depois = pedidos;
  await page.clock.install({ time: new Date('2026-10-26T12:00:00+01:00') });
  await page.reload();
  await page.waitForSelector('#page > *');
  const base = pedidos;
  await page.clock.runFor(6 * 60000);
  await expect.poll(() => pedidos).toBeGreaterThan(base);
  expect(depois).toBeGreaterThan(antes);
});
