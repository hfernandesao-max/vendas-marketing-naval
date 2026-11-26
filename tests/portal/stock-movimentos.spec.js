// Logística › Stock › Extracto de movimentos (API simulada): entradas e saídas por documento, filtros e saldo corrido.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const linha = (em, doc, tipo, codigo, artigo, entrada, saida, saldo) => ({
  em,
  doc,
  tipo,
  tipo_nome: tipo === 'GTC' ? 'Guia de transporte' : 'Factura-recibo',
  armazem_cod: '100010',
  armazem: 'Carrinha 1010',
  codigo,
  artigo,
  entrada,
  saida,
  ...(saldo != null ? { saldo } : {}),
});

test('extracto de movimentos: resumo, lista por documento e, com armazém e artigo, o saldo depois de cada movimento', async ({ page }) => {
  const pedidos = [];
  await page.route('**/v1/movimentos**', (r) => {
    const u = new URL(r.request().url());
    pedidos.push(Object.fromEntries(u.searchParams));
    const comSaldo = u.searchParams.get('armazem') && u.searchParams.get('artigo');
    const linhas = [
      linha('2026-11-25 07:10:00', 'GTC A1000/150', 'GTC', '10000332', 'FRALDAS NAVAL', 50, 0, comSaldo ? 120 : null),
      linha('2026-11-25 10:00:00', 'FR0126S77782N/21', 'FR', '10000332', 'FRALDAS NAVAL', 0, 50, comSaldo ? 70 : null),
    ];
    if (!comSaldo) linhas.push(linha('2026-11-25 11:00:00', 'FR0126S77782N/22', 'FR', '10000385', 'SABÃO CASTANHO', 0, 4));
    return r.fulfill({
      json: {
        ok: true,
        linhas,
        resumo: { movimentos: linhas.length, documentos: linhas.length, entradas: 50, saidas: comSaldo ? 50 : 54, liquido: comSaldo ? 0 : -4 },
        por_artigo: comSaldo
          ? [{ codigo: '10000332', artigo: 'FRALDAS NAVAL', entradas: 50, saidas: 50, liquido: 0 }]
          : [
              { codigo: '10000332', artigo: 'FRALDAS NAVAL', entradas: 50, saidas: 50, liquido: 0 },
              { codigo: '10000385', artigo: 'SABÃO CASTANHO', entradas: 0, saidas: 4, liquido: -4 },
            ],
        saldo_inicial: comSaldo ? 70 : null,
        saldo_final: comSaldo ? 70 : null,
        armazens: [{ codigo: '100010', nome: 'Carrinha 1010' }],
        limitado: false,
      },
    });
  });
  const erros = await abrir(page, '#stock-movimentos', { periodo: { preset: 'semana' } });
  await expect(page.locator('#page')).toContainText('Resumo por artigo');
  await expect(page.locator('#page')).toContainText('GTC A1000/150');
  await expect(page.locator('#page')).toContainText('Escolha um armazém e um artigo');
  // um armazém e um artigo: o saldo aparece
  await page.getByLabel('Armazém ou carrinha').selectOption('100010');
  await page.getByLabel('Artigo (código ou nome)').fill('10000332');
  await page.locator('.mov-filtros').getByRole('button', { name: 'Procurar' }).click();
  await expect(page.locator('#page')).toContainText('Saldo inicial');
  await expect(page.locator('#page')).toContainText('120');
  expect(pedidos.at(-1)).toMatchObject({ armazem: '100010', artigo: '10000332' });
  // nada sai da página
  const larg = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(larg).toBeLessThanOrEqual(1);
  expect(erros).toEqual([]);
});

