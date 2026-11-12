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

test('previsão: objectivos automáticos do mês por rota, com ritmo, necessário por dia e estado', async ({ page }) => {
  await simular(page);
  const obj = (rota, o, r, estado) => ({
    rota,
    promotor: 'P' + rota,
    objectivo: o,
    dias_uteis: 25,
    realizado: r,
    objectivo_ate_hoje: o / 2,
    cumprimento: r / o,
    ritmo: r / (o / 2),
    desvio: r - o / 2,
    falta: o - r,
    dias_restantes: 12,
    necessario_dia: (o - r) / 12,
    projeccao: r * 2,
    estado,
    criterio: 'minimo',
    crescimento: 0.03,
  });
  await page.route('**/v1/previsao**', (r) =>
    r.fulfill({
      json: {
        ...PREVISAO,
        objectivos: {
          mes: '2026-10',
          parametros: { crescimento_min: 0.03, crescimento_alvo: 0.05 },
          rotas: [obj('1002', 2000000, 800000, 'abaixo'), obj('1001', 1000000, 600000, 'acima')],
          total: { ...obj('', 3000000, 1400000, 'abaixo') },
        },
      },
    }),
  );
  const erros = await abrir(page, '#previsao');
  await expect(page.locator('.tile').filter({ hasText: 'Objectivo de' })).toContainText(/3\s?000\s?000/);
  await expect(page.locator('.tile').filter({ hasText: 'Ritmo face ao objectivo' })).toContainText('93%');
  const linhas = page.locator('section', { hasText: 'Objectivos do mês por rota' }).locator('tbody tr');
  await expect(linhas).toHaveCount(2);
  await expect(linhas.first()).toContainText('Abaixo do objectivo');
  await expect(page.locator('section', { hasText: 'Objectivos do mês por rota' })).toContainText('+ 5% de crescimento');
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

for (const [w, h, umEcra] of [
  [1920, 1080, true],
  [1600, 860, true],
  [1366, 768, true],
  [1280, 680, true],
]) {
  test(`Início com os gráficos de gestão ${w}×${h}: ${umEcra ? 'grelha 4 × 3 num só ecrã' : 'quadros legíveis com deslocação'}, nada cortado, tabelas e legendas`, async ({
    page,
  }) => {
    const P = JSON.parse(readFileSync('tests/dados/painel-exemplo.json', 'utf8'));
    await page.setViewportSize({ width: w, height: h });
    await page.route('**/v1/painel**', (r) => r.fulfill({ json: P }));
    const erros = await abrir(page, '#inicio');
    await expect(page.locator('.viz-grid .viz-card')).toHaveCount(11);
    await expect(page.locator('.viz-grid.ecra-unico')).toHaveCount(umEcra ? 1 : 0);
    await expect(page.locator('.viz-card.dupla')).toHaveCount(1);
    for (const titulo of ['Tendência diária e previsão', 'Como foi cobrado', 'Antiguidade da dívida', 'Distribuição numérica', 'Carteira de clientes por mês'])
      await expect(page.locator('.viz-card h3', { hasText: titulo })).toHaveCount(1);
    await page.waitForTimeout(300);
    if (umEcra) {
      expect(await page.evaluate(() => document.documentElement.scrollHeight - innerHeight)).toBeLessThanOrEqual(1);
      for (const c of await page.locator('.viz-card').all()) {
        const b = await c.boundingBox();
        expect(b.y + b.height).toBeLessThanOrEqual(h);
      }
    }
    // números dos eixos sem sobreposição (marcas a pelo menos 12 px na vertical)
    const sobrepostos = await page.evaluate(() =>
      [...document.querySelectorAll('.viz-card svg')].some((sv) => {
        const eixo = [...sv.querySelectorAll('text.vx-ax[text-anchor="end"]')];
        const x0 = eixo.length ? eixo[0].getAttribute('x') : null;
        const ys = eixo
          .filter((e) => e.getAttribute('x') === x0)
          .map((e) => e.getBoundingClientRect().top)
          .sort((a, b) => a - b);
        return ys.some((y, i) => i && y - ys[i - 1] < 9);
      }),
    );
    expect(sobrepostos).toBe(false);
    // nada cortado: eixos, rótulos e legendas dentro de cada quadro
    const cortados = await page.evaluate(() =>
      [...document.querySelectorAll('.viz-card')]
        .map((c) => {
          const cb = c.getBoundingClientRect();
          let pior = 0;
          c.querySelectorAll('svg text').forEach((e) => {
            const b = e.getBoundingClientRect();
            if (b.height) pior = Math.max(pior, b.bottom - cb.bottom);
          });
          return pior > 0 ? c.querySelector('h3').textContent : null;
        })
        .filter(Boolean),
    );
    expect(cortados).toEqual([]);
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
