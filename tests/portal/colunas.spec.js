// Colunas à medida de cada pessoa, em todas as tabelas do portal: escolher (botão direito no cabeçalho ou botão
// «Colunas»), ordenar (arrastar ou ↑ ↓), dimensionar (arrastar a borda; duplo clique repõe), guardado na conta.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const cabecalhos = (page) => page.locator('#s-vp thead th button').allTextContents();
const nomes = async (page) => (await cabecalhos(page)).map((x) => x.replace(/ [↑↓]$/, ''));

test('colunas: esconder e mostrar com o botão direito, ordenar, repor; fica guardado e vai para a conta', async ({ page }) => {
  const enviados = [];
  await page.route('**/v1/preferencias**', (r) => {
    if (r.request().method() === 'POST') enviados.push(r.request().postDataJSON());
    return r.fulfill({ json: { ok: true, preferencias: {} } });
  });
  await page.setViewportSize({ width: 1600, height: 900 });
  const erros = await abrir(page, '#vendas-promotor');
  const antes = await nomes(page);
  expect(antes).toContain('Documentos');
  // botão direito no cabeçalho abre o menu das colunas
  await page.locator('#s-vp thead th', { hasText: 'Documentos' }).click({ button: 'right' });
  const menu = page.getByRole('dialog', { name: /Colunas da tabela/ });
  await expect(menu).toBeVisible();
  // mover «Promotor» para baixo (alternativa ao arrastar, pelo teclado)
  const iP = antes.indexOf('Promotor');
  await menu.getByRole('button', { name: 'Mover para baixo: Promotor' }).click();
  const depois = await nomes(page);
  expect(depois.indexOf('Promotor')).toBe(iP + 1);
  expect(depois[iP]).toBe(antes[iP + 1]);
  await menu.getByRole('checkbox', { name: 'Documentos', exact: true }).uncheck();
  expect(await nomes(page)).not.toContain('Documentos');
  await page.keyboard.press('Escape');
  await expect(menu).toHaveCount(0);
  // fica guardado: ao voltar à página, as colunas vêm como as deixou
  await page.evaluate(() => (location.hash = '#inicio'));
  await page.evaluate(() => (location.hash = '#vendas-promotor'));
  await expect(page.locator('#s-vp thead th', { hasText: 'Promotor' })).toBeVisible();
  expect(await nomes(page)).not.toContain('Documentos');
  // e vai para a conta (igual em qualquer computador)
  await expect.poll(() => enviados.length).toBeGreaterThan(0);
  const ult = enviados[enviados.length - 1].colunas['vendas-promotor:vp'];
  expect(ult.h).toContain('docs');
  // repor
  await page.getByRole('button', { name: 'Colunas', exact: true }).first().click();
  await page.getByRole('dialog').getByRole('button', { name: 'Repor as colunas' }).click();
  expect(await nomes(page)).toEqual(antes);
  expect(erros).toEqual([]);
});

test('colunas: arrastar o nome muda o lugar; arrastar a borda muda a largura e o duplo clique repõe', async ({ page }) => {
  await page.route('**/v1/preferencias**', (r) => r.fulfill({ json: { ok: true, preferencias: {} } }));
  await page.setViewportSize({ width: 1600, height: 900 });
  await abrir(page, '#vendas-promotor');
  const antes = await nomes(page);
  const doc = page.locator('#s-vp thead th', { hasText: 'Documentos' });
  const rota = page.locator('#s-vp thead th').first();
  await doc.dragTo(rota, { targetPosition: { x: 2, y: 5 } });
  const depois = await nomes(page);
  expect(depois[0]).toBe('Documentos');
  expect(depois.length).toBe(antes.length);
  // largura
  const th = page.locator('#s-vp thead th', { hasText: 'Promotor' });
  const w0 = (await th.boundingBox()).width;
  const pega = th.locator('.col-res');
  const b = await pega.boundingBox();
  await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2);
  await page.mouse.down();
  await page.mouse.move(b.x + b.width / 2 + 120, b.y + b.height / 2, { steps: 5 });
  await page.mouse.up();
  const w1 = (await th.boundingBox()).width;
  expect(w1).toBeGreaterThan(w0 + 80);
  await page.locator('#s-vp thead th', { hasText: 'Promotor' }).locator('.col-res').dblclick();
  const w2 = (await page.locator('#s-vp thead th', { hasText: 'Promotor' }).boundingBox()).width;
  expect(w2).toBeLessThan(w1 - 60);
});
