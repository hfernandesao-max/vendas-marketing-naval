// Indicadores com variação e tendência; preço unitário sem IVA por artigo; Vendas para o SAP em Integrações.
import { test, expect } from '@playwright/test';
import { abrir, vendas, artigos } from './dados.js';

test('indicadores do início: variação face ao período comparável (azul favorável, vermelho desfavorável) e mini-linha', async ({ page }) => {
  // período comparável (mês anterior) com metade das vendas: sobe tudo, incluindo as pendentes (desfavorável)
  const api = (r) => {
    const u = new URL(r.request().url());
    const anterior = u.searchParams.get('start_date') < '20261001';
    const v = anterior ? vendas.filter((_, i) => i % 2 === 0) : vendas;
    return r.fulfill({ json: anterior ? v : { vendas: v, artigos } });
  };
  const erros = await abrir(page, '#inicio', { api });
  const vendasTile = page.locator('.tiles-kpi .tile', { hasText: 'Vendas RTM' });
  await expect(vendasTile.locator('.tile-var')).toHaveText(/^▲ /);
  await expect(vendasTile.locator('.tile-var')).toHaveClass(/bom/);
  await expect(vendasTile.locator('.tile-var')).toHaveAttribute('title', /mesmo período do mês anterior/);
  await expect(page.locator('.tiles-kpi .tile', { hasText: 'Facturas pendentes' }).locator('.tile-var')).toHaveClass(/mau/);
  await expect(vendasTile.locator('svg.spark polyline')).toHaveCount(1);
  expect(erros).toEqual([]);
});

test('vendas por artigo: preço unitário médio sem IVA = valor s/ IVA ÷ quantidade', async ({ page }) => {
  await abrir(page, '#vendas-artigo');
  const cab = await page.locator('#s-va thead th').allTextContents();
  expect(cab.join('|')).toContain('Preço unit. s/ IVA');
  const i = cab.findIndex((x) => x.includes('Preço unit. s/ IVA'));
  const linha = page.locator('#s-va tbody tr', { hasText: 'Fraldas' }).first();
  const preco = await linha.locator('td').nth(i).textContent();
  const ls = artigos.filter((a) => a.artigo === 'Fraldas' && a.data >= '2026-10-01');
  const esperado = ls.reduce((s, a) => s + a.valor_sem_iva, 0) / ls.reduce((s, a) => s + a.quantidade, 0);
  expect(parseFloat(preco.replace(/[^\d,]/g, '').replace(',', '.'))).toBeCloseTo(esperado, 1);
});

test('Vendas para o SAP fica em Integrações (fora de Recebimentos)', async ({ page }) => {
  await abrir(page, '#sap-exportar');
  await expect(page.locator('#nav .navg.atual')).toContainText('Integrações');
  await expect(page.locator('#titulo')).toHaveText('Vendas para o SAP');
});
