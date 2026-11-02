// Velocidade (2.ª parte) no servidor: linhas de artigos pedidas à parte (include_items=only) e pedidos condicionais
// resolvidos cedo (ETag dos metadados da origem e do âmbito de quem pede: 304 sem ler nem converter os dados).
import { test, expect } from '@playwright/test';
import { existsSync, rmSync, statSync, utimesSync, writeFileSync } from 'node:fs';
import { entrar, API, DIA } from './ajuda.js';

test('vendas: documentos sem linhas por omissão; linhas à parte com include_items=only; ambas com include_items=1', async () => {
  const { ctx } = await entrar('dir');
  const docs = await (await ctx.get(`${API}vendas?${DIA}`)).json();
  expect(Array.isArray(docs)).toBe(true);
  expect(docs.length).toBeGreaterThan(0);
  const so = await (await ctx.get(`${API}vendas?${DIA}&include_items=only`)).json();
  expect(Object.keys(so)).toEqual(['artigos']);
  expect(so.artigos.length).toBeGreaterThan(0);
  const tudo = await (await ctx.get(`${API}vendas?${DIA}&include_items=1`)).json();
  expect(tudo.vendas.length).toBe(docs.length);
  expect(tudo.artigos).toEqual(so.artigos);
  // as linhas seguem o âmbito do perfil: o supervisor só vê as da sua rota
  const sup = await (await (await entrar('sup')).ctx.get(`${API}vendas?${DIA}&include_items=only`)).json();
  expect(sup.artigos.length).toBeGreaterThan(0);
  expect(new Set(sup.artigos.map((a) => a.rota))).toEqual(new Set(['1001']));
});

test('ETag antecipado: 304 sem corpo para a mesma pessoa; outra conta ou outro perfil nunca recebe 304 com esse ETag', async () => {
  const dir = (await entrar('dir')).ctx;
  const com = (await entrar('com')).ctx;
  // cadastros de teste (o ambiente de teste não tem o repositório rtm-clientes)
  const cad = 'reports/data/cadastros.json';
  const criado = !existsSync(cad);
  if (criado) writeFileSync(cad, JSON.stringify({ cadastros: [{ rota: '1001', cliente: 'X', nif: '1' }], cadastros_diarios: [], carteira: [] }));
  try {
    await verificar(dir, com);
  } finally {
    if (criado) rmSync(cad);
  }
});
async function verificar(dir, com) {
  for (const rota of [`vendas?${DIA}`, `vendas?${DIA}&include_items=only`, 'dados/rotas', 'dados/cargas', 'cadastros']) {
    const r = await dir.get(API + rota);
    expect(r.status(), rota).toBe(200);
    const etag = r.headers()['etag'];
    expect(etag, rota).toMatch(/^W\/"a[0-9a-f]{31}"$/);
    expect(r.headers()['cache-control']).toBe('private, no-cache');
    const r304 = await dir.get(API + rota, { headers: { 'If-None-Match': etag } });
    expect(r304.status(), rota).toBe(304);
    expect(await r304.text()).toBe('');
    expect(r304.headers()['etag']).toBe(etag);
    // outra conta (outro perfil) com o mesmo ETag: resposta completa
    const outro = await com.get(API + rota, { headers: { 'If-None-Match': etag } });
    expect(outro.status(), rota).not.toBe(304);
  }
}

test('ETag antecipado: muda quando muda a origem dos dados (ficheiro)', async () => {
  const { ctx } = await entrar('dir');
  const r = await ctx.get(API + 'dados/rotas');
  const etag = r.headers()['etag'];
  const f = 'reports/data/rotas.json';
  const st = statSync(f);
  try {
    utimesSync(f, st.atime, new Date(st.mtimeMs + 5000));
    const depois = await ctx.get(API + 'dados/rotas', { headers: { 'If-None-Match': etag } });
    expect(depois.status()).toBe(200);
    expect(depois.headers()['etag']).not.toBe(etag);
  } finally {
    utimesSync(f, st.atime, st.mtime);
  }
});

test('portal: import map com todos os módulos; pré-carregamento só do arranque e da página principal', async () => {
  const { ctx } = await entrar('dir');
  const html = await (await ctx.get('/reports/')).text();
  const mapa = JSON.parse(html.match(/<script type="importmap">([^<]+)<\/script>/)[1]).imports;
  for (const m of ['./js/main.js', './js/app.js', './js/paginas/acessos.js', './js/paginas/sap.js', './js/i18n/en.js', './js/i18n/ti.js'])
    expect(mapa[m], m).toMatch(/\?v=[0-9a-f]{10}$/);
  const pre = [...html.matchAll(/<link rel="modulepreload" href="([^"?]+)\?v=/g)].map((m) => m[1]);
  for (const m of ['js/app.js', 'js/api.js', 'js/i18n/i18n.js', 'js/paginas/modulos.js', 'js/paginas/inicio.js']) expect(pre, m).toContain(m);
  for (const m of ['js/paginas/acessos.js', 'js/paginas/sap.js', 'js/paginas/plano-cargas.js', 'js/i18n/en.js', 'js/i18n/ti.js', 'js/main.js'])
    expect(pre, m).not.toContain(m);
});
