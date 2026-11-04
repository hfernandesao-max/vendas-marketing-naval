// Velocidade (2.ª parte) no navegador: páginas e idiomas carregados a pedido, linhas de artigos só quando são
// precisas e pedidos do arranque em paralelo.
import { test, expect } from '@playwright/test';
import { HOJE, artigos, vendas } from './dados.js';

/**
 * Abre o portal com uma API do Pinguim BO que distingue os documentos (sem include_items) das linhas
 * (include_items=only), e regista os pedidos feitos (endereços).
 */
async function abrirMedindo(page, hash, opcoes = {}) {
  const pedidos = [];
  page.on('request', (r) => pedidos.push(r.url()));
  await page.route('**/v1/vendas**', (r) => {
    const u = new URL(r.request().url());
    const modo = u.searchParams.get('include_items');
    if (modo === 'only') return r.fulfill({ json: { artigos } });
    return r.fulfill({ json: modo === '1' ? { vendas, artigos } : vendas });
  });
  if (opcoes.antes) await opcoes.antes(page);
  await page.addInitScript(
    ({ lang }) => {
      sessionStorage.setItem('vm-periodo', JSON.stringify({ preset: 'mes' }));
      Object.keys(localStorage)
        .filter((k) => k.startsWith('vm-'))
        .forEach((k) => localStorage.removeItem(k));
      if (lang) localStorage.setItem('vm-lang', lang);
    },
    { lang: opcoes.lang || '' },
  );
  await page.clock.setFixedTime(HOJE);
  const erros = [];
  page.on('pageerror', (e) => erros.push(e.message));
  await page.goto('/reports/index.html' + hash);
  await page.waitForSelector('#loader[hidden]', { state: 'attached' });
  await page.waitForSelector('#page > *');
  return { pedidos, erros };
}
const modulo = (pedidos, nome) => pedidos.some((u) => new URL(u).pathname.endsWith('/js/' + nome));
const linhas = (pedidos) => pedidos.filter((u) => u.includes('/v1/vendas') && u.includes('include_items=only')).length;

test('páginas a pedido: o arranque traz só a página aberta; as outras descarregam-se quando se abrem', async ({ page }) => {
  const { pedidos, erros } = await abrirMedindo(page, '#vendas-promotor');
  expect(modulo(pedidos, 'paginas/vendas.js')).toBe(true);
  for (const m of ['paginas/acessos.js', 'paginas/sap.js', 'paginas/plano-cargas.js', 'paginas/equipamentos.js', 'paginas/clientes.js'])
    expect(modulo(pedidos, m), m).toBe(false);
  await page.evaluate(() => (location.hash = '#cadastros'));
  await expect(page.locator('#titulo')).toHaveText('Cadastros · indicadores');
  await expect(page.locator('#page')).not.toContainText('A carregar…');
  expect(modulo(pedidos, 'paginas/clientes.js')).toBe(true);
  expect(erros).toEqual([]);
});

test('linhas de artigos: não se pedem no arranque; pedem-se uma vez ao abrir «Vendas por artigo»', async ({ page }) => {
  const { pedidos, erros } = await abrirMedindo(page, '#vendas-dia');
  expect(linhas(pedidos)).toBe(0);
  expect(pedidos.filter((u) => u.includes('include_items=1')).length).toBe(0);
  await page.evaluate(() => (location.hash = '#vendas-artigo'));
  await expect(page.locator('#page')).toContainText('Fraldas');
  expect(linhas(pedidos)).toBe(1);
  // voltar à página não volta a pedir as linhas do mesmo período
  await page.evaluate(() => (location.hash = '#vendas-dia'));
  await page.evaluate(() => (location.hash = '#vendas-artigo'));
  await expect(page.locator('#page')).toContainText('Sabão');
  expect(linhas(pedidos)).toBe(1);
  // outro período: as linhas do período novo pedem-se de novo
  await page.click('#presets button:has-text("7 dias")');
  await expect.poll(() => linhas(pedidos)).toBe(2);
  expect(erros).toEqual([]);
});

