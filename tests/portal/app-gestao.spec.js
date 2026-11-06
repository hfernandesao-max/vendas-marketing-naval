// App de gestão «Naval Gestão» no telemóvel (gestao/index.html), com a API simulada: período Hoje · Semana · Mês,
// gráficos, «Precisa de atenção», classificação e detalhe da rota, alertas, aviso de dados incompletos e tema escuro.
import { test, expect, devices } from '@playwright/test';

const HOJE = '2026-11-06';
const dia = (n) => {
  const d = new Date(HOJE + 'T12:00:00Z');
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
};
// série desde 01-10-2026: domingos sem vendas, hoje a meio do dia
const serie = [];
for (let d = '2026-10-01', i = 0; d <= HOJE; i++, d = new Date(Date.parse(d + 'T12:00:00Z') + 864e5).toISOString().slice(0, 10)) {
  const ds = new Date(d + 'T12:00:00Z').getUTCDay();
  serie.push({ d, t: ds === 0 ? 0 : d === HOJE ? 612000 : 1100000 + ((i * 137) % 9) * 60000 });
}
const rota = (r, promotor, mes, ant, hoje, seq) => ({
  rota: r,
  promotor,
  hoje,
  previsto_hoje: hoje ? Math.round(hoje * 1.9) : 0,
  ontem: Math.round(mes / 5),
  habitual_ontem: Math.round(mes / 5.5),
  mes,
  mes_anterior: ant,
  variacao_mes: ant ? Math.round((mes / ant - 1) * 1000) / 1000 : null,
  sequencia: seq,
  serie: Array.from({ length: 14 }, (_, i) => (i % 7 === 2 ? 0 : Math.round(mes / 5 + i * 1500))),
});
const IND = {
  ok: true,
  hora: HOJE + ' 14:35',
  hoje: { dia: HOJE, total: 612000, documentos: 41, previsto: 1437381, progresso: 0.426 },
  ontem: { dia: dia(-1), total: 1340000, documentos: 88, habitual: 1210000, variacao: 0.107 },
  semana: { de: '2026-11-02', total: 5180000, anterior: 5420000, variacao: -0.044 },
  mes: { de: '2026-11-01', total: 5180000, anterior: 4860000, variacao: 0.066 },
  rotas: [
    rota('1011', 'Robel Tesfamichael', 1420000, 1210000, 160000, 4),
    rota('1002', 'Cristina Sachuma', 1130000, 1240000, 140000, 0),
    rota('1001', 'Andrea Santos', 980000, 900000, 0, 0),
    rota('1005', 'Mateus Kiala', 870000, 860000, 120000, 0),
  ],
  serie,
  ultima_venda: HOJE,
  compras: {
    hoje: { compras: 38, valor_medio: 16105, artigos_por_compra: 3.1 },
    mes: { compras: 301, valor_medio: 17209, artigos_por_compra: 3.4 },
    mes_anterior: { compras: 288, valor_medio: 16875, artigos_por_compra: 3.2 },
  },
  alertas: {
    critico: 2,
    aviso: 1,
    info: 3,
    principais: [
      { gravidade: 'critico', tipo: 'rota-sem-vendas', p: { nome: '1001 · Andrea Santos', dia: HOJE, com: 6, n: 6, ds: 5, esperado: 160000 } },
      {
        gravidade: 'critico',
        tipo: 'queda-dia',
        p: { nome: '1002 · Cristina Sachuma', dia: dia(-1), var: -0.41, real: 140000, esperado: 238000, n: 6, ds: 4 },
      },
      { gravidade: 'aviso', tipo: 'cliente-parado', p: { nome: 'Cantina Kilamba', rota: '1005', sem: 19, habitual: 6, n: 11, t: 420000, ultimo: dia(-19) } },
    ],
  },
};
const SESSAO = { login: 'dir', nome: 'Direcção', perfil: 'DIR', permissoes: { 'app-gestao': true, assistente: true, alertas: true }, idioma: 'pt', csrf: 'x' };

async function abrirApp(page, { ind = IND, fonte = { ok: true } } = {}) {
  await page.route('**/v1/sessao**', (r) => r.fulfill({ json: SESSAO }));
  await page.route('**/v1/indicadores**', (r) => r.fulfill({ json: ind }));
  await page.route('**/v1/vendas/estado**', (r) => r.fulfill({ json: fonte }));
  const erros = [];
  page.on('pageerror', (e) => erros.push(e.message));
  await page.goto('/gestao/index.html');
  await expect(page.locator('.ag-heroi')).toBeVisible();
  return erros;
}
const semCorte = (page) =>
  page.evaluate(() => {
    const larg = document.documentElement.clientWidth;
    return [...document.querySelectorAll('#ag-conteudo *')]
      .filter((e) => {
        const r = e.getBoundingClientRect();
        return r.width && (r.right > larg + 1 || r.left < -1);
      })
      .map((e) => e.className || e.tagName);
  });

// telemóvel (sem mudar de navegador: o perfil do iPhone escolheria o WebKit)
const IPHONE = { ...devices['iPhone 13'] };
delete IPHONE.defaultBrowserType;
test.use(IPHONE);

