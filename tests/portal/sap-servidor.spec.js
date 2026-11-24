// O ficheiro para o SAP gerado no navegador (página do SAP) e no servidor (envio automático das 16:00,
// nucleo/servicos/sap-ficheiro.php) tem de ser igual: os dois testes comparam a folha com a mesma referência,
// tests/dados/sap-ficheiro/folha.xml (SAP_GRAVAR=1 grava-a de novo a partir do navegador).
import { test, expect } from '@playwright/test';
import { readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { abrir } from './dados.js';

const require = createRequire(import.meta.url);
const JSZip = require('../../reports/vendor/jszip.min.js');
const CASO = JSON.parse(readFileSync('tests/dados/sap-ficheiro/caso.json', 'utf8'));
const FOLHA = 'tests/dados/sap-ficheiro/folha.xml';

test('ficheiro para o SAP: a folha do navegador é igual à referência partilhada com o servidor', async ({ page }) => {
  await page.route('**/v1/sap**', (r) => {
    if (r.request().method() === 'POST') return r.fulfill({ json: { ok: true, lote: { numero: 2, ficheiro: 'teste.xlsx' } } });
    return r.fulfill({ json: { config: CASO.config, origens: CASO.origens, lotes: [], exportados: CASO.exportados, pode_gerar: true } });
  });
  const erros = await abrir(page, '#sap-exportar', { api: (r) => r.fulfill({ json: { vendas: CASO.vendas, artigos: CASO.artigos } }) });
  await page.click('#titulo-acc button:has-text("Gerar ficheiro")');
  const [dl] = await Promise.all([page.waitForEvent('download'), page.click('dialog.dlg button:has-text("Gerar e descarregar")')]);
  const zip = await JSZip.loadAsync(readFileSync(await dl.path()));
  const xml = await zip.file('xl/worksheets/sheet1.xml').async('string');
  if (process.env.SAP_GRAVAR) writeFileSync(FOLHA, xml);
  expect(xml).toBe(readFileSync(FOLHA, 'utf8'));
  expect(erros).toEqual([]);
});

test('envio automático: a página guarda a hora e os destinatários com a correspondência', async ({ page }) => {
  const posts = [];
  await page.route('**/v1/sap**', (r) => {
    if (r.request().method() === 'POST') {
      const q = JSON.parse(r.request().postData());
      posts.push(q);
      return r.fulfill({ json: { ok: true, config: q.config } });
    }
    return r.fulfill({ json: { config: CASO.config, origens: CASO.origens, lotes: [], exportados: {}, pode_gerar: true } });
  });
  const erros = await abrir(page, '#sap-exportar', { api: (r) => r.fulfill({ json: { vendas: CASO.vendas, artigos: CASO.artigos } }) });
  // a secção abre pelo índice da página
  await page.getByRole('link', { name: 'Correspondência com o SAP' }).first().click();
  await page.getByLabel('Ligar o envio automático').check();
  await page.getByLabel('Enviar para').fill('sap@exemplo.com');
  await page.getByLabel('Avisar quando carregado').fill('equipa@exemplo.com');
  await page.getByRole('button', { name: 'Guardar correspondência' }).click();
  await expect.poll(() => posts.length).toBe(1);
  expect(posts[0].config.envio).toEqual({ activo: true, hora: '16:00', para: 'sap@exemplo.com', avisar: 'equipa@exemplo.com' });
  expect(erros).toEqual([]);
});

test('envio automático: o botão de teste envia com os endereços escritos e mostra o resultado de cada um', async ({ page }) => {
  const posts = [];
  await page.route('**/v1/sap**', (r) => {
    if (r.request().method() === 'POST') {
      posts.push(JSON.parse(r.request().postData()));
      return r.fulfill({
        json: {
          ok: true,
          teste: {
            para: { enderecos: ['sap@exemplo.com'], ok: true, erro: '' },
            avisar: { enderecos: ['equipa@exemplo.com'], ok: false, erro: 'domínio não autorizado' },
            ficheiro: { documentos: 3, linhas: 4, total: 5000 },
            sem_ligacao: false,
            modo_ficheiro: false,
          },
        },
      });
    }
    return r.fulfill({ json: { config: CASO.config, origens: CASO.origens, lotes: [], exportados: {}, pode_gerar: true } });
  });
  const erros = await abrir(page, '#sap-exportar', { api: (r) => r.fulfill({ json: { vendas: CASO.vendas, artigos: CASO.artigos } }) });
  await page.getByRole('link', { name: 'Correspondência com o SAP' }).first().click();
  await page.getByLabel('Enviar para').fill('sap@exemplo.com');
  await page.getByLabel('Avisar quando carregado').fill('equipa@exemplo.com');
  await page.getByRole('button', { name: 'Enviar email de teste' }).click();
  await expect(page.locator('.sap-envio-res')).toContainText('sap@exemplo.com): enviado');
  await expect(page.locator('.sap-envio-res')).toContainText('não seguiu: domínio não autorizado');
  await expect(page.locator('.sap-envio-res')).toContainText('3 documentos, 4 linhas');
  expect(posts[0]).toMatchObject({ accao: 'envio_teste', envio: { para: 'sap@exemplo.com', avisar: 'equipa@exemplo.com' } });
  expect(erros).toEqual([]);
});
