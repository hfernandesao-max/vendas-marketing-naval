// Rotas propostas (servidor real): matriz de acessos, âmbito por rota, CSRF, só quem decide aprova, a aprovação
// grava a atribuição dos clientes e a decisão não se repete.
import { test, expect } from '@playwright/test';
import { mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { entrar, API, csrf } from './ajuda.js';

test.describe.configure({ mode: 'serial' });
const PRIV = 'tests/servidor/ambiente/dados-privados/geo';
const proposta = (id, tipo, origem, clientes) => ({
  id,
  tipo,
  estado: 'pendente',
  origem_rota: origem,
  motivo: 'Ensaio',
  territorio: [
    [-8.8, 13.2],
    [-8.8, 13.21],
    [-8.81, 13.21],
  ],
  centro: [-8.805, 13.205],
  clientes,
  potenciais: tipo === 'zona' ? 45 : 0,
  impacto: { clientes: clientes.length, visitas_semana: 2, capacidade_visitas: 180, ocupacao: 0.011 },
  criada: '2026-11-03',
  decisao: null,
  historico: [{ em: '2026-11-03', accao: 'criada' }],
});

test.beforeAll(() => {
  rmSync(`${PRIV}/propostas.json`, { force: true });
  rmSync(`${PRIV}/atribuicoes.json`, { force: true });
  mkdirSync(PRIV, { recursive: true });
  writeFileSync(
    `${PRIV}/propostas.json`,
    JSON.stringify({ seq: 2, propostas: [proposta('RP-0001', 'divisao', '1001', ['C1', 'C9']), proposta('RP-0002', 'zona', '', [])] }),
  );
});

test('rotas propostas: acessos pela matriz e âmbito por rota', async () => {
  for (const [conta, estado] of [
    ['fin', 403],
    ['bo', 403],
    ['pro', 403],
    ['log', 200],
    ['dir', 200],
    ['sup', 200],
  ])
    expect((await (await entrar(conta)).ctx.get(API + 'rotas-propostas')).status(), conta).toBe(estado);
  const dir = await (await (await entrar('dir')).ctx.get(API + 'rotas-propostas')).json();
  expect(dir.decide).toBe(true);
  expect(dir.propostas.map((p) => p.id)).toEqual(['RP-0002', 'RP-0001']);
  expect(dir.pendentes).toBe(2);
  // o supervisor da rota 1001 vê a divisão da sua rota, não a zona sem rota, e não decide
  const sup = await (await (await entrar('sup')).ctx.get(API + 'rotas-propostas')).json();
  expect(sup.decide).toBe(false);
  expect(sup.propostas.map((p) => p.id)).toEqual(['RP-0001']);
});

test('rotas propostas: só quem decide aprova, com CSRF; a aprovação grava a atribuição e não se repete', async () => {
  const log = (await entrar('log')).ctx;
  expect((await log.post(API + 'rotas-propostas', { data: { accao: 'aprovar', id: 'RP-0001', rota_nova: '1015', csrf: await csrf(log) } })).status()).toBe(403);
  const { ctx } = await entrar('dir');
  expect((await ctx.post(API + 'rotas-propostas', { data: { accao: 'aprovar', id: 'RP-0001', rota_nova: '1015' } })).status()).toBe(403);
  const c = await csrf(ctx);
  expect((await ctx.post(API + 'rotas-propostas', { data: { accao: 'aprovar', id: 'RP-0001', rota_nova: '', csrf: c } })).status()).toBe(400);
  expect((await ctx.post(API + 'rotas-propostas', { data: { accao: 'aprovar', id: 'RP-0001', rota_nova: '1001', csrf: c } })).status()).toBe(409);
  expect((await ctx.post(API + 'rotas-propostas', { data: { accao: 'xpto', id: 'RP-0001', csrf: c } })).status()).toBe(400);
  const r = await (await ctx.post(API + 'rotas-propostas', { data: { accao: 'aprovar', id: 'RP-0001', rota_nova: '1015', promotor: 'Novo', csrf: c } })).json();
  expect(r).toMatchObject({ ok: true, estado: 'aprovada', rota_nova: '1015', atribuidos: 2 });
  const atrib = JSON.parse(readFileSync(`${PRIV}/atribuicoes.json`, 'utf8'));
  expect(atrib.C1).toMatchObject({ r: '1015', proposta: 'RP-0001', por: 'dir', origem: '1001' });
  expect((await ctx.post(API + 'rotas-propostas', { data: { accao: 'rejeitar', id: 'RP-0001', nota: 'outra vez', csrf: c } })).status()).toBe(409);
  // rejeitar exige o motivo
  expect((await ctx.post(API + 'rotas-propostas', { data: { accao: 'rejeitar', id: 'RP-0002', nota: '', csrf: c } })).status()).toBe(400);
  const rj = await (await ctx.post(API + 'rotas-propostas', { data: { accao: 'rejeitar', id: 'RP-0002', nota: 'Sem viatura disponível', csrf: c } })).json();
  expect(rj).toMatchObject({ ok: true, estado: 'rejeitada' });
  const v = await (await ctx.get(API + 'rotas-propostas')).json();
  expect(v.propostas.map((p) => [p.id, p.estado])).toEqual([
    ['RP-0002', 'rejeitada'],
    ['RP-0001', 'aprovada'],
  ]);
  expect(v.propostas[1].decisao).toMatchObject({ rota_nova: '1015', promotor: 'Novo', atribuidos: ['C1', 'C9'] });
});