test('app de gestão: Hoje com período, gráficos, atenção, indicadores e melhores rotas', async ({ page }) => {
  const erros = await abrirApp(page);
  await expect(page.locator('#ag-h1')).toHaveText('Hoje');
  await expect(page.locator('#ag-estado')).toContainText('Actualizado às 14:35');
  const heroi = page.locator('.ag-heroi');
  await expect(heroi).toContainText('Vendas de hoje');
  await expect(heroi).toContainText('612 000 Kz');
  await expect(heroi).toContainText('43% da previsão do dia (1 437 381 Kz)');
  await expect(heroi.locator('rect.ag-b')).toHaveCount(14);
  await expect(heroi.locator('rect.ag-b.on')).toHaveCount(1);
  // semana: barras desta semana sobre a semana passada, variação com seta
  await page.locator('.ag-seg button', { hasText: 'Semana' }).click();
  await expect(heroi).toContainText('5 180 000 Kz');
  await expect(heroi.locator('.ag-var.desce')).toHaveText('▼ −4%');
  await expect(heroi.locator('rect.ag-fant')).toHaveCount(7);
  await expect(heroi.locator('.ag-eixo span').first()).toHaveText('seg');
  // mês: acumulado deste mês e do mês passado
  await page.locator('.ag-seg button', { hasText: 'Mês' }).click();
  await expect(heroi.locator('.ag-var.sobe')).toHaveText('▲ +7%');
  await expect(heroi.locator('polyline.ag-l')).toHaveCount(1);
  await expect(heroi.locator('polyline.ag-l-ant')).toHaveCount(1);
  // precisa de atenção: 3 alertas com atalho
  await expect(page.locator('.ag-sec', { hasText: 'Precisa de atenção' })).toBeVisible();
  await expect(page.locator('button.ag-al')).toHaveCount(3);
  await expect(page.locator('.ag-kpi').first()).toContainText('1,3 M Kz');
  await expect(page.locator('.ag-kpi', { hasText: 'Compras de clientes' })).toContainText('301');
  await expect(page.locator('.ag-kpi', { hasText: 'Valor médio por compra' })).toContainText('17 mil Kz');
  // contador dos alertas na barra de separadores
  await expect(page.locator('.ag-separadores .ag-badge')).toHaveText('3');
  expect(await semCorte(page)).toEqual([]);
  expect(erros).toEqual([]);
});

test('app de gestão: classificação das rotas e detalhe numa folha que sobe do fundo', async ({ page }) => {
  const erros = await abrirApp(page);
  await page.locator('.ag-separadores button', { hasText: 'Rotas' }).click();
  await expect(page.locator('#ag-h1')).toHaveText('Rotas');
  await expect(page.locator('.ag-resumo')).toHaveText('4 rotas · 3 a crescer · 1 a cair');
  await expect(page.locator('.ag-rota').first()).toContainText('1011 · Robel Tesfamichael');
  await expect(page.locator('.ag-rota').first()).toContainText('4 dias acima do habitual');
  await page.locator('.ag-seg button', { hasText: /^Hoje$/ }).click();
  await expect(page.locator('.ag-rota').nth(2)).toContainText('1005');
  await page.locator('.ag-rota').first().click();
  const f = page.locator('#ag-folha');
  await expect(f).toBeVisible();
  await expect(f).toContainText('Rota 1011');
  await expect(f.locator('rect.ag-b')).toHaveCount(14);
  await f.getByRole('button', { name: 'Fechar' }).click();
  await expect(f).toBeHidden();
  expect(await semCorte(page)).toEqual([]);
  expect(erros).toEqual([]);
});

test('app de gestão: alertas com contagens e texto completo', async ({ page }) => {
  await abrirApp(page);
  await page.locator('button.ag-al').first().click();
  await expect(page.locator('#ag-h1')).toHaveText('Alertas');
  await expect(page.locator('.ag-conta.critico strong')).toHaveText('2');
  await expect(page.locator('article.ag-al')).toHaveCount(3);
  await expect(page.locator('article.ag-al').first()).toContainText('Confirmar se a rota saiu');
});

test('app de gestão: aviso de dados incompletos (Pinguim BO sem ligação ou dias sem vendas)', async ({ page }) => {
  await abrirApp(page, { fonte: { ok: false, erro: 'x' } });
  await expect(page.locator('.ag-faixa')).toContainText('Pinguim BO sem ligação');
  await expect(page.locator('.ag-ponto-est.erro')).toHaveCount(1);
  const vazio = {
    ...IND,
    hoje: { ...IND.hoje, total: 0, documentos: 0 },
    ontem: { ...IND.ontem, total: 0, variacao: -1 },
    ultima_venda: '2026-11-03',
  };
  await page.unrouteAll({ behavior: 'ignoreErrors' });
  await abrirApp(page, { ind: vazio });
  await expect(page.locator('.ag-faixa')).toContainText('Sem vendas registadas desde 03-11-2026');
});

test('app de gestão: tema escuro e ecrã pequeno sem nada cortado', async ({ browser }) => {
  const SE = { ...devices['iPhone SE'] };
  delete SE.defaultBrowserType;
  const ctx = await browser.newContext({ ...SE, colorScheme: 'dark' });
  const page = await ctx.newPage();
  const erros = await abrirApp(page);
  const fundo = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
  expect(fundo).toBe('rgb(7, 14, 34)');
  expect(await semCorte(page)).toEqual([]);
  if (process.env.CAPTURAS) {
    const pasta = process.env.CAPTURAS;
    await page.screenshot({ path: pasta + '/g-escuro.png', fullPage: true });
  }
  expect(erros).toEqual([]);
  await ctx.close();
});