test('página principal: abre com os documentos e o gráfico de artigos aparece quando as linhas chegam', async ({ page }) => {
  let soltar;
  const espera = new Promise((r) => (soltar = r));
  const { pedidos, erros } = await abrirMedindo(page, '#inicio', {
    antes: (p) =>
      p.route(
        (u) => u.href.includes('include_items=only'),
        async (r) => {
          await espera;
          await r.fulfill({ json: { artigos } });
        },
      ),
  });
  // o painel já está desenhado enquanto as linhas ainda não chegaram
  await expect(page.locator('.viz-card h3', { hasText: 'Vendas por dia' })).toBeVisible();
  expect(linhas(pedidos)).toBe(1);
  await expect(page.locator('.viz-card h3', { hasText: 'Artigos mais vendidos' })).toHaveCount(0);
  soltar();
  await expect(page.locator('.viz-card h3', { hasText: 'Artigos mais vendidos' })).toBeVisible();
  expect(erros).toEqual([]);
});

test('idiomas a pedido: em português não se descarrega nenhum dicionário; o inglês só quando é escolhido', async ({ page }) => {
  const { pedidos, erros } = await abrirMedindo(page, '#inicio');
  expect(modulo(pedidos, 'i18n/en.js')).toBe(false);
  expect(modulo(pedidos, 'i18n/ti.js')).toBe(false);
  await page.click('#lang button[data-l="en"]');
  await expect(page.locator('#titulo')).toHaveText('KPI dashboard');
  expect(modulo(pedidos, 'i18n/en.js')).toBe(true);
  expect(modulo(pedidos, 'i18n/ti.js')).toBe(false);
  expect(erros).toEqual([]);
});

test('idioma guardado: o portal abre já traduzido (o dicionário chega antes do primeiro desenho)', async ({ page }) => {
  const { pedidos, erros } = await abrirMedindo(page, '#inicio', { lang: 'ti' });
  expect(modulo(pedidos, 'i18n/ti.js')).toBe(true);
  expect(modulo(pedidos, 'i18n/en.js')).toBe(false);
  await expect(page.locator('html')).toHaveAttribute('lang', 'ti');
  expect(await page.locator('#titulo').textContent()).not.toBe('Painel de indicadores');
  expect(erros).toEqual([]);
});

test('arranque em paralelo: as vendas e os dados de base pedem-se sem esperar pela sessão', async ({ page }) => {
  const inicio = {};
  let sessaoRespondida = 0;
  const { erros } = await abrirMedindo(page, '#inicio', {
    antes: async (p) => {
      p.on('request', (r) => {
        const u = r.url();
        for (const k of ['v1/vendas', 'v1/dados/rotas', 'v1/cadastros']) if (u.includes(k) && !inicio[k]) inicio[k] = Date.now();
      });
      await p.route('**/v1/sessao', async (r) => {
        await new Promise((ok) => setTimeout(ok, 800));
        sessaoRespondida = Date.now();
        await r.fulfill({ status: 404, body: '' });
      });
    },
  });
  expect(sessaoRespondida).toBeGreaterThan(0);
  for (const k of ['v1/vendas', 'v1/dados/rotas', 'v1/cadastros']) expect(inicio[k], k).toBeLessThan(sessaoRespondida);
  expect(erros).toEqual([]);
});

test('relatório consolidado: carrega antes as páginas e as linhas que faltam', async ({ page }) => {
  const { pedidos, erros } = await abrirMedindo(page, '#vendas-dia');
  expect(linhas(pedidos)).toBe(0);
  const [d] = await Promise.all([page.waitForEvent('download', { timeout: 30_000 }), page.click('#xl-all')]);
  expect(d.suggestedFilename()).toMatch(/\.xlsx$/);
  expect(linhas(pedidos)).toBe(1);
  for (const m of ['paginas/acessos.js', 'paginas/sap.js', 'paginas/clientes.js']) expect(modulo(pedidos, m), m).toBe(true);
  expect(erros).toEqual([]);
});
