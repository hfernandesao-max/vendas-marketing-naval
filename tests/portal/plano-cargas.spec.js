// Plano de cargas produzido na plataforma: exportar Excel (rascunho identificado ou versão publicada),
// publicar com ou sem envio por email e enviar por email aos destinatários escolhidos.
import { test, expect } from '@playwright/test';
import { createRequire } from 'node:module';
import { abrir } from './dados.js';

const require = createRequire(import.meta.url);
const ExcelJS = require('exceljs');

const ROTA = {
  rota: '1001',
  vendedor: 'Ana',
  sai: true,
  armazem: 'HIGITEC',
  viagens: 1,
  zonas: ['Samba'],
  quantidades: { fraldas: 90, sabao: 10 },
  motorista: 'Adão Paulo',
  telefone: '924516916',
  matricula: 'LD-58-73-GY',
  viatura: 'Canter',
  transportadora: 'Velotrans',
  factura: 'FT 1',
  obs: '',
};
const resposta = (plano) => ({
  data: '2026-10-26',
  plano,
  anterior: null,
  rotas: [{ rota: '1001', promotor: 'Ana' }],
  listas: {
    artigos: [
      { id: 'fraldas', nome: 'Fraldas' },
      { id: 'sabao', nome: 'Sabão' },
    ],
    destinatarios: [
      { nome: 'Transportes', email: 'transportes@naval.co.ao' },
      { nome: 'Armazém', email: 'armazem@naval.co.ao' },
    ],
    motoristas: [{ nome: 'Adão Paulo' }],
    viaturas: ['Canter'],
    transportadoras: ['Velotrans'],
    armazens: ['HIGITEC'],
    zonas: ['Samba'],
  },
  gere: true,
  exportar: true,
  email_configurado: true,
  ligacao: '',
});
const RASCUNHO = { data: '2026-10-26', rev: 3, rotas: [ROTA], publicado: null, envios: [], alterado: '2026-10-26 07:50:00' };
const PUBLICADO = {
  ...RASCUNHO,
  publicado: { versao: 1, rev: 3, em: '2026-10-26 08:00:00', por: 'log', por_nome: 'Logística', nota: '', rotas: [ROTA] },
};

async function abrirPlano(page, plano, pedidos) {
  await page.route('**/data/plano.php**', (r) => {
    const q = r.request();
    if (q.method() === 'POST') {
      const corpo = q.postDataJSON();
      pedidos.push(corpo);
      if (corpo.accao === 'publicar') return r.fulfill({ json: { ok: true, plano: PUBLICADO, email: corpo.enviar ? { ok: true, para: corpo.para } : null } });
      if (corpo.accao === 'enviar') return r.fulfill({ json: { ok: true, plano: PUBLICADO, email: { ok: true, para: corpo.para } } });
      return r.fulfill({ json: { ok: true, plano } });
    }
    if (q.url().includes('historico')) return r.fulfill({ json: { dias: [] } });
    return r.fulfill({ json: resposta(plano) });
  });
  await page.route('**/data/sessao.php*', (r) => r.fulfill({ json: { csrf: 'x' } }));
  return abrir(page, '#plano-cargas');
}

test('rascunho: exportar Excel identificado como não publicado; publicar sem enviar por email', async ({ page }) => {
  const pedidos = [];
  const erros = await abrirPlano(page, RASCUNHO, pedidos);
  await expect(page.locator('.pc-dcampo .dv')).toHaveText('26-10-2026');
  await expect(page.locator('#pc-enviar')).toHaveCount(0);
  const [d] = await Promise.all([page.waitForEvent('download'), page.click('#pc-excel')]);
  expect(d.suggestedFilename()).toBe('PLANO_DE_CARGAS_26.10.2026_RASCUNHO.xlsx');
  const wb = new ExcelJS.Workbook();
  await wb.xlsx.readFile(await d.path());
  expect(String(wb.worksheets[0].getCell('A1').value)).toContain('RASCUNHO — NÃO PUBLICADO');
  await expect.poll(() => pedidos.some((p) => p.accao === 'exportado')).toBe(true);

  await page.click('#pc-publicar');
  const dlg = page.locator('dialog[open]');
  await expect(dlg.getByRole('group', { name: 'Destinatários' })).toBeVisible();
  await dlg.getByText('Enviar por email agora').click();
  await expect(dlg.getByRole('group', { name: 'Destinatários' })).toBeHidden();
  await dlg.getByRole('button', { name: 'Publicar sem enviar' }).click();
  await expect.poll(() => pedidos.find((p) => p.accao === 'publicar')).toMatchObject({ enviar: false, para: [] });
  expect(erros).toEqual([]);
});

test('publicado: enviar por email só aos destinatários escolhidos', async ({ page }) => {
  const pedidos = [];
  await abrirPlano(page, PUBLICADO, pedidos);
  await page.click('#pc-enviar');
  const dlg = page.locator('dialog[open]');
  await dlg.getByLabel('Transportes · transportes@naval.co.ao').uncheck();
  await dlg.getByRole('button', { name: 'Enviar', exact: true }).click();
  await expect.poll(() => pedidos.find((p) => p.accao === 'enviar')).toMatchObject({ data: '2026-10-26', para: ['armazem@naval.co.ao'] });
});
