// Páginas como grupos de relatórios: separadores, sub-itens do menu, endereço próprio e memória do último.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

test('mostra um relatório de cada vez e muda pelos separadores', async ({ page }) => {
  await abrir(page, '#vendas-dia');
  await expect(page.locator('.rel-tab')).toHaveText(['Vendas por dia', 'Promotor × dia']);
  await expect(page.locator('#s-vd')).toBeVisible();
  await expect(page.locator('#s-vd-mat')).toBeHidden();
  await page.click('.rel-tab:has-text("Promotor × dia")');
  await expect(page).toHaveURL(/#vendas-dia\/vd-mat$/);
  await expect(page.locator('#s-vd-mat')).toBeVisible();
  await expect(page.locator('#s-vd')).toBeHidden();
  await expect(page.locator('.nav-sub a.on')).toHaveText('Promotor × dia');
});

test('lembra o último relatório aberto em cada página', async ({ page }) => {
  await abrir(page, '#documentos/dc');
  await page.click('#nav a[href="#vendas-promotor"]');
  await page.click('#nav a[href="#documentos"]');
  await expect(page.locator('.rel-tab.on')).toHaveText('Documentos Pinguim');
});

test('as áreas do menu recolhem e expandem', async ({ page }) => {
  await abrir(page, '#inicio');
  const grupo = page.locator('.navg', { hasText: 'Clientes' });
  await grupo.click();
  await expect(grupo).toHaveAttribute('aria-expanded', 'false');
  await grupo.click();
  await expect(grupo).toHaveAttribute('aria-expanded', 'true');
});

test('menu organizado por áreas de negócio', async ({ page }) => {
  await abrir(page, '#inicio');
  await expect(page.locator('#nav .navg')).toHaveText(['Vendas', 'Recebimentos', 'Clientes', 'Terreno', 'Logística', 'Administração']);
});
