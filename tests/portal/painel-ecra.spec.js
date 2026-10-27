// Página principal num só ecrã (sem deslocação) e estados do menu lateral.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

for (const [w, h] of [
  [1920, 1080],
  [1440, 900],
  [1366, 768],
])
  test(`início ${w}×${h}: todos os gráficos visíveis sem deslocar a página`, async ({ page }) => {
    await page.setViewportSize({ width: w, height: h });
    const erros = await abrir(page, '#inicio');
    await page.waitForTimeout(400);
    await expect(page.locator('.viz-grid.ecra-unico')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollHeight - innerHeight)).toBeLessThanOrEqual(1);
    const cartoes = page.locator('.viz-card');
    expect(await cartoes.count()).toBeGreaterThanOrEqual(6);
    for (const c of await cartoes.all()) {
      const b = await c.boundingBox();
      expect(b.y + b.height).toBeLessThanOrEqual(h);
    }
    expect(erros).toEqual([]);
  });

test('menu lateral: ícone da página aberta em branco sobre a cor de destaque; ícone da área em azul; restantes neutros', async ({ page }) => {
  await abrir(page, '#clientes');
  const cor = (sel) =>
    page
      .locator(sel)
      .first()
      .evaluate((e) => getComputedStyle(e).stroke);
  expect(await cor('#nav a.on svg')).toBe('rgb(255, 255, 255)');
  const grupo = await cor('#nav .navg.atual svg');
  const outro = await cor('#nav .navg:not(.atual):not([aria-expanded="true"]) svg');
  expect(grupo).not.toBe(outro);
  expect(await page.locator('#nav a.on:not(.nav-sub a)').evaluate((e) => getComputedStyle(e, '::before').width)).toBe('3px');
});
