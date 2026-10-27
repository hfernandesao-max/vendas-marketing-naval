// «Exportar dados»: todos os dados de gestão do negócio num só Excel, desde o arranque do Pinguim BO.
import { test, expect } from '@playwright/test';
import { createRequire } from 'node:module';
import { abrir, vendas } from './dados.js';

const require = createRequire(import.meta.url);
const ExcelJS = require('exceljs');

test('exportar dados: todo o histórico, sem os filtros das páginas, em tabelas do Excel com dicionário', async ({ page }) => {
  const pedidos = [];
  page.on('request', (r) => {
    if (r.url().includes('/data/api.php')) pedidos.push(r.url());
  });
  // período aberto no portal: só hoje — a exportação de dados não depende dele
  const erros = await abrir(page, '#vendas-dia', { periodo: null });
  await expect(page.locator('#xl-dados')).toContainText('Exportar dados');
  await expect(page.locator('#xl-all')).toContainText('Relatório consolidado');
  const [d] = await Promise.all([page.waitForEvent('download', { timeout: 60_000 }), page.click('#xl-dados')]);
  expect(d.suggestedFilename()).toBe('RTM - Dados de Gestão-26-10-2026.xlsx');
  expect(pedidos.some((u) => u.includes('start_date=20261020') && u.includes('end_date=20261026'))).toBe(true);
  const wb = new ExcelJS.Workbook();
  await wb.xlsx.readFile(await d.path());
  const nomes = wb.worksheets.map((w) => w.name);
  expect(nomes[0]).toBe('Capa');
  for (const n of ['Dados · Documentos', 'Dados · Cobranças', 'Dados · Linhas de artigos', 'Dicionário de dados']) expect(nomes).toContain(n);
  expect(nomes).not.toContain('Resumo');
  const docs = wb.getWorksheet('Dados · Documentos');
  expect(docs.rowCount - 3).toBe(vendas.length);
  expect(docs.getRow(4).getCell(1).value).toBeInstanceOf(Date);
  const capa = [];
  wb.getWorksheet('Capa').eachRow((r) => capa.push(r.values.map((v) => (v && v.text) || v).join(' ')));
  const txt = capa.join(' ');
  expect(txt).toContain('RTM - Dados de Gestão');
  expect(txt).toContain('20-10-2026 – 26-10-2026');
  expect(txt).toContain('Confidencial');
  expect(txt).toContain('Dados · Documentos');
  expect(erros).toEqual([]);
});
