// Carteira de clientes: primeiro o registo dos clientes (dados mestre), depois a análise por rota.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

test('carteira de clientes: «Clientes» antes de «Carteira por rota», no menu e nos separadores', async ({ page }) => {
  const erros = await abrir(page, '#clientes');
  await expect(page.locator('.rel-tab')).toHaveText(['Clientes', 'Carteira por rota']);
  await expect(page.locator('.rel-tab.on')).toHaveText('Clientes');
  const sub = page.locator('#nav a[href^="#clientes/"]');
  await expect(sub.first()).toContainText('Clientes');
  await expect(sub.nth(1)).toContainText('Carteira por rota');
  await expect(page.locator('#s-cl-d thead')).toContainText('GPS');
  expect(erros).toEqual([]);
});
