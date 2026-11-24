// Início: «À sua espera» — o que aguarda a acção da pessoa (v1/pendentes), os atrasados marcados, cada um com a ligação
// para onde se trata; sem nada, «Tudo em dia».
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const ITENS = [
  {
    tipo: 'sap',
    numero: '17',
    dia: '2026-10-25',
    de: '2026-10-25',
    documentos: 38,
    por: 'hf',
    em: '2026-10-25 16:15:00',
    atraso: true,
    url: '../reports/#sap-exportar',
  },
  {
    tipo: 'carrinha',
    accao: 'coordenar',
    rota: '1011',
    dia: '2026-10-26',
    promotor: 'Robel',
    diferencas: 2,
    atraso: false,
    url: '../reports/#conciliacao-carrinha',
  },
  { tipo: 'po', numero: 'PO-9', rota: '1003', dia: '2026-10-26', artigos: 4, quantidade: 120, atraso: false, url: '../reports/#requisicoes-carrinha' },
  { tipo: 'plano', dia: '2026-10-27', rotas: 9, atraso: false, url: '../reports/#plano-cargas' },
];

test('Início: «À sua espera» com os atrasados primeiro, ligação para a página e «Ver todos»', async ({ page }) => {
  await page.route('**/v1/pendentes*', (r) => r.fulfill({ json: { ok: true, itens: ITENS, nomes: { hf: 'Henrique' }, total: ITENS.length } }));
  const erros = await abrir(page, '#inicio');
  const f = page.locator('.pend-faixa');
  await expect(f).toBeVisible();
  await expect(f.locator('.pend-n')).toHaveText('4');
  await expect(f.locator('.pend-atraso')).toHaveText('1 atrasado');
  await expect(f.locator('.pend-item')).toHaveCount(3);
  const sap = f.locator('.pend-item').first();
  await expect(sap).toContainText('Ficheiro para o SAP, lote 17: confirmar o carregamento');
  await expect(sap).toContainText('38 documentos');
  await expect(sap).toContainText('Henrique (hf)');
  await expect(sap).toHaveClass(/atraso/);
  await expect(sap).toHaveAttribute('href', '#sap-exportar');
  await f.getByRole('button', { name: 'Ver todos (4)' }).click();
  await expect(page.locator('.pend-faixa .pend-item')).toHaveCount(4);
  await page.locator('.pend-faixa .pend-item').nth(1).click();
  await expect(page).toHaveURL(/#conciliacao-carrinha/);
  expect(erros).toEqual([]);
});

test('Início: sem nada à espera mostra «Tudo em dia»', async ({ page }) => {
  await page.route('**/v1/pendentes*', (r) => r.fulfill({ json: { ok: true, itens: [], nomes: {}, total: 0 } }));
  await abrir(page, '#inicio');
  await expect(page.locator('.pend-faixa.em-dia')).toHaveText('Tudo em dia: nada à sua espera.');
});
