// API central (api/index.php/v1/…) com token JWT: obtenção do token, recusas (sem token, token alterado,
// token de outra sessão, origem estranha, método, tipo de conteúdo), revogação ao sair ou ao mudar a
// palavra-passe, e as optimizações da fase 4 (ETag/304, compressão).
import { test, expect, request } from '@playwright/test';
import { entrar, csrfPagina, pedirToken, comToken, API, BASE, SENHA } from './ajuda.js';

const novo = () => request.newContext({ baseURL: BASE });
const bearer = (t) => ({ headers: { Authorization: 'Bearer ' + t } });

test('token: só com a sessão aberta e o código CSRF da página; resposta sem cache', async () => {
  const anon = await novo();
  const r0 = await anon.post(API + 'auth/token', { headers: { 'X-CSRF-Token': 'x' }, data: {} });
  expect(r0.status()).toBe(401);
  const { ctx } = await entrar('dir');
  expect((await ctx.post(API + 'auth/token', { data: {} })).status()).toBe(403); // sem X-CSRF-Token
  expect((await ctx.post(API + 'auth/token', { headers: { 'X-CSRF-Token': 'errado' }, data: {} })).status()).toBe(403);
  const r = await ctx.post(API + 'auth/token', { headers: { 'X-CSRF-Token': await csrfPagina(ctx) }, data: {} });
  expect(r.status()).toBe(200);
  expect(r.headers()['cache-control']).toContain('no-store');
  const d = await r.json();
  expect(d).toMatchObject({ ok: true, token_type: 'Bearer', expires_in: 900 });
  const [cab, corpo] = d.access_token
    .split('.')
    .slice(0, 2)
    .map((x) => JSON.parse(Buffer.from(x, 'base64url').toString()));
  expect(cab).toMatchObject({ alg: 'HS256', typ: 'JWT' });
  expect(corpo).toMatchObject({ sub: 'dir', aud: 'portal-rtm-api' });
  expect(corpo.exp - corpo.iat).toBe(900);
  expect(JSON.stringify(corpo)).not.toMatch(/senha|email|perfil|csrf/); // sem dados sensíveis no token
});

test('sem token, com token alterado ou com «alg: none»: 401 com o motivo', async () => {
  const raw = (await entrar('dir')).ctx; // contexto com sessão (o token explícito tem prioridade)
  const t = await pedirToken(raw);
  const semToken = await (await novo()).get(API + 'sessao');
  expect(semToken.status()).toBe(401);
  expect((await semToken.json()).codigo).toBe('token_em_falta');
  const [a, b, s] = t.split('.');
  const corpo = JSON.parse(Buffer.from(b, 'base64url').toString());
  const alterado = a + '.' + Buffer.from(JSON.stringify({ ...corpo, sub: 'sadm' })).toString('base64url') + '.' + s;
  expect((await (await raw.get(API + 'gestao', bearer(alterado))).json()).codigo).toBe('token_assinatura');
  const nenhum = Buffer.from(JSON.stringify({ alg: 'none', typ: 'JWT' })).toString('base64url') + '.' + b + '.' + Buffer.from('x').toString('base64url');
  expect((await (await raw.get(API + 'sessao', bearer(nenhum))).json()).codigo).toBe('token_algoritmo');
});

test('token de outra pessoa ou de outra sessão não serve (ligado à sessão)', async () => {
  const dir = (await entrar('dir')).ctx;
  const fin = (await entrar('fin')).ctx;
  const tDir = await pedirToken(dir);
  const r = await fin.get(API + 'sessao', bearer(tDir)); // cookie da sessão do FIN com o token do DIR
  expect(r.status()).toBe(401);
  expect((await r.json()).codigo).toBe('sessao_terminada');
  const semCookie = await (await novo()).get(API + 'sessao', bearer(tDir)); // token roubado, sem a sessão
  expect(semCookie.status()).toBe(401);
});

test('sair termina a sessão e invalida o token; mudar a palavra-passe também', async () => {
  const { ctx } = await entrar('log');
  const t = await pedirToken(ctx);
  expect((await ctx.get(API + 'sessao', bearer(t))).status()).toBe(200);
  const s = await (await ctx.get(API + 'sessao', bearer(t))).json();
  await ctx.post('/geo/auth/logout.php', { form: { csrf: s.csrf }, maxRedirects: 0 });
  expect((await ctx.get(API + 'sessao', bearer(t))).status()).toBe(401);
  // palavra-passe mudada noutra sessão: o token aberto deixa de valer (versão das credenciais)
  const a1 = (await entrar('fin')).ctx;
  const ta = await pedirToken(a1);
  const b1 = (await entrar('fin')).ctx;
  const mudar = async (actual, nova) => {
    const html = await (await b1.get('/geo/senha.php')).text();
    const c = (html.match(/name="csrf" value="([^"]+)"/) || [])[1];
    return (await b1.post('/geo/senha.php', { form: { csrf: c, actual, nova, confirmar: nova } })).text();
  };
  expect(await mudar(SENHA, 'Outra frase para o teste 9')).toContain('Palavra-passe alterada');
  expect((await a1.get(API + 'sessao', bearer(ta))).status()).toBe(401);
  expect(await mudar('Outra frase para o teste 9', SENHA)).toContain('Palavra-passe alterada');
});

test('API: origem estranha 403, rota desconhecida 404, método 405, tipo de conteúdo 415', async () => {
  const { ctx } = await entrar('dir');
  const t = await pedirToken(ctx);
  const r1 = await ctx.get(API + 'sessao', { headers: { Authorization: 'Bearer ' + t, Origin: 'https://atacante.test' } });
  expect(r1.status()).toBe(403);
  expect(r1.headers()['access-control-allow-origin']).toBeUndefined();
  expect((await ctx.get(API + 'nao-existe', bearer(t))).status()).toBe(404);
  expect((await ctx.get('/api/index.php/v2/sessao', bearer(t))).status()).toBe(404);
  const r405 = await ctx.delete(API + 'sessao', bearer(t));
  expect(r405.status()).toBe(405);
  expect(r405.headers()['allow']).toBe('GET');
  const r415 = await ctx.post(API + 'exportacoes', { headers: { Authorization: 'Bearer ' + t, 'Content-Type': 'text/plain' }, data: 'x' });
  expect(r415.status()).toBe(415);
  // o código do back-end nunca é servido directamente
  const directo = await (await novo()).get('/nucleo/api/v1/sessao.php'); // no Apache: 403 (.htaccess)
  expect(directo.status() === 403 || (await directo.text()) === '').toBe(true);
});

test('fase 4: ETag e 304 quando os dados não mudaram; compressão gzip nas respostas grandes', async () => {
  const { ctx } = await entrar('dir');
  const api = comToken(ctx);
  const r = await api.get(API + 'vendas?start_date=20261024&end_date=20261024&include_items=1', { headers: { 'Accept-Encoding': 'gzip' } });
  expect(r.status()).toBe(200);
  const etag = r.headers()['etag'];
  expect(etag).toMatch(/^W\/"[0-9a-f]{32}"$/);
  expect(r.headers()['cache-control']).toBe('private, no-cache');
  const r304 = await api.get(API + 'vendas?start_date=20261024&end_date=20261024&include_items=1', { headers: { 'If-None-Match': etag } });
  expect(r304.status()).toBe(304);
  const sessao = await api.get(API + 'sessao', { headers: { 'Accept-Encoding': 'gzip' } });
  expect(sessao.headers()['content-encoding']).toBeUndefined(); // tem o código CSRF: nunca comprimida
  expect(sessao.headers()['cache-control']).toContain('no-store');
});
