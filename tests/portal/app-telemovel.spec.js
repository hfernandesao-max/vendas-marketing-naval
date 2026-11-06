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
          nome: 'Naval Gestão',
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
        nome: 'Naval Gestão',
      },
    });
  });
  const erros = await abrir(page, '#inicio');
  await page.locator('#conta-b').click();
  await page.locator('#conta-app').click();
  const dlg = page.locator('dialog.dlg');
  await expect(dlg).toContainText('App no telemóvel');
  await expect(dlg).toContainText('A app «Naval Gestão» instala-se só por este código QR pessoal');
  await expect(dlg.locator('.app-estado')).toContainText('iPhone · iOS 26.0 · activado em 20-10-2026 09:15');
  await expect(dlg.locator('.app-estado')).toContainText('com biometria');
  await dlg.getByRole('button', { name: 'Gerar código para outro telemóvel' }).click();
  await expect(dlg.locator('.app-qr-img svg')).toBeVisible();
  await expect(dlg.locator('.app-prazo')).toContainText('Válido durante 10:00 · uso único');
  expect(pedidos).toEqual([{ accao: 'codigo', csrf: 'x' }]);
  await expect(dlg.getByRole('button', { name: 'Desactivar este equipamento' })).toBeVisible();
  expect(erros).toEqual([]);
});
