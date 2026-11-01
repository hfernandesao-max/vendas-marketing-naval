// Tema do portal: Sistema / Claro / Escuro. O selector muda <html data-theme>, a escolha fica guardada
// e o modo claro cumpre o contraste mínimo WCAG 2.2 AA (4,5:1) nos textos principais.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

// Contraste entre a cor do texto de um elemento e o fundo opaco mais próximo (WCAG 2.x)
async function contraste(page, seletor) {
  return page
    .locator(seletor)
    .first()
    .evaluate((el) => {
      const rgb = (c) => (c.match(/[\d.]+/g) || []).map(Number);
      const lum = ([r, g, b]) => {
        const f = (v) => ((v /= 255) <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4);
        return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
      };
      let fundo = null;
      for (let n = el; n && !fundo; n = n.parentElement) {
        const c = rgb(getComputedStyle(n).backgroundColor);
        if (c.length >= 3 && (c[3] === undefined || c[3] >= 0.95)) fundo = c;
      }
      fundo = fundo || [255, 255, 255];
      const [a, b] = [lum(rgb(getComputedStyle(el).color)), lum(fundo)].sort((x, y) => y - x);
      return (a + 0.05) / (b + 0.05);
    });
}

test('o tema segue o sistema por omissão e o selector muda e guarda a escolha', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'dark' });
  // começa sem escolha guardada (só na primeira abertura; ao recarregar a escolha mantém-se)
  await page.addInitScript(() => {
    if (!sessionStorage.getItem('teste-tema')) {
      localStorage.removeItem('vm-tema');
      sessionStorage.setItem('teste-tema', '1');
    }
  });
  await abrir(page, '#inicio', { manterMemoria: true });
  const html = page.locator('html');
  await expect(html).toHaveAttribute('data-theme', 'dark');
  await expect(page.locator('#tema button[data-t="sistema"]')).toHaveAttribute('aria-pressed', 'true');

  await page.emulateMedia({ colorScheme: 'light' });
  await expect(html).toHaveAttribute('data-theme', 'light');

  await page.locator('#tema button[data-t="escuro"]').click();
  await expect(html).toHaveAttribute('data-theme', 'dark');
  await expect(page.locator('#tema button[data-t="escuro"]')).toHaveAttribute('aria-pressed', 'true');
  expect(await page.evaluate(() => localStorage.getItem('vm-tema'))).toBe('escuro');

  await page.reload();
  await expect(html).toHaveAttribute('data-theme', 'dark');

  await page.locator('#tema button[data-t="claro"]').click();
  await expect(html).toHaveAttribute('data-theme', 'light');
  const esquema = await page.evaluate(() => getComputedStyle(document.documentElement).colorScheme);
  expect(esquema).toBe('light');
});

test('modo claro: contraste AA nos textos principais', async ({ page }) => {
  await page.addInitScript(() => localStorage.setItem('vm-tema', 'claro'));
  await abrir(page, '#documentos');
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  for (const s of ['h1', '.card', 'table td', 'table th', '.nav a', '.seg button.on']) {
    if (!(await page.locator(s).count())) continue;
    expect(await contraste(page, s), s).toBeGreaterThanOrEqual(4.5);
  }
});
