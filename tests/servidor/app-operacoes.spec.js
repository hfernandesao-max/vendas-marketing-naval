// App «Naval Operações» (/operacoes/) e entrega do dinheiro pelo vendedor (v1/caixa-entrega), com o servidor real:
// sem sessão vai à entrada certa; o vendedor só declara nas suas rotas; a tesouraria vê a declaração na conferência.
import { test, expect, request } from '@playwright/test';
import { rmSync } from 'node:fs';
import { BASE, entrar, API, csrf } from './ajuda.js';

test.describe.configure({ mode: 'serial' });
const hoje = new Date(Date.now() + 3600e3).toISOString().slice(0, 10); // em Luanda
test.beforeAll(() => {
  rmSync('tests/servidor/ambiente/dados-privados/caixa-entregas', { recursive: true, force: true });
});

test('app de operações: sem sessão envia para /geo/login.php com o regresso a ../operacoes/', async () => {
  const ctx = await request.newContext({ baseURL: BASE });
  const r = await ctx.get('/operacoes/', { maxRedirects: 0 });
  expect(r.status()).toBe(302);
  expect(r.headers()['location']).toBe('/geo/login.php?redirect=..%2Foperacoes%2F');
});

test('entrega de dinheiro: o vendedor declara só nas suas rotas; a tesouraria vê a declaração na conferência', async () => {
  const pro = (await entrar('pro')).ctx;
  const v = await (await pro.get(API + 'caixa-entrega?dia=' + hoje)).json();
  expect(v.ok).toBe(true);
  expect(v.rotas.map((r) => r.rota)).toEqual(['1002']);
  const post = async (ctx, corpo) => {
    const x = await ctx.post(API + 'caixa-entrega', { data: { dia: hoje, ...corpo, csrf: await csrf(ctx) } });
    return { status: x.status(), json: await x.json() };
  };
  expect((await post(pro, { accao: 'declarar', rota: '1001', notas: { 5000: 1 } })).status).toBe(403); // rota de outro
  const d = await post(pro, { accao: 'declarar', rota: '1002', notas: { 5000: 2, 1000: 1, moedas: 50 } });
  expect(d.status).toBe(200);
  expect(d.json.declaracao.total).toBe(11050);
  // quem não é vendedor não declara
  expect((await (await entrar('bo')).ctx.get(API + 'caixa-entrega?dia=' + hoje)).status()).toBe(403);
  // a tesouraria vê a declaração (só para a conferência)
  const fin = (await entrar('fin')).ctx;
  const c = await (await fin.get(API + 'caixa?dia=' + hoje)).json();
  expect(c.declaracoes['1002'].total).toBe(11050);
});

test('apps: o super administrador pode instalar todas (escolhe qual); os outros só as do seu perfil', async () => {
  const sadm = (await entrar('sadm')).ctx;
  const v = await (await sadm.get(API + 'app-telemovel')).json();
  expect(v.apps.map((a) => a.app)).toEqual(['gestao', 'operacoes', 'portal']);
  const pedir = async (ctx, app) => {
    const r = await ctx.post(API + 'app-telemovel', { data: { accao: 'codigo', app, csrf: await csrf(ctx) } });
    return { status: r.status(), json: await r.json() };
  };
  const g = await pedir(sadm, 'operacoes');
  expect(g.status).toBe(200);
  expect(g.json.app).toBe('operacoes');
  // o vendedor só tem a app de operações: não pode pedir a de gestão
  const pro = (await entrar('pro')).ctx;
  expect((await (await pro.get(API + 'app-telemovel')).json()).apps.map((a) => a.app)).toEqual(['operacoes']);
  expect((await pedir(pro, 'gestao')).status).toBe(403);
});
