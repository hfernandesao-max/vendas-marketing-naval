// Inactividade: aos 5 minutos sem mexer o portal volta ao painel; 1 minuto antes dos 15 avisa, com «Continuar
// ligado»; no fim termina a sessão (POST logout com o motivo). Os pedidos à API dizem há quanto tempo a pessoa não mexe.
import { test, expect } from '@playwright/test';
import { abrir, HOJE } from './dados.js';

const min = (m) => new Date(HOJE.getTime() + m * 60000);

test('inactividade: volta ao painel aos 5 min, avisa aos 14 e termina a sessão aos 15', async ({ page }) => {
  await page.route('**/v1/sessao*', (r) =>
    r.fulfill({
      json: { login: 'henrique', perfil: 'SADM', csrf: 'abc', permissoes: { inicio: 'V', 'vendas-dia': 'V' }, sessao: { inactiva_s: 900, maxima_s: 36000 } },
    }),
  );
  let saida = null;
  await page.route('**/geo/auth/logout.php', (r) => {
    saida = r.request().postData();
    return r.fulfill({ contentType: 'text/html', body: '<p>entrada</p>' });
  });
  const cabecalhos = [];
  page.on('request', (q) => {
    if (q.url().includes('/v1/vendas')) cabecalhos.push(q.headers()['x-inactivo-s']);
  });
  const erros = await abrir(page, '#vendas-dia');
  // 4 minutos: fica onde está
  await page.clock.setFixedTime(min(4));
  await page.waitForTimeout(1500);
  expect(page.url()).toContain('#vendas-dia');
  // 6 minutos: volta ao painel principal
  await page.clock.setFixedTime(min(6));
  await expect.poll(() => new URL(page.url()).hash).toBe('#inicio');
  // a leitura automática dos dados leva há quanto tempo a pessoa não mexe (o servidor não a conta como actividade)
  await expect.poll(() => cabecalhos.map(Number).some((v) => v >= 300)).toBe(true);
  // 14,5 minutos: aviso com a contagem e «Continuar ligado»
  await page.clock.setFixedTime(min(14.5));
  const aviso = page.locator('dialog.ina-aviso[open]');
  await expect(aviso).toBeVisible();
  await expect(aviso).toContainText('A sessão termina em');
  await expect(aviso.getByRole('button', { name: 'Continuar ligado' })).toBeVisible();
  // qualquer gesto (mexer o rato, tocar, escrever) conta como actividade e fecha o aviso
  await page.mouse.move(200, 200);
  await page.mouse.move(260, 240);
  await expect(aviso).toHaveCount(0);
  // depois de continuar, conta de novo a partir de agora; 15 minutos sem mexer: termina a sessão
  await page.clock.setFixedTime(min(30));
  await expect.poll(() => saida).toContain('motivo=inactividade');
  expect(saida).toContain('csrf=abc');
  expect(erros).toEqual([]);
});

test('inactividade: com uma janela aberta (trabalho a meio) não muda de página', async ({ page }) => {
  await page.route('**/v1/sessao*', (r) =>
    r.fulfill({
      json: { login: 'henrique', perfil: 'SADM', csrf: 'abc', permissoes: { inicio: 'V', 'vendas-dia': 'V' }, sessao: { inactiva_s: 900, maxima_s: 36000 } },
    }),
  );
  await abrir(page, '#vendas-dia');
  // uma janela aberta (por exemplo, a meio de uma contagem): o portal não sai da página
  await page.evaluate(() => {
    const d = document.createElement('dialog');
    document.body.append(d);
    d.showModal();
  });
  await page.clock.setFixedTime(min(6));
  await page.waitForTimeout(1500);
  expect(page.url()).toContain('#vendas-dia');
});
