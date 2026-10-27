// Menu lateral: Início fecha as áreas; detalhe das fontes fecha sozinho; bolinha a piscar com ligação parcial.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

test('Início fecha todas as áreas do menu e volta ao estado inicial', async ({ page }) => {
  await abrir(page, '#pagamentos');
  await expect(page.locator('.navg[aria-expanded="true"]')).toHaveCount(1);
  await page.locator('#nav a[href="#inicio"]').click();
  await expect(page.locator('#titulo')).toHaveText('Visão geral');
  await expect(page.locator('.navg[aria-expanded="true"]')).toHaveCount(0);
  // abrir uma área no Início e voltar a carregar em Início: fecha outra vez
  await page.locator('.navg', { hasText: 'Vendas' }).click();
  await expect(page.locator('.navg[aria-expanded="true"]')).toHaveCount(1);
  await page.locator('#nav a[href="#inicio"]').click();
  await expect(page.locator('.navg[aria-expanded="true"]')).toHaveCount(0);
});

test('detalhe das fontes: abre ao clicar e fecha sozinho; Esc e clique fora também fecham', async ({ page }) => {
  await abrir(page, '#inicio');
  await page.clock.install({ time: new Date('2026-10-26T12:00:00+01:00') });
  await page.locator('#fontes').click();
  await expect(page.locator('#fontes-det')).toBeVisible();
  await expect(page.locator('#fontes')).toHaveAttribute('aria-expanded', 'true');
  await page.mouse.move(900, 600);
  await page.clock.runFor(8500);
  await expect(page.locator('#fontes-det')).toBeHidden();
  await page.locator('#fontes').click();
  await page.keyboard.press('Escape');
  await expect(page.locator('#fontes-det')).toBeHidden();
  await page.locator('#fontes').click();
  await page.mouse.click(900, 600);
  await expect(page.locator('#fontes-det')).toBeHidden();
});

test('ligação parcial: a bolinha das fontes alterna entre verde e âmbar', async ({ page }) => {
  await abrir(page, '#inicio');
  await page.evaluate(() => {
    document.querySelector('#fontes-dot').className = 'dot aviso';
  });
  const anim = await page.locator('#fontes-dot').evaluate((e) => getComputedStyle(e).animationName);
  expect(anim).toBe('fontes-parcial');
});
