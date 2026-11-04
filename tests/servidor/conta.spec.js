// Menu da conta no canto superior direito: nome, perfil e Terminar sessão (com o código anti-falsificação).
import { test, expect } from '@playwright/test';
import { SENHA } from './ajuda.js';

test('conta no topo: nome e perfil, preferências de favoritos guardadas na conta, terminar sessão @navegador', async ({ page }) => {
  await page.goto('/geo/login.php');
  await page.fill('#usuario', 'dir');
  await page.fill('#senha', SENHA);
  await Promise.all([page.waitForURL(/\/reports\/$/), page.click('.btn-submit')]);
  const conta = page.locator('#conta-b');
  await expect(conta).toBeVisible();
  await expect(page.locator('#conta-p')).toBeHidden();
  await conta.click();
  await expect(conta).toHaveAttribute('aria-expanded', 'true');
  await expect(page.locator('#conta-p #quem b')).not.toBeEmpty();
  await page.keyboard.press('Escape');
  await expect(page.locator('#conta-p')).toBeHidden();
  // favoritos seguem a conta (v1/preferencias)
  await page.goto('/reports/#vendas-dia');
  await page.locator('#fav').click();
  await expect(page.locator('#nav .nav-fav a[href="#vendas-dia"]')).toHaveCount(1);
  // a lista vem da conta: sem a memória do navegador, volta igual
  await page.waitForTimeout(500);
  await page.evaluate(() => localStorage.removeItem('vm-favoritos'));
  await page.reload();
  await expect(page.locator('#nav .nav-fav a[href="#vendas-dia"]')).toHaveCount(1);
  await page.locator('#fav').click();
  // terminar sessão: volta à entrada e o portal já não abre sem entrar
  await page.locator('#conta-b').click();
  await Promise.all([page.waitForURL(/login\.php/), page.locator('#sair').click()]);
  await page.goto('/reports/');
  await expect(page).toHaveURL(/login\.php/);
});
