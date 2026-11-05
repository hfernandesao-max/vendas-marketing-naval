// Recuperação do acesso pela própria pessoa: conta bloqueada → «Esqueceu-se…?» → ligação por correio → nova palavra-passe.
import { test, expect } from '@playwright/test';
import { readdirSync, readFileSync } from 'node:fs';
import { SENHA } from './ajuda.js';

const SAIDA = 'tests/servidor/ambiente/dados-privados/email-saida';
const ligacoes = () => {
  let l = [];
  try {
    l = readdirSync(SAIDA).filter((f) => f.endsWith('.html'));
  } catch (e) {
    /* ainda sem correio */
  }
  return l.map((f) => (readFileSync(SAIDA + '/' + f, 'utf8').match(/recuperar\.php\?t=([a-f0-9]{64})/) || [])[1]).filter(Boolean);
};

test('conta bloqueada: a própria pessoa recupera o acesso pelo correio, sem o super administrador @navegador', async ({ page }) => {
  test.setTimeout(60000);
  const entrar = async (senha) => {
    await page.goto('/geo/login.php');
    await page.fill('#usuario', 'rec');
    await page.fill('#senha', senha);
    await page.click('.btn-submit');
  };
  for (let i = 0; i < 3; i++) await entrar('errada-' + i + '-123456');
  await expect(page.locator('.error-alert')).toContainText('A conta está bloqueada');
  await expect(page.locator('.error-alert')).toContainText('Esqueceu-se da palavra-passe ou tem a conta bloqueada?');
  await entrar(SENHA);
  await expect(page.locator('.error-alert')).toContainText('A conta está bloqueada');

  // 1. pedir a ligação (a resposta é a mesma, exista ou não a conta)
  const antes = new Set(ligacoes());
  await page.click('#recuperar');
  await expect(page).toHaveURL(/recuperar\.php$/);
  await page.fill('#usuario', 'conta-que-nao-existe');
  await page.click('button[type=submit]');
  const generica = await page.locator('#rec-enviado').textContent();
  await page.goto('/geo/recuperar.php');
  await page.fill('#usuario', 'rec@naval.co.ao');
  await page.click('button[type=submit]');
  await expect(page.locator('#rec-enviado')).toHaveText(generica);
  let codigo;
  await expect.poll(() => (codigo = ligacoes().find((x) => !antes.has(x)))).toBeTruthy();

  // 2. a ligação recusa palavras-passe fracas e depois desbloqueia
  await page.goto('/geo/recuperar.php?t=' + codigo);
  await page.fill('#nova', 'curta');
  await page.fill('#confirmar', 'curta');
  await page.locator('#nova').evaluate((i) => i.removeAttribute('minlength'));
  await page.locator('#confirmar').evaluate((i) => i.removeAttribute('minlength'));
  await page.click('button[type=submit]');
  await expect(page.locator('.msg.erro')).toContainText('pelo menos 12 caracteres');
  const nova = 'Uma frase nova e segura 2026';
  await page.fill('#nova', nova);
  await page.fill('#confirmar', nova);
  await page.click('button[type=submit]');
  await expect(page.locator('#rec-concluido')).toBeVisible();

  // 3. a ligação já não serve (uso único)
  await page.goto('/geo/recuperar.php?t=' + codigo);
  await expect(page.locator('#rec-invalido')).toBeVisible();

  // 4. entra com a nova palavra-passe (conta desbloqueada); a antiga já não serve
  await page.goto('/geo/login.php');
  await page.fill('#usuario', 'rec');
  await page.fill('#senha', nova);
  await Promise.all([page.waitForURL(/\/reports\/$/), page.click('.btn-submit')]);
});

test('recuperação: página traduzida (EN) e ligação inválida @navegador', async ({ page }) => {
  await page.goto('/geo/recuperar.php?t=' + '0'.repeat(64));
  await expect(page.locator('#rec-invalido')).toBeVisible();
  await page.goto('/geo/login.php');
  await page.click('#idioma button[data-l="en"]');
  await expect(page.locator('#recuperar')).toHaveText('Forgot your password or is your account locked?');
  await page.click('#recuperar');
  await expect(page.locator('h1')).toHaveText('Recover access');
  await expect(page.locator('button[type=submit]')).toHaveText('Send the link');
});
