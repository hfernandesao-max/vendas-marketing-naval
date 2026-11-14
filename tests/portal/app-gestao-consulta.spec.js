// App de gestão: menu numa gaveta lateral com as secções agrupadas, sem barras de deslocamento; consulta de vendas
// (até 12 meses), documentos com as linhas, clientes, artigos e cobranças, cada secção só com o recurso na matriz.
import { test, expect, devices } from '@playwright/test';

const HOJE = '2026-11-14';
const IND = {
  ok: true,
  hora: HOJE + ' 14:35',
  hoje: { dia: HOJE, total: 612000, documentos: 3, previsto: 1437381, progresso: 0.426 },
  ontem: { dia: '2026-11-13', total: 1340000, documentos: 88, habitual: 1210000, variacao: 0.107 },
  semana: { de: '2026-11-09', total: 5180000, anterior: 5420000, variacao: -0.044 },
  mes: { de: '2026-11-01', total: 5180000, anterior: 4860000, variacao: 0.066 },
  rotas: [],
  serie: [{ d: HOJE, t: 612000 }],
  ultima_venda: HOJE,
  alertas: { critico: 1, aviso: 0, info: 0, principais: [] },
};
const doc = (n, data, hora, cliente, codigo, rota, total, pago, extra = {}) => ({
  chave: n,
  documento: n,
  data,
  hora,
  rota,
  promotor: 'Promotor ' + rota,
  tipo: 'Factura/Recibo',
  cliente,
  cliente_codigo: codigo,
  nif: '5000' + codigo,
  liquidado: pago,
  total,
  total_sem_iva: Math.round(total / 1.14),
  valor_pago: pago ? total : 0,
  pagamentos: pago ? [{ meio: 'Numerário', valor: total }] : [],
  ...extra,
});
const DOCS = [
  doc('FR A/101', HOJE, '09:12', 'Cantina Kilamba', 'C001', '1004', 114000, true),
  doc('FR A/102', HOJE, '10:40', 'Loja Maianga', 'C002', '1005', 228000, false),
  doc('NC A/7', HOJE, '11:05', 'Cantina Kilamba', 'C001', '1004', -11400, false, { nc: true, tipo: 'Nota de crédito' }),
  doc('FR A/055', '2026-10-03', '15:00', 'Cantina Kilamba', 'C001', '1004', 342000, true),
  doc('FR A/012', '2026-06-20', '12:00', 'Loja Maianga', 'C002', '1005', 57000, false),
  doc('FR A/003', '2025-12-01', '12:00', 'Mercado Viana', 'C003', '1011', 570000, true),
];
const LINHAS = [
  {
    documento: 'FR A/101',
    codigo: 'A10',
    artigo: 'Fraldas Naval Medium',
    unidade: 'UN',
    quantidade: 10,
    preco_unitario: 10000,
    valor_sem_iva: 100000,
    desconto: 0,
    iva: 14000,
    total: 114000,
  },
  {
    documento: 'FR A/102',
    codigo: 'A20',
    artigo: 'Sabão Verde 8PC',
    unidade: 'CX',
    quantidade: 20,
    preco_unitario: 10000,
    valor_sem_iva: 200000,
    desconto: 0,
    iva: 28000,
    total: 228000,
  },
  {
    documento: 'NC A/7',
    codigo: 'A10',
    artigo: 'Fraldas Naval Medium',
    unidade: 'UN',
    quantidade: 1,
    preco_unitario: 10000,
    valor_sem_iva: 10000,
    desconto: 0,
    iva: 1400,
    total: 11400,
    nc: true,
  },
];
const IPHONE = { ...devices['iPhone 13'] };
delete IPHONE.defaultBrowserType;
test.use({ ...IPHONE, locale: 'pt-PT' });

