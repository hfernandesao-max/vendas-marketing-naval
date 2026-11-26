// «App no telemóvel» no menu da conta: estado do equipamento, código QR pessoal com validade e desactivação.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

test('menu da conta: gerar o código QR pessoal para instalar a app e ver o equipamento activo', async ({ page }) => {
  const pedidos = [];
  await page.route('**/v1/sessao*', (r) => r.fulfill({ json: { login: 'henrique', nome: 'Henrique Fernandes', perfil: 'SADM', csrf: 'x', permissoes: {} } }));
  await page.route('**/v1/app-telemovel*', (r) => {
    if (r.request().method() !== 'POST')
      return r.fulfill({
        json: {
          ok: true,
          app: 'gestao',
          nome: 'RTM Gestão',
          meu: {
            id: 'a'.repeat(24),
            modelo: 'iPhone',
            so: 'iOS 26.0',
            activado: '2026-10-20 09:15:00',
            ultimo_uso: '2026-10-26 08:02:00',
            biometria: true,
            estado: 'activo',
          },
        },
      });
    pedidos.push(r.request().postDataJSON());
    return r.fulfill({
      json: {
        ok: true,
        codigo: 'X'.repeat(32),
        url: 'https://geo.naval.co.ao:2051/app/instalar.php?c=' + 'X'.repeat(32),
        ate: '2026-10-26 12:10:00',
        nome: 'RTM Gestão',
      },
    });
  });
  const erros = await abrir(page, '#inicio');
  await page.locator('#conta-b').click();
  await page.locator('#conta-app').click();
  const dlg = page.locator('dialog.dlg');
  await expect(dlg).toContainText('App no telemóvel');
  await expect(dlg).toContainText('A app «RTM Gestão» instala-se só por este código QR pessoal');
  await expect(dlg.locator('.app-estado')).toContainText('iPhone · iOS 26.0 · activado em 20-10-2026 09:15');
  await expect(dlg.locator('.app-estado')).toContainText('com biometria');
  await dlg.getByRole('button', { name: 'Gerar código para outro telemóvel' }).click();
  await expect(dlg.locator('.app-qr-img svg')).toBeVisible();
  await expect(dlg.locator('.app-prazo')).toContainText('Válido durante 10:00 · uso único');
  expect(pedidos).toEqual([{ accao: 'codigo', app: 'gestao', csrf: 'x' }]);
  await expect(dlg.getByRole('button', { name: 'Desactivar este equipamento' })).toBeVisible();
  expect(erros).toEqual([]);
});

for (const tem of [false, true]) {
  test(
    'menu da conta: «App no telemóvel» ' + (tem ? 'activa' : 'por instalar') + ', em texto e na cor normal (vermelho só em Terminar sessão)',
    async ({ page }) => {
      await page.route('**/v1/sessao*', (r) =>
        r.fulfill({ json: { login: 'h', nome: 'Henrique Fernandes', perfil: 'SADM', csrf: 'x', permissoes: {}, app_instalada: tem } }),
      );
      await abrir(page, '#inicio');
      await page.locator('#conta-b').click();
      const b = page.locator('#conta-app');
      if (tem) await expect(b).toHaveClass(/instalada/);
      else await expect(b).not.toHaveClass(/instalada/);
      await expect(page.locator('#conta-app-e')).toHaveText(tem ? 'activa' : 'por instalar');
      const [app, sair] = await page.evaluate(() => ['#conta-app', '#sair'].map((s) => getComputedStyle(document.querySelector(s)).color));
      expect(app).not.toBe(sair);
    },
  );
}
