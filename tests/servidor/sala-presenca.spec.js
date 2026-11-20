// App «Naval Sala»: presença dos telemóveis na sala de resultados (POST v1/sala/presenca), só com a chave da app.
import { test, expect, request } from '@playwright/test';
import { API, BASE } from './ajuda.js';

const CHAVE = 'chave-de-teste-da-app-da-sala-0123456789abcdef';

test('presença na sala: sem chave ou com a chave errada recusado; com a chave regista a entrada e a saída', async () => {
  const c = await request.newContext({ baseURL: BASE });
  const enviar = (chave, corpo) => c.post(API + 'sala/presenca', { data: corpo, headers: chave ? { Authorization: 'Bearer ' + chave } : {} });
  const corpo = { evento: 'entrada', app: 'a1b2c3d4e5f6', modelo: 'BV6200 Pro', modo: 'app', wifi_sala: 'true', winrest_aberto: 'true' };
  expect((await enviar('', corpo)).status()).toBe(401);
  expect((await enviar('chave-errada-chave-errada-chave-errada-0000', corpo)).status()).toBe(401);
  expect((await enviar(CHAVE, { ...corpo, evento: 'apagar_tudo' })).status()).toBe(400);
  const e = await enviar(CHAVE, corpo);
  expect(e.status()).toBe(200);
  expect(await e.json()).toMatchObject({ ok: true, na_sala: true, kiosk_ate: null });
  expect(await (await enviar(CHAVE, { ...corpo, evento: 'saida' })).json()).toMatchObject({ ok: true, na_sala: false });
});
