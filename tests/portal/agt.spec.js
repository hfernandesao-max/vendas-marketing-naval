// Comunicação AGT (Integrações): página com o estado de cada documento, a partir da API v1/agt.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const doc = (o) => ({
  rota: '1001',
  promotor: 'Andrea Santos',
  tipo: 'Factura-recibo',
  tipo_codigo: 'FR',
  documento: 'FR 1/1',
  total: 1000,
  data: '2026-10-24',
  data_fiscal: '2026-10-24',
  anulado: false,
  entidade: 'LOJA A',
  motivo_anulacao: null,
  estado: 'comunicado',
  pedido_agt: '559728934618656132',
  ...o,
});

test('Comunicação AGT: menu Integrações, colunas, estados e um só pedido ao servidor', async ({ page }) => {
  let pedidos = 0;
  await page.route('**/v1/agt*', (r) => {
    pedidos++;
    return r.fulfill({
      json: {
        disponivel: true,
        documentos: [
          doc({}),
          doc({ documento: 'FR 1/2', rota: '1002', promotor: 'Cristina', estado: 'por_comunicar', pedido_agt: null }),
          doc({ documento: 'NC 1/1', tipo: 'Nota de crédito', total: -200 }),
          doc({ documento: 'FT 1/3', tipo: 'Factura', anulado: true, motivo_anulacao: 'Anulado', data_fiscal: null }),
        ],
      },
    });
  });
  const erros = await abrir(page, '#agt');
  await expect(page.locator('h1')).toHaveText('Comunicação AGT');
  await expect(page.locator('.nav a.on, .nav .on').first()).toContainText('Comunicação AGT');
  const cab = page.locator('#page table thead');
  for (const c of ['Rota', 'Tipo de documento', 'Total', 'Data do documento', 'Data fiscal', 'Estado AGT', 'Estado do documento', 'Nome da entidade', 'Estado'])
    await expect(cab).toContainText(c);
  await expect(page.locator('tr', { hasText: 'FR 1/1' })).toContainText('Pedido n.º 559728934618656132');
  await expect(page.locator('tr', { hasText: 'FR 1/1' })).toContainText('Comunicado');
  await expect(page.locator('tr', { hasText: 'FR 1/2' })).toContainText('Sem pedido de comunicação');
  await expect(page.locator('tr', { hasText: 'FR 1/2' })).toContainText('Por comunicar');
  await expect(page.locator('tr', { hasText: 'FT 1/3' })).toContainText('Anulado');
  await expect(page.locator('.tile', { hasText: 'Comunicados' }).locator('.v')).toHaveText('3');
  await expect(page.locator('.tile', { hasText: 'Por comunicar' }).locator('.v')).toHaveText('1');
  await expect(page.locator('.tile', { hasText: 'Anulados' }).locator('.v')).toHaveText('1');
  await expect(page.locator('.tile', { hasText: 'Documentos' }).first().locator('.v')).toHaveText('4');
  expect(pedidos).toBe(1);
  expect(erros).toEqual([]);
});

test('Comunicação AGT: sem base de dados mostra o motivo e permite tentar de novo', async ({ page }) => {
  await page.route('**/v1/agt*', (r) => r.fulfill({ json: { disponivel: false, erro: 'Sem ligação à base de dados do Pinguim BO.' } }));
  await abrir(page, '#agt');
  await expect(page.locator('#page')).toContainText('Não foi possível ler a comunicação à AGT');
  await expect(page.getByRole('button', { name: 'Tentar de novo' })).toBeVisible();
});
