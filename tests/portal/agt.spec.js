// Comunicação AGT (Integrações): página com o estado de cada documento, a partir da API v1/agt.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const doc = (o) => ({
  rota: '1001',
  promotor: 'Andrea Santos',
  loja: 'Rota 1001',
  tipo: 'Factura-recibo',
  tipo_codigo: 'FR',
  documento: 'FR FR0126S78519N 6',
  documento_agt: 'FR FR0126S78519N/6',
  total: 1000,
  estado_documento: '8002',
  data: '2026-10-24',
  data_fiscal: '2026-10-24',
  data_documento_txt: '24/10/2026',
  data_fiscal_txt: '24/10/2026',
  anulado: false,
  entidade: 'LOJA A',
  estado: 'comunicado',
  estado_agt: 'Enviado',
  mensagem_erro: '',
  ...o,
});

test('Comunicação AGT: as colunas e os estados da consulta validada, sem alterações, e um só pedido ao servidor', async ({ page }) => {
  let pedidos = 0;
  const json = '{"errorList":[{"idError":"E40","descriptionError":"customerTaxID 5000 desconhecido"}]}';
  await page.route('**/v1/agt*', (r) => {
    pedidos++;
    return r.fulfill({
      json: {
        disponivel: true,
        documentos: [
          doc({}),
          doc({ documento_agt: 'FR FR0126S78519N/7', estado: 'pendente', estado_agt: 'Pendente' }),
          doc({ documento_agt: 'FR FR0126S78519N/8', estado: 'erro', estado_agt: 'Erro', mensagem_erro: json }),
          doc({ documento_agt: 'FR FR0126S78519N/9', estado: 'erro', estado_agt: 'Não enviado', mensagem_erro: 'Falha genérica' }),
          doc({ documento_agt: 'NC NC0126S1/1', tipo: 'Nota de crédito', total: -200, loja: 'Rota 1002', rota: '1002' }),
        ],
      },
    });
  });
  const erros = await abrir(page, '#agt');
  await expect(page.locator('h1')).toHaveText('Comunicação AGT');
  const cab = page.locator('#page table thead');
  for (const c of [
    'Loja',
    'Tipo de documento',
    'Documento',
    'Total do documento',
    'Mensagem',
    'Data documento',
    'Data fiscal',
    'Nome entidade',
    'Estado AGT',
    'Mensagem de erro',
  ])
    await expect(cab).toContainText(c);
  await expect(page.locator('tr', { hasText: 'FR FR0126S78519N/6' })).toContainText('Enviado');
  await expect(page.locator('tr', { hasText: 'FR FR0126S78519N/6' })).toContainText('8002');
  await expect(page.locator('tr', { hasText: 'FR FR0126S78519N/6' })).toContainText('24/10/2026');
  await expect(page.locator('tr', { hasText: 'FR FR0126S78519N/7' })).toContainText('Pendente');
  await expect(page.locator('tr', { hasText: 'FR FR0126S78519N/8' })).toContainText('Erro');
  await expect(page.locator('tr', { hasText: 'FR FR0126S78519N/8' })).toContainText('descriptionError');
  await expect(page.locator('tr', { hasText: 'FR FR0126S78519N/9' })).toContainText('Não enviado');
  await expect(page.locator('tr', { hasText: 'FR FR0126S78519N/9' })).toContainText('Falha genérica');
  await expect(page.locator('.tile', { hasText: 'Documentos' }).first().locator('.v')).toHaveText('5');
  await expect(
    page
      .locator('.tile')
      .filter({ has: page.locator('.l', { hasText: /^Enviado$/ }) })
      .locator('.v'),
  ).toHaveText('2');
  await expect(
    page
      .locator('.tile')
      .filter({ has: page.locator('.l', { hasText: /^Erro$/ }) })
      .locator('.v'),
  ).toHaveText('1');
  await expect(
    page
      .locator('.tile')
      .filter({ has: page.locator('.l', { hasText: /^Não enviado$/ }) })
      .locator('.v'),
  ).toHaveText('1');
  await expect(
    page
      .locator('.tile')
      .filter({ has: page.locator('.l', { hasText: /^Pendente$/ }) })
      .locator('.v'),
  ).toHaveText('1');
  // ordem da consulta: loja e, dentro da loja, como vem do Pinguim BO
  await expect(page.locator('#page tbody tr').first()).toContainText('FR FR0126S78519N/6');
  await expect(page.locator('#page tbody tr').nth(4)).toContainText('Rota 1002');
  expect(pedidos).toBe(1);
  expect(erros).toEqual([]);
});

test('Comunicação AGT: sem base de dados mostra o motivo e permite tentar de novo', async ({ page }) => {
  await page.route('**/v1/agt*', (r) => r.fulfill({ json: { disponivel: false, erro: 'Sem ligação à base de dados do Pinguim BO.' } }));
  await abrir(page, '#agt');
  await expect(page.locator('#page')).toContainText('Não foi possível ler a comunicação à AGT');
  await expect(page.getByRole('button', { name: 'Tentar de novo' })).toBeVisible();
});
