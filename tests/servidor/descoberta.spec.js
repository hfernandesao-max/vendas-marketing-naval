// Descoberta de potenciais clientes e radar do dia (servidor real): webhook das fontes externas (chave, validação,
// rota pública sem sessão) e radar no âmbito de cada perfil, dentro da capacidade da rota.
import { test, expect, request } from '@playwright/test';
import { mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { entrar, API, BASE } from './ajuda.js';

test.describe.configure({ mode: 'serial' });
const PRIV = 'tests/servidor/ambiente/dados-privados';
const GEO = `${PRIV}/geo`;
const CHAVE = 'chave-de-teste-da-entrada-geo-0123456789abcdef';
const hoje = new Date().toISOString().slice(0, 10);
const ds = new Date().getDay() || 7;

test.beforeAll(() => {
  mkdirSync(GEO, { recursive: true });
  mkdirSync(`${PRIV}/carteira`, { recursive: true });
  rmSync(`${GEO}/externos.json`, { force: true });
  rmSync(`${GEO}/recalcular.json`, { force: true });
  // plano do dia: 2 visitas na rota 1001 (do supervisor) e 1 na 1002
  const cli = (c, r, estado) => ({ c, n: 'Cliente ' + c, r, dia: ds, proxima: hoje, estado, media: 10000, ritmo: 7 });
  writeFileSync(
    `${PRIV}/carteira/${hoje}.json`,
    JSON.stringify({
      gerado: hoje + ' 04:00:00',
      dia: hoje,
      clientes: { A: cli('A', '1001', 'parado'), B: cli('B', '1001', 'em_dia'), D: cli('D', '1002', 'em_dia') },
    }),
  );
  writeFileSync(
    `${GEO}/vista.json`,
    JSON.stringify({
      gerado: hoje + ' 04:10:00',
      parametros: { visitas_dia: 30, dias_semana: 6, limiar: 0.9 },
      rotas: [
        { rota: '1001', territorio: [] },
        { rota: '1002', territorio: [] },
      ],
      zonas_sem_rota: [],
      clientes: [
        { c: 'A', n: 'Cliente A', lat: -8.84, lng: 13.23, r: '1001', d: ds },
        { c: 'B', n: 'Cliente B', lat: -8.835, lng: 13.23, r: '1001', d: ds },
        { c: 'D', n: 'Cliente D', lat: -8.9, lng: 13.3, r: '1002', d: ds },
      ],
      potenciais: [
        {
          id: 'osm:n1',
          ids: ['osm:n1'],
          n: 'Mini Mercado Perto',
          t: 'convenience',
          lat: -8.8395,
          lng: 13.2305,
          r: '1001',
          d: ds,
          s: 72,
          a: 'ambos',
          f: ['osm'],
        },
        { id: 'osm:n2', ids: ['osm:n2'], n: 'Da outra rota', t: 'supermarket', lat: -8.9005, lng: 13.3, r: '1002', d: null, s: 80, a: 'ambos', f: ['osm'] },
      ],
      carteira: {
        esquecidos: [{ c: 'E1', n: 'Parado há muito', r: '1001', dias: 75, faixa: 60, compras: 6, media: 90000, lat: -8.838, lng: 13.231 }],
        sem_gps: [],
        duplicados: [],
      },
    }),
  );
});

test('entrada externa: só com a chave, sem sessão, valida e conta o que aceitou', async () => {
  const ctx = await request.newContext({ baseURL: BASE });
  const corpo = {
    fonte: 'n8n-teste',
    estabelecimentos: [
      { id_externo: 'L1', nome: 'Armazém Grossista do Teste', lat: -8.83, lng: 13.24, categoria: 'store', telefone: '+244 923 000 111' },
      { id_externo: 'L2', nome: 'Oficina', lat: -8.83, lng: 13.24, categoria: 'car_repair' },
      { id_externo: 'L3', nome: 'Supermercado Benguela', lat: -12.58, lng: 13.4, categoria: 'supermarket' },
    ],
  };
  expect((await ctx.post(API + 'geo/entrada', { data: corpo })).status()).toBe(401);
  expect((await ctx.post(API + 'geo/entrada', { data: corpo, headers: { Authorization: 'Bearer errada' } })).status()).toBe(401);
  const r = await ctx.post(API + 'geo/entrada', { data: corpo, headers: { Authorization: 'Bearer ' + CHAVE } });
  expect(r.status()).toBe(200);
  expect(await r.json()).toMatchObject({
    ok: true,
    recebidos: 3,
    aceites: 1,
    novos: 1,
    rejeitados: [
      { i: 1, motivo: 'fora do portfólio (higiene, limpeza e alimentar)' },
      { i: 2, motivo: 'fora de Luanda e Ícolo e Bengo' },
    ],
  });
  expect((await ctx.post(API + 'geo/entrada', { data: { fonte: 'x' }, headers: { Authorization: 'Bearer ' + CHAVE } })).status()).toBe(400);
  // uma sessão do portal não serve de chave
  const { ctx: dir } = await entrar('dir');
  expect((await dir.post(API + 'geo/entrada', { data: corpo })).status()).toBe(401);
  await ctx.dispose();
});

test('radar do dia: lugares livres, recuperar e conquistar perto do caminho, no âmbito de cada perfil', async () => {
  for (const [conta, estado] of [
    ['fin', 403],
    ['bo', 403],
    ['dir', 200],
    ['sup', 200],
  ])
    expect((await (await entrar(conta)).ctx.get(API + 'geo/radar')).status(), conta).toBe(estado);
  const dir = await (await (await entrar('dir')).ctx.get(API + 'geo/radar?dia=' + hoje)).json();
  const r1 = dir.rotas.find((x) => x.rota === '1001');
  expect(r1).toMatchObject({ planeadas: 2, capacidade: 30, livres: 28, excesso: 0 });
  expect(r1.sugestoes.map((s) => [s.tipo, s.id])).toEqual([
    ['recuperar', 'E1'],
    ['conquistar', 'osm:n1'],
  ]);
  expect(dir.rotas.find((x) => x.rota === '1002').sugestoes.map((s) => s.id)).toEqual(['osm:n2']);
  const sup = await (await (await entrar('sup')).ctx.get(API + 'geo/radar?dia=' + hoje)).json();
  expect(sup.rotas.map((x) => x.rota)).toEqual(['1001']);
  expect((await (await entrar('dir')).ctx.get(API + 'geo/radar?dia=2099-01-01')).status()).toBe(400);
});
