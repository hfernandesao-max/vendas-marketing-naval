// Vendas para o SAP: os quadros mostram os mesmos valores do Início (vendas, dinheiro, Multicaixa) e a conciliação
// explica, linha a linha, o que não segue no ficheiro (notas de crédito, já enviados, sem linhas): Início − exclusões = ficheiro.
import { test, expect } from '@playwright/test';
import { abrir, vendas } from './dados.js';

const quadro = (page, rotulo) =>
  page
    .locator('.tile')
    .filter({ has: page.locator(`.l:text-is("${rotulo}"), .l-t:text-is("${rotulo}")`) })
    .first()
    .locator('.v')
    .innerText();

test('SAP: vendas, dinheiro e Multicaixa iguais ao Início; conciliação fecha com o ficheiro', async ({ page }) => {
  const rotas = Object.fromEntries([...new Set(vendas.map((v) => v.rota))].map((r) => [r, { local: 'RTM' + r.slice(-1) }]));
  await page.route('**/v1/sap**', (r) =>
    r.fulfill({ json: { config: { prefixo: 'FHR PIV', moeda: 'AOA', unidade: 'CAR', rotas }, lotes: [], exportados: {}, pode_gerar: true } }),
  );
  await abrir(page, '#inicio');
  await expect(page.locator('.tiles-kpi')).toBeVisible();
  const inicio = {};
  for (const r of ['Vendas RTM', 'Em dinheiro', 'Em Multicaixa']) inicio[r] = await quadro(page, r);
  await page.evaluate(() => (location.hash = '#sap-exportar'));
  await expect(page.locator('#s-sap-conc')).toBeVisible();
  for (const r of ['Vendas RTM', 'Em dinheiro', 'Em Multicaixa']) expect(await quadro(page, r), r).toBe(inicio[r]);

  // Início − exclusões = ficheiro, em cada coluna
  const linhas = await page.$$eval('#s-sap-conc tbody tr', (trs) => trs.map((tr) => [...tr.cells].map((c) => c.innerText.trim())));
  const num = (s) =>
    +String(s)
      .replace(/[^\d,-]/g, '')
      .replace(',', '.') || 0;
  expect(linhas.length).toBeGreaterThanOrEqual(2);
  expect(linhas[0][1]).toContain('Início');
  expect(linhas[linhas.length - 1][1]).toContain('ficheiro do SAP');
  for (const c of [2, 3, 4, 5]) {
    const soma = linhas.slice(0, -1).reduce((s, l) => s + num(l[c]), 0);
    expect(Math.abs(soma - num(linhas[linhas.length - 1][c])), 'coluna ' + c).toBeLessThan(0.02);
  }
  if (vendas.some((v) => v.nc)) expect(linhas.map((l) => l[1]).join('|')).toContain('Notas de crédito');
});
