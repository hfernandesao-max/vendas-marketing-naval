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

test('menu em acordeão: áreas fechadas, só uma aberta de cada vez, a da página actual abre sozinha', async ({ page }) => {
  await abrir(page, '#inicio');
  const grupo = (nome) => page.locator('.navg', { hasText: nome });
  await expect(page.locator('.navg[aria-expanded="true"]')).toHaveCount(0);
  await expect(page.locator('#nav a[href="#clientes"]')).not.toBeInViewport();
  await grupo('Clientes').click();
  await expect(grupo('Clientes')).toHaveAttribute('aria-expanded', 'true');
  await expect(page.locator('#nav a[href="#clientes"]')).toBeVisible();
  await grupo('Vendas').click();
  await expect(grupo('Vendas')).toHaveAttribute('aria-expanded', 'true');
  await expect(grupo('Clientes')).toHaveAttribute('aria-expanded', 'false');
  await grupo('Vendas').click();
  await expect(page.locator('.navg[aria-expanded="true"]')).toHaveCount(0);
  // ao abrir uma página por endereço, a sua área abre e fica destacada
  await page.goto(page.url().replace(/#.*$/, '#pendentes'));
  await expect(grupo('Recebimentos')).toHaveAttribute('aria-expanded', 'true');
  await expect(grupo('Recebimentos')).toHaveClass(/atual/);
  await expect(page.locator('.navg[aria-expanded="true"]')).toHaveCount(1);
});

test('menu organizado por áreas de negócio', async ({ page }) => {
  await abrir(page, '#inicio');
  await expect(page.locator('#nav .navg .navg-t')).toHaveText(['Vendas', 'Recebimentos', 'Clientes', 'Terreno', 'Logística', 'Integrações', 'Administração']);
});
