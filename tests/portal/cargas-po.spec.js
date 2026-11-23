// Logística › Cargas (PO): conferir a PO no cais (visto por linha e motivo de cada diferença), validar no Back-Office
// e as ordens da Guia de Remessa ao Pinguim BO (execução à mão enquanto o robô não está ligado).
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const po = (estado = 'emitida') => ({
  numero: 'PO-2026-000412',
  rota: '1008',
  promotor: 'Daniel M.',
  data: '2026-10-26',
  estado,
  fase: estado === 'carregada' ? 'conferida' : undefined,
  emitida_por: 'sup',
  versao: 2,
  confirmado_por: estado === 'carregada' ? 'log' : null,
  confirmado_em: estado === 'carregada' ? '2026-10-26 07:20:00' : null,
  linhas: [
    { codigo: '10000332', artigo: 'FRALDAS NAVAL MEDIUM', quantidade: 400, entregue: estado === 'carregada' ? 400 : undefined },
    { codigo: '10000292', artigo: 'SABÃO VERDE', quantidade: 30, entregue: estado === 'carregada' ? 28 : undefined },
  ],
});

test('cargas (PO): conferir no cais exige o visto em todas as linhas e o motivo de cada diferença', async ({ page }) => {
  const pedidos = [];
  let estado = 'emitida';
  await page.route('**/v1/requisicoes**', (r) => {
    if (r.request().method() === 'POST') {
      pedidos.push(JSON.parse(r.request().postData()));
      estado = 'carregada';
      return r.fulfill({ json: { ok: true, requisicao: po(estado) } });
    }
    return r.fulfill({ json: { ok: true, gere: true, requisicao: po(estado), motivos: {} } });
  });
  await page.route('**/v1/ordens-pinguim**', (r) => r.fulfill({ json: { ok: true, gere: true, modo: 'manual', por_validar: [], ordens: [] } }));
  const erros = await abrir(page, '#cargas-po');
  await expect(page.getByRole('tab', { name: 'Conferir no cais' })).toHaveAttribute('aria-selected', 'true');
  await page.getByLabel('Número da PO').fill('po-2026-000412');
  await page.getByRole('button', { name: 'Abrir' }).click();
  await expect(page.locator('.cp-cab')).toContainText('PO-2026-000412');
  // diferença: aparece o motivo; sem vistos não conclui
  await page.getByLabel('Carregado · SABÃO VERDE').fill('28');
  await expect(page.getByLabel('Motivo · SABÃO VERDE')).toBeVisible();
  await expect(page.locator('.cp-res')).toContainText('0 de 2 conferidas · diferenças: 1 (-2 un.)');
  // diferença viva ao lado do campo: ✓ verde se bate com o pedido, senão a diferença a vermelho
  const linhaSabao = page.locator('.cp-tab tr', { has: page.getByLabel('Carregado · SABÃO VERDE') });
  const linhaFraldas = page.locator('.cp-tab tr', { has: page.getByLabel('Carregado · FRALDAS NAVAL MÉDIO') });
  await expect(linhaSabao.locator('.lc-dif')).toHaveText('−2');
  await expect(linhaSabao.locator('.lc-dif')).toHaveClass(/lc-dif-mal/);
  await expect(linhaFraldas.locator('.lc-dif')).toHaveText('✓');
  await page.getByRole('button', { name: 'Concluir conferência' }).click();
  await expect(page.locator('.cp-conf .erro')).toContainText('Ponha o visto em todas as linhas');
  await page.getByLabel('Visto · FRALDAS NAVAL MÉDIO').check();
  await page.getByLabel('Visto · SABÃO VERDE').check();
  await page.getByRole('button', { name: 'Concluir conferência' }).click();
  await expect(page.locator('.cp-conf .erro')).toContainText('Indique o motivo');
  await page.getByLabel('Motivo · SABÃO VERDE').selectOption('falta_armazem');
  await page.getByRole('button', { name: 'Concluir conferência' }).click();
  await expect(page.locator('.cp-conf')).toContainText('Conferida por log');
  expect(pedidos[0]).toMatchObject({
    accao: 'confirmar_carga',
    numero: 'PO-2026-000412',
    entregue: { 10000332: 400, 10000292: 28 },
    motivos: { 10000292: { codigo: 'falta_armazem' } },
  });
  expect(erros).toEqual([]);
});