async function abrirApp(page, permissoes) {
  const sessao = {
    login: 'henrique',
    nome: 'Henrique Fernandes',
    perfil: 'SADM',
    idioma: 'pt',
    csrf: 'x',
    permissoes: permissoes || {
      'app-gestao': 'V',
      'vendas-dia': 'V',
      documentos: 'V',
      'vendas-cliente': 'V',
      'vendas-artigo': 'V',
      pendentes: 'V',
      alertas: 'V',
    },
  };
  await page.route('**/v1/sessao**', (r) => r.fulfill({ json: sessao }));
  await page.route('**/v1/indicadores**', (r) => r.fulfill({ json: IND }));
  await page.route('**/v1/vendas/estado**', (r) => r.fulfill({ json: { ok: true } }));
  await page.route('**/v1/vendas?**', (r) => {
    const u = new URL(r.request().url());
    const iso = (s) => s.slice(0, 4) + '-' + s.slice(4, 6) + '-' + s.slice(6, 8);
    const de = iso(u.searchParams.get('start_date'));
    const ate = iso(u.searchParams.get('end_date'));
    const docs = DOCS.filter((d) => d.data >= de && d.data <= ate);
    if (u.searchParams.get('include_items') === '1') {
      const ns = new Set(docs.map((d) => d.documento));
      return r.fulfill({ json: { vendas: docs, artigos: LINHAS.filter((l) => ns.has(l.documento)) } });
    }
    return r.fulfill({ json: docs });
  });
  const erros = [];
  page.on('pageerror', (e) => erros.push(e.message));
  await page.clock.setFixedTime(new Date(HOJE + 'T14:40:00'));
  await page.goto('/gestao/index.html');
  await expect(page.locator('#ag-h1')).toHaveText('Hoje');
  await expect(page.locator('#ag-separadores button').first()).toBeAttached(); // a app já arrancou
  return erros;
}
async function ir(page, nome) {
  await page.locator('#ag-menu-b').click();
  await expect(page.locator('#ag-gaveta')).toBeVisible();
  await page.locator('#ag-separadores button', { hasText: nome }).click();
  await expect(page.locator('#ag-gaveta')).not.toBeVisible();
  await expect(page.locator('#ag-h1')).toHaveText(nome);
}

test('app de gestão: menu numa gaveta lateral, agrupado, sem barras de deslocamento nem barra de separadores', async ({ page }) => {
  const erros = await abrirApp(page);
  await expect(page.locator('#ag-menu-b')).toHaveAttribute('aria-expanded', 'false');
  await page.locator('#ag-menu-b').click();
  const g = page.locator('#ag-gaveta');
  await expect(g).toBeVisible();
  await expect(page.locator('#ag-menu-b')).toHaveAttribute('aria-expanded', 'true');
  await expect(g.locator('h2')).toHaveText(['Resumo', 'Comercial', 'Finanças', 'Operações', 'Acompanhamento']);
  await expect(g.locator('button[aria-current=page]')).toContainText('Hoje');
  await expect(g).toContainText('Henrique Fernandes');
  // Esc fecha (diálogo modal nativo: foco preso enquanto aberto)
  await page.keyboard.press('Escape');
  await expect(g).not.toBeVisible();
  // sem barra de separadores em baixo e sem barras de deslocamento visíveis
  expect(await page.evaluate(() => getComputedStyle(document.documentElement).scrollbarWidth)).toBe('none');
  await expect(page.locator('.ag-separadores')).toHaveCount(0);
  expect(erros).toEqual([]);
});

test('app de gestão: documento com as linhas, cliente com 12 meses e voltar ao documento', async ({ page }) => {
  const erros = await abrirApp(page);
  await ir(page, 'Documentos');
  const l = page.locator('.ag-linha');
  await expect(l).toHaveCount(3);
  await expect(l.first()).toContainText('NC A/7'); // mais recente primeiro
  await expect(page.locator('.ag-linha', { hasText: 'FR A/102' })).toContainText('Por pagar');
  // pesquisa sem perder o teclado
  await page.locator('.ag-pesquisa').fill('maianga');
  await expect(l).toHaveCount(1);
  await page.locator('.ag-linha', { hasText: 'FR A/102' }).click();
  const f = page.locator('#ag-folha');
  await expect(f.locator('.ag-folha-cab h2')).toHaveText('Documento FR A/102');
  await expect(f).toContainText('Sabão Verde 8PC');
  await expect(f).toContainText('20 CX');
  await expect(f).toContainText('Loja Maianga (C002)');
  await expect(f.locator('.ag-totais')).toContainText('228 000 Kz');
  // cliente: últimos 12 meses e os documentos dele
  await f.getByRole('button', { name: 'Loja Maianga (C002)' }).click();
  await expect(f.locator('.ag-folha-cab h2')).toHaveText('Loja Maianga');
  await expect(f).toContainText('Vendas por mês');
  await expect(f.locator('.ag-linha')).toHaveCount(2);
  // voltar ao documento
  await f.getByRole('button', { name: /Voltar/ }).click();
  await expect(f.locator('.ag-folha-cab h2')).toHaveText('Documento FR A/102');
  // dia anterior: sem documentos
  await f.getByRole('button', { name: 'Fechar' }).click();
  await page.getByRole('button', { name: 'Dia anterior' }).click();
  await expect(page.locator('#ag-conteudo')).toContainText('Sem documentos neste dia.');
  expect(erros).toEqual([]);
});

