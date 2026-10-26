// Início: painel de gráficos (forma por tipo de análise), dicas, vista em tabela e ligação para o detalhe.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

test('mostra os gráficos de vendas, pagamentos, dívida, clientes e artigos', async ({ page }) => {
  const erros = await abrir(page, '#inicio');
  await expect(page.locator('.viz-card').first()).toBeVisible();
  const titulos = await page.locator('.viz-card h3').allInnerTexts();
  // «Novos clientes por dia» só aparece quando há dados de cadastros (rtm-clientes)
  expect(titulos).toEqual(
    expect.arrayContaining([
      'Vendas por dia',
      'Vendas por promotor',
      'Como foi pago',
      'Antiguidade da dívida',
      'Melhores clientes',
      'Artigos mais vendidos',
      'Caixas carregadas por dia',
    ]),
  );
  expect(erros).toEqual([]);
});

test('dica ao passar o rato e com o teclado; tabela alternativa; clicar abre o detalhe filtrado', async ({ page }) => {
  await abrir(page, '#inicio');
  const promotor = page.locator('.viz-card', { has: page.locator('h3', { hasText: 'Vendas por promotor' }) });
  const barra = promotor.locator('.vx-m').first();
  await barra.hover();
  await expect(page.locator('.viz-tip')).toBeVisible();
  await expect(page.locator('.viz-tip')).toContainText('vendido');
  await barra.focus();
  await expect(page.locator('.viz-tip')).toContainText('documentos');
  await promotor.locator('button:has-text("Tabela")').click();
  await expect(promotor.locator('.viz-tab tbody tr')).toHaveCount(4);
  await promotor.locator('button:has-text("Gráfico")').click();
  await promotor.locator('.vx-m').first().click();
  await expect(page).toHaveURL(/#documentos(\/dc)?\?rota=1004$/);
  await expect(page.locator('.f-rota .f-btn')).toContainText('1004');
});
