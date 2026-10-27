// SAP storage location automática pela origem do stock (armazéns principais → viaturas).
import { test, expect } from '@playwright/test';
import { abrir, vendas } from './dados.js';

const ARMAZENS = {
  1150: { nome: 'RTM REFITEC/FOODTEC PIV – FOOD', local: 'RTM1' },
  1151: { nome: 'RTM HIGITEC PIV – NON FOOD', local: 'RTM2' },
};
const rotas = [...new Set(vendas.map((v) => v.rota))];

async function abrirSap(page, origens, config = {}) {
  await page.route('**/data/sap.php**', (r) =>
    r.fulfill({
      json: {
        config: { prefixo: 'FHR PIV', moeda: 'AOA', unidade: 'CAR', armazens: ARMAZENS, rotas: {}, ...config },
        origens,
        lotes: [],
        exportados: {},
        pode_gerar: true,
      },
    }),
  );
  return abrir(page, '#sap-exportar');
}

test('SAP location preenchida pela origem do stock de cada viatura, sem erros nem campos a vermelho', async ({ page }) => {
  const origens = {
    fonte: 'saldos de stock dos armazéns principais e das viaturas',
    periodo: '20-10-2026 a 24-10-2026',
    rotas: Object.fromEntries(
      rotas.map((r) => [r, { local: r === '1004' ? 'RTM1' : 'RTM2', locais: {}, armazens: r === '1004' ? { 1150: 40 } : { 1151: 900 }, artigos: {} }]),
    ),
    artigos: {},
  };
  const erros = await abrirSap(page, origens);
  await expect(page.locator('#page')).not.toContainText('Sem SAP location');
  for (const r of rotas) await expect(page.getByLabel(r + ' local')).not.toHaveClass(/falta/);
  await expect(page.getByLabel('1001 local')).toHaveAttribute('placeholder', 'RTM2 · automático');
  await expect(page.getByLabel('1004 local')).toHaveAttribute('placeholder', 'RTM1 · automático');
  await expect(page.locator('.sap-arm')).toContainText('saldos de stock dos armazéns principais e das viaturas (20-10-2026 a 24-10-2026)');
  await expect(page.getByLabel('1151 SAP location')).toHaveValue('RTM2');
  await expect(page.locator('.sap-map-arm')).toContainText('1001, 1002, 1003');
  // linhas do ficheiro com a localização da rota
  const loc = await page.locator('#s-sap-l tbody tr').evaluateAll((trs) => trs.map((tr) => tr.textContent));
  expect(loc.some((x) => x.includes('RTM1'))).toBe(true);
  expect(erros).toEqual([]);
});

test('sem origem do stock: a rota fica a vermelho e o erro explica porquê', async ({ page }) => {
  await abrirSap(page, { fonte: '', periodo: '', rotas: {}, artigos: {} });
  await expect(page.locator('#page')).toContainText('não há transferências de stock dos armazéns principais');
  await expect(page.getByLabel('1001 local')).toHaveClass(/falta/);
  await expect(page.getByLabel('1001 local')).toHaveAttribute('placeholder', 'obrigatório');
});

test('a SAP location indicada na rota substitui a automática', async ({ page }) => {
  const origens = { fonte: 'x', periodo: '', rotas: { 1001: { local: 'RTM2', locais: {}, armazens: { 1151: 1 }, artigos: {} } }, artigos: {} };
  await abrirSap(page, origens, { rotas: { 1001: { local: 'RTM9' } } });
  await expect(page.getByLabel('1001 local')).toHaveValue('RTM9');
});
