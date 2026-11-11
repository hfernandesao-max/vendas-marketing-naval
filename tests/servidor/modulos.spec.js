// Módulos do portal: só o super administrador gere; piloto só para SADM e TI; desligado para ninguém (matriz de
// acessos, menu e API); o núcleo não se desliga; cada mudança pede a confirmação da identidade.
import { test, expect } from '@playwright/test';
import { rmSync } from 'node:fs';
import { entrar, API, csrf, SENHA } from './ajuda.js';

test.describe.configure({ mode: 'serial' });
const PRIV = 'tests/servidor/ambiente/dados-privados';

test.afterAll(() => rmSync(`${PRIV}/modulos.json`, { force: true }));

test('módulos: só o SADM gere; piloto e desligado mudam a matriz e a API; o núcleo não se desliga', async () => {
  rmSync(`${PRIV}/modulos.json`, { force: true });
  expect((await (await entrar('ti')).ctx.get(API + 'modulos')).status()).toBe(403);
  const sadm = (await entrar('sadm')).ctx;
  const v = await (await sadm.get(API + 'modulos')).json();
  expect(v.ok).toBe(true);
  expect(v.modulos.map((m) => m.id)).toEqual(expect.arrayContaining(['nucleo', 'vendas', 'sap', 'logistica']));
  expect(v.conectores.find((c) => c.id === 'quatenus').estado).toBe('por_ligar');

  const c = await csrf(sadm);
  // confirmação da identidade
  const sem = await (await sadm.post(API + 'modulos', { data: { id: 'sap', estado: 'piloto', csrf: c } })).json();
  expect(sem.confirmar).toBeTruthy();
  const r = await (await sadm.post(API + 'modulos', { data: { id: 'sap', estado: 'piloto', motivo: 'piloto', csrf: c, confirmacao: SENHA } })).json();
  expect(r.ok).toBe(true);
  expect(r.modulos.find((m) => m.id === 'sap').estado).toBe('piloto');

  // piloto: a Tesouraria deixa de ver o SAP (matriz e API); o TI vê
  const fin = (await entrar('fin')).ctx;
  expect((await (await fin.get(API + 'sessao')).json()).permissoes['sap-exportar']).toBe('');
  expect((await fin.get(API + 'sap')).status()).toBe(403);
  const ti = (await entrar('ti')).ctx;
  expect((await (await ti.get(API + 'sessao')).json()).permissoes['sap-exportar']).not.toBe('');

  // desligado: ninguém, nem o SADM
  await sadm.post(API + 'modulos', { data: { id: 'sap', estado: 'desligado', csrf: c, confirmacao: SENHA } });
  expect((await sadm.get(API + 'sap')).status()).toBe(403);
  expect((await (await sadm.get(API + 'sessao')).json()).permissoes['sap-exportar']).toBe('');
  // núcleo: não se desliga
  const n = await sadm.post(API + 'modulos', { data: { id: 'nucleo', estado: 'desligado', csrf: c, confirmacao: SENHA } });
  expect(n.status()).toBe(409);
  // volta a ligar
  await sadm.post(API + 'modulos', { data: { id: 'sap', estado: 'ligado', csrf: c, confirmacao: SENHA } });
  expect((await fin.get(API + 'sap')).status()).toBe(200);
});

test('painel da carrinha: mesmo acesso da conciliação, só leitura', async () => {
  expect((await (await entrar('bo')).ctx.get(API + 'painel-carrinha')).status()).toBe(403);
  const sup = (await entrar('sup')).ctx;
  const r = await sup.get(API + 'painel-carrinha');
  expect([200, 503]).toContain(r.status());
  if (r.status() === 200) {
    const d = await r.json();
    expect(d.fontes.map((f) => f.id)).toEqual(['pinguim-sql', 'contagens', 'quatenus', 'cctv']);
  }
});
