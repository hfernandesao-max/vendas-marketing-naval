// Vendas para o SAP: o total, o numerário e o Multicaixa são os do Início, e são exactamente esses que seguem no ficheiro
// (notas de crédito incluídas, a negativo). A estrutura do ficheiro não muda.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { abrir, vendas, artigos } from './dados.js';

const require = createRequire(import.meta.url);
const JSZip = require('../../reports/vendor/jszip.min.js');

const quadro = (page, rotulo) =>
  page
    .locator('.tile')
    .filter({ has: page.locator(`.l:text-is("${rotulo}"), .l-t:text-is("${rotulo}")`) })
    .first()
    .locator('.v')
    .innerText();

// quadro do SAP → quadro do Início com o mesmo valor
const PARES = [
  ['Total facturado', 'Vendas líquidas s/ IVA'],
  ['Numerário', 'Em dinheiro'],
  ['Multicaixa', 'Em Multicaixa'],
];

test('SAP: total, numerário e Multicaixa iguais ao Início', async ({ page }) => {
  const rotas = Object.fromEntries([...new Set(vendas.map((v) => v.rota))].map((r) => [r, { local: 'RTM' + r.slice(-1) }]));
  await page.route('**/v1/sap**', (r) =>
    r.fulfill({ json: { config: { prefixo: 'FHR PIV', moeda: 'AOA', unidade: 'CAR', rotas }, lotes: [], exportados: {}, pode_gerar: true } }),
  );
  await abrir(page, '#inicio');
  await expect(page.locator('.tiles-kpi')).toBeVisible();
  const ini = {};
  for (const [, r] of PARES) ini[r] = await quadro(page, r);
  await page.evaluate(() => (location.hash = '#sap-exportar'));
  await expect(page.locator('#s-sap-l')).toBeVisible();
  for (const [s, r] of PARES) expect(await quadro(page, s), s).toBe(ini[r]);
});

test('SAP: nota de crédito no ficheiro (a negativo, devolução em numerário): totais do ficheiro = Início', async ({ page }) => {
  const f = vendas.find((v) => !v.nc && (v.pagamentos || []).some((p) => p.codigo === 'NU'));
  const la = artigos.find((a) => a.documento === f.documento);
  const nc = {
    ...f,
    chave: 'NC T/1',
    documento: 'NC T/1',
    tipo: 'Nota de crédito',
    tipo_codigo: 'NC',
    nc: true,
    total: -1000,
    valor_pago: 0,
    pagamentos: [{ codigo: 'NU', meio: 'Numerário', valor: -1000 }],
  };
  const linhaNc = { ...la, chave: 'NC T/1|1', documento: 'NC T/1', quantidade: -1, valor_sem_iva: -1000, iva: 0, total: -1000, gross_total: -1000, nc: true };
  const rotas = Object.fromEntries([...new Set(vendas.map((v) => v.rota))].map((r) => [r, { local: 'RTM' + r.slice(-1) }]));
  await page.route('**/v1/sap**', (r) => {
    if (r.request().method() === 'POST') return r.fulfill({ json: { ok: true, lote: { numero: 1, ficheiro: 'teste.xlsx' } } });
    return r.fulfill({ json: { config: { prefixo: 'FHR PIV', moeda: 'AOA', unidade: 'CAR', rotas }, lotes: [], exportados: {}, pode_gerar: true } });
  });
  const api = (r) => r.fulfill({ json: { vendas: [...vendas, nc], artigos: [...artigos, linhaNc] } });
  await abrir(page, '#inicio', { api });
  await expect(page.locator('.tiles-kpi')).toBeVisible();
  const ini = {};
  for (const [, r] of PARES) ini[r] = await quadro(page, r);
  const subIni = await page
    .locator('.tile')
    .filter({ has: page.locator('.l:text-is("Vendas líquidas s/ IVA"), .l-t:text-is("Vendas líquidas s/ IVA")') })
    .first()
    .locator('.s')
    .innerText();
  await page.evaluate(() => (location.hash = '#sap-exportar'));
  await expect(page.locator('#s-sap-l')).toBeVisible();
  for (const [s, r] of PARES) expect(await quadro(page, s), s).toBe(ini[r]);
  // número de documentos e notas de crédito como no Início
  const sap = page.locator('.tile').filter({ has: page.locator('.l:text-is("Documentos a incluir")') });
  expect(await sap.locator('.v').innerText()).toBe(subIni.match(/([\d\s]+) documentos/)[1].trim());
  expect(await sap.locator('.s').innerText()).toContain(subIni.match(/notas de crédito: −[^·]+/)[0].trim());

  await page.click('.sap-barra button:has-text("Gerar ficheiro")');
  const [dl] = await Promise.all([page.waitForEvent('download'), page.click('dialog.dlg button:has-text("Gerar e descarregar")')]);
  const zip = await JSZip.loadAsync(readFileSync(await dl.path()));
  const xml = await zip.file('xl/worksheets/sheet1.xml').async('string');
  const rows = [...xml.matchAll(/<row r="(\d+)">([\s\S]*?)<\/row>/g)].map((m) =>
    Object.fromEntries(
      [...m[2].matchAll(/<c r="([A-Q])\d+"(?: s="\d")?(?: t="inlineStr")?>(?:<v>([^<]*)<\/v>|<is><t[^>]*>([^<]*)<\/t><\/is>)<\/c>/g)].map((c) => [
        c[1],
        c[2] ?? c[3],
      ]),
    ),
  );
  const dados = rows.slice(1);
  // Invoice Total (M), CASH (N) e CARD (O) repetem-se por linha: um valor por documento (A)
  const porDoc = {};
  dados.forEach((r) => (porDoc[r.A] = r));
  const docs = Object.values(porDoc);
  const kz = (v) => Math.round(v * 100);
  const txt = (s) =>
    kz(
      +String(s)
        .replace(/[^\d,-]/g, '')
        .replace(',', '.'),
    );
  expect(kz(docs.reduce((s, r) => s + (+r.M || 0), 0))).toBe(txt(ini['Vendas líquidas s/ IVA']));
  expect(kz(docs.reduce((s, r) => s + (+r.N || 0), 0))).toBe(txt(ini['Em dinheiro']));
  expect(kz(docs.reduce((s, r) => s + (+r.O || 0), 0))).toBe(txt(ini['Em Multicaixa']));
  const linhaNcXml = dados.find((r) => String(r.A).includes('NC T/1'));
  expect(linhaNcXml, 'a nota de crédito segue no ficheiro').toBeTruthy();
  expect(+linhaNcXml.M).toBe(-1000);
  expect(+linhaNcXml.N).toBe(-1000);
});
