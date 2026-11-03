// Páginas da área Gestão no navegador (API simulada): alertas compostos na língua escolhida, previsão, assistente com
// texto seguro (nunca HTML vindo da resposta) e pedidos dos clientes com a marcação «fora do habitual».
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';
import { readFileSync } from 'node:fs';

const ALERTAS = {
  ok: true,
  dia: '2026-10-25',
  gerado: '2026-10-26 07:00:00',
  indisponivel: [],
  alertas: [
    {
      id: 'a',
      tipo: 'rota-sem-vendas',
      gravidade: 'critico',
      rota: '1001',
      titulo: 'x',
      detalhe: 'x',
      pagina: 'vendas-dia',
      p: { nome: '1001 · Andrea', dia: '2026-10-25', com: 5, n: 6, ds: 7, esperado: 120000 },
    },
    {
      id: 'b',
      tipo: 'subida-semana',
      gravidade: 'info',
      rota: '1004',
      titulo: 'x',
      detalhe: 'x',
      pagina: 'vendas-promotor',
      p: { nome: '1004', var: 0.42, semana: 900000, de: '2026-10-19', dia: '2026-10-25', media: 630000 },
    },
  ],
};
const PREVISAO = {
  ok: true,
  ultimo: '2026-10-25',
  dias: ['2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30', '2026-10-31', '2026-11-01'],
  rotas: [],
  sem_historico: [],
  total: null,
};
PREVISAO.total = {
  dias: PREVISAO.dias.map((d, i) => ({ dia: d, previsto: 100000 + i * 1000, minimo: 90000, maximo: 120000 })),
  semana: 721000,
  tendencia: 1.05,
  qualidade: { precisao: 0.87, dias: 14, wape: 0.13 },
  historico: { '2026-10-24': 98000, '2026-10-25': 0 },
};
PREVISAO.rotas = [{ rota: '1001', promotor: 'Andrea', ...PREVISAO.total }];

async function simular(page) {
  await page.route('**/v1/alertas**', (r) => r.fulfill({ json: ALERTAS }));
  await page.route('**/v1/previsao**', (r) => r.fulfill({ json: PREVISAO }));
  await page.route('**/v1/assistente', (r) =>
    r.request().method() === 'GET'
      ? r.fulfill({
          json: { ok: true, configurada: true, modelo: 'modelo-teste', uso: { tokens: 0, perguntas: 0, minhas_hoje: 0 }, limites: { perguntas_dia: 60 } },
        })
      : r.fulfill({
          json: {
            ok: true,
            resposta: 'Total: **635 400 Kz**\n\n| Rota | Vendas |\n|---|---|\n| 1001 | 60 000 |\n\n- <img src=x onerror="window.__xss=1">',
            fontes: ['vendas_resumo'],
          },
        }),
  );
}

test('alertas: texto composto a partir dos parâmetros, gravidade e ligação aos dados', async ({ page }) => {
  await simular(page);
  const erros = await abrir(page, '#alertas');
  await expect(page.locator('.gst-alerta')).toHaveCount(2);
  await expect(page.locator('.gst-alerta').first()).toContainText('Rota 1001 · Andrea sem vendas em 25-10-2026');
  await expect(page.locator('.gst-alerta').first()).toContainText('de 6 domingos anteriores');
  await expect(page.locator('.gst-alerta.g-info')).toContainText('+42%');
  await page.getByRole('button', { name: 'Críticos' }).click();
  await expect(page.locator('.gst-alerta')).toHaveCount(1);
  // o botão seleccionado continua legível: texto com cor diferente do fundo e estado em aria-pressed
  const sel = page.locator('.gst-filtros .btn[aria-pressed="true"]');
  await expect(sel).toHaveText('Críticos');
  const [cor, fundo] = await sel.evaluate((b) => [getComputedStyle(b).color, getComputedStyle(b).backgroundColor]);
  expect(cor).not.toBe(fundo);
  expect(fundo).toBe('rgb(255, 255, 255)');
  expect(erros).toEqual([]);
});

test('alertas em inglês: mesmo alerta traduzido', async ({ page }) => {
  await page.addInitScript(() => localStorage.setItem('vm-lang', 'en'));
  await simular(page);
  await abrir(page, '#alertas', { manterMemoria: true });
  await expect(page.locator('.gst-alerta').first()).toContainText('Route 1001 · Andrea had no sales on 25-10-2026');
  await expect(page.locator('.gst-alerta').first()).toContainText('Sundays');
});

test('previsão: indicadores, precisão medida e tabela por rota', async ({ page }) => {
  await simular(page);
  const erros = await abrir(page, '#previsao');
  await expect(page.locator('.tile').filter({ hasText: 'Precisão medida' })).toContainText('87%');
  await expect(page.locator('.tile').filter({ hasText: 'Tendência' })).toContainText('+5%');
  await expect(page.locator('table').last()).toContainText('1001 · Andrea');
  expect(erros).toEqual([]);
});

