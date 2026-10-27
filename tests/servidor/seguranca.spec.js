// Segurança do servidor PHP: CSRF na entrada e na saída, enumeração de contas, cabeçalhos, cookie de
// sessão, limites dos pedidos e detalhes técnicos dos erros (auditoria de 27-10-2026).
import { test, expect, request } from '@playwright/test';
import { entrar, csrf, tokenEntrada, erroEntrada, BASE, SENHA } from './ajuda.js';

test('entrada sem o código anti-falsificação (CSRF) é recusada e não abre sessão', async () => {
  const ctx = await request.newContext({ baseURL: BASE });
  const r = await ctx.post('/geo/login.php', { form: { usuario: 'fin', senha: SENHA, redirect: '../reports/' }, maxRedirects: 0 });
  expect(r.status()).toBe(200);
  expect(await erroEntrada(r)).toContain('expirou');
  expect((await ctx.get('/reports/data/sessao.php')).status()).toBe(401);
  // com o código certo entra (e a sessão autenticada recebe um código CSRF novo)
  const t = await tokenEntrada(ctx);
  const ok = await ctx.post('/geo/login.php', { form: { usuario: 'fin', senha: SENHA, redirect: '../reports/', csrf: t }, maxRedirects: 0 });
  expect(ok.status()).toBe(302);
  expect(await csrf(ctx)).not.toBe(t);
});

test('conta inexistente e palavra-passe errada dão a mesma resposta (sem enumeração de contas)', async () => {
  const a = await (await entrar('nao.existe.' + Date.now(), 'Errada-123!')).resposta;
  const b = await (await entrar('pro', 'Errada-123!')).resposta; // conta que existe (1.ª falha)
  expect(await erroEntrada(a)).toContain('Utilizador ou palavra-passe incorrectos');
  expect(await erroEntrada(a)).toBe(await erroEntrada(b));
});

test('sair sem o código da sessão pede confirmação; com o código termina a sessão', async () => {
  const { ctx } = await entrar('dir');
  const r = await ctx.get('/geo/auth/logout.php', { maxRedirects: 0 });
  expect(r.status()).toBe(200);
  expect(await r.text()).toContain('Terminar a sessão na plataforma?');
  expect((await ctx.get('/reports/data/sessao.php')).status()).toBe(200);
  const p = await ctx.post('/geo/auth/logout.php', { form: { csrf: await csrf(ctx) }, maxRedirects: 0 });
  expect(p.status()).toBe(302);
  expect((await ctx.get('/reports/data/sessao.php')).status()).toBe(401);
});

test('cabeçalhos de segurança e cookie de sessão HttpOnly + SameSite', async () => {
  const ctx = await request.newContext({ baseURL: BASE });
  const r = await ctx.get('/geo/login.php');
  const h = r.headers();
  expect(h['x-content-type-options']).toBe('nosniff');
  expect(h['x-frame-options']).toBe('SAMEORIGIN');
  expect(h['referrer-policy']).toBe('strict-origin-when-cross-origin');
  expect(h['content-security-policy']).toContain("frame-ancestors 'self'");
  expect(h['content-security-policy']).toContain("object-src 'none'");
  expect(h['content-security-policy-report-only']).toContain('https://maps.googleapis.com');
  expect(h['x-powered-by']).toBeUndefined();
  const cookie = h['set-cookie'] || '';
  expect(cookie).toMatch(/HttpOnly/i);
  expect(cookie).toMatch(/SameSite=Lax/i);
  expect(cookie).not.toMatch(/Secure/i); // em HTTP; em HTTPS o PHP acrescenta Secure
});

test('identificador de sessão inventado não é aceite (modo estrito)', async () => {
  const ctx = await request.newContext({ baseURL: BASE, extraHTTPHeaders: { Cookie: 'PHPSESSID=identificadorinventado1234567890' } });
  const r = await ctx.get('/geo/login.php');
  expect(r.headers()['set-cookie'] || '').not.toContain('identificadorinventado');
});

test('vendas: período inválido, invertido ou demasiado longo é recusado (400)', async () => {
  const { ctx } = await entrar('dir');
  for (const q of ['start_date=20261340&end_date=20261341', 'start_date=20261027&end_date=20261001', 'start_date=20240101&end_date=20261027']) {
    const r = await ctx.get('/reports/data/api.php?' + q);
    expect(r.status(), q).toBe(400);
    expect((await r.json()).ok).toBe(false);
  }
});

test('estado do Pinguim BO: endereço interno só para SADM/TI', async () => {
  const com = await (await (await entrar('com')).ctx.get('/reports/data/api.php?ping=1')).json();
  expect(com.ok).toBe(true);
  expect(com.origem).toBe('Pinguim BO');
  const ti = await (await (await entrar('ti')).ctx.get('/reports/data/api.php?ping=1')).json();
  expect(ti.origem).toContain('127.0.0.1');
});

test('GEO: chave do Google Maps vem do .env (escapada) e as bibliotecas são locais', async () => {
  const { ctx } = await entrar('sadm');
  const html = await (await ctx.get('/geo/geo.php')).text();
  expect(html).toContain('https://maps.googleapis.com/maps/api/js?key=chave-de-teste&amp;v=3.64&amp;callback=initMap');
  expect(html).not.toMatch(/AIza[0-9A-Za-z_-]{20,}/);
  expect(html).toContain('vendor/lucide-1.62.0.min.js');
  expect(html).not.toContain('unpkg.com');
  expect(html).toMatch(/auth\/logout\.php\?t=[0-9a-f]{32}/);
  expect((await ctx.get('/geo/vendor/lucide-1.62.0.min.js')).status()).toBe(200);
});
