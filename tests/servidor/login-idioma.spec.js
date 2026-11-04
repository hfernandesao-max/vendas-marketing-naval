// Página de entrada: selector PT/EN/TI (só visual e idioma; a entrada e as validações não mudam).
import { test, expect } from '@playwright/test';

test('selector de idioma na entrada: textos, marcadores de campo e mensagens do servidor na língua escolhida @navegador', async ({ page }) => {
  await page.goto('/geo/login.php');
  await expect(page.locator('html')).toHaveAttribute('lang', 'pt-PT');
  await expect(page.locator('label[for="usuario"]')).toHaveText('Utilizador ou correio electrónico');
  await page.click('#idioma button[data-l="ti"]');
  await expect(page.locator('html')).toHaveAttribute('lang', 'ti');
  await expect(page.locator('#idioma button[data-l="ti"]')).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('label[for="senha"]')).toHaveText('መሕለፊ ቃል');
  await expect(page.locator('.btn-submit span')).toHaveText('እቶ');
  await expect(page.locator('#senha')).toHaveAttribute('placeholder', 'መሕለፊ ቃልኩም ኣእትዉ');
  // a escolha fica guardada e a mensagem do servidor (com o número de tentativas) aparece em tigrínia
  await page.fill('#usuario', 'aud');
  await page.fill('#senha', 'errada-123456');
  await page.click('.btn-submit');
  await expect(page.locator('.error-alert span')).toContainText('ተጠቃሚ ወይ መሕለፊ ቃል ጌጋ እዩ።');
  await page.click('#idioma button[data-l="en"]');
  await expect(page.locator('.error-alert span')).toContainText('Incorrect username or password.');
  await page.click('#idioma button[data-l="pt"]');
  await expect(page.locator('.error-alert span')).toContainText('Utilizador ou palavra-passe incorrectos.');
});

test('depois de entrar vai directo ao portal (Início), sem página intermédia; a raiz do site também leva ao portal @navegador', async ({ page }) => {
  await page.goto('/geo/login.php');
  await page.fill('#usuario', 'sadm');
  await page.fill('#senha', 'Teste-12345!');
  await Promise.all([page.waitForURL(/\/reports\/$/), page.click('.btn-submit')]);
  await expect(page.locator('#nav')).toContainText('Início');
  await expect(page.locator('#voltar')).toBeHidden();
  await page.goto('/index.php');
  await expect(page).toHaveURL(/\/reports\/$/);
  // a conta de teste não tem idioma na ficha: abre em português
  await expect(page.locator('html')).toHaveAttribute('lang', 'pt-PT');
});
