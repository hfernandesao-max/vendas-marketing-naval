// Tempo real (v1/mudancas): a marca muda quando um registo do portal muda (ex.: uma PO emitida) e traz as PO por
// aprovar a quem as aprova; sem sessão, recusado.
import { test, expect, request } from '@playwright/test';
import { entrar, API, BASE, csrf } from './ajuda.js';

test('sinal de mudança: marca estável sem alterações, nova depois de emitir uma PO; PO por aprovar só para quem aprova', async () => {
  const anon = await request.newContext({ baseURL: BASE });
  expect([401, 403]).toContain((await anon.get(API + 'mudancas')).status());
  const dir = (await entrar('dir')).ctx;
  const m1 = await (await dir.get(API + 'mudancas')).json();
  expect(m1).toMatchObject({ ok: true, intervalo: 20 });
  expect(m1.marca).toMatch(/^[0-9a-f]{16}$/);
  expect(typeof m1.accoes.po_por_aprovar).toBe('number');
  expect((await (await dir.get(API + 'mudancas')).json()).marca).toBe(m1.marca);
  // o promotor emite uma PO: a marca muda e a Direcção vê mais uma por aprovar
  const pro = (await entrar('pro')).ctx;
  const post = async (corpo) => (await pro.post(API + 'requisicoes', { data: { ...corpo, csrf: await csrf(pro) } })).json();
  const c = await post({ accao: 'criar', rota: '1002', linhas: [{ codigo: 'M1', artigo: 'Massa', quantidade: 3 }] });
  await post({ accao: 'emitir', numero: c.requisicao.numero, versao: c.requisicao.versao });
  const m2 = await (await dir.get(API + 'mudancas')).json();
  expect(m2.marca).not.toBe(m1.marca);
  expect(m2.accoes.po_por_aprovar).toBe(m1.accoes.po_por_aprovar + 1);
  // quem não aprova não recebe o contador
  expect((await (await pro.get(API + 'mudancas')).json()).accoes.po_por_aprovar).toBeUndefined();
});
