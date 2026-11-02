// Resumo das actualizações do portal: janela só para o super administrador, com os pontos do registo de alterações;
// fechada, marca as actualizações como vistas (POST v1/novidades).
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const ACTUALIZACOES = [
  {
    id: '20261103021500-abcdef12',
    detectada_em: '2026-11-03 02:15:07',
    versao: 'v2026.11.02.4',
    seccoes: [
      {
        id: 'a1',
        data: '02-11-2026',
        titulo: 'Actualizações automáticas',
        itens: ['**Publicação:** cada alteração dá uma versão; `ferramentas/actualizar.php` aplica-a.'],
      },
      { id: 'a2', data: '01-11-2026', titulo: 'Equipamentos (fase 1)', itens: ['Ficha e documentos <script>alert(1)</script>'] },
    ],
  },
];

test('super administrador: janela com o resumo; ao fechar marca como visto', async ({ page }) => {
  let marcadas = null;
  await page.route('**/v1/sessao*', (r) => r.fulfill({ json: { login: 'henrique', perfil: 'SADM', csrf: 'x', permissoes: {} } }));
  await page.route('**/v1/novidades*', (r) => {
    if (r.request().method() === 'POST') {
      marcadas = r.request().postDataJSON();
      return r.fulfill({ json: { ok: true, marcadas: 1 } });
    }
    return r.fulfill({ json: { ok: true, actualizacoes: ACTUALIZACOES } });
  });
  const erros = await abrir(page);
  const dlg = page.locator('dialog.dlg-largo');
  await expect(dlg).toBeVisible();
  await expect(dlg.locator('h3')).toHaveText('Actualizações do portal');
  await expect(dlg.locator('.nov-cab')).toContainText('Versão v2026.11.02.4');
  await expect(dlg.locator('.nov-cab')).toContainText('03-11-2026 02:15');
  // o dia mais recente vem aberto, com negrito e código formatados; texto do servidor nunca vira HTML
  await expect(dlg.locator('details').first()).toHaveAttribute('open', '');
  await expect(dlg.locator('details').first().locator('li strong')).toHaveText('Publicação:');
  await expect(dlg.locator('details').first().locator('li code')).toHaveText('ferramentas/actualizar.php');
  await dlg.locator('details').nth(1).locator('summary').click();
  await expect(dlg.locator('details').nth(1).locator('li')).toContainText('<script>alert(1)</script>');
  await dlg.getByRole('button', { name: 'Fechar' }).click();
  await expect(dlg).toHaveCount(0);
  await expect.poll(() => marcadas).toMatchObject({ vistas: ['20261103021500-abcdef12'], csrf: 'x' });
  expect(erros).toEqual([]);
});

test('outros perfis: nunca pedem o resumo nem vêem a janela', async ({ page }) => {
  let pedidos = 0;
  await page.route('**/v1/sessao*', (r) => r.fulfill({ json: { login: 'ana', perfil: 'TI', csrf: 'x', permissoes: {} } }));
  await page.route('**/v1/novidades*', (r) => {
    pedidos++;
    return r.fulfill({ json: { ok: true, actualizacoes: ACTUALIZACOES } });
  });
  await abrir(page);
  await page.waitForTimeout(800);
  await expect(page.locator('dialog.dlg-largo')).toHaveCount(0);
  expect(pedidos).toBe(0);
});
