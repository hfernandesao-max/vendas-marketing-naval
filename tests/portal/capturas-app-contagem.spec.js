// Capturas de ecrã da app «RTM Operações» › Contar a carrinha, com a API simulada (para apresentar a app).
// Corre à mão: CAPTURAS=pasta npx playwright test tests/portal/capturas-app-contagem.spec.js
import { test, devices } from '@playwright/test';

test.use({ ...devices['Pixel 7'] });
const PASTA = process.env.CAPTURAS || '';

test('capturas da app de contagem', async ({ page }) => {
  test.skip(!PASTA, 'só à mão, com CAPTURAS=pasta');
  await page.route('**/operacoes/index.html', async (r) => {
    const resp = await r.fetch();
    const html = (await resp.text())
      .replace('<head>', '<head><meta name="naval-tarefas" content="contagem,carga">')
      .replace('<link rel="stylesheet" href="app.css" />', '<link rel="stylesheet" href="../gestao/app.css" /><link rel="stylesheet" href="app.css" />');
    return r.fulfill({ response: resp, body: html, headers: { ...resp.headers(), 'content-type': 'text/html; charset=utf-8' } });
  });
  await page.route('**/v1/sessao**', (r) =>
    r.fulfill({ json: { login: 'manuel.queia', nome: 'Manuel Queia', perfil: 'LOG', permissoes: {}, idioma: 'pt', csrf: 'x' } }),
  );
  const linhas = [
    { codigo: '10000332', artigo: 'FRALDAS NAVAL MEDIUM (BIG) 4×48', unidade: 'UN', contado: null },
    { codigo: '10000015', artigo: 'FRALDAS MIMO MEDIUM 4×48', unidade: 'UN', contado: null },
    { codigo: '10000292', artigo: 'SABÃO VERDE CONFIANÇA 100G', unidade: 'UN', contado: null },
    { codigo: '10000293', artigo: 'SOAP BROWN CONFIANÇA 100G', unidade: 'UN', contado: null },
    { codigo: '10000386', artigo: 'SABÃO 6 BARRAS EXTRA 20×850G', unidade: 'UN', contado: null },
    { codigo: '10000385', artigo: 'SOAP BROWN W/P EXTRA 20×850G', unidade: 'UN', contado: null },
  ];
  await page.route('**/v1/conciliacao-carrinha**', (r) =>
    r.fulfill({
      json: {
        ok: true,
        rotas: [
          { rota: '1001', promotor: 'Andrea Santos', resumo: { estado: 'aprovada', diferencas: 0 }, linhas: [] },
          { rota: '1004', promotor: 'Vendedor 4', resumo: { estado: 'por_contar', diferencas: 0 }, linhas },
          { rota: '1007', promotor: 'Awet Weldemenkorios', resumo: { estado: 'por_validar', diferencas: 0 }, contado_por: 'manuel.queia', linhas: [] },
          { rota: '1008', promotor: 'Daniel M.', resumo: { estado: 'por_contar', diferencas: 0 }, linhas },
        ],
      },
    }),
  );
  await page.goto('/operacoes/index.html');
  await page.locator('.op-carrinha').first().waitFor();
  await page.screenshot({ path: PASTA + '/1-carrinhas-de-hoje.png', fullPage: true });
  await page.locator('.op-carrinha[data-rota="1004"]').getByRole('button', { name: 'Contar esta carrinha' }).click();
  await page.keyboard.type('90');
  await page.keyboard.press('Enter');
  await page.keyboard.type('10');
  await page.keyboard.press('Enter');
  await page.keyboard.type('14');
  await page.keyboard.press('Enter');
  await page.keyboard.type('2.5');
  await page.screenshot({ path: PASTA + '/2-contagem-as-cegas.png', fullPage: true });
});

test('capturas da app de registo das notas', async ({ page }) => {
  test.skip(!PASTA, 'só à mão, com CAPTURAS=pasta');
  await page.route('**/operacoes/index.html', async (r) => {
    const resp = await r.fetch();
    const html = (await resp.text())
      .replace('<head>', '<head><meta name="naval-tarefas" content="entrega,visitas,mercadoria">')
      .replace('<link rel="stylesheet" href="app.css" />', '<link rel="stylesheet" href="../gestao/app.css" /><link rel="stylesheet" href="app.css" />');
    return r.fulfill({ response: resp, body: html, headers: { ...resp.headers(), 'content-type': 'text/html; charset=utf-8' } });
  });
  await page.route('**/v1/sessao**', (r) =>
    r.fulfill({ json: { login: 'awet.w', nome: 'Awet Weldemenkorios', perfil: 'PRO', permissoes: {}, idioma: 'pt', csrf: 'x' } }),
  );
  await page.route('**/v1/caixa-entrega**', (r) =>
    r.request().method() === 'POST'
      ? r.fulfill({ json: { ok: true } })
      : r.fulfill({
          json: {
            ok: true,
            folha: 'aberta',
            notas: [5000, 2000, 1000, 500, 200, 100, 50],
            rotas: [{ rota: '1007', promotor: 'Awet Weldemenkorios', vendido_dinheiro: 187350, contada: false, declaracao: null }],
          },
        }),
  );
  await page.goto('/operacoes/index.html');
  await page.locator('.op-entrega').first().waitFor();
  await page.screenshot({ path: PASTA + '/3-entregar-dinheiro.png', fullPage: true });
  const campos = page.locator('.op-entrega input');
  for (const [i, v] of [['30'], ['10'], ['5'], ['12'], ['3'], ['2'], ['1']].entries()) {
    await campos.nth(i).fill(v[0]);
  }
  await campos.nth(7).fill('150');
  await campos.nth(7).focus();
  await page.screenshot({ path: PASTA + '/4-notas-preenchidas.png', fullPage: true });
});
