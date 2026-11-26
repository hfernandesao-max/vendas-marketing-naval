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
