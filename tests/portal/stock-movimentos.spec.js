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

test('extracto de movimentos: vendido = vendas Pinguim BO + SAP; SA + guia é transferência; balanço por armazém conferido com o Pinguim BO', async ({
  page,
}) => {
  const l = (em, doc, tipo, arm, armazem, entrada, saida, natureza, par) => ({
    em,
    doc,
    tipo,
    tipo_nome: '',
    armazem_cod: arm,
    armazem,
    codigo: '10000332',
    artigo: 'FRALDAS NAVAL',
    entrada,
    saida,
    natureza,
    ...(par ? { par } : {}),
  });
  const linhas = [
    l('2026-11-25 07:00:00', 'SA A1000/150', 'SA', '1151', 'HIGITEC', 0, 400, 'transf_enviada', ['GTC A1000/150']),
    l('2026-11-25 07:00:01', 'GTC A1000/150', 'GTC', '10001010', 'Carrinha 1010', 400, 0, 'transf_recebida', ['SA A1000/150']),
    l('2026-11-25 10:00:00', 'FR/21', 'FR', '10001010', 'Carrinha 1010', 0, 50, 'venda'),
    l('2026-11-25 11:00:00', 'FA/3', 'FA', '10001010', 'Carrinha 1010', 0, 30, 'venda'),
    l('2026-11-25 12:00:00', 'NC/1', 'NC', '10001010', 'Carrinha 1010', 5, 0, 'devolucao'),
    l('2026-11-25 17:00:00', 'SA A1000/151', 'SA', '1151', 'HIGITEC', 0, 20, 'venda_sap'),
  ];
  const der = {
    saidas_efectivas: 95,
    vendas_pinguim: 75,
    vendas: 80,
    devolucoes: 5,
    vendas_sap: 20,
    transf_enviadas: 400,
    transf_recebidas: 400,
    entradas_externas: 0,
    outras_saidas: 0,
    acertos: 0,
  };
  const bal = (cod, armazem, inicial, x) => ({
    armazem_cod: cod,
    armazem,
    inicial,
    compras: 0,
    recebido: 0,
    vendas: 0,
    vendas_sap: 0,
    enviado: 0,
    outras: 0,
    acertos: 0,
    ...x,
  });
  await page.route('**/v1/movimentos**', (r) =>
    r.fulfill({
      json: {
        ok: true,
        linhas,
        resumo: { movimentos: 6, documentos: 6, entradas: 405, saidas: 500, liquido: -95, ...der },
        por_artigo: [{ codigo: '10000332', artigo: 'FRALDAS NAVAL', entradas: 405, saidas: 500, liquido: -95, ...der }],
        por_tipo: [],
        por_armazem: [
          {
            armazem_cod: '1151',
            armazem: 'HIGITEC',
            documentos: 2,
            entradas: 0,
            saidas: 420,
            liquido: -420,
            ...der,
            saidas_efectivas: 20,
            transf_recebidas: 0,
          },
          {
            armazem_cod: '10001010',
            armazem: 'Carrinha 1010',
            documentos: 4,
            entradas: 405,
            saidas: 80,
            liquido: 325,
            ...der,
            transf_enviadas: 0,
            vendas_sap: 0,
          },
        ],
        por_natureza: [
          { natureza: 'venda', documentos: 2, entradas: 0, saidas: 80, liquido: -80 },
          { natureza: 'devolucao', documentos: 1, entradas: 5, saidas: 0, liquido: 5 },
          { natureza: 'venda_sap', documentos: 1, entradas: 0, saidas: 20, liquido: -20 },
          { natureza: 'transf_enviada', documentos: 1, entradas: 0, saidas: 400, liquido: -400 },
          { natureza: 'transf_recebida', documentos: 1, entradas: 400, saidas: 0, liquido: 400 },
        ],
        balanco: [
          bal('10001010', 'Carrinha 1010', 0, { recebido: 400, vendas: 75, entradas: 400, saidas: 75, final: 325, pinguim: 325, diferenca: 0 }),
          bal('1151', 'HIGITEC', 500, { enviado: 400, vendas_sap: 20, entradas: 0, saidas: 420, final: 80, pinguim: 79, diferenca: -1 }),
        ],
        armazens: [],
        limitado: false,
      },
    }),
  );
  const erros = await abrir(page, '#stock-movimentos', { periodo: { preset: 'semana' } });
  const tile = (n) => page.locator('.tile', { hasText: n });
  await expect(tile('Vendido')).toContainText('95');
  await expect(tile('Vendido')).toContainText('Pinguim BO 75 (FR, FA − NC) · SAP 20');
  await expect(tile('Transferências entre armazéns')).toContainText('400');
  await expect(tile('Stock final')).toContainText('405');
  await expect(tile('Stock final')).toContainText('Pinguim BO: 404');
  const b = page.locator('#s-mov-balanco');
  await expect(b).toContainText('Balanço por armazém');
  await expect(b.locator('.mov-ok')).toHaveText('✓');
  await expect(b.locator('.mov-dif')).toHaveText('-1');
  await expect(page.locator('#s-mov-balanco-detalhe')).toContainText('Vendas SAP');
  const nat = page.locator('#s-mov-naturezas');
  await expect(nat).toContainText('Vendas no Pinguim BO (FR, FA)');
  await expect(nat).toContainText('Vendas SAP (saída de armazém sem guia)');
  await expect(page.locator('#s-mov-armazens')).toContainText('Transf. enviadas');
  await expect(page.locator('#s-mov-linhas .mov-nat-transf_enviada')).toHaveText('Transferência enviada');
  await expect(page.locator('#s-mov-linhas .mov-nat-par').first()).toContainText('→ GTC A1000/150');
  await expect(page.locator('#s-mov-linhas .mov-nat-venda_sap')).toHaveText('Venda SAP');
  expect(erros).toEqual([]);
});
