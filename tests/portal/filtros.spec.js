// Barra de filtros comum: escolha de rotas, estado, persistência entre páginas, endereço partilhável e limpar.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const linhas = (page, id) => page.locator(`#s-${id} tbody tr`).count();

test('rotas e estado filtram os documentos e mantêm-se ao mudar de página', async ({ page }) => {
  await abrir(page, '#documentos/dc');
  await page.click('#filtros2 .f-rota .f-btn');
  await page.locator('.f-pop .f-op', { hasText: '1001' }).click();
  await page.locator('.f-pop .f-op', { hasText: '1003' }).click();
  await page.click('.f-pop button:has-text("Aplicar")');
  await expect(page.locator('.f-rota .f-btn')).toHaveText('2 rotas');
  expect(await linhas(page, 'dc')).toBe(12);
  await expect(page).toHaveURL(/#documentos\/dc\?rota=1001,1003$/);

  await page.selectOption('#filtros2 select[aria-label="Estado"]', 'pendente');
  expect(await linhas(page, 'dc')).toBe(4);

  await page.click('#nav a[href="#vendas-dia"]');
  await expect(page.locator('.f-rota .f-btn')).toHaveText('2 rotas');
  await expect(page.locator('#filtros2 select[aria-label="Estado"]')).toHaveValue('pendente');
  await expect(page).toHaveURL(/rota=1001,1003&estado=pendente/);

  await page.click('.f-limpar');
  await expect(page.locator('#filtros2 .f-ctl.on')).toHaveCount(0);
});

test('uma ligação com filtros abre a página já filtrada', async ({ page }) => {
  await abrir(page, '#pendentes?rota=1002&tipo=FT');
  await expect(page.locator('.f-rota .f-btn')).toContainText('1002');
  await expect(page.locator('#filtros2 select[aria-label="Tipo de documento"]')).toHaveValue('FT');
});

test('cada página só mostra os filtros que se aplicam', async ({ page }) => {
  await abrir(page, '#stock-carrinha');
  await expect(page.locator('#filtros2 select')).toHaveCount(0);
  await abrir(page, '#vendas-artigo');
  await expect(page.locator('#filtros2 .f-txt input')).toHaveCount(2);
});
