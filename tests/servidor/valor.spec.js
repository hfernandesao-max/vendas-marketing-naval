// Valor do portal para a Naval: quem vê, quem altera os parâmetros (Direcção) e o cálculo com contas reais.
import { test, expect } from '@playwright/test';
import { rmSync } from 'node:fs';
import { entrar, API, csrf } from './ajuda.js';

test.describe.configure({ mode: 'serial' });
const PRIV = 'tests/servidor/ambiente/dados-privados';
test.beforeAll(() => rmSync(`${PRIV}/valor`, { recursive: true, force: true }));

test('valor do portal: a Direcção altera os parâmetros; o Financeiro só vê; a Logística não entra', async () => {
  const dir = (await entrar('dir')).ctx;
  const v = await (await dir.get(API + 'valor?meses=6')).json();
  expect(v.ok, JSON.stringify(v)).toBe(true);
  expect(v.gere).toBe(true);
  expect(v.meses).toHaveLength(6);
  expect(v.por_definir).toContain('custo_hora_kz');
  const g = await (await dir.post(API + 'valor?meses=6', { data: { accao: 'parametros', parametros: { custo_hora_kz: 3000 }, csrf: await csrf(dir) } })).json();
  expect(g.ok, JSON.stringify(g)).toBe(true);
  expect(g.parametros.custo_hora_kz).toBe(3000);
  expect(g.por_definir).not.toContain('custo_hora_kz');
  const fin = (await entrar('fin')).ctx;
  const vf = await (await fin.get(API + 'valor')).json();
  expect(vf.gere).toBe(false);
  expect(vf.parametros.custo_hora_kz).toBe(3000);
  expect((await fin.post(API + 'valor', { data: { accao: 'parametros', parametros: { custo_hora_kz: 1 }, csrf: await csrf(fin) } })).status()).toBe(403);
  expect((await (await entrar('log')).ctx.get(API + 'valor')).status()).toBe(403);
  const mau = await dir.post(API + 'valor', { data: { accao: 'parametros', parametros: { custo_hora_kz: -5 }, csrf: await csrf(dir) } });
  expect(mau.status()).toBe(400);
});
