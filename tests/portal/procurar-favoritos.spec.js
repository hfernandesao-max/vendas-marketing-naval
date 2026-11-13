// Barra superior: procurar uma função (Ctrl+K, /), favoritos no menu, sino dos alertas e estado do sistema.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

test('procurar uma função: Ctrl+K e «/» abrem, as setas escolhem, Enter abre, Esc fecha', async ({ page }) => {
  const erros = await abrir(page, '#inicio');
  await page.keyboard.press('Control+k');
  const dlg = page.locator('#procura');
  await expect(dlg).toBeVisible();
  await expect(page.locator('#procura-campo')).toBeFocused();
  await page.keyboard.type('zonas');
  await expect(dlg.locator('.proc-item')).toHaveCount(1);
  await expect(dlg.locator('.proc-item small')).toHaveText('Operações › Terreno');
  await page.keyboard.press('Enter');
  await expect(dlg).toBeHidden();
  await expect(page).toHaveURL(/#geo-zonas$/);
  // «/» fora de um campo de texto; sem acentos também encontra
  await page.locator('#titulo').click();
  await page.keyboard.press('/');
  await expect(dlg).toBeVisible();
  await page.keyboard.type('logistica');
  await expect(dlg.locator('.proc-item').first()).toContainText('Cargas planeadas');
  await page.keyboard.press('ArrowDown');
  await expect(dlg.locator('.proc-item.on')).toContainText('Requisições ao armazém');
  await page.keyboard.press('Escape');
  await expect(dlg).toBeHidden();
  // botão Procurar
  await page.click('#procurar');
  await expect(dlg).toBeVisible();
  await page.keyboard.type('xyz123');
  await expect(dlg.locator('.proc-vazio')).toHaveText('Nenhuma função com esse nome.');
  expect(erros).toEqual([]);
});

test('favoritos: a estrela fixa a página no topo do menu, segue a conta e retira-se da mesma forma', async ({ page }) => {
  let gravado = null;
  await page.route('**/v1/preferencias', (r) => {
    gravado = JSON.parse(r.request().postData() || '{}');
    return r.fulfill({ json: { ok: true, preferencias: { barra: 'fixa', lado: 'esquerda', favoritos: gravado.favoritos } } });
  });
  const erros = await abrir(page, '#vendas-dia');
  await expect(page.locator('#nav .nav-fav')).toHaveCount(0);
  const est = page.locator('#fav');
  await expect(est).toHaveAttribute('aria-pressed', 'false');
  await est.click();
  await expect(est).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('#nav .nav-sec-fav')).toHaveText('Favoritos');
  await expect(page.locator('#nav .nav-fav a[href="#vendas-dia"]')).toHaveText('Vendas por dia');
  await expect.poll(() => gravado && gravado.favoritos).toEqual(['vendas-dia']);
  // também pela procura (estrela de cada resultado)
  await page.keyboard.press('Control+k');
  await page.keyboard.type('agt');
  await page.locator('#procura .proc-fav').first().click();
  await page.keyboard.press('Escape');
  await expect(page.locator('#nav .nav-fav a')).toHaveText(['Vendas por dia', 'Comunicação AGT']);
  // no Início não há estrela; os favoritos ficam logo a seguir ao Início
  await page.click('#nav a[href="#inicio"]');
  await expect(est).toBeHidden();
  await page.goto(page.url().replace(/#.*$/, '#vendas-dia'));
  await page.locator('#fav').click();
  await expect(page.locator('#nav .nav-fav a')).toHaveText(['Comunicação AGT']);
  expect(erros).toEqual([]);
});

test('estado do sistema e alertas na barra superior, em texto (não só por cor)', async ({ page }) => {
  await page.route('**/v1/alertas*', (r) =>
    r.fulfill({ json: { ok: true, alertas: [{ gravidade: 'critico' }, { gravidade: 'aviso' }, { gravidade: 'info' }] } }),
  );
  const erros = await abrir(page, '#inicio');
  await expect(page.locator('#estado-sis #fontes')).toContainText('fontes ligadas');
  await expect(page.locator('#sino')).toBeVisible();
  await expect(page.locator('#sino-n')).toHaveText('2');
  await expect(page.locator('#sino')).toHaveAttribute('aria-label', '2 alertas por tratar');
  await page.click('#sino');
  await expect(page).toHaveURL(/#alertas(\/[\w-]+)?$/); // a página pode abrir já na sua primeira secção
  await expect(page.locator('#eyebrow')).not.toContainText('›');
  expect(erros).toEqual([]);
});
