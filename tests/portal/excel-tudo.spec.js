// «Relatório consolidado»: um só Excel com resumo, relatórios, dados de base em tabelas do Excel e dicionário de dados.
import { test, expect } from '@playwright/test';
import { createRequire } from 'node:module';
import { abrir } from './dados.js';

const require = createRequire(import.meta.url);
const ExcelJS = require('exceljs');

test('relatório consolidado: folhas de dados com tabelas, tipos reais e dicionário', async ({ page }) => {
  await abrir(page, '#inicio');
  await expect(page.locator('#xl-all')).toContainText('Relatório consolidado');
  const [d] = await Promise.all([page.waitForEvent('download', { timeout: 60_000 }), page.click('#xl-all')]);
  const wb = new ExcelJS.Workbook();
  await wb.xlsx.readFile(await d.path());
  const nomes = wb.worksheets.map((w) => w.name);
  for (const n of ['Capa', 'Resumo', 'Dados · Documentos', 'Dados · Cobranças', 'Dados · Linhas de artigos', 'Dicionário de dados']) expect(nomes).toContain(n);
  const docs = wb.getWorksheet('Dados · Documentos');
  expect(docs.getRow(3).getCell(1).value).toBe('Data');
  expect(docs.getRow(4).getCell(1).value).toBeInstanceOf(Date);
  const total = docs.getRow(3).values.indexOf('Total (Kz)');
  expect(typeof docs.getRow(4).getCell(total).value).toBe('number');
  expect(docs.rowCount - 3).toBe(24);
  const dic = wb.getWorksheet('Dicionário de dados');
  expect(dic.rowCount).toBeGreaterThan(30);
  const capa = wb.getWorksheet('Capa');
  const textoCapa = [];
  capa.eachRow((r) => textoCapa.push(r.values.join(' ')));
  expect(textoCapa.join(' ')).toContain('Dados de base');
});
