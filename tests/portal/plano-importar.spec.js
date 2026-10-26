// Plano de cargas: importar o Excel no formato antigo (blocos por vendedor com células unidas) e associar às rotas.
import { test, expect } from '@playwright/test';
import { createRequire } from 'node:module';
import { abrir } from './dados.js';

const require = createRequire(import.meta.url);
const ExcelJS = require('exceljs');

async function excelAntigo() {
  const wb = new ExcelJS.Workbook(),
    ws = wb.addWorksheet('GENERAL SALES PLAN');
  ws.getCell('A1').value = 'GENERAL SALES PLAN (DATE: 26/10/26)';
  ws.getRow(2).values = [
    'REQUESTED DATE',
    'SALES PERSON',
    'LOADING',
    'DISCHARGED',
    'NUMBER OF TRIPS',
    'ITEM',
    'QUANTITY',
    'DRIVER ',
    'PHONE NUMBER',
    'PLATE',
    'VEHICLE TYPE',
    'COMPANY',
    'INVOICE NUMBER',
  ];
  const bloco = (ini, vend, zonas, q, mot, tel, mat) => {
    const n = Math.max(zonas.length, 3);
    ws.getCell(ini, 1).value = new Date(Date.UTC(2026, 9, 26));
    ws.getCell(ini, 2).value = vend;
    ws.getCell(ini, 3).value = 'HIGITEC';
    ws.getCell(ini, 5).value = 1;
    ws.getCell(ini, 8).value = mot;
    ws.getCell(ini, 9).value = tel;
    ws.getCell(ini, 10).value = mat;
    ws.getCell(ini, 11).value = 'CANTER';
    ws.getCell(ini, 12).value = 'VELOTRANS ';
    zonas.forEach((z, i) => {
      ws.getCell(ini + i, 4).value = z;
    });
    ['FRALDAS', 'SABÃO ', 'SABONETE'].forEach((it, i) => {
      ws.getCell(ini + i, 6).value = it;
      ws.getCell(ini + i, 7).value = q[i];
    });
    [1, 2, 3, 5, 8, 9, 10, 11, 12].forEach((c) => ws.mergeCells(ini, c, ini + n - 1, c));
    return ini + n + 1;
  };
  let l = bloco(3, 'ANDRÉA SANTOS', ['AVÔ-CUMBE', 'SAMBA', 'CASSENDA'], [91, 132, 5], 'ADÃO PAULO', 924516916, 'LD-58-73-GY');
  bloco(l, 'AWET HAILE', ['SÃO PAULO', 'BOA FÉ'], [160, 125, 0], 'ADELINO CHILELA', 927430004, 'LD-58-69-GY');
  return Buffer.from(await wb.xlsx.writeBuffer());
}

test('importa as rotas do Excel antigo, pelo nome do vendedor e, se preciso, pela viatura', async ({ page }) => {
  const resp = {
    data: '2026-10-26',
    plano: null,
    anterior: null,
    gere: true,
    exportar: true,
    email_configurado: false,
    ligacao: '',
    rotas: [
      { rota: '1001', promotor: 'Andrea Santos' },
      { rota: '1007', promotor: 'Awet Weldemenkorios' },
    ],
    listas: {
      armazens: ['HIGITEC'],
      artigos: [
        { id: 'fraldas', nome: 'Fraldas' },
        { id: 'sabao', nome: 'Sabão' },
        { id: 'sabonete', nome: 'Sabonete' },
      ],
      motoristas: [],
      zonas: ['Avô Cumbe'],
      viaturas: [],
      transportadoras: [],
      destinatarios: [],
    },
  };
  await page.route('**/data/plano.php**', (r) => {
    const u = r.request().url();
    if (r.request().method() === 'POST') {
      const b = JSON.parse(r.request().postData());
      return r.fulfill({ json: { ok: true, plano: { data: b.data, rev: (b.rev || 0) + 1, rotas: b.rotas, publicado: null } } });
    }
    if (u.includes('historico=1')) return r.fulfill({ json: { dias: [] } });
    return r.fulfill({ json: resp });
  });
  await abrir(page, '#plano-cargas');
  await page
    .locator('.pc-acc input[type=file]')
    .setInputFiles({ name: 'plano.xlsx', mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', buffer: await excelAntigo() });
  await expect(page.locator('#toast')).toContainText('Importadas 2 rotas');
  await expect(page.locator('.pc-card:not(.fora)')).toHaveCount(2);
  const c1 = page.locator('.pc-card').nth(0),
    c7 = page.locator('.pc-card').nth(1);
  await expect(c1.locator('.pc-chip').first()).toContainText('Avô Cumbe');
  await expect(c1.locator('.pc-qs input').nth(1)).toHaveValue('132');
  await expect(c7.locator('.pc-tit span')).toHaveText('Awet Weldemenkorios');
  await expect(c7.locator('.pc-total')).toContainText('285');
});
