// Resumo das actualizações (v1/novidades): só o super administrador; detecção das secções novas do CHANGELOG.md
// em relação à base (nucleo/recursos/novidades-base.json); marcação como vistas por conta e com CSRF.
import { test, expect } from '@playwright/test';
import { rmSync } from 'node:fs';
import { entrar, API } from './ajuda.js';

test.describe.configure({ mode: 'serial' });

test('outros perfis: 403', async () => {
  for (const conta of ['ti', 'dir', 'com']) {
    const { ctx } = await entrar(conta);
    expect((await ctx.get(API + 'novidades')).status(), conta).toBe(403);
  }
});

test('super administrador: actualizações por ver, marcar como vistas (com CSRF) e não voltam', async () => {
  rmSync('tests/servidor/ambiente/dados-privados/novidades', { recursive: true, force: true });
  const { ctx } = await entrar('sadm');
  const r = await ctx.get(API + 'novidades');
  expect(r.status()).toBe(200);
  expect(r.headers()['cache-control'] || '').not.toContain('max-age');
  const d = await r.json();
  expect(d.actualizacoes.length).toBe(1);
  const titulos = d.actualizacoes[0].seccoes.map((s) => s.data + ' · ' + s.titulo);
  expect(titulos).toContain('01-11-2026 · Equipamentos (fase 1)');
  expect(titulos).toContain('02-11-2026 · Actualizações automáticas');
  expect(titulos.some((x) => x.startsWith('26-10-2026'))).toBe(false); // já existia na versão de base
  for (const s of d.actualizacoes[0].seccoes) expect(s.itens.length).toBeGreaterThan(0);
  const id = d.actualizacoes[0].id;
  // sem CSRF: recusado
  expect((await ctx.post(API + 'novidades', { data: { vistas: [id] } })).status()).toBe(403);
  const csrf = (await (await ctx.get(API + 'sessao')).json()).csrf;
  const m = await (await ctx.post(API + 'novidades', { data: { vistas: [id, 'nao-existe'], csrf } })).json();
  expect(m).toEqual({ ok: true, marcadas: 1 });
  expect((await (await ctx.get(API + 'novidades')).json()).actualizacoes).toEqual([]);
  // a mesma actualização não volta a ser detectada (secções já conhecidas)
  rmSync('tests/servidor/ambiente/dados-privados/novidades/vistas.json', { force: true });
  const outra = await (await ctx.get(API + 'novidades')).json();
  expect(outra.actualizacoes.map((a) => a.id)).toEqual([id]);
});
