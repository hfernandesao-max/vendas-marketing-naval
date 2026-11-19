// Zonas de entrega e Distribuidores automáticos (geo/zonas.js): geofences das rotas na API dos mapas, legenda e números
// na barra lateral, clientes fora da geofence da sua rota, zona de cada distribuidor e exportações.
import { test, expect } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { entrar, API, BASE, fecharAviso } from './ajuda.js';

test.describe.configure({ mode: 'serial' });
const GEO = 'tests/servidor/ambiente/dados-privados/geo';
const hoje = new Date().toISOString().slice(0, 10);
const quadrado = (la, lo) => [
  [la, lo],
  [la, lo + 0.02],
  [la - 0.02, lo + 0.02],
  [la - 0.02, lo],
];

test.beforeAll(() => {
  mkdirSync(GEO, { recursive: true });
  writeFileSync(
    `${GEO}/clientes-${hoje}.json`,
    JSON.stringify({
      origem: 'teste',
      gerado: hoje + ' 04:00:00',
      clientes: [
        { c: 'A', n: 'Loja A', lat: -8.805, lng: 13.205, r: '1001', d: 3, f: 1 },
        { c: 'B', n: 'Loja B', lat: -8.9, lng: 13.4, r: '1001', d: 3, f: 1 }, // fora da geofence da rota 1001
        { c: 'C', n: 'Loja C', lat: -8.705, lng: 13.305, r: '1002', d: 2, f: 1, tipo: 'DISTRIBUIDOR' },
        { c: 'D', n: 'Loja D', lat: -8.71, lng: 13.31, r: '1002', d: 2, f: 1 },
      ],
    }),
  );
  writeFileSync(
    `${GEO}/vista.json`,
    JSON.stringify({
      gerado: hoje + ' 04:10:00',
      rotas: [
        { rota: '1001', territorio: quadrado(-8.8, 13.2) },
        { rota: '1002', territorio: quadrado(-8.7, 13.3) },
      ],
      clientes: [],
      potenciais: [],
      zonas_sem_rota: [],
      carteira: { esquecidos: [], sem_gps: [], duplicados: [] },
    }),
  );
});

test('API dos mapas: geofences das rotas no âmbito de cada perfil', async () => {
  const dir = await (await (await entrar('dir')).ctx.get(API + 'geo-clientes')).json();
  expect(Object.keys(dir.territorios)).toEqual(['1001', '1002']);
  expect(dir.territorios['1001']).toHaveLength(4);
  const sup = await (await (await entrar('sup')).ctx.get(API + 'geo-clientes')).json();
  expect(Object.keys(sup.territorios)).toEqual(['1001']);
});

async function abrir(browser, endereco, rota) {
  const { ctx } = await entrar('dir');
  const pagina = await browser.newContext({
    baseURL: BASE,
    storageState: await ctx.storageState(),
    viewport: { width: 1400, height: 900 },
    acceptDownloads: true,
  });
  const page = await pagina.newPage();
  await page.route(/maps\.googleapis\.com/, (r) => r.abort());
  await page.goto(endereco);
  await expect(page).toHaveURL(new RegExp('/reports/#' + rota));
  await fecharAviso(page);
  const f = page.frameLocator('iframe.geo-quadro');
  await f.locator('#zLegenda .z-item').first().waitFor();
  // o aviso das novidades pode abrir depois do primeiro fecho, por cima da página
  await fecharAviso(page);
  return { page, f };
}

test('zonas de entrega: automáticas, legenda na barra lateral, fora da geofence e exportações @navegador', async ({ browser }) => {
  const { page, f } = await abrir(browser, '/geo/view/index.php', 'geo-zonas');
  await expect(f.getByRole('heading', { name: 'Geofences das rotas' })).toBeVisible();
  // nada manual: sem desenhar, editar ou importar zonas
  await expect(f.getByRole('button', { name: /Desenhar|Editar zona|Importar/ })).toHaveCount(0);
  const leg = f.locator('#zLegenda');
  await expect(leg.locator('.z-item')).toHaveCount(3); // Todas + 2 rotas
  await expect(leg.locator('.z-item').nth(1)).toContainText('1001');
  await expect(leg.locator('.z-item').nth(1)).toContainText('1 fora');
  await expect(f.locator('#zIndicadores')).toContainText('Fora da geofence da sua rota');
  await expect(f.locator('#zIndicadores .z-i').nth(3)).toContainText('1');
  await leg.locator('.z-item').nth(1).click();
  await expect(leg.locator('.z-item').nth(1)).toHaveClass(/on/);
  const espera = page.waitForEvent('download');
  await f.locator('[data-exporta="fora"]').click();
  const d = await espera;
  expect(d.suggestedFilename()).toMatch(/^clientes-fora-das-geofences-\d{4}-\d{2}-\d{2}\.csv$/);
  await page.context().close();
});

test('distribuidores: zona automática de cada distribuidor pelos clientes mais próximos @navegador', async ({ browser }) => {
  const { page, f } = await abrir(browser, '/geo/view/viewDist/index.php', 'geo-distribuidores');
  await expect(f.getByRole('heading', { name: 'Distribuidores e as suas zonas' })).toBeVisible();
  const leg = f.locator('#zLegenda .z-item');
  await expect(leg).toHaveCount(2); // Todas + o distribuidor
  await expect(leg.nth(1)).toContainText('Loja C');
  await expect(leg.nth(1)).toContainText('3'); // os outros 3 clientes ficam com o único distribuidor
  await expect(f.locator('#zIndicadores')).toContainText('Distribuidores');
  await page.context().close();
});
