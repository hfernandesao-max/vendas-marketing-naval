// Estados da página: esqueleto enquanto os dados chegam; quando a leitura falha, o motivo (com a hora) e «Tentar de novo».
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

test('esqueleto enquanto carrega; depois o erro com o motivo do servidor e «Tentar de novo»', async ({ page }) => {
  let soltar;
  const espera = new Promise((r) => (soltar = r));
  await page.route('**/v1/conciliacao-carrinha*', async (r) => {
    await espera;
    await r.fulfill({ status: 503, json: { ok: false, erro: 'Pinguim BO sem ligação.' } });
  });
  const erros = await abrir(page, '#conciliacao-carrinha');
  const esq = page.locator('#page .glass.empty.esqueleto');
  await expect(esq).toBeVisible();
  await expect(esq).toHaveAttribute('aria-busy', 'true');
  await expect(esq.locator('.esq-barras i')).toHaveCount(5);
  soltar();
  const e = page.locator('#page .glass.empty.com-erro');
  await expect(e).toBeVisible();
  await expect(e.locator('.erro-frase')).toHaveText('Não foi possível ler os dados.');
  await expect(e.locator('.erro-motivo')).toContainText('Pinguim BO sem ligação.');
  await expect(e.getByRole('button', { name: 'Tentar de novo' })).toBeVisible();
  expect(erros).toEqual([]);
});

test('sem ligação ao servidor: diz que é a rede', async ({ page }) => {
  await page.route('**/v1/conciliacao-carrinha*', (r) => r.abort('internetdisconnected'));
  await abrir(page, '#conciliacao-carrinha');
  await expect(page.locator('#page .com-erro .erro-motivo')).toContainText('Sem ligação ao servidor do portal: verifique a rede.');
});
