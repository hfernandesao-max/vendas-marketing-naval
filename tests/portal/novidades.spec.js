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

const AVISOS = [
  {
    id: '20261118090000-aaaa1111',
    versao: 'v2026.11.18.124',
    detectada_em: '2026-11-18 09:00:00',
    seccoes: [
      {
        id: 's1',
        titulo: 'Técnico',
        tipo: 'funcionalidade',
        aviso: {
          titulo: { pt: 'Pedidos de acesso' },
          resumo: [{ pt: 'Peça acessos em **Suporte**.', en: 'Request access in **Support**.' }],
          passos: [{ pt: 'Carregue em «Li e compreendi».' }, { pt: 'Abra o Suporte <b>já</b>.' }],
        },
      },
    ],
  },
];

test('todos os perfis: aviso do que muda e como proceder; «Mais tarde» volta a mostrar, «Li e compreendi» regista', async ({ page }) => {
  const posts = [];
  let avisos = AVISOS;
  await page.route('**/v1/sessao*', (r) => r.fulfill({ json: { login: 'ana', perfil: 'TI', csrf: 'x', permissoes: {} } }));
  await page.route('**/v1/novidades*', (r) => {
    if (r.request().method() === 'POST') {
      posts.push(r.request().postDataJSON());
      avisos = [];
      return r.fulfill({ json: { ok: true, marcadas: 0, confirmadas: 1 } });
    }
    // outros perfis: nunca o resumo técnico
    return r.fulfill({ json: { ok: true, avisos } });
  });
  const erros = await abrir(page);
  const dlg = page.locator('dialog.dlg-aviso');
  await expect(dlg).toBeVisible();
  await expect(dlg.locator('h3').first()).toHaveText('O portal foi actualizado');
  await expect(dlg.locator('.nov-faixa')).toContainText('Versão v2026.11.18.124 · instalada em 18-11-2026 09:00');
  const c = dlg.locator('.nov-cartao');
  await expect(c.locator('h3')).toContainText('Pedidos de acesso');
  await expect(c.locator('h3 .pill')).toHaveText('Nova funcionalidade');
  await expect(c.locator('ul li strong')).toHaveText('Suporte');
  await expect(c.locator('ol.nov-passos li')).toHaveText(['Carregue em «Li e compreendi».', 'Abra o Suporte <b>já</b>.']);
  // «Mais tarde»: não regista; volta na próxima entrada
  await dlg.getByRole('button', { name: 'Mais tarde' }).click();
  await expect(dlg).toHaveCount(0);
  expect(posts).toEqual([]);
  await page.reload();
  await expect(dlg).toBeVisible();
  await dlg.getByRole('button', { name: 'Li e compreendi' }).click();
  await expect.poll(() => posts[0]).toMatchObject({ confirmar: ['20261118090000-aaaa1111'], csrf: 'x' });
  await page.reload();
  await page.waitForTimeout(600);
  await expect(dlg).toHaveCount(0);
  expect(erros).toEqual([]);
});

test('super administrador: depois do resumo técnico, o aviso que os utilizadores vão ver', async ({ page }) => {
  await page.route('**/v1/sessao*', (r) => r.fulfill({ json: { login: 'henrique', perfil: 'SADM', csrf: 'x', permissoes: {} } }));
  await page.route('**/v1/novidades*', (r) =>
    r.fulfill({ json: r.request().method() === 'POST' ? { ok: true } : { ok: true, actualizacoes: ACTUALIZACOES, avisos: AVISOS } }),
  );
  await abrir(page);
  await page.locator('dialog.dlg-largo').getByRole('button', { name: 'Fechar' }).click();
  await expect(page.locator('dialog.dlg-aviso')).toBeVisible();
});
