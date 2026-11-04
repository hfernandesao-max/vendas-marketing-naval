// App «Naval Gestão» (/gestao/): sem sessão vai à página de entrada certa e, depois de entrar, volta à app.
import { test, expect, request, devices } from '@playwright/test';
import { BASE, SENHA } from './ajuda.js';

test('app de gestão: sem sessão envia para /geo/login.php com o regresso a ../gestao/', async () => {
  const ctx = await request.newContext({ baseURL: BASE });
  const r = await ctx.get('/gestao/', { maxRedirects: 0 });
  expect(r.status()).toBe(302);
  expect(r.headers()['location']).toBe('/geo/login.php?redirect=..%2Fgestao%2F');
});

test('app de gestão: no iPhone, entrar abre a app; sem «App de gestão» na matriz vai para o portal @navegador', async ({ browser }) => {
  for (const [conta, destino, titulo] of [
    ['dir', '/gestao/', 'Naval Gestão'],
    ['ti', '/reports/', 'Portal RTM'],
  ]) {
    const ctx = await browser.newContext({ ...devices['iPhone 13'] });
    const page = await ctx.newPage();
    await page.goto(BASE + '/gestao/');
    await page.fill('#usuario', conta);
    await page.fill('#senha', SENHA);
    await page.locator('form button[type=submit]').click();
    await expect(page, conta).toHaveURL(new RegExp(destino + '$'));
    await expect(page, conta).toHaveTitle(new RegExp(titulo));
    await ctx.close();
  }
});
