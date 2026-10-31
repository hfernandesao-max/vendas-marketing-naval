// O ficheiro para o SAP: um livro simples com UMA folha, UMA linha de cabeçalho com os nomes exactos das colunas
// e as linhas de dados, sem estilos. Invoice Total = soma do GROSSSALES das linhas do documento.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { abrir, vendas, artigos } from './dados.js';

const require = createRequire(import.meta.url);
const JSZip = require('../../reports/vendor/jszip.min.js');
const CAB = [
  'INVOICENO',
  'DOCUMENT_DATE',
  'LOJA',
  'PRODUCT',
  'QUANTITY',
  'UNIT',
  'DOCUMENT_TYPE',
  'STORAGELOCATION',
  'NETSALES',
  'TAXAMOUNT',
  'GROSSSALES',
  'CURRENCY',
  'Invoice Total',
  'CASH AMOUNT',
  'CARD AMOUNT',
  'CUSTOMER',
  'SALES EMPLOYEE',
];

test('gera o Excel no formato de carregamento do SAP: uma folha, um cabeçalho, sem estilos', async ({ page }) => {
  const rotas = Object.fromEntries([...new Set(vendas.map((v) => v.rota))].map((r) => [r, { local: 'RTM' + r.slice(-1) }]));
  await page.route('**/v1/sap**', (r) => {
    if (r.request().method() === 'POST') return r.fulfill({ json: { ok: true, lote: { numero: 1, ficheiro: 'teste.xlsx' } } });
    return r.fulfill({ json: { config: { prefixo: 'FHR PIV', moeda: 'AOA', unidade: 'CAR', rotas }, lotes: [], exportados: {}, pode_gerar: true } });
  });
  await abrir(page, '#sap-exportar');
  await page.click('.sap-barra button:has-text("Gerar ficheiro")');
  const [dl] = await Promise.all([page.waitForEvent('download'), page.click('dialog.dlg button:has-text("Gerar e descarregar")')]);
  if (process.env.SAP_SAIDA) await dl.saveAs(process.env.SAP_SAIDA); // cópia para inspecção manual
  const zip = await JSZip.loadAsync(readFileSync(await dl.path()));

  // uma só folha e nenhuma parte de estilos, tema ou fórmulas
  const livro = await zip.file('xl/workbook.xml').async('string');
  expect([...livro.matchAll(/<sheet /g)].length).toBe(1);
  expect(Object.keys(zip.files).filter((f) => f.startsWith('xl/worksheets/') && f.endsWith('.xml'))).toEqual(['xl/worksheets/sheet1.xml']);
  for (const f of ['xl/theme/theme1.xml', 'xl/calcChain.xml', 'xl/sharedStrings.xml']) expect(zip.file(f), f).toBeNull();
  // estilos: só os formatos de número (data e #,##0.00); sem negrito, cores, preenchimentos nem contornos
  const est = await zip.file('xl/styles.xml').async('string');
  expect(est).not.toMatch(/<b\/>|<color |fgColor|bgColor|<left style|<right style|<top style|<bottom style/);
  expect(est).toContain('formatCode="dd/mm/yyyy"');

  const xml = await zip.file('xl/worksheets/sheet1.xml').async('string');
  expect(xml).not.toMatch(/<cols>|<mergeCell|<f>|<sheetViews>/); // sem larguras, células unidas, fórmulas nem painéis
  expect(xml.match(/<row r="1">[\s\S]*?<\/row>/)[0]).not.toContain(' s="'); // cabeçalho simples
  const linhas = [...xml.matchAll(/<row r="(\d+)">([\s\S]*?)<\/row>/g)].map((m) =>
    Object.fromEntries(
      [...m[2].matchAll(/<c r="([A-Q])\d+"(?: s="\d")?(?: t="inlineStr")?>(?:<v>([^<]*)<\/v>|<is><t[^>]*>([^<]*)<\/t><\/is>)<\/c>/g)].map((c) => [
        c[1],
        c[2] ?? c[3],
      ]),
    ),
  );
  // linha 1: cabeçalho exactamente como o SAP espera
  expect('ABCDEFGHIJKLMNOPQ'.split('').map((c) => linhas[0][c])).toEqual(CAB);
  const n = artigos.filter((a) => a.data >= '2026-10-01').length;
  expect(linhas.length).toBe(n + 1);
  expect(xml).toContain(`<dimension ref="A1:Q${n + 1}"/>`);

  const dados = linhas.slice(1);
  for (const l of dados) {
    expect(+l.B).toBeGreaterThan(46000); // DOCUMENT_DATE: data do Excel (série), apresentada em dd/mm/aaaa
    expect(+l.K).toBeCloseTo(+l.I + +l.J, 2); // sem GROSS_TOTAL na fonte: GROSSSALES = NETSALES + TAXAMOUNT
  }
  // Invoice Total = soma do GROSSSALES das linhas do mesmo documento
  const porDoc = {};
  for (const l of dados) porDoc[l.A] = (porDoc[l.A] || 0) + +l.K;
  for (const l of dados) expect(+l.M).toBeCloseTo(porDoc[l.A], 2);
});
