// App no telemóvel: instalação só pelo código QR pessoal do portal, registo do equipamento, um equipamento activo por
// pessoa (o anterior é desactivado), entrada com biometria (chave de acesso WebAuthn, com um autenticador virtual do
// Chromium) e lista dos equipamentos para o super administrador.
import { test, expect } from '@playwright/test';
import { readdirSync, readFileSync } from 'node:fs';
import { entrar, csrf, API, SENHA } from './ajuda.js';

// As chaves de acesso não aceitam endereços IP como domínio: a app abre-se por «localhost»
const LOCAL = 'http://localhost:8810';
const SAIDA = 'tests/servidor/ambiente/dados-privados/email-saida';
const avisos = () => {
  try {
    return readdirSync(SAIDA)
      .filter((f) => f.endsWith('.html'))
      .map((f) => readFileSync(SAIDA + '/' + f, 'utf8'))
      .filter((h) => h.includes('foi activada em'));
  } catch (e) {
    return [];
  }
};

async function novoCodigo(login) {
  const { ctx } = await entrar(login);
  const r = await (await ctx.post(API + 'app-telemovel', { data: { accao: 'codigo', csrf: await csrf(ctx) } })).json();
  expect(r.ok).toBe(true);
  return { ctx, ...r };
}

/** Telemóvel com a app aberta pelo ícone (modo «app») e um leitor biométrico virtual. */
async function telemovel(browser) {
  const ctx = await browser.newContext({ baseURL: LOCAL });
  await ctx.addInitScript(() => {
    const original = window.matchMedia.bind(window);
    window.matchMedia = (q) =>
      /display-mode:\s*standalone/.test(q) ? { matches: true, media: q, addEventListener() {}, removeEventListener() {} } : original(q);
  });
  const page = await ctx.newPage();
  const cdp = await ctx.newCDPSession(page);
  await cdp.send('WebAuthn.enable');
  await cdp.send('WebAuthn.addVirtualAuthenticator', {
    options: {
      protocol: 'ctap2',
      transport: 'internal',
      hasResidentKey: true,
      hasUserVerification: true,
      isUserVerified: true,
      automaticPresenceSimulation: true,
    },
  });
  return { ctx, page };
}

test('código QR: pessoal, de uso único e com validade; sem código não há instalação nem manifesto', async () => {
  const { ctx, codigo, url, nome } = await novoCodigo('dir');
  expect(nome).toBe('Naval Gestão'); // perfil com a app de gestão
  expect(url).toContain('/app/instalar.php?c=' + codigo);
  const m = await (await ctx.get('/app/manifesto.php?c=' + codigo)).json();
  expect(m.start_url).toBe('activar.php?c=' + codigo);
  expect(m.short_name).toBe('Naval Gestão');
  expect((await ctx.get('/app/manifesto.php?c=invalido')).status()).toBe(404);
  const inv = await (await ctx.get('/app/instalar.php?c=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA')).text();
  expect(inv).toContain('Este código QR expirou ou já foi usado');
  expect(inv).not.toContain('rel="manifest"');
  // o portal e a página de entrada já não oferecem a instalação
  for (const p of ['/geo/login.php', '/reports/']) expect(await (await ctx.get(p)).text()).not.toContain('rel="manifest"');
  // sem CSRF não gera código; pedidos de activação de outro site são recusados
  expect((await ctx.post(API + 'app-telemovel', { data: { accao: 'codigo' } })).status()).toBe(403);
  expect((await ctx.post('/app/chave.php', { data: { f: 'activar', codigo }, headers: { Origin: 'https://exemplo.com' } })).status()).toBe(403);
});