test('cargas (PO): o Back-Office valida e trata a ordem da guia no Pinguim BO', async ({ page }) => {
  const pedidos = [];
  let validada = false;
  let gerada = false;
  const carga = { ...po('carregada'), diferencas: 1 };
  const ordem = () => ({
    id: 'GUIA-PO-2026-000412',
    referencia: 'PO-2026-000412',
    rota: '1008',
    promotor: 'Daniel M.',
    armazem_destino: '10001008',
    modo: 'manual',
    estado: gerada ? 'gerada' : 'por_executar',
    documento: gerada ? { numero: 'GTC 2026/0042' } : null,
    criada_em: '2026-10-26 08:00:00',
    linhas: [
      { codigo: '10000332', artigo: 'FRALDAS NAVAL MEDIUM', quantidade: 400, armazem_origem: 'HIGITEC PIV' },
      { codigo: '10000292', artigo: 'SABÃO VERDE', quantidade: 28, armazem_origem: 'HIGITEC PIV' },
    ],
  });
  await page.route('**/v1/requisicoes**', (r) => {
    pedidos.push(JSON.parse(r.request().postData() || '{}'));
    validada = true;
    return r.fulfill({ json: { ok: true, requisicao: { ...carga, fase: 'validada' } } });
  });
  await page.route('**/v1/ordens-pinguim**', (r) => {
    if (r.request().method() === 'POST') {
      pedidos.push(JSON.parse(r.request().postData()));
      gerada = true;
      return r.fulfill({ json: { ok: true, ordem: ordem() } });
    }
    return r.fulfill({ json: { ok: true, gere: true, modo: 'manual', por_validar: validada ? [] : [carga], ordens: validada ? [ordem()] : [] } });
  });
  const erros = await abrir(page, '#cargas-po');
  await page.getByRole('tab', { name: 'Validar' }).click();
  await expect(page.locator('.tile').filter({ hasText: 'Por validar' }).locator('.v')).toHaveText('1');
  // com diferenças: rever antes de validar (o motivo do Fiel de Armazém está à vista)
  await page.getByRole('button', { name: 'Rever' }).click();
  const dlg = page.locator('dialog[open]');
  await expect(dlg).toContainText('SABÃO VERDE');
  await dlg.getByRole('button', { name: 'Validar e pedir a guia' }).click();
  await expect(page.locator('.tile').filter({ hasText: 'Por validar' }).locator('.v')).toHaveText('0');
  expect(pedidos[0]).toMatchObject({ accao: 'validar', numero: 'PO-2026-000412' });
  // ordem por executar (robô por ligar): o Back-Office cria-a no Pinguim BO e escreve o número
  await page.getByRole('tab', { name: 'Ordens ao Pinguim BO' }).click();
  await expect(page.locator('.cp-ordem')).toContainText('Guia de Remessa · PO-2026-000412');
  await page.getByRole('button', { name: 'Executada no Pinguim BO' }).click();
  const d2 = page.locator('dialog[open]');
  await expect(d2).toContainText('10001008');
  await d2.getByLabel('Número do documento no Pinguim BO').fill('GTC 2026/0042');
  await d2.getByRole('button', { name: 'Gravar' }).click();
  await expect(page.locator('.cp-ordem')).toContainText('Gerada · a confirmar');
  expect(pedidos[1]).toMatchObject({ accao: 'executada_manual', id: 'GUIA-PO-2026-000412', documento: 'GTC 2026/0042' });
  expect(erros).toEqual([]);
});

test('cargas (PO): o documento da carga conferida mostra o pedido, o carregado, a diferença e o motivo', async ({ page }) => {
  const carga = {
    ...po('carregada'),
    diferencas: 1,
    linhas: [
      { codigo: '10000332', artigo: 'FRALDAS NAVAL MEDIUM', quantidade: 400, entregue: 400, visto: true },
      { codigo: '10000292', artigo: 'SABAO VERDE', quantidade: 30, entregue: 28, visto: true, motivo: { codigo: 'falta_armazem', texto: '' } },
    ],
  };
  await page.route('**/v1/ordens-pinguim**', (r) => r.fulfill({ json: { ok: true, gere: true, modo: 'manual', por_validar: [carga], ordens: [] } }));
  await page.route('**/v1/requisicoes**', (r) => r.fulfill({ json: { ok: true, gere: true, requisicao: null, motivos: {} } }));
  // PDF: sem separador novo (bloqueado), descarrega; o texto lê-se do ficheiro (jsPDF sem compressão)
  await page.addInitScript(() => (window.open = () => null));
  const erros = await abrir(page, '#cargas-po');
  await page.getByRole('tab', { name: 'Validar' }).click();
  const linha = page.locator('tr', { hasText: 'PO-2026-000412' });
  const [d] = await Promise.all([page.waitForEvent('download'), linha.getByRole('button', { name: 'PDF', exact: true }).click()]);
  const fs = await import('node:fs');
  const texto = fs.readFileSync(await d.path(), 'latin1');
  expect(texto).toContain('Carregado');
  expect(texto).not.toContain('Vendido'); // a PO é um pedido ao armazém, não uma venda
  expect(texto).toContain('Conferida no cais');
  expect(texto).toContain('por validar'); // parênteses escapados no PDF
  expect(texto).toContain('Diferen');
  expect(texto).toContain('-2');
  expect(texto).toContain('Falta no armaz');
  expect(texto).toContain('428'); // total carregado (400 + 28), ao lado do total pedido (430)
  expect(texto).toContain('430');
  expect(erros).toEqual([]);
});

