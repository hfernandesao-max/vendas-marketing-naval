// Conversão das visitas: acesso pela matriz, âmbito por rota e período limitado.
import { test, expect } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { entrar, API } from './ajuda.js';

const PRIV = 'tests/servidor/ambiente/dados-privados';
const dia = new Date(Date.now() + 3600e3 - 2 * 86400e3).toISOString().slice(0, 10);

test('conversão das visitas: plano guardado × compras, só nas rotas de cada um', async () => {
  mkdirSync(`${PRIV}/visitas-plano`, { recursive: true });
  writeFileSync(
    `${PRIV}/visitas-plano/${dia}.json`,
    JSON.stringify({ dia, rotas: { 1001: [{ c: 'A', n: 'Loja A', media: 1000 }], 1002: [{ c: 'B', n: 'Loja B', media: 500 }] } }),
  );
  expect((await (await entrar('fin')).ctx.get(API + `conversao?de=${dia}&ate=${dia}`)).status()).toBe(403);
  const sup = await (await (await entrar('sup')).ctx.get(API + `conversao?de=${dia}&ate=${dia}`)).json();
  expect(sup.ok).toBe(true);
  expect(sup.rotas.map((r) => r.rota)).toEqual(['1001']);
  expect(sup.rotas[0].planeados).toBe(1);
  const dir = (await entrar('dir')).ctx;
  const todas = await (await dir.get(API + `conversao?de=${dia}&ate=${dia}`)).json();
  expect(todas.rotas.map((r) => r.rota).sort()).toEqual(['1001', '1002']);
  expect((await dir.get(API + 'conversao?de=2026-01-01&ate=2026-06-30')).status()).toBe(400);
});
