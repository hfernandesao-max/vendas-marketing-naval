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

test('painel de gestão: indicadores, nove gráficos com tabela e legendas nas séries múltiplas', async ({ page }) => {
  const P = JSON.parse(readFileSync('tests/dados/painel-exemplo.json', 'utf8'));
  await page.route('**/v1/painel**', (r) => r.fulfill({ json: P }));
  const erros = await abrir(page, '#painel-gestao');
  await expect(page.locator('.tile')).toHaveCount(5);
  await expect(page.locator('.gst-painel .viz-card')).toHaveCount(9);
  await expect(page.locator('.gst-painel svg.vx').first()).toBeVisible();
  await expect(page.locator('.gst-painel .glegenda')).toHaveCount(2);
  await page
    .locator('.gst-painel .viz-card')
    .nth(3)
    .getByRole('button', { name: /Tabela/ })
    .click();
  await expect(page.locator('.gst-painel .viz-card').nth(3).locator('table')).toContainText('A (80% do valor)');
  expect(erros).toEqual([]);
});
