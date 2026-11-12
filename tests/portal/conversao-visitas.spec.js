// Conversão das visitas: plano do dia congelado × compras (× visitas do OLAP): conversão por rota e planeados sem compra.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const rota = (r, planeados, compraram, fora) => ({
  rota: r,
  promotor: 'P' + r,
  dias: 5,
  planeados,
  planeados_compra: compraram,
  valor_planeados: compraram * 50000,
  fora_plano: fora,
  valor_fora: fora * 20000,
  visitados: null,
  visitados_compra: null,
  planeados_visitados: null,
  conversao_plano: compraram / planeados,
  peso_plano: 0.8,
  cobertura: null,
  conversao_visitas: null,
});

test('conversão das visitas: indicadores, rotas do mais baixo para o mais alto e planeados sem compra', async ({ page }) => {
  let pedido = '';
  await page.route('**/v1/conversao**', (r) => {
    pedido = r.request().url();
    return r.fulfill({
      json: {
        ok: true,
        de: '2026-10-01',
        ate: '2026-10-26',
        olap: false,
        dias_sem_plano: ['2026-10-01'],
        rotas: [rota('1001', 40, 30, 4), rota('1002', 50, 20, 10)],
        total: {},
        seguir: [{ dia: '2026-10-26', rota: '1002', c: 'B', n: 'Cantina B', media: 80000, motivo: 'compra em atraso' }],
      },
    });
  });
  const erros = await abrir(page, '#conversao-visitas');
  await expect(page.locator('.tile', { hasText: 'Conversão do plano' })).toContainText('56%'); // 50 de 90
  await expect(page.locator('.tile', { hasText: 'Conversão das visitas' })).toContainText('OLAP');
  expect(pedido).toContain('de=2026-10-01');
  const linhas = page.locator('#s-conv tbody tr');
  await expect(linhas.first()).toContainText('1002'); // 40%: o mais baixo primeiro
  await expect(page.locator('#s-conv tfoot')).toContainText('55,6%');
  await page.click('a[href^="#conversao-visitas/conv-seguir"]');
  await expect(page.locator('#s-conv-seguir tbody tr')).toContainText('Cantina B');
  expect(erros).toEqual([]);
});
