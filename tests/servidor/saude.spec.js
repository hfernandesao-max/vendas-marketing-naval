// Verificação de saúde (monitorização e, no futuro, balanceador de carga): pública, sem informação interna.
import { test, expect, request } from '@playwright/test';
import { BASE, API } from './ajuda.js';

test('saúde: GET v1/saude responde 200 sem sessão e sem revelar a versão nem caminhos', async () => {
  const ctx = await request.newContext({ baseURL: BASE });
  const r = await ctx.get(API + 'saude');
  expect(r.status()).toBe(200);
  const d = await r.json();
  expect(d.ok).toBe(true);
  expect(Object.keys(d).sort()).toEqual(['hora', 'ok']);
  expect(r.headers()['cache-control']).toMatch(/no-cache|no-store/); // nunca em cache partilhada
});
