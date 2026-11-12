// Vendas por promotor e artigo: matriz promotor × artigo, sortido de cada promotor (ABC), lacunas de sortido e detalhe
// com peso no promotor, quota no artigo, preço face à média da empresa, clientes e presença nos documentos.
import { test, expect } from '@playwright/test';
import { abrir, artigos, vendas } from './dados.js';

// A rota 1004 deixa de vender «Fraldas»: fica uma lacuna de sortido nesse artigo
const A = artigos.filter((a) => !(a.rota === '1004' && a.artigo === 'Fraldas'));
const api = (r) => r.fulfill({ json: { vendas, artigos: A } });

test('matriz, sortido, lacunas e detalhe por promotor e artigo', async ({ page }) => {
  const erros = [];
  page.on('pageerror', (e) => erros.push(e.message));
  await abrir(page, '#vendas-promotor-artigo', { api });
  await expect(page.locator('#s-vpa-matriz tbody tr')).toHaveCount(4);
  await expect(page.locator('#s-vpa-matriz thead')).toContainText('Fraldas');
  await expect(page.locator('#s-vpa-matriz thead')).toContainText('Sabão');
  await expect(page.locator('#s-vpa-matriz .pa-cel').first()).toHaveAttribute('title', /do promotor/);
  await expect(page.locator('#s-vpa-sortido tbody tr')).toHaveCount(4);

  // Lacuna: «Fraldas» sem venda na rota 1004
  const lac = page.locator('#s-vpa-lacunas tbody tr');
  await expect(lac).toHaveCount(1);
  await expect(lac.first()).toContainText('Fraldas');
  await expect(lac.first()).toContainText('1004');
  await expect(page.locator('.tile', { hasText: 'Lacunas de sortido' })).toContainText('1');

  // Detalhe: 3 rotas com os 2 artigos + a rota 1004 só com «Sabão» (100% do promotor e classe A)
  await page.click('a[href="#vendas-promotor-artigo/vpa"]');
  await page.fill('#q-vpa', '1004');
  const det = page.locator('#s-vpa tbody tr');
  await expect(det).toHaveCount(1);
  await expect(det.first()).toContainText('Sabão');
  await expect(det.first()).toContainText('100,0%');
  await expect(det.first()).toContainText('A');
  expect(erros).toEqual([]);
});

test('sem linhas de venda: aviso em vez de tabelas vazias', async ({ page }) => {
  await abrir(page, '#vendas-promotor-artigo', { api: (r) => r.fulfill({ json: { vendas, artigos: [] } }) });
  await expect(page.locator('#page')).toContainText('Sem linhas de venda no período e filtros escolhidos.');
});
