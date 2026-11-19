// Ocorrências do terreno e contadores do menu (servidor real): matriz de acessos, âmbito por rota, tratamento pelo
// circuito dos alertas (CSRF, quem trata, ocorrência desconhecida) e números para o menu.
import { test, expect } from '@playwright/test';
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { entrar, API, csrf } from './ajuda.js';

test.describe.configure({ mode: 'serial' });
const GEO = 'tests/servidor/ambiente/dados-privados/geo';

test.beforeAll(() => {
  mkdirSync(GEO, { recursive: true });
  rmSync(`${GEO}/propostas.json`, { force: true });
  rmSync('tests/servidor/ambiente/dados-privados/cache', { recursive: true, force: true });
  // carteira da prospecção com um cliente parado em cada rota (1001 do supervisor, 1002 de outra pessoa)
  writeFileSync(
    `${GEO}/vista.json`,
    JSON.stringify({
      gerado: '2026-11-03 04:10:00',
      parametros: { visitas_dia: 30, dias_semana: 6, limiar: 0.9 },
      rotas: [],
      zonas_sem_rota: [],
      clientes: [],
      potenciais: [],
      carteira: {
        esquecidos: [
          { c: 'C1', n: 'Um', r: '1001', ult: '2026-08-20', dias: 75, faixa: 60, compras: 6, media: 90000 },
          { c: 'C2', n: 'Dois', r: '1002', ult: '2026-10-15', dias: 19, faixa: 14, compras: 4, media: 40000 },
        ],
        sem_gps: [],
        duplicados: [],
      },
    }),
  );
  writeFileSync(
    `${GEO}/propostas.json`,
    JSON.stringify({
      seq: 1,
      propostas: [
        {
          id: 'RP-0001',
          tipo: 'divisao',
          estado: 'pendente',
          origem_rota: '1001',
          motivo: 'Ensaio',
          territorio: [],
          centro: [-8.8, 13.2],
          clientes: [],
          potenciais: 0,
          impacto: {},
          criada: '2026-11-03',
          decisao: null,
          historico: [],
        },
      ],
    }),
  );
});

test('ocorrências: acessos pela matriz e âmbito por rota', async () => {
  for (const [conta, estado] of [
    ['fin', 403],
    ['bo', 403],
    ['log', 403],
    ['pro', 403],
    ['dir', 200],
    ['sup', 200],
    ['aud', 200],
  ])
    expect((await (await entrar(conta)).ctx.get(API + 'ocorrencias')).status(), conta).toBe(estado);
  const dir = await (await (await entrar('dir')).ctx.get(API + 'ocorrencias')).json();
  expect(dir.trata).toBe(true);
  // depois das 18h juntam-se as visitas do plano não feitas: o ensaio olha só para os clientes parados
  const parados = (l) => l.filter((o) => o.tipo === 'cliente_parado');
  expect(parados(dir.ocorrencias).map((o) => o.id)).toEqual(['oco|cliente_parado|C1|60', 'oco|cliente_parado|C2|14']);
  expect(parados(dir.ocorrencias)[0]).toMatchObject({ gravidade: 'critico', rota: '1001', activo: true, tratamento: { estado: 'novo' } });
  const sup = await (await (await entrar('sup')).ctx.get(API + 'ocorrencias')).json();
  expect(sup.trata).toBe(true);
  expect(parados(sup.ocorrencias).map((o) => o.rota)).toEqual(['1001']);
  const aud = await (await (await entrar('aud')).ctx.get(API + 'ocorrencias')).json();
  expect(aud.trata).toBe(false);
  expect((await (await entrar('dir')).ctx.get(API + 'ocorrencias?data=2099-01-01')).status()).toBe(400);
});

test('ocorrências: tratar com CSRF, só quem trata e só as que vê; o tratamento fica na lista', async () => {
  const aud = (await entrar('aud')).ctx;
  expect((await aud.post(API + 'ocorrencias', { data: { id: 'oco|cliente_parado|C1|60', accao: 'assumir', csrf: await csrf(aud) } })).status()).toBe(403);
  const { ctx } = await entrar('sup');
  expect((await ctx.post(API + 'ocorrencias', { data: { id: 'oco|cliente_parado|C1|60', accao: 'assumir' } })).status()).toBe(403);
  const c = await csrf(ctx);
  // a da rota 1002 não é do supervisor
  expect((await ctx.post(API + 'ocorrencias', { data: { id: 'oco|cliente_parado|C2|14', accao: 'assumir', csrf: c } })).status()).toBe(404);
  expect((await ctx.post(API + 'ocorrencias', { data: { id: 'oco|cliente_parado|C1|60', accao: 'resolver', nota: '', csrf: c } })).status()).toBe(400);
  const r = await (
    await ctx.post(API + 'ocorrencias', { data: { id: 'oco|cliente_parado|C1|60', accao: 'resolver', nota: 'Visitado: volta a comprar', csrf: c } })
  ).json();
  expect(r).toMatchObject({ ok: true, tratamento: { estado: 'resolvido', por: 'sup', nota: 'Visitado: volta a comprar' } });
  const v = await (await ctx.get(API + 'ocorrencias')).json();
  expect(v.ocorrencias.find((o) => o.id === 'oco|cliente_parado|C1|60')).toMatchObject({ activo: false, tratamento: { estado: 'resolvido', por: 'sup' } });
  expect(v.abertas).toBe(v.ocorrencias.filter((o) => o.activo).length);
  expect(v.ocorrencias.filter((o) => o.activo && o.tipo === 'cliente_parado')).toEqual([]);
});

test('contadores do menu: só das páginas que a pessoa vê e no seu âmbito', async () => {
  // o número das ocorrências é o das abertas na lista da própria pessoa (depende da hora: visitas não feitas)
  const abertas = async (ctx) => (await (await ctx.get(API + 'ocorrencias')).json()).abertas;
  const d = (await entrar('dir')).ctx;
  const dir = await (await d.get(API + 'terreno/contagens')).json();
  expect(dir).toMatchObject({ ok: true, contagens: { 'rotas-propostas': 1, ocorrencias: await abertas(d) } });
  const s = (await entrar('sup')).ctx;
  const sup = await (await s.get(API + 'terreno/contagens')).json();
  expect(sup.contagens).toEqual({ 'rotas-propostas': 1, ocorrencias: await abertas(s) });
  const log = await (await (await entrar('log')).ctx.get(API + 'terreno/contagens')).json();
  expect(log.contagens).toEqual({ 'rotas-propostas': 1 });
  const fin = await (await (await entrar('fin')).ctx.get(API + 'terreno/contagens')).json();
  expect(fin.contagens).toEqual({});
});
