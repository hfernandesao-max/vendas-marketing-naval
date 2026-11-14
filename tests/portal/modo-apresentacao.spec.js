// Modo apresentação: activado no menu da conta por um período; mostra a app ao vivo numa moldura de telemóvel no
// ecrã do computador (ou lado a lado com o portal); durante esse período o portal não volta sozinho ao painel.
import { test, expect } from '@playwright/test';
import { abrir, HOJE } from './dados.js';

const SESSAO = {
  login: 'henrique',
  nome: 'Henrique Fernandes',
  perfil: 'SADM',
  csrf: 'abc',
  permissoes: { inicio: 'V', 'vendas-dia': 'V', 'modo-apresentacao': 'G' },
  sessao: { inactiva_s: 900, normal_s: 900, apresentacao_s: 3600, maxima_s: 36000 },
  apresentacao: null,
};

test('modo apresentação: app de gestão no ecrã do computador, lado a lado, sem regresso ao painel; termina', async ({ page }) => {
  await page.route('**/v1/sessao*', (r) => r.fulfill({ json: SESSAO }));
  const pedidos = [];
  const ate = Math.floor(HOJE.getTime() / 1000) + 7200;
  await page.route('**/v1/modo-apresentacao*', (r) => {
    const b = r.request().postDataJSON();
    pedidos.push(b);
    const activo = b.accao === 'iniciar';
    return r.fulfill({ json: { ok: true, activo, ate: activo ? ate : null, inactiva_s: activo ? 3600 : 900 } });
  });
  const erros = await abrir(page, '#vendas-dia');
  await page.locator('#conta-b').click();
  await page.locator('#conta-apres').click();
  const dlg = page.locator('dialog[open]');
  await expect(dlg.locator('h3')).toHaveText('Modo apresentação');
  await dlg.getByLabel('O que mostrar').selectOption('gestao');
  await dlg.getByLabel('Ecrã inteiro').uncheck();
  await dlg.getByRole('button', { name: 'Iniciar apresentação' }).click();
  await expect.poll(() => pedidos.length).toBe(1);
  expect(pedidos[0]).toMatchObject({ accao: 'iniciar', minutos: 120 });
  // a app ao vivo, numa moldura de telemóvel, no ecrã do computador
  const cena = page.locator('.apres-cena');
  await expect(cena).toBeVisible();
  await expect(page.locator('.apres-tel iframe')).toHaveAttribute('src', '../gestao/');
  await expect(page.frameLocator('.apres-tel iframe').locator('h1').first()).toBeVisible();
  const barra = page.locator('.apres-barra');
  await expect(barra).toContainText('Apresentação até às 14:00');
  // lado a lado: o portal continua à esquerda e a app à direita
  await barra.getByRole('button', { name: 'Portal e app lado a lado' }).click();
  await expect(page.locator('body')).toHaveClass(/apres-lado/);
  await expect(page.locator('.apres-cena-lado')).toBeVisible();
  // só o portal
  await barra.getByRole('button', { name: 'Portal', exact: true }).click();
  await expect(page.locator('.apres-cena')).toHaveCount(0);
  // durante a apresentação o portal não volta sozinho ao painel
  await page.clock.setFixedTime(new Date(HOJE.getTime() + 6 * 60000));
  await page.waitForTimeout(1500);
  expect(new URL(page.url()).hash).toMatch(/^#vendas-dia/);
  await barra.getByRole('button', { name: 'Terminar' }).click();
  await expect.poll(() => pedidos.length).toBe(2);
  expect(pedidos[1]).toMatchObject({ accao: 'terminar' });
  await expect(page.locator('.apres-barra')).toHaveCount(0);
  expect(erros).toEqual([]);
});

test('modo apresentação: sem o recurso na matriz, a opção não aparece', async ({ page }) => {
  await page.route('**/v1/sessao*', (r) => r.fulfill({ json: { ...SESSAO, perfil: 'DIR', permissoes: { inicio: 'V', 'vendas-dia': 'V' } } }));
  await abrir(page, '#vendas-dia');
  await page.locator('#conta-b').click();
  await expect(page.locator('#conta-apres')).toBeHidden();
});
