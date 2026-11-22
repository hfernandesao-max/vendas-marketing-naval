// Perdas e tempo parado (v1/perdas) e calendário de trabalho (v1/calendario), com o servidor real e as permissões.
import { test, expect } from '@playwright/test';
import { entrar, API, csrf } from './ajuda.js';

test.describe.configure({ mode: 'serial' });

test('perdas: quem tem a linha vê por rota; o vendedor não; período até 31 dias', async () => {
  const dir = (await entrar('dir')).ctx;
  const hoje = new Date(Date.now() + 3600e3).toISOString().slice(0, 10);
  const r = await dir.get(API + 'perdas?de=' + hoje.slice(0, 8) + '01&ate=' + hoje);
  expect(r.status()).toBe(200);
  const d = await r.json();
  expect(Array.isArray(d.rotas)).toBe(true);
  expect(d.total).toHaveProperty('perdas_kz');
  expect((await dir.get(API + 'perdas?de=2026-01-01&ate=2026-03-31')).status()).toBe(400);
  expect((await (await entrar('pro')).ctx.get(API + 'perdas')).status()).toBe(403);
});

test('calendário: todos vêem; só a Direcção define, com motivo', async () => {
  const pro = (await entrar('pro')).ctx;
  const v = await (await pro.get(API + 'calendario')).json();
  expect(v.ok).toBe(true);
  expect(v.gere).toBe(false);
  const dia = new Date(Date.now() + 9 * 864e5).toISOString().slice(0, 10);
  const post = async (ctx, corpo) => (await ctx.post(API + 'calendario', { data: { ...corpo, csrf: await csrf(ctx) } })).status();
  expect(await post(pro, { accao: 'definir', dia, tipo: 'folga', motivo: 'Ponte' })).toBe(403);
  const dir = (await entrar('dir')).ctx;
  expect(await post(dir, { accao: 'definir', dia, tipo: 'folga', motivo: '' })).toBe(400);
  expect(await post(dir, { accao: 'definir', dia, tipo: 'folga', motivo: 'Ponte da empresa' })).toBe(200);
  const x = await (await dir.get(API + 'calendario')).json();
  expect(x.excepcoes.find((e) => e.dia === dia)).toMatchObject({ tipo: 'folga', motivo: 'Ponte da empresa', por: 'dir' });
  expect(await post(dir, { accao: 'definir', dia, tipo: '', motivo: '' })).toBe(200);
});