test('app de gestão: vendas dos últimos 12 meses, artigos e cobranças por antiguidade', async ({ page }) => {
  const erros = await abrirApp(page);
  await ir(page, 'Vendas');
  await page.locator('.ag-seg button', { hasText: '12 meses' }).click();
  await expect(page.locator('.ag-heroi svg rect.ag-b')).toHaveCount(12);
  await expect(page.locator('.ag-kpi', { hasText: 'Documentos' })).toContainText('5');
  await expect(page.locator('#ag-conteudo')).toContainText('Melhores clientes');
  await ir(page, 'Artigos');
  await page.locator('.ag-seg button', { hasText: 'Hoje' }).click();
  await expect(page.locator('.ag-doc-linha').first()).toContainText('Sabão Verde 8PC');
  await ir(page, 'Cobranças');
  await expect(page.locator('.ag-heroi')).toContainText('285 000 Kz');
  await expect(page.locator('.ag-kpi', { hasText: 'mais de 90 dias' })).toContainText('57 mil Kz');
  await page.locator('.ag-linha', { hasText: 'Loja Maianga' }).click();
  await expect(page.locator('#ag-folha .ag-folha-cab h2')).toHaveText('Loja Maianga');
  expect(erros).toEqual([]);
});

test('app de gestão: cada secção só aparece com o recurso na matriz de acessos', async ({ page }) => {
  await abrirApp(page, { 'app-gestao': 'V', alertas: 'V' });
  await page.locator('#ag-menu-b').click();
  const m = page.locator('#ag-separadores');
  await expect(m).toContainText('Hoje');
  for (const x of ['Vendas', 'Documentos', 'Clientes', 'Artigos', 'Cobranças']) await expect(m.getByRole('button', { name: x, exact: true })).toHaveCount(0);
});

test('app de gestão: as secções abrem logo (dados antecipados em segundo plano; 12 meses = dias fechados + hoje)', async ({ page }) => {
  const pedidos = [];
  page.on('request', (q) => {
    if (q.url().includes('/v1/vendas?')) pedidos.push(new URL(q.url()).search);
  });
  const erros = await abrirApp(page);
  // a antecipação pede os dados das secções, um de cada vez
  await expect.poll(() => pedidos.some((x) => x.includes('start_date=20251115&end_date=20261113'))).toBe(true);
  await expect.poll(() => pedidos.some((x) => x.includes('start_date=20261114&end_date=20261114&include_items=1'))).toBe(true);
  await page.waitForTimeout(500);
  const n = pedidos.length;
  // ao abrir, nada a carregar: os documentos de hoje já estão na memória
  await page.locator('#ag-menu-b').click();
  await page.locator('#ag-separadores button', { hasText: 'Documentos' }).click();
  await expect(page.locator('.ag-carrega')).toHaveCount(0);
  await expect(page.locator('.ag-linha')).toHaveCount(3, { timeout: 300 });
  await page.locator('#ag-menu-b').click();
  await page.locator('#ag-separadores button', { hasText: 'Clientes' }).click();
  await expect(page.locator('.ag-linha')).toHaveCount(3, { timeout: 300 });
  expect(pedidos.length).toBe(n); // não voltou a pedir
  expect(erros).toEqual([]);
});