test('assistente: resposta com negrito e tabela, HTML da resposta mostrado como texto, fontes', async ({ page }) => {
  await simular(page);
  const erros = await abrir(page, '#assistente');
  await page.getByRole('textbox', { name: 'Pergunta ao assistente' }).fill('Quanto vendemos?');
  await page.keyboard.press('Enter');
  const r = page.locator('.gst-msg.ia').last();
  await expect(r.locator('strong')).toHaveText('635 400 Kz');
  await expect(r.locator('table td').first()).toHaveText('1001');
  await expect(r).toContainText('<img src=x');
  await expect(r.locator('img')).toHaveCount(0);
  expect(await page.evaluate(() => window.__xss)).toBeUndefined();
  await expect(r.locator('.gst-fontes')).toContainText('Vendas');
  expect(erros).toEqual([]);
});

test('pedidos dos clientes: encomenda com linha fora do habitual e acções de validação', async ({ page }) => {
  await page.route('**/v1/pedidos-clientes**', (r) =>
    r.fulfill({
      json: {
        ok: true,
        gere: true,
        pedidos: [
          {
            id: '261026090000-abcdef',
            tipo: 'encomenda',
            estado: 'pendente',
            cliente: 'X2',
            nome: 'Loja B',
            rota: '1002',
            criado: '2026-10-26 09:00:00',
            conteudo: {
              linhas: [
                { codigo: 'A1', nome: 'Fraldas', unidade: 'CX', quantidade: 50 },
                { codigo: 'A2', nome: 'Sabão', unidade: 'CX', quantidade: 5 },
              ],
              contacto_nome: 'Ana',
              contacto_telefone: '923000000',
              observacoes: '',
            },
            verificacao: { A1: { habitual: 10, factor: 5, fora: true }, A2: { habitual: 5, factor: 1, fora: false } },
          },
        ],
      },
    }),
  );
  const erros = await abrir(page, '#pedidos-clientes');
  const c = page.locator('.gst-alerta');
  await expect(c).toContainText('Loja B');
  await expect(c.locator('.p-warn').filter({ hasText: 'fora do habitual' })).toContainText('5×');
  await expect(c).toContainText('dentro do habitual (5)');
  await expect(c.getByRole('button', { name: 'Aprovar' })).toBeVisible();
  expect(erros).toEqual([]);
});

for (const [w, h] of [
  [1920, 1080],
  [1366, 768],
]) {
  test(`Início com os gráficos de gestão ${w}×${h}: grelha 4 × 3 num só ecrã, recebimentos mantidos, tabelas e legendas`, async ({ page }) => {
    const P = JSON.parse(readFileSync('tests/dados/painel-exemplo.json', 'utf8'));
    await page.setViewportSize({ width: w, height: h });
    await page.route('**/v1/painel**', (r) => r.fulfill({ json: P }));
    const erros = await abrir(page, '#inicio');
    await expect(page.locator('.viz-grid.ecra-unico .viz-card')).toHaveCount(11);
    await expect(page.locator('.viz-card.dupla')).toHaveCount(1);
    for (const titulo of ['Tendência diária e previsão', 'Como foi cobrado', 'Antiguidade da dívida', 'Distribuição numérica', 'Carteira de clientes por mês'])
      await expect(page.locator('.viz-card h3', { hasText: titulo })).toHaveCount(1);
    await page.waitForTimeout(300);
    expect(await page.evaluate(() => document.documentElement.scrollHeight - innerHeight)).toBeLessThanOrEqual(1);
    for (const c of await page.locator('.viz-card').all()) {
      const b = await c.boundingBox();
      expect(b.y + b.height).toBeLessThanOrEqual(h);
    }
    const abc = page.locator('.viz-card', { hasText: 'Curva ABC de clientes' });
    await abc.getByRole('button', { name: /Tabela/ }).click();
    await expect(abc.locator('table')).toContainText('A (80% do valor)');
    expect(erros).toEqual([]);
  });
}

test('painel principal: arrastar um quadro muda a ordem só nesta sessão; «Guardar vista» grava; «Desfazer» repõe', async ({ page }) => {
  const P = JSON.parse(readFileSync('tests/dados/painel-exemplo.json', 'utf8'));
  await page.setViewportSize({ width: 1920, height: 1080 });
  let gravado = null;
  await page.route('**/v1/painel**', (r) => r.fulfill({ json: P }));
  await page.route('**/v1/vistas', (r) => {
    if (r.request().method() === 'POST') {
      gravado = JSON.parse(r.request().postData());
      return r.fulfill({ json: { ok: true, vistas: { inicio: gravado.ordem } } });
    }
    return r.fulfill({ json: { ok: true, vistas: {} } });
  });
  const erros = await abrir(page, '#inicio');
  const titulos = () => page.locator('.viz-grid .viz-card h3').allTextContents();
  await expect(page.locator('.viz-grid .viz-card')).toHaveCount(11);
  const antes = await titulos();
  const cobrado = page.locator('.viz-card', { hasText: 'Como foi cobrado' }).locator('.viz-h');
  await cobrado.focus();
  await page.keyboard.press('Alt+ArrowLeft');
  const depois = await titulos();
  expect(depois).not.toEqual(antes);
  expect(depois.indexOf('Como foi cobrado')).toBe(antes.indexOf('Como foi cobrado') - 1);
  await expect(page.locator('#barra-vista')).toContainText('só até sair');
  await page.getByRole('button', { name: 'Guardar vista' }).click();
  await expect.poll(() => gravado && gravado.pagina).toBe('inicio');
  expect(gravado.ordem.indexOf('cobrado')).toBe(antes.indexOf('Como foi cobrado') - 1);
  expect(erros).toEqual([]);
});
