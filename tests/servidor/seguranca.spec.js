// Segurança do servidor PHP: CSRF na entrada e na saída, enumeração de contas, cabeçalhos, cookie de
// sessão, limites dos pedidos e detalhes técnicos dos erros (auditoria de 27-10-2026).
import { test, expect, request } from '@playwright/test';
import { entrar, csrf, tokenEntrada, erroEntrada, totp, BASE, SENHA } from './ajuda.js';

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
  const ctx = await request.newContext({ baseURL: BASE, extraHTTPHeaders: { Cookie: 'NAVALSESS=identificadorinventado1234567890' } });
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

// ── Segunda validação (ASVS 4.0.3 nível 2) ──

test('dois passos obrigatórios: a pessoa configura-os na entrada e depois são pedidos sempre', async () => {
  const BASE_MFA = 'http://127.0.0.1:8811';
  const ctx = await request.newContext({ baseURL: BASE_MFA });
  const r = await ctx.post('/geo/login.php', {
    form: { usuario: 'ti', senha: SENHA, redirect: '../reports/', csrf: await tokenEntrada(ctx) },
    maxRedirects: 0,
  });
  const html = await r.text();
  expect(html).toContain('id="qr-inscricao"');
  expect((await ctx.get('/reports/data/sessao.php')).status()).toBe(401); // ainda não entrou
  const segredo = (html.match(/id="chave-inscricao">([A-Z2-7 ]+)</) || [])[1].replace(/ /g, '');
  const t = (html.match(/name="csrf" value="([^"]+)"/) || [])[1];
  const errado = await ctx.post('/geo/login.php', { form: { codigo_inscricao: '000000', redirect: '../reports/', csrf: t }, maxRedirects: 0 });
  expect(await erroEntrada(errado)).toContain('Código inválido');
  const ok = await ctx.post('/geo/login.php', { form: { codigo_inscricao: totp(segredo), redirect: '../reports/', csrf: t }, maxRedirects: 0 });
  expect(ok.status()).toBe(302);
  expect((await ctx.get('/reports/data/sessao.php')).status()).toBe(200);
  // nova entrada: já pede o código (não volta a mostrar o QR)
  const outro = await request.newContext({ baseURL: BASE_MFA });
  const r2 = await outro.post('/geo/login.php', {
    form: { usuario: 'ti', senha: SENHA, redirect: '../reports/', csrf: await tokenEntrada(outro) },
    maxRedirects: 0,
  });
  const h2 = await r2.text();
  expect(h2).toContain('name="codigo"');
  expect(h2).not.toContain('id="qr-inscricao"');
});

test('mudar a palavra-passe: regras ASVS, recusa comuns, termina as outras sessões e mantém a actual', async () => {
  const a = (await entrar('aud')).ctx;
  const b = (await entrar('aud')).ctx; // outra sessão da mesma conta
  const mudar = async (actual, nova) => {
    const html = await (await a.get('/geo/senha.php')).text();
    const t = (html.match(/name="csrf" value="([^"]+)"/) || [])[1];
    return (await a.post('/geo/senha.php', { form: { csrf: t, actual, nova, confirmar: nova } })).text();
  };
  expect(await mudar('Errada-0000!', 'Outra-frase-segura-27')).toContain('actual está incorrecta');
  expect(await mudar(SENHA, 'curta-1')).toContain('pelo menos 12');
  expect(await mudar(SENHA, 'password1234')).toContain('muito comum');
  expect(await mudar(SENHA, 'Naval2026!!!')).toContain('nome da conta, do email ou da empresa');
  expect(await mudar(SENHA, 'Outra frase segura 27')).toContain('Palavra-passe alterada');
  expect((await a.get('/reports/data/sessao.php')).status()).toBe(200);
  expect((await b.get('/reports/data/sessao.php')).status()).toBe(401);
  // repõe a palavra-passe dos testes
  expect(await mudar('Outra frase segura 27', SENHA)).toContain('Palavra-passe alterada');
});

test('repor a palavra-passe de uma conta termina as sessões abertas dessa conta', async () => {
  const pro = (await entrar('pro')).ctx;
  expect((await pro.get('/reports/data/sessao.php')).status()).toBe(200);
  const sadm = (await entrar('sadm')).ctx;
  const r = await (await sadm.post('/reports/data/gestao.php', { data: { accao: 'senha', conta: 'pro', csrf: await csrf(sadm), confirmacao: SENHA } })).json();
  expect(r.ok).toBe(true);
  expect((await pro.get('/reports/data/sessao.php')).status()).toBe(401);
});

test('pasta privada protegida pelo Apache e ficheiros de bloqueio sem o texto escrito em contas inexistentes', async () => {
  const fs = await import('node:fs');
  const priv = new URL('./ambiente/dados-privados/', import.meta.url);
  expect(fs.readFileSync(new URL('.htaccess', priv), 'utf8')).toContain('Require all denied');
  await entrar('palavra.passe.no.campo.errado', 'x');
  const bloqueios = fs.readdirSync(new URL('bloqueios/', priv)).map((f) => fs.readFileSync(new URL('bloqueios/' + f, priv), 'utf8'));
  expect(bloqueios.join('\n')).not.toContain('palavra.passe.no.campo.errado');
});

test('CSP: relatórios das violações recebidos e gravados sem parâmetros dos endereços', async () => {
  const ctx = await request.newContext({ baseURL: BASE });
  const h = (await ctx.get('/geo/login.php')).headers();
  expect(h['content-security-policy-report-only']).toContain('report-uri /reports/data/csp.php');
  expect(h['reporting-endpoints']).toContain('csp=');
  expect((await ctx.get('/reports/data/csp.php')).status()).toBe(405);
  const r = await ctx.post('/reports/data/csp.php', {
    headers: { 'Content-Type': 'application/csp-report' },
    data: JSON.stringify({
      'csp-report': { 'document-uri': BASE + '/reports/?t=segredo', 'effective-directive': 'script-src-elem', 'blocked-uri': 'https://exemplo.test/x.js?k=1' },
    }),
  });
  expect(r.status()).toBe(204);
  const fs = await import('node:fs');
  const dir = new URL('./ambiente/dados-privados/registos/', import.meta.url);
  const f = fs.readdirSync(dir).find((x) => x.startsWith('csp-2'));
  const txt = fs
    .readFileSync(new URL(f, dir), 'utf8')
    .trim()
    .split('\n')
    .map((l) => JSON.stringify(JSON.parse(l)).replace(/\\\//g, '/'))
    .join('\n');
  expect(txt).toContain('https://exemplo.test/x.js');
  expect(txt).not.toContain('segredo');
  expect(txt).not.toContain('k=1');
});
