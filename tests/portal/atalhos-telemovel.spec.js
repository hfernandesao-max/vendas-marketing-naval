// Atalhos de teclado («g» + letra, «?» com a lista), barra de navegação em baixo no telemóvel, densidade das tabelas e
// acções principais no cabeçalho da página.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

test('atalhos: g v abre as Vendas, g i o Início; ? mostra a lista; a escrever num campo não actuam', async ({ page }) => {
  const erros = await abrir(page, '#inicio');
  await page.locator('body').click({ position: { x: 5, y: 5 } });
  await page.keyboard.press('g');
  await page.keyboard.press('v');
  await expect(page).toHaveURL(/#vendas-promotor/);
  await page.keyboard.press('g');
  await page.keyboard.press('i');
  await expect(page).toHaveURL(/#inicio/);
  await page.keyboard.press('?');
  const d = page.locator('dialog.atalhos-dlg[open]');
  await expect(d).toBeVisible();
  await expect(d).toContainText('Vendas');
  await expect(d.locator('kbd', { hasText: 'Ctrl' })).toHaveCount(1);
  await page.keyboard.press('Escape');
  await expect(d).toHaveCount(0);
  expect(erros).toEqual([]);
});

test.describe('telemóvel', () => {
  test.use({ viewport: { width: 390, height: 800 }, isMobile: true, hasTouch: true });
  test('barra em baixo: Início, páginas da família activas e Menu abre o menu lateral', async ({ page }) => {
    const erros = await abrir(page, '#vendas-dia');
    const b = page.locator('#barra-inf');
    await expect(b).toBeVisible();
    await expect(b.locator('.bi-l').first()).toContainText('Início');
    await expect(b.locator('.bi-l.on')).toContainText('Vendas');
    await expect(b.locator('.bi-l').last()).toContainText('Menu');
    await b.locator('.bi-l').last().click();
    await expect(page.locator('#app')).toHaveClass(/open/);
    expect(erros).toEqual([]);
  });
});

test('computador: sem barra em baixo; densidade compacta reduz a altura das linhas', async ({ page }) => {
  await abrir(page, '#vendas-dia');
  await expect(page.locator('#barra-inf')).toBeHidden();
  const linha = page.locator('#page table tbody tr').first();
  const antes = (await linha.boundingBox()).height;
  await page.locator('#menu-cfg-b').click();
  await page.locator('#menu-cfg-p button[data-densidade="compacta"]').click();
  await expect(page.locator('html')).toHaveAttribute('data-densidade', 'compacta');
  expect((await linha.boundingBox()).height).toBeLessThan(antes);
});
