// Ao actualizar o portal (F5) fica-se na mesma página e no mesmo relatório.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

test('F5 mantém a página e o relatório abertos', async ({ page }) => {
  await abrir(page, '#pagamentos');
  await page.locator('.rel-tab').nth(1).click();
  const url = page.url();
  await page.reload();
  await page.waitForSelector('#page > *');
  await expect(page).toHaveURL(url);
  await expect(page.locator('#titulo')).not.toHaveText('Visão geral');
});

test('sem endereço (portal dentro de outra página ou regresso do login), volta à última página do separador', async ({ page }) => {
  await abrir(page, '#registo-cargas');
  await page.evaluate(() => sessionStorage.setItem('vm-voltar', '1'));
  await page.goto('/reports/index.html');
  await page.waitForSelector('#page > *');
  await expect(page).toHaveURL(/#registo-cargas/);
  // uma entrada normal, sem regresso, abre a página principal
  await page.goto('/reports/index.html');
  await page.waitForSelector('#page > *');
  await expect(page.locator('#titulo')).toHaveText('Visão geral');
});
