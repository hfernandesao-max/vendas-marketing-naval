// Falha momentânea da rede (ERR_NETWORK_CHANGED ao trocar de Wi-Fi, VPN, computador a acordar): o portal repete o
// pedido sozinho e a pessoa não vê «Sem ligação ao servidor do portal».
import { test, expect } from '@playwright/test';

test('rede: o token e as leituras repetem-se sozinhos depois de uma falha momentânea da rede', async ({ page }) => {
  const consola = [];
  page.on('console', (m) => consola.push(m.text()));
  let token = 0,
    leituras = 0;
  await page.route('**/v1/auth/token', (r) => (++token === 1 ? r.abort('internetdisconnected') : r.fulfill({ json: { access_token: 't', expires_in: 900 } })));
  await page.route('**/v1/saude', (r) => (++leituras <= 2 ? r.abort('connectionreset') : r.fulfill({ json: { ok: true } })));
  await page.goto('/gestao/index.html');
  const d = await page.evaluate(() => import('/reports/js/api.js').then((m) => m.apiJson('saude')));
  expect(d).toEqual({ ok: true });
  expect(token).toBe(2);
  expect(leituras).toBe(3);
  expect(consola.filter((x) => /Sem ligação ao servidor/.test(x))).toEqual([]);
});

test('rede: as gravações (POST) não se repetem sozinhas (não ficam em dobro)', async ({ page }) => {
  let n = 0;
  await page.route('**/v1/auth/token', (r) => r.fulfill({ json: { access_token: 't', expires_in: 900 } }));
  await page.route('**/v1/preferencias', (r) => {
    n++;
    return r.abort('connectionreset');
  });
  await page.goto('/gestao/index.html');
  const erro = await page.evaluate(() =>
    import('/reports/js/api.js').then((m) =>
      m.apiFetch('preferencias', { method: 'POST', body: '{}' }).then(
        () => 'ok',
        () => 'erro',
      ),
    ),
  );
  expect(erro).toBe('erro');
  expect(n).toBe(1);
});