test('cargas (PO): valores fora do normal pedem confirmação; Enter passa à linha seguinte; o rascunho volta ao reabrir', async ({ page }) => {
  const pedidos = [];
  await page.route('**/v1/requisicoes**', (r) => {
    if (r.request().method() === 'POST') {
      pedidos.push(JSON.parse(r.request().postData()));
      return r.fulfill({ json: { ok: true, requisicao: po('carregada') } });
    }
    return r.fulfill({ json: { ok: true, gere: true, requisicao: po('emitida'), motivos: {} } });
  });
  await page.route('**/v1/ordens-pinguim**', (r) => r.fulfill({ json: { ok: true, gere: true, modo: 'manual', por_validar: [], ordens: [] } }));
  const erros = await abrir(page, '#cargas-po');
  await page.getByLabel('Número da PO').fill('PO-2026-000412');
  await page.getByRole('button', { name: 'Abrir' }).click();
  const fraldas = page.getByLabel('Carregado · FRALDAS NAVAL MÉDIO');
  const linhaFraldas = page.locator('.cp-tab tr', { has: fraldas });
  // a linha activa fica destacada; Enter passa ao visto e depois à linha seguinte
  await fraldas.focus();
  await expect(linhaFraldas).toHaveClass(/lc-activa/);
  await page.keyboard.type('4000');
  await expect(linhaFraldas).toHaveClass(/lc-atipico/);
  await expect(linhaFraldas.locator('.lc-atipico-txt')).toContainText('mais do dobro do esperado (400)');
  await page.keyboard.press('Enter');
  await expect(page.getByLabel('Visto · FRALDAS NAVAL MÉDIO')).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.getByLabel('Carregado · SABÃO VERDE')).toBeFocused();
  // o rascunho guarda só o que mudou e volta ao reabrir a PO
  await page.getByLabel('Visto · FRALDAS NAVAL MÉDIO').check();
  await page.getByRole('button', { name: 'Abrir' }).click();
  await expect(page.getByLabel('Carregado · FRALDAS NAVAL MÉDIO')).toHaveValue('4000');
  await expect(page.getByLabel('Visto · FRALDAS NAVAL MÉDIO')).toBeChecked();
  await expect(page.locator('.lc-reposto')).toContainText('Rascunho reposto');
  await page.getByRole('button', { name: 'Marcar todas iguais ao pedido' }).click();
  await page.getByLabel('Carregado · FRALDAS NAVAL MÉDIO').fill('4000');
  await page.getByLabel('Motivo · FRALDAS NAVAL MÉDIO').selectOption('falta_armazem');
  // ao concluir, o valor fora do normal pede confirmação: «Corrigir» volta ao campo
  await page.getByRole('button', { name: 'Concluir conferência' }).click();
  const dlg = page.locator('dialog[open]');
  await expect(dlg).toContainText('Valores fora do normal');
  await expect(dlg).toContainText('10000332');
  await dlg.getByRole('button', { name: 'Corrigir' }).click();
  await expect(page.getByLabel('Carregado · FRALDAS NAVAL MÉDIO')).toBeFocused();
  expect(pedidos).toEqual([]);
  // «Gravar assim mesmo» segue
  await page.getByRole('button', { name: 'Concluir conferência' }).click();
  await page.locator('dialog[open]').getByRole('button', { name: 'Gravar assim mesmo' }).click();
  await expect.poll(() => pedidos.length).toBe(1);
  expect(pedidos[0].entregue).toEqual({ 10000332: 4000, 10000292: 30 });
  // gravado: o rascunho sai deste navegador
  await expect.poll(() => page.evaluate(() => localStorage.getItem('vm-rascunho-po-conferencia-PO-2026-000412'))).toBeNull();
  expect(erros).toEqual([]);
});
