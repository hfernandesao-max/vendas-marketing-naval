// Stock de artigos (API v1/stock): sem base de dados no ambiente de teste o serviço responde com o motivo; âmbito por perfil.
import { test, expect } from '@playwright/test';
import { API, entrar } from './ajuda.js';

test('stock: carrinhas só das rotas do supervisor e armazéns só para quem os pode ver', async () => {
  const rFin = await (await entrar('fin')).ctx.get(API + 'stock?start_date=20261024&end_date=20261024');
  const fin = await rFin.json();
  if (rFin.status() === 404) {
    // ambiente sem SQL Server nem ficheiro de stock: o serviço explica porquê
    expect(fin.erro).toContain('stock');
    return;
  }
  expect(fin.carrinhas.length).toBeGreaterThan(1);
  expect(fin.armazens.length).toBeGreaterThan(0);
  const sup = await (await (await entrar('sup')).ctx.get(API + 'stock?start_date=20261024&end_date=20261024')).json();
  expect(new Set(sup.carrinhas.map((c) => c.rota))).toEqual(new Set(['1001']));
  expect(sup.armazens).toEqual([]);
});

test('stock: só pela API (reports/data não serve PHP) e só para quem tem as páginas de stock', async () => {
  // o perfil GEO não tem páginas do portal: não obtém token nem dados de stock
  const geo = (await entrar('geo')).ctx;
  expect([401, 403]).toContain((await geo.get(API + 'stock?data=2026-10-24')).status());
  expect((await geo.get('/reports/data/stock.php')).status()).not.toBe(200);
});
