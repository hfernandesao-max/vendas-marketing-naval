// O ficheiro para o SAP sai do próprio modelo: estilos, tema, livro e linhas 1–2 iguais ao original;
// cada linha de dados com a formatação da linha de exemplo do modelo.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { abrir, vendas, artigos } from './dados.js';

const require = createRequire(import.meta.url);
const JSZip = require('../../reports/vendor/jszip.min.js');
const MODELO = 'reports/modelos/RTM_Sales_SAP_Upload_Format.xlsx';

test('gera o Excel igual ao modelo do SAP', async ({ page }) => {
  const rotas = Object.fromEntries([...new Set(vendas.map((v) => v.rota))].map((r) => [r, { local: 'RTM' + r.slice(-1) }]));
  await page.route('**/data/sap.php**', (r) => {
    const u = r.request().url();
    if (u.includes('modelo=1'))
      return r.fulfill({ body: readFileSync(MODELO), contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    if (r.request().method() === 'POST') return r.fulfill({ json: { ok: true, lote: { numero: 1, ficheiro: 'teste.xlsx' } } });
    return r.fulfill({ json: { config: { prefixo: 'FHR PIV', moeda: 'AOA', unidade: 'CAR', rotas }, lotes: [], exportados: {}, pode_gerar: true } });
  });
  await abrir(page, '#sap-exportar');
  await page.click('.sap-barra button:has-text("Gerar ficheiro")');
  const [dl] = await Promise.all([page.waitForEvent('download'), page.click('dialog.dlg button:has-text("Gerar e descarregar")')]);
  const gerado = await JSZip.loadAsync(readFileSync(await dl.path()));
  const modelo = await JSZip.loadAsync(readFileSync(MODELO));

  for (const f of ['xl/styles.xml', 'xl/theme/theme1.xml', 'xl/workbook.xml', 'docProps/app.xml']) {
    expect(await gerado.file(f).async('string'), f).toBe(await modelo.file(f).async('string'));
  }
  const fg = await gerado.file('xl/worksheets/sheet1.xml').async('string');
  const fm = await modelo.file('xl/worksheets/sheet1.xml').async('string');
  for (const n of ['1', '2']) expect(fg.match(new RegExp(`<row r="${n}"[\\s\\S]*?</row>`))[0]).toBe(fm.match(new RegExp(`<row r="${n}"[\\s\\S]*?</row>`))[0]);
  // estilos por coluna da linha de exemplo do modelo (linha 3)
  const estilos = (xml, r) =>
    [...xml.match(new RegExp(`<row r="${r}"[\\s\\S]*?</row>`))[0].matchAll(/<c r="([A-Q])\d+" s="(\d+)"/g)].map((m) => m[1] + m[2]).join();
  const linhas = artigos.filter((a) => a.data >= '2026-10-01').length;
  expect(fg).toContain(`<dimension ref="A1:Q${linhas + 2}"/>`);
  for (let r = 3; r < linhas + 3; r++) expect(estilos(fg, r)).toBe(estilos(fm, 3));
  expect(gerado.file('xl/calcChain.xml')).toBeNull();
});
