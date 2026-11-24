// Fichas abertas a partir de uma tabela: painel lateral à direita (a tabela fica à vista), setas ↑ ↓ para a linha
// anterior/seguinte da mesma tabela, Esc fecha.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

test.use({ viewport: { width: 1440, height: 900 } });

test('ficha da rota em painel lateral; ↓ e ↑ mudam de linha; Esc fecha', async ({ page }) => {
  const erros = await abrir(page, '#vendas-promotor');
  const linhas = page.locator('#page table tbody tr');
  await expect(linhas.first().locator('button.lig-rota').first()).toBeVisible();
  const r1 = (await linhas.nth(0).locator('button.lig-rota').first().textContent()).trim();
  const r2 = (await linhas.nth(1).locator('button.lig-rota').first().textContent()).trim();
  await linhas.nth(0).locator('button.lig-rota').first().click();
  let d = page.locator('dialog.lig-dlg[open]');
  await expect(d).toBeVisible();
  await expect(d.locator('.dlg-h h3')).toContainText(r1);
  // painel encostado à direita, à altura do ecrã
  // (depois da animação de entrada)
  await expect
    .poll(async () => {
      const c = await d.boundingBox();
      return [Math.round(c.x + c.width), Math.round(c.height)];
    })
    .toEqual([1440, 900]);
  await page.keyboard.press('ArrowDown');
  d = page.locator('dialog.lig-dlg[open]');
  await expect(d.locator('.dlg-h h3')).toContainText(r2);
  await expect(page.locator('dialog[open]')).toHaveCount(1);
  await page.keyboard.press('ArrowUp');
  await expect(page.locator('dialog.lig-dlg[open] .dlg-h h3')).toContainText(r1);
  await page.keyboard.press('Escape');
  await expect(page.locator('dialog[open]')).toHaveCount(0);
  expect(erros).toEqual([]);
});