test('rastreabilidade: com um artigo, o fluxo entre locais; uma caixa abre os documentos e um documento abre o detalhe', async ({ page }) => {
  await page.route('**/v1/movimentos**', (r) =>
    r.fulfill({
      json: {
        ok: true,
        linhas: [linha('2026-11-21 07:00:00', 'GTC/7', 'GTC', '10000332', 'FRALDAS NAVAL', 50, 0)],
        resumo: { movimentos: 1, documentos: 1, entradas: 50, saidas: 0, liquido: 50 },
        por_artigo: [{ codigo: '10000332', artigo: 'FRALDAS NAVAL', entradas: 50, saidas: 0, liquido: 50 }],
        por_tipo: [],
        por_armazem: [],
        armazens: [],
      },
    }),
  );
  const doc = (id, d, em, q, tipo, ent) => ({ id, doc: d, em, q, tipo, tipo_nome: '', entidade: ent || '' });
  await page.route('**/v1/rastreabilidade**', (r) => {
    const u = new URL(r.request().url());
    if (u.searchParams.get('doc'))
      return r.fulfill({
        json: {
          ok: true,
          documento: {
            id: '2',
            doc: 'GTC/7',
            em: '2026-11-21 07:00:00',
            tipo: 'GTC',
            tipo_nome: 'Guia de transporte',
            entidade: '',
            loja: '1010',
            total: 0,
            linhas: [{ codigo: '10000332', artigo: 'FRALDAS NAVAL', armazem: '1151 · HIGITEC', quantidade: 50, efeito: -50, preco: 0, total: 0 }],
          },
        },
      });
    return r.fulfill({
      json: {
        ok: true,
        artigo: '10000332',
        nome: 'FRALDAS NAVAL',
        de: '2026-11-20',
        ate: '2026-11-26',
        documentos: 3,
        nos: [
          { id: 'FORN', tipo: 'fornecedores', nome: 'Fornecedores e entradas', entradas: 0, saidas: 500 },
          { id: 'A:1151', tipo: 'central', cod: '1151', nome: 'HIGITEC', entradas: 500, saidas: 50, inicial: 100, final: 550, diferenca: 0 },
          {
            id: 'A:10001010',
            tipo: 'carrinha',
            rota: '1010',
            cod: '10001010',
            nome: 'Carrinha 1010',
            entradas: 50,
            saidas: 30,
            inicial: 0,
            final: 20,
            diferenca: 0,
          },
          { id: 'CLI', tipo: 'clientes', nome: 'Clientes', entradas: 30, saidas: 0 },
        ],
        ligacoes: [
          { de: 'FORN', para: 'A:1151', q: 500, docs: [doc('1', 'FC/1', '2026-11-20 08:00:00', 500, 'FC', 'Fornecedor X')] },
          { de: 'A:1151', para: 'A:10001010', q: 50, docs: [doc('2', 'GTC/7', '2026-11-21 07:00:00', 50, 'GTC')] },
          { de: 'A:10001010', para: 'CLI', q: 30, docs: [doc('3', 'FR/26', '2026-11-21 15:34:00', 30, 'FR', 'BAILO')] },
        ],
      },
    });
  });
  await page.addInitScript(() => sessionStorage.setItem('vm-mov-pedido', JSON.stringify({ artigo: '10000332' })));
  const erros = await abrir(page, '#stock-movimentos', { periodo: { preset: 'semana' } });
  const svg = page.locator('.rast-svg');
  await expect(svg).toBeVisible();
  await expect(svg.locator('.rast-no')).toHaveCount(4);
  await expect(svg.locator('.rast-lig')).toHaveCount(3);
  if (process.env.CAPTURAS) await page.locator('.rast').screenshot({ path: 'test-results/rastreio.png' });
  // a carrinha: documentos que entraram e saíram
  await svg.locator('.rast-carrinha').click();
  const lista = page.locator('dialog.dlg').last();
  await expect(lista).toContainText('GTC/7');
  await expect(lista).toContainText('FR/26');
  // um documento: o detalhe com as linhas
  await lista.locator('tr.rast-doc', { hasText: 'GTC/7' }).click();
  const det = page.locator('dialog.dlg').last();
  await expect(det).toContainText('Guia de transporte');
  await expect(det).toContainText('1151 · HIGITEC');
  expect(erros).toEqual([]);
});
