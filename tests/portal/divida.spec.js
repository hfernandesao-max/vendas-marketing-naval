// Antiguidade da dívida em escalões de 30 dias (0–30, 31–60, 61–90, > 90, desde a emissão) e prazo médio de
// recebimento (PMR = por receber ÷ facturado × dias do período), em Recebimentos › Facturas pendentes e no Início.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

let n = 0;
const factura = (data, total, pago) => ({
  chave: 'F' + ++n,
  data,
  hora: '10:00',
  rota: '1001',
  tipo: 'Factura',
  tipo_codigo: 'FT',
  documento: 'FT 1/' + n,
  cliente: 'CLIENTE ' + n,
  cliente_codigo: 'C' + n,
  nif: '50000000' + String(n).padStart(2, '0'),
  liquidado: pago >= total,
  total,
  valor_pago: pago,
  pagamentos: pago ? [{ codigo: 'NU', meio: 'Numerário', valor: pago }] : [],
  meio_pagamento: pago ? 'Numerário' : null,
  nc: false,
});
const V = [
  factura('2026-10-15', 6000, 6000), // paga
  factura('2026-10-20', 1000, 0), //   6 dias
  factura('2026-09-10', 1000, 0), //  46 dias
  factura('2026-08-10', 1000, 0), //  77 dias
  factura('2026-06-15', 1000, 0), // 133 dias
];
async function quadro(page, rotulo) {
  const v = await page
    .locator('.tile', { has: page.locator('.l:not(.l-var), .l-t', { hasText: new RegExp('^' + rotulo + '$') }) })
    .first()
    .locator('.v')
    .textContent({ timeout: 5000 });
  return Number(v.replace(/[^\d,-]/g, '').replace(',', '.'));
}

test('facturas pendentes: quatro escalões de 30 dias e prazo médio de recebimento', async ({ page }) => {
  const erros = await abrir(page, '#pendentes', {
    api: (r) => r.fulfill({ json: { vendas: V, artigos: [] } }),
    periodo: { de: '2026-06-01', ate: '2026-10-26' },
  });
  for (const rot of ['0–30 dias', '31–60 dias', '61–90 dias', 'Mais de 90 dias']) expect(await quadro(page, rot), rot).toBeCloseTo(1000, 2);
  expect(await quadro(page, 'Por receber')).toBeCloseTo(4000, 2);
  expect(await quadro(page, 'Prazo médio de recebimento')).toBe(59); // 4000 ÷ 10 000 × 148 dias
  await expect(page.locator('tr', { hasText: 'FT 1/5' }).locator('.p-bad')).toHaveText('Mais de 90 dias');
  await expect(page.locator('tr', { hasText: 'FT 1/4' }).locator('.p-serio')).toHaveText('61–90 dias');
  await page.evaluate(() => (location.hash = '#inicio'));
  const cartao = page.locator('.viz-card', { hasText: 'Antiguidade da dívida' });
  for (const rot of ['0–30 dias', '31–60 dias', '61–90 dias', 'Mais de 90 dias']) await expect(cartao).toContainText(rot);
  expect(erros).toEqual([]);
});
