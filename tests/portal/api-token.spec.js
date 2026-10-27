// Cliente da API: quando o servidor não emite o token por um problema de configuração (ex.: JWT_SEGREDO em
// falta), o portal mostra o motivo e não volta à página de entrada em ciclo.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

test('token indisponível (503): mostra o motivo, não envia pedidos sem token e não volta ao login', async ({ page }) => {
  const MOTIVO = 'Configuração incompleta no servidor para a API (tokens de acesso) (.env: JWT_SEGREDO).';
  await page.route('**/v1/auth/token', (r) => r.fulfill({ status: 503, json: { ok: false, erro: MOTIVO } }));
  const semToken = [];
  page.on('request', (q) => {
    if (q.url().includes('/v1/') && !q.url().includes('auth/token')) semToken.push(q.url());
  });
  await abrir(page, '#vendas-dia');
  await expect(page.locator('#toast')).toContainText('JWT_SEGREDO');
  expect(page.url()).toContain('/reports/');
  expect(page.url()).not.toContain('login.php');
  expect(semToken).toEqual([]); // nenhum pedido à API sem token
});
