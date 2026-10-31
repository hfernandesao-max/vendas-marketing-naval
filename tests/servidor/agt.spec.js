// Comunicação AGT no servidor: acesso pela matriz (linha «agt») e, sem base de dados, o motivo.
import { test, expect } from '@playwright/test';
import { entrar } from './ajuda.js';

test('Comunicação AGT: só com a página na matriz; sem base de dados indica o motivo', async () => {
  const { ctx } = await entrar('sadm');
  const r = await ctx.get('/api/index.php/v1/agt?start_date=20261001&end_date=20261024');
  expect(r.status()).toBe(200);
  const d = await r.json();
  expect(d.disponivel).toBe(false);
  expect(d.erro).toContain('base de dados');

  const pro = await entrar('pro');
  expect((await pro.ctx.get('/api/index.php/v1/agt')).status()).toBe(403);
});
