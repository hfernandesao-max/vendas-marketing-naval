// Barra de filtros comum: escolha de rotas, estado, persistência entre páginas, endereço partilhável e limpar.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const linhas = (page, id) => page.locator(`#s-${id} tbody tr`).count();

test('rotas e estado filtram os documentos e mantêm-se ao mudar de página', async ({ page }) => {
  await abrir(page, '#documentos/dc');
  await page.click('#filtros2 .fb-ms-btn:has-text("Rotas")');
  await page.locator('.fb-pop .fb-item', { hasText: 'Rota 1001' }).click();
  await page.locator('.fb-pop .fb-item', { hasText: 'Rota 1003' }).click();
  await page.keyboard.press('Escape');
  await expect(page.locator('#filtros2 .fb-ms-btn', { hasText: 'Rotas' })).toContainText('2 selecionados');
  await expect(page.locator('#filtros2 .fb-etiqueta')).toHaveCount(2);
  expect(await linhas(page, 'dc')).toBe(12);
  await expect(page).toHaveURL(/#documentos\/dc\?rota=1001,1003$/);

  await page.click('#filtros2 .fb-ms-btn:has-text("Estado")');
  await page.locator('.fb-pop .fb-item', { hasText: 'Pendentes' }).click();
  expect(await linhas(page, 'dc')).toBe(4);

  await page.click('#nav a[href="#vendas-dia"]');
  await expect(page.locator('#filtros2 .fb-ms-btn', { hasText: 'Rotas' })).toContainText('2 selecionados');
  await expect(page.locator('#filtros2 .fb-ms-btn', { hasText: 'Estado' })).toContainText('Pendentes');
  await expect(page).toHaveURL(/rota=1001,1003&estado=pendente/);

  await page.click('#filtros2 .fb-limpar');
  await expect(page.locator('#filtros2 .fb-ms.on')).toHaveCount(0);
  await expect(page.locator('#filtros2 .fb-etiqueta')).toHaveCount(0);
});

test('uma ligação com filtros abre a página já filtrada', async ({ page }) => {
  await abrir(page, '#pendentes?rota=1002&tipo=FT');
  await expect(page.locator('#filtros2 .fb-ms-btn', { hasText: 'Rotas' })).toContainText('1002');
  await expect(page.locator('#filtros2 .fb-ms-btn', { hasText: 'Tipo de documento' })).toContainText('Factura');
});

test('cada página só mostra os filtros que se aplicam', async ({ page }) => {
  await abrir(page, '#stock-carrinha');
  await expect(page.locator('#filtros2 .fb-ms-btn', { hasText: 'Estado' })).toHaveCount(0);
  await abrir(page, '#vendas-artigo');
  await expect(page.locator('#filtros2 .fb-pesq input')).toHaveCount(2);
});
