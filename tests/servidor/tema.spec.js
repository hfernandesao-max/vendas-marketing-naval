// Tema na página de entrada e na página de escolha: o mesmo selector e a mesma escolha guardada do portal.
import { test, expect } from '@playwright/test';
import { BASE, SENHA } from './ajuda.js';

test('a página de entrada e a de escolha partilham o tema com o portal', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'dark' });
  await page.goto(BASE + '/geo/login.php');
  const html = page.locator('html');
  await expect(html).toHaveAttribute('data-theme', 'dark');
  await page.locator('#tema button[data-tm="claro"]').click();
  await expect(html).toHaveAttribute('data-theme', 'light');
  await expect(page.locator('#tema button[data-tm="claro"]')).toHaveAttribute('aria-pressed', 'true');

  await page.fill('#usuario', 'sadm');
  await page.fill('#senha', SENHA);
  await page.locator('form button[type=submit]').click();
  await page.goto(BASE + '/');
  await expect(html).toHaveAttribute('data-theme', 'light');
  await expect(page.locator('#tema button[data-tm="claro"]')).toHaveAttribute('aria-pressed', 'true');
  await page.locator('#tema button[data-tm="escuro"]').click();
  await expect(html).toHaveAttribute('data-theme', 'dark');

  await page.goto(BASE + '/reports/');
  await expect(html).toHaveAttribute('data-theme', 'dark');
  await expect(page.locator('#tema button[data-t="escuro"]')).toHaveAttribute('aria-pressed', 'true');
});