test('instalar pelo QR: activa o equipamento, pede a biometria, entra com ela e o equipamento anterior é desactivado @navegador', async ({ browser }) => {
  test.setTimeout(90000);
  const n0 = avisos().length;
  const { codigo } = await novoCodigo('dir');
  const t1 = await telemovel(browser);
  // a câmara abre a página de instalação; aberta como app, segue para a activação
  await t1.page.goto('/app/instalar.php?c=' + codigo);
  await expect(t1.page).toHaveURL(/activar\.php/);
  await expect(t1.page.locator('#act-titulo')).toHaveText('App activada');
  await expect(t1.page.locator('#act-quem')).toHaveText('Teste DIR');
  await t1.page.locator('#act-bio-b').click(); // Face ID / impressão digital (autenticador virtual)
  await expect(t1.page).toHaveURL(/\/gestao\/$/);
  await expect(t1.page).toHaveTitle(/Naval Gestão/);
  expect(avisos().length).toBe(n0 + 1); // aviso por correio à própria pessoa
  // o código só serve uma vez
  const de2 = await t1.ctx.request.post('/app/chave.php', { data: { f: 'activar', codigo }, headers: { Origin: LOCAL } });
  expect((await de2.json()).erro).toContain('expirou ou já foi usado');

  // sessão terminada: ao abrir a app, a entrada rápida pede logo a biometria e entra (sem palavra-passe nem toques)
  const eqCookie = (await t1.ctx.cookies()).filter((x) => x.name === 'naval_eq');
  expect(eqCookie.length).toBe(1);
  await t1.ctx.clearCookies();
  await t1.ctx.addCookies(eqCookie);
  await t1.page.goto('/gestao/').catch(() => {});
  await t1.page.waitForURL(/\/gestao\/$/);
  await expect(t1.page).toHaveTitle(/Naval Gestão/);
  // sem o cookie do equipamento (ex.: dados do navegador limpos): na app a página da palavra-passe nunca aparece,
  // segue para a entrada com a biometria pelo equipamento guardado na própria app
  await t1.ctx.clearCookies();
  await t1.page.goto('/geo/login.php?redirect=../gestao/').catch(() => {});
  await t1.page.waitForURL(/\/gestao\/$/);
  await expect(t1.page).toHaveTitle(/Naval Gestão/);

  // a mesma pessoa activa a app noutro telemóvel: o primeiro deixa de funcionar
  const { ctx: portal, codigo: c2 } = await novoCodigo('dir');
  const t2 = await telemovel(browser);
  await t2.page.goto('/app/activar.php?c=' + c2);
  await expect(t2.page.locator('#act-titulo')).toHaveText('App activada');
  await expect(t2.page.locator('#act-depois')).toHaveCount(0); // o Face ID é obrigatório: não há «Agora não»
  // activação interrompida antes do Face ID: a app não abre e volta a pedir a biometria
  await t2.page.goto('/gestao/');
  await expect(t2.page).toHaveURL(/activar\.php$/);
  await t2.page.locator('#act-bio-b').click();
  await expect(t2.page).toHaveURL(/\/gestao\/$/);
  await t1.page.goto('/gestao/').catch(() => {}); // a sessão termina: a app volta à entrada rápida
  await t1.page.waitForURL(/entrar\.php/);
  await expect(t1.page.locator('h1')).toHaveText('App desactivada');
  await expect(t1.page.locator('#usuario')).toHaveCount(0); // nunca pede a palavra-passe

  // registo: o super administrador vê os equipamentos; a própria pessoa vê o seu
  const meu = await (await portal.get(API + 'app-telemovel')).json();
  expect(meu.meu.estado).toBe('activo');
  expect(meu.meu.biometria).toBe(true);
  const { ctx: adm } = await entrar('sadm');
  const l = await (await adm.get(API + 'app-telemovel')).json();
  const dir = l.lista.filter((x) => x.login === 'dir');
  expect(dir.map((x) => x.estado).sort()).toEqual(['activo', 'substituido']);
  expect(dir.find((x) => x.estado === 'substituido').biometria).toBe(false); // as chaves do equipamento antigo apagam-se
  expect(l.resumo.activos).toBeGreaterThanOrEqual(1);
  // desactivar pelo administrador: a app do segundo telemóvel termina a sessão
  const r = await (await adm.post(API + 'app-telemovel', { data: { accao: 'desactivar', id: meu.meu.id, csrf: await csrf(adm) } })).json();
  expect(r.ok).toBe(true);
  await t2.page.goto('/gestao/').catch(() => {});
  await t2.page.waitForURL(/entrar\.php/);
  await expect(t2.page.locator('h1')).toHaveText('App desactivada');
  const reg = await (await adm.get(API + 'gestao')).json();
  const accoes = (reg.registos || []).map((x) => x.accao);
  for (const a of ['app_codigo_gerado', 'app_activada', 'app_substituida', 'app_desactivada', 'app_biometria_activada']) expect(accoes, a).toContain(a);
  await t1.ctx.close();
  await t2.ctx.close();
});

test('sem o código QR a app não funciona: navegador normal e ícone adicionado à mão @navegador', async ({ browser }) => {
  // app de gestão no navegador (sem equipamento registado): página «instale pelo código QR»
  const ctx = await browser.newContext({ baseURL: LOCAL });
  const page = await ctx.newPage();
  await page.goto('/gestao/');
  await page.fill('#usuario', 'dir');
  await page.fill('#senha', SENHA);
  await page.locator('form button[type=submit]').click();
  await expect(page).toHaveURL(/so-pelo-qr\.php$/);
  await expect(page.locator('h1')).toHaveText('Instale a app pelo código QR');
  await ctx.close();
  // ícone adicionado à mão (modo app sem equipamento registado): a entrada não aceita e explica como instalar
  const t = await telemovel(browser);
  await t.page.goto('/geo/login.php');
  await expect(t.page.locator('#app-aviso')).toContainText('não foi instalada pelo código QR');
  await expect(t.page.locator('#usuario')).toBeHidden();
  await t.ctx.close();
});
