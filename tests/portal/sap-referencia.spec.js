// Estrutura do ficheiro para o SAP congelada: o livro gerado com dados fixos tem de ser igual, parte a parte e
// carácter a carácter, ao de referência (tests/referencia/sap/). Regra do negócio: «a estrutura do ficheiro gerado
// para o SAP não pode sofrer nenhum tipo de alteração». Qualquer mudança em sap.js que altere o ficheiro — colunas,
// ordem, nomes, formatos, folha, estilos — falha aqui e trava a versão antes de chegar ao servidor.
// Uma mudança pedida pelo negócio e validada no SAP: gerar de novo com SAP_REFERENCIA_GRAVAR=1 e rever a diferença.
import { test, expect } from '@playwright/test';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { abrir, vendas } from './dados.js';

const require = createRequire(import.meta.url);
const JSZip = require('../../reports/vendor/jszip.min.js');
const REF = join(dirname(fileURLToPath(import.meta.url)), '..', 'referencia', 'sap');

test('ficheiro para o SAP: igual, carácter a carácter, ao ficheiro de referência', async ({ page }) => {
  const rotas = Object.fromEntries([...new Set(vendas.map((v) => v.rota))].map((r) => [r, { local: 'RTM' + r.slice(-1) }]));
  await page.route('**/v1/sap**', (r) => {
    if (r.request().method() === 'POST') return r.fulfill({ json: { ok: true, lote: { numero: 1, ficheiro: 'teste.xlsx' } } });
    return r.fulfill({ json: { config: { prefixo: 'FHR PIV', moeda: 'AOA', unidade: 'CAR', rotas }, lotes: [], exportados: {}, pode_gerar: true } });
  });
  await abrir(page, '#sap-exportar');
  await page.click('.sap-barra button:has-text("Gerar ficheiro")');
  const [dl] = await Promise.all([page.waitForEvent('download'), page.click('dialog.dlg button:has-text("Gerar e descarregar")')]);
  const zip = await JSZip.loadAsync(readFileSync(await dl.path()));
  const partes = Object.keys(zip.files)
    .filter((f) => !zip.files[f].dir)
    .sort();

  if (process.env.SAP_REFERENCIA_GRAVAR === '1') {
    for (const f of partes) {
      const destino = join(REF, f.replace(/[[\]]/g, '_'));
      mkdirSync(dirname(destino), { recursive: true });
      writeFileSync(destino, await zip.file(f).async('string'));
    }
    writeFileSync(join(REF, 'partes.json'), JSON.stringify(partes, null, 2) + '\n');
  }
  expect(existsSync(join(REF, 'partes.json')), 'falta a referência: gerar com SAP_REFERENCIA_GRAVAR=1').toBe(true);
  expect(partes).toEqual(JSON.parse(readFileSync(join(REF, 'partes.json'), 'utf8')));
  for (const f of partes) {
    const esperado = readFileSync(join(REF, f.replace(/[[\]]/g, '_')), 'utf8');
    expect(await zip.file(f).async('string'), 'parte ' + f + ' do ficheiro para o SAP mudou').toBe(esperado);
  }
});
