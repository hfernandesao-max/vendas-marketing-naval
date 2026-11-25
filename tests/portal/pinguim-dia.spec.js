// Sincronização › abertura e fecho do dia no Pinguim BO (SESSION e FCL), com os casos reais de 19 a 25-11-2026.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const SYNC = {
  disponivel: true,
  agora: '2026-11-25 10:00:00',
  limites: { alerta_horas: 2, critico_horas: 24 },
  rotas: [{ rota: '1002', promotor: 'P 1002', ultima: '2026-11-25 09:00:00', minutos: 60, estado: 'em_dia' }],
};
const L = (o) => ({
  aberto_em: null,
  abertura: null,
  fechado_em: null,
  documento: null,
  fecho: 'aberto',
  atraso_horas: null,
  documentos: 5,
  vendas: 5,
  noutro_dia: 0,
  ultimo: null,
  ...o,
});
const PD = {
  disponivel: true,
  de: '2026-11-19',
  ate: '2026-11-25',
  agora: '2026-11-25 10:00:00',
  limite: '06:00',
  linhas: [
    L({
      rota: '1002',
      dia: '2026-11-19',
      aberto_em: '2026-11-19 06:50:00',
      abertura: 'certa',
      fechado_em: '2026-11-23 15:46:15',
      documento: 'FCL A1002/18',
      fecho: 'atrasado',
      atraso_horas: 81,
      noutro_dia: 4,
    }),
    L({ rota: '1006', dia: '2026-11-21', fechado_em: '2026-11-22 01:00:00', documento: 'FCL A1006/16', fecho: 'a_tempo' }),
    L({ rota: '1004', dia: '2026-11-24', fecho: 'por_fechar', atraso_horas: 4 }),
    L({ rota: '1010', dia: '2026-11-25', aberto_em: '2026-11-24 16:01:26', abertura: 'antecipada' }),
  ],
};

test('abertura e fecho do dia: primeiro o que precisa de acção, depois o quadro rota × dia', async ({ page }) => {
  await page.route('**/v1/sincronizacao*', (r) => r.fulfill({ json: SYNC }));
  await page.route('**/v1/pinguim-dia*', (r) => r.fulfill({ json: PD }));
  const erros = await abrir(page, '#sincronizacao');
  const exc = page.locator('#s-pd-excepcoes');
  await expect(exc).toContainText('o que precisa de acção');
  await expect(exc.locator('tbody tr')).toHaveCount(3);
  await expect(exc).toContainText('Dia por fechar há 4 h');
  await expect(exc).toContainText('Fechado 81 h depois do limite · 4 venda(s) emitida(s) noutro dia com esta data');
  await expect(exc).toContainText('Aberto na véspera');
  await expect(exc).not.toContainText('1006');
  const q = page.locator('#s-pd-quadro');
  await expect(q.locator('tbody tr')).toHaveCount(4);
  await expect(q.locator('tr', { hasText: '1006' }).locator('.pill')).toHaveText('Fechado 01:00');
  await expect(q.locator('tr', { hasText: '1004' }).locator('.pill')).toHaveText('Por fechar');
  await expect(q.locator('tr', { hasText: '1010' }).locator('.pd-marca')).toHaveCount(1);
  expect(erros).toEqual([]);
});

test('abertura e fecho do dia: tudo em dia mostra a confirmação', async ({ page }) => {
  await page.route('**/v1/sincronizacao*', (r) => r.fulfill({ json: SYNC }));
  await page.route('**/v1/pinguim-dia*', (r) => r.fulfill({ json: { ...PD, linhas: [PD.linhas[1]] } }));
  const erros = await abrir(page, '#sincronizacao');
  await expect(page.locator('#page p.note.ok')).toContainText('Tudo em dia');
  await expect(page.locator('#s-pd-excepcoes')).toHaveCount(0);
  expect(erros).toEqual([]);
});
