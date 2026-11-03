// Prospecção (GeoMobilidade): matriz de acessos, âmbito por rota, resultado da visita do vendedor e página do mapa.
import { test, expect } from '@playwright/test';
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { entrar, API, BASE, csrf } from './ajuda.js';

test.describe.configure({ mode: 'serial' });
const PRIV = 'tests/servidor/ambiente/dados-privados/geo';
const rota = (r, n) => ({
  rota: r,
  promotor: 'P' + r,
  clientes: n,
  visitas_semana: n,
  capacidade: 180,
  ocupacao: n / 180,
  potenciais: 1,
  ocupacao_potencial: (n + 1) / 180,
  cobertura: 0.9,
  convertidos_30d: 0,
  territorio: [
    [-8.8, 13.2],
    [-8.8, 13.21],
    [-8.81, 13.21],
  ],
  cheia: n / 180 >= 0.9,
});

test.beforeAll(() => {
  rmSync(PRIV, { recursive: true, force: true });
  mkdirSync(PRIV, { recursive: true });
  writeFileSync(
    `${PRIV}/vista.json`,
    JSON.stringify({
      gerado: '2026-11-03 04:10:00',
      origem_clientes: 'ENTITY.LATITUDE/LONGITUDE',
      erro_clientes: null,
      fontes: { osm: 2, google: 0, google_mes: 0, google_limite: 4500, google_activo: false },
      parametros: { visitas_dia: 30, dias_semana: 6, limiar: 0.9 },
      rotas: [rota('1001', 170), rota('1002', 40)],
      zonas_sem_rota: [{ lat: -8.9, lng: 13.3, potenciais: 45 }],
      clientes: [
        { c: 'C1', n: 'Um', lat: -8.8, lng: 13.2, r: '1001', d: 1 },
        { c: 'C2', n: 'Dois', lat: -8.85, lng: 13.25, r: '1002', d: 2 },
      ],
      potenciais: [
        { id: 'osm:n1', ids: ['osm:n1'], n: 'Loja Um', t: 'convenience', lat: -8.801, lng: 13.2, f: ['osm'], r: '1001', d: 1, dc: 120, s: 70 },
        { id: 'osm:n2', ids: ['osm:n2'], n: 'Loja Dois', t: 'kiosk', lat: -8.851, lng: 13.25, f: ['osm'], r: '1002', d: 2, dc: 90, s: 60 },
        { id: 'osm:n3', ids: ['osm:n3'], n: 'Fora', t: 'bar', lat: -8.9, lng: 13.3, f: ['osm'], r: '', d: null, dc: null, s: 20 },
      ],
    }),
  );
});

test('prospecção: acessos e âmbito por rota (o vendedor só vê a sua rota e não vê as zonas sem rota)', async () => {
  for (const [conta, estado] of [
    ['fin', 403],
    ['log', 403],
    ['bo', 403],
    ['dir', 200],
    ['pro', 200],
  ])
    expect((await (await entrar(conta)).ctx.get(API + 'prospeccao')).status(), conta).toBe(estado);
  const dir = await (await (await entrar('dir')).ctx.get(API + 'prospeccao')).json();
  expect(dir.potenciais.map((p) => p.id)).toEqual(['osm:n1', 'osm:n2', 'osm:n3']);
  expect(dir.zonas_sem_rota).toHaveLength(1);
  expect(dir.potenciais[0].ids).toBeUndefined();
  const pro = await (await (await entrar('pro')).ctx.get(API + 'prospeccao')).json();
  expect(pro.rotas.map((r) => r.rota)).toEqual(['1002']);
  expect(pro.potenciais.map((p) => p.id)).toEqual(['osm:n2']);
  expect(pro.clientes.map((c) => c.c)).toEqual(['C2']);
  expect(pro.zonas_sem_rota).toEqual([]);
  expect((await (await entrar('pro')).ctx.get(API + 'prospeccao?actualizar=1')).status()).toBe(403);
});

test('prospecção: o vendedor regista o resultado da visita, só na sua rota e com CSRF', async () => {
  const { ctx } = await entrar('pro');
  const c = await csrf(ctx);
  expect((await ctx.post(API + 'prospeccao', { data: { accao: 'marcar', id: 'osm:n2', estado: 'visitado' } })).status()).toBe(403);
  expect((await ctx.post(API + 'prospeccao', { data: { accao: 'marcar', id: 'osm:n1', estado: 'visitado', csrf: c } })).status()).toBe(404);
  expect((await ctx.post(API + 'prospeccao', { data: { accao: 'marcar', id: 'osm:n2', estado: 'xpto', csrf: c } })).status()).toBe(400);
  const r = await (await ctx.post(API + 'prospeccao', { data: { accao: 'marcar', id: 'osm:n2', estado: 'nao_interessado', csrf: c } })).json();
  expect(r).toMatchObject({ ok: true, id: 'osm:n2', e: 'nao_interessado' });
});

test('prospecção: página do mapa protegida pela sessão e pela matriz', async () => {
  const pro = (await entrar('pro')).ctx;
  const r = await pro.get(BASE + '/geo/prospeccao.php');
  expect(r.status()).toBe(200);
  const html = await r.text();
  expect(html).toContain('Onde procurar clientes novos');
  expect(html).toMatch(/<meta name="csrf-token" content="[^"]{20,}"/);
  expect((await (await entrar('fin')).ctx.get(BASE + '/geo/prospeccao.php')).status()).toBe(403);
});
