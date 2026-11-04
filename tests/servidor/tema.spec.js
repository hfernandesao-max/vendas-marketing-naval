// Tema na página de entrada e no portal: o mesmo selector e a mesma escolha guardada.
import { test, expect } from '@playwright/test';
import { BASE, SENHA } from './ajuda.js';

test('a página de entrada partilha o tema com o portal @navegador', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'dark' });
  await page.goto(BASE + '/geo/login.php');
  const html = page.locator('html');
  await expect(html).toHaveAttribute('data-theme', 'dark');
  await page.locator('#tema button[data-tm="claro"]').click();
  await expect(html).toHaveAttribute('data-theme', 'light');
  await expect(page.locator('#tema button[data-tm="claro"]')).toHaveAttribute('aria-pressed', 'true');

  await page.fill('#usuario', 'sadm');
  await page.fill('#senha', SENHA);
  // entra directo no portal (sem página intermédia) e o tema escolhido na entrada mantém-se
  await Promise.all([page.waitForURL(/\/reports\/$/), page.locator('form button[type=submit]').click()]);
  await expect(html).toHaveAttribute('data-theme', 'light');
  await expect(page.locator('#tema button[data-t="claro"]')).toHaveAttribute('aria-pressed', 'true');
  // a raiz do site leva ao portal, com o mesmo tema
  await page.goto(BASE + '/');
  await expect(page).toHaveURL(/\/reports\/$/);
  await expect(html).toHaveAttribute('data-theme', 'light');
});
