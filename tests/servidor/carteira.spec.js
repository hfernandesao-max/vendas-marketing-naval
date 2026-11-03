// Carteira (visitas do dia, qualidade) e Terreno do dia (OLAP): matriz de acessos e âmbito por rota.
import { test, expect } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import { entrar, API } from './ajuda.js';

test.describe.configure({ mode: 'serial' });
const PRIV = 'tests/servidor/ambiente/dados-privados';
const hoje = new Date().toISOString().slice(0, 10);
const cli = (c, r, dia) => ({
  c,
  n: 'Loja ' + c,
  r,
  dia,
  media: 1000,
  intervalo: 7,
  ult: '2026-10-20',
  dias: 14,
  atraso: 2,
  estado: 'parado',
  proxima: '2026-10-27',
  compras: 5,
  encomenda: [],
  deixou: [],
  propor: [],
  risco: { risco: 'alto', aberto: 500, dias: 40 },
});

test.beforeAll(() => {
  mkdirSync(`${PRIV}/carteira`, { recursive: true });
  writeFileSync(
    `${PRIV}/carteira/${hoje}.json`,
    JSON.stringify({
      gerado: hoje + ' 04:10:00',
      dia: hoje,
      clientes: { A: cli('A', '1001', 1), B: cli('B', '1002', 1) },
      anomalias: [{ tipo: 'repetidos', rota: '1002', cliente: 'Loja B', documento: 'FR 1', data: hoje, valor: 1, texto: 'x' }],
      qualidade: [{ tipo: 'artigo_sem_peso', rota: '', texto: 'Artigo X sem peso' }],
    }),
  );
});

test('visitas do dia: âmbito por rota, risco só para quem vê os pendentes; qualidade pela matriz', async () => {
  const dia = '2026-11-02'; // segunda-feira: dia habitual dos dois clientes de teste
  const dir = await (await (await entrar('dir')).ctx.get(API + 'carteira?vista=visitas&dia=' + dia)).json();
  expect(Object.keys(dir.visitas)).toEqual(['1001', '1002']);
  expect(dir.visitas['1001'][0].risco.risco).toBe('alto');
  const sup = await (await (await entrar('sup')).ctx.get(API + 'carteira?vista=visitas&dia=' + dia)).json();
  expect(Object.keys(sup.visitas)).toEqual(['1001']);
  const bo = await (await (await entrar('bo')).ctx.get(API + 'carteira?vista=visitas&dia=' + dia)).json();
  expect(bo.visitas['1001'][0].risco).toBeUndefined();
  expect((await (await entrar('fin')).ctx.get(API + 'carteira?vista=visitas')).status()).toBe(403);
  const q = await (await (await entrar('sup')).ctx.get(API + 'carteira?vista=qualidade')).json();
  expect(q.anomalias).toEqual([]);
  expect(q.qualidade).toEqual([]); // artigos sem rota só para quem vê todas as rotas
  const qd = await (await (await entrar('dir')).ctx.get(API + 'carteira?vista=qualidade')).json();
  expect(qd.anomalias).toHaveLength(1);
  expect((await (await entrar('pro')).ctx.get(API + 'carteira?vista=qualidade')).status()).toBe(403);
});

test('terreno do dia: localização do OLAP por rota, prova de visita e estado da ligação', async () => {
  const dir = await (await (await entrar('dir')).ctx.get(API + 'terreno?data=2026-11-02')).json();
  expect(dir.olap).toBe(true);
  expect(dir.rotas.map((r) => r.rota)).toEqual(['1001', '1002']);
  expect(dir.rotas[0].visitas[0]).toMatchObject({ doc: 'FR 1', cli: 'C1' });
  expect(dir.rotas[0].inicio).toBe('08:00');
  const sup = await (await (await entrar('sup')).ctx.get(API + 'terreno?data=2026-11-02')).json();
  expect(sup.rotas.map((r) => r.rota)).toEqual(['1001']);
  for (const conta of ['fin', 'bo', 'log']) expect((await (await entrar(conta)).ctx.get(API + 'terreno?data=2026-11-02')).status(), conta).toBe(403);
  expect((await (await entrar('dir')).ctx.get(API + 'terreno?estado=1')).status()).toBe(403);
  const e = await (await (await entrar('sadm')).ctx.get(API + 'terreno?estado=1')).json();
  expect(e).toMatchObject({ ok: true, configurado: true });
});
