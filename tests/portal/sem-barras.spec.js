// Tudo dentro dos limites: os painéis laterais (fichas da rota, do cliente, do artigo, do documento e a ajuda) nunca
// têm barra de deslocação horizontal; os quadros ajustam-se à largura e os nomes longos passam à linha seguinte.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';
import { readFileSync } from 'node:fs';

const PERDAS = {
  ok: true,
  rotas: [
    {
      rota: '1001',
      perdas_kz: 0,
      perdas_pct: 0,
      falta_kz: 0,
      entrega_dif_kz: 0,
      parado_min: 0,
      parado_kz: null,
      km: 0,
      nc_kz: 0,
      desconto_kz: 0,
      anulados_kz: 0,
      anulados_n: 0,
    },
  ],
};

/** Largura a mais do corpo do painel (0 = cabe tudo) e elementos que saem fora dele. */
const excesso = (d) =>
  d.evaluate((dlg) => {
    const b = dlg.querySelector('.dlg-b');
    const r = b.getBoundingClientRect();
    const fora = [...b.querySelectorAll('*')]
      .filter((e) => {
        const x = e.getBoundingClientRect();
        return x.width > 0 && (x.right > r.right + 1 || x.left < r.left - 1);
      })
      .slice(0, 5)
      .map((e) => e.tagName + '.' + e.className + ' ' + (e.textContent || '').slice(0, 40));
    return { a_mais: b.scrollWidth - b.clientWidth, fora };
  });

for (const [w, h] of [
  [1366, 768],
  [800, 900],
  [1920, 1080],
  [390, 844],
]) {
  test(`${w}×${h}: fichas da rota, do cliente e do artigo sem barra horizontal`, async ({ page }) => {
    await page.setViewportSize({ width: w, height: h });
    await page.route('**/v1/perdas**', (r) => r.fulfill({ json: PERDAS }));
    const erros = await abrir(page, '#vendas-promotor');
    for (const tipo of ['rota', 'cliente', 'artigo', 'documento']) {
      const b = page.locator(`#page button.lig-${tipo}:visible`).first();
      if (!(await b.count())) continue;
      await b.scrollIntoViewIfNeeded();
      await b.click();
      const d = page.locator('dialog.lig-dlg[open], dialog[open]').first();
      await expect(d).toBeVisible();
      await page.waitForTimeout(400);
      expect(await excesso(d), `ficha ${tipo}`).toEqual({ a_mais: 0, fora: [] });
      // nomes reais longos (clientes e artigos do Pinguim BO) também cabem: partem em várias linhas
      await d.evaluate((dlg) =>
        dlg.querySelectorAll('.dlg-b .lig').forEach((x) => {
          if (!/^\d/.test(x.textContent)) x.textContent = 'ROBEL SIMON - COMERCIO GERAL,(SU),LDA · SABÃO VERDE EM BARRA 8UN CONFIANCA 16×1.3kg';
        }),
      );
      expect(await excesso(d), `ficha ${tipo} com nomes longos`).toEqual({ a_mais: 0, fora: [] });
      await page.keyboard.press('Escape');
      await expect(page.locator('dialog[open]')).toHaveCount(0);
    }
    expect(erros).toEqual([]);
  });
}

// Todas as páginas do catálogo, no computador e no telemóvel: nenhuma caixa com barra de deslocação própria
// (horizontal ou vertical) e a página nunca mais larga do que o ecrã.
const IDS = [...readFileSync('reports/js/paginas/catalogo.js', 'utf8').matchAll(/\{ id: '([a-z0-9-]+)'/g)].map((m) => m[1]);
for (const [w, h] of [
  [1366, 768],
  [390, 844],
]) {
  test(`${w}×${h}: nenhuma página com barras de deslocação internas`, async ({ page }) => {
    test.setTimeout(240000);
    await page.setViewportSize({ width: w, height: h });
    await abrir(page, '#inicio');
    const com = [];
    for (const id of IDS) {
      await page.evaluate((x) => (location.hash = '#' + x), id);
      await page.waitForTimeout(500);
      const r = await page.evaluate(() => {
        const caixas = [...document.querySelectorAll('#page *')]
          .filter((e) => {
            const s = getComputedStyle(e);
            const hx = ['auto', 'scroll'].includes(s.overflowX) && e.clientWidth > 0 && e.scrollWidth > e.clientWidth + 1;
            const hy = ['auto', 'scroll'].includes(s.overflowY) && e.clientHeight > 0 && e.scrollHeight > e.clientHeight + 1;
            return hx || hy;
          })
          .map((e) => e.tagName + '.' + String(e.className).slice(0, 30));
        if (document.documentElement.scrollWidth > innerWidth) caixas.push('página mais larga do que o ecrã');
        return caixas;
      });
      if (r.length) com.push(id + ': ' + r.join(', '));
    }
    expect(com).toEqual([]);
  });
}
