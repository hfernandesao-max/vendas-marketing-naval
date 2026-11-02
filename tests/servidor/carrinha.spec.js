// Conciliação da carrinha: matriz de acessos, contagem cega, âmbito por rota, CSRF, motivo obrigatório e aprovação.
import { test, expect } from '@playwright/test';
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { entrar, API, csrf } from './ajuda.js';

test.describe.configure({ mode: 'serial' });
const PRIV = 'tests/servidor/ambiente/dados-privados';
const dia = new Date(Date.now() + 3600e3).toISOString().slice(0, 10); // Luanda (UTC+1)
const rota = (q) => ({
  s0: { A1: { n: 'Fraldas M', q: 10 } },
  s1: { A1: { n: 'Fraldas M', q: q } },
  v: { A1: { n: 'Fraldas M', u: 'CX', q: 26, t: 26000 } },
  mov: { A1: { c: 20, d: 0 } },
});

test.beforeAll(() => {
  const d = `${PRIV}/carrinha/${dia}`;
  rmSync(d, { recursive: true, force: true });
  mkdirSync(d, { recursive: true });
  writeFileSync(
    `${d}/_dados.json`,
    JSON.stringify({ data: dia, gerado: dia + ' 18:00:00', movimentos: 'separados', precos: { A1: 1000 }, rotas: { 1001: rota(4), 1002: rota(4) } }),
  );
});

test('conciliação da carrinha: acessos, contagem cega, âmbito, motivo e aprovação', async () => {
  const URL = API + 'conciliacao-carrinha?data=' + dia;
  expect((await (await entrar('bo')).ctx.get(URL)).status()).toBe(403);
  const sup = (await entrar('sup')).ctx;
  const vs = await (await sup.get(URL)).json();
  expect(vs.rotas.map((r) => r.rota)).toEqual(['1001']);
  expect(vs.gere).toBe(false);
  expect(vs.rotas[0].linhas[0].esperado).toBe(4); // quem só vê, vê tudo
  expect(
    (await sup.post(API + 'conciliacao-carrinha', { data: { accao: 'contar', data: dia, rota: '1001', contagem: { A1: 1 }, csrf: await csrf(sup) } })).status(),
  ).toBe(403);

  const log = (await entrar('log')).ctx;
  const v = await (await log.get(URL)).json();
  expect(v.gere).toBe(true);
  expect(v.rotas[0].cega).toBe(true);
  expect(Object.keys(v.rotas[0].linhas[0])).not.toContain('esperado');
  const c = await csrf(log);
  expect((await log.post(API + 'conciliacao-carrinha', { data: { accao: 'contar', data: dia, rota: '1001', contagem: { A1: 3 } } })).status()).toBe(403);
  const r = await (await log.post(API + 'conciliacao-carrinha', { data: { accao: 'contar', data: dia, rota: '1001', contagem: { A1: 3 }, csrf: c } })).json();
  expect(r.resumo).toMatchObject({ estado: 'por_aprovar', diferencas: 1, falta_kz: 1000 });
  expect((await log.post(API + 'conciliacao-carrinha', { data: { accao: 'aprovar', data: dia, rota: '1001', motivos: {}, csrf: c } })).status()).toBe(400);
  const a = await (
    await log.post(API + 'conciliacao-carrinha', { data: { accao: 'aprovar', data: dia, rota: '1001', motivos: { A1: { motivo: 'avaria' } }, csrf: c } })
  ).json();
  expect(a.resumo.estado).toBe('aprovada');
  const dir = await (await (await entrar('dir')).ctx.get(URL)).json();
  const r1 = dir.rotas.find((x) => x.rota === '1001');
  expect(r1.resumo.estado).toBe('aprovada');
  expect(r1.aprovado_por).toBe('log');
  expect(r1.motivos.A1.motivo).toBe('avaria');
  expect(dir.rotas.find((x) => x.rota === '1002').resumo.estado).toBe('por_contar');
});
