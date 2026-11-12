// «Excel desta página» em todas as páginas com dados (os cadastros dependem de um ficheiro privado, fora do GitHub); inactivo (com explicação) quando não há nada a exportar.
import { test, expect } from '@playwright/test';
import { createRequire } from 'node:module';
import { abrir } from './dados.js';

const require = createRequire(import.meta.url);
const ExcelJS = require('exceljs');

const PAGINAS = [
  'vendas-promotor',
  'vendas-dia',
  'vendas-cliente',
  'vendas-artigo',
  'vendas-promotor-artigo',
  'ranking',
  'documentos',
  'pagamentos',
  'pendentes',
  'clientes',
  'plano-visitas',
  'auditoria',
  'registo-cargas',
];
test('todas as páginas com dados exportam para Excel (capa, resumo e as tabelas da página)', async ({ page }) => {
  test.setTimeout(240_000);
  for (const p of PAGINAS) {
    await abrir(page, '#' + p);
    await expect(page.locator('#xl-page'), p).toBeEnabled();
    const [d] = await Promise.all([page.waitForEvent('download', { timeout: 30_000 }), page.click('#xl-page')]);
    const wb = new ExcelJS.Workbook();
    await wb.xlsx.readFile(await d.path());
    expect(wb.worksheets.length, p).toBeGreaterThanOrEqual(3);
  }
});

test('página sem dados: o botão fica inactivo e explica porquê', async ({ page }) => {
  await abrir(page, '#vendas-marca');
  await expect(page.locator('#xl-page')).toBeDisabled();
  await expect(page.locator('#xl-page')).toHaveAttribute('title', 'Esta página ainda não tem dados para exportar.');
});

test('sincronização dos smartphones: o Excel inclui a última sincronização por rota', async ({ page }) => {
  await page.route('**/v1/sincronizacao*', (r) =>
    r.fulfill({
      json: {
        disponivel: true,
        agora: '2026-10-26 12:00:00',
        limites: { alerta_horas: 2, critico_horas: 24 },
        rotas: [{ rota: '1001', descricao: '1001 - FHR PIV', promotor: 'Ana', ultima: '2026-10-26 11:30:00', minutos: 30, horas: 0.5, estado: 'em_dia' }],
      },
    }),
  );
  await abrir(page, '#sincronizacao');
  await expect(page.locator('#xl-page')).toBeEnabled();
  const [d] = await Promise.all([page.waitForEvent('download'), page.click('#xl-page')]);
  const wb = new ExcelJS.Workbook();
  await wb.xlsx.readFile(await d.path());
  const nomes = wb.worksheets.map((w) => w.name);
  expect(nomes.some((n) => n.startsWith('Última sincronização por'))).toBe(true); // nome da folha limitado pelo Excel
  expect(nomes).not.toContain('Sincronizações por dia');
});
