// Terreno › Visitas do dia (ficha de preparação) e Gestão › Qualidade dos dados e anomalias.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const CLI = (c, estado, extra = {}) => ({
  c,
  n: 'Loja ' + c,
  r: '1001',
  dia: 2,
  media: 50000,
  intervalo: 7,
  ult: '2026-10-20',
  dias: 14,
  atraso: 2,
  estado,
  proxima: '2026-10-27',
  compras: 6,
  motivo: 'compra em atraso',
  encomenda: [{ cod: 'FR', n: 'Fraldas M', q: 12, freq: 1 }],
  deixou: [{ cod: 'SB', n: 'Sabão azul' }],
  propor: [{ cod: 'SN', n: 'Sabonete', pen: 0.6 }],
  risco: { risco: 'alto', aberto: 60000, dias: 44 },
  ...extra,
});

test('visitas do dia: parados primeiro, encomenda sugerida, deixou de comprar, propor e risco', async ({ page }) => {
  await page.route('**/v1/carteira**', (r) =>
    r.fulfill({
      json: {
        ok: true,
        gerado: '2026-11-03 04:20:00',
        base: '2026-11-03',
        dia: '2026-11-03',
        visitas: { 1001: [CLI('B', 'parado'), CLI('A', 'em_dia', { risco: undefined })] },
      },
    }),
  );
  const erros = await abrir(page, '#visitas-dia');
  await expect(page.locator('.vd-ficha')).toHaveCount(2);
  const f = page.locator('.vd-ficha').first();
  await expect(f).toContainText('Loja B');
  await expect(f).toContainText('Parado');
  await expect(f).toContainText('Risco alto');
  await expect(f).toContainText('12 × Fraldas M');
  await expect(f).toContainText('Sabão azul');
  await expect(f).toContainText('Sabonete (compram 60 % dos clientes da rota)');
  await expect(f).toContainText('compra a cada 7 dias');
  await expect(page.locator('.tile', { hasText: 'Parados' })).toContainText('1');
  expect(erros).toEqual([]);
});

test('qualidade dos dados e anomalias: duas tabelas com o tipo traduzido', async ({ page }) => {
  await page.route('**/v1/carteira**', (r) =>
    r.fulfill({
      json: {
        ok: true,
        gerado: '2026-11-03 04:20:00',
        anomalias: [
          {
            tipo: 'repetidos',
            rota: '1001',
            cliente: 'Loja X',
            documento: 'FR 1, FR 2, FR 3',
            data: '2026-11-02',
            valor: 3000,
            texto: '3 documentos do mesmo cliente no mesmo dia',
          },
        ],
        qualidade: [{ tipo: 'artigo_sem_peso', rota: '', texto: 'Artigo Fraldas (FR) sem peso no Pinguim BO' }],
      },
    }),
  );
  const erros = await abrir(page, '#qualidade-dados');
  await expect(page.locator('#s-qd-anomalias')).toContainText('Documentos repetidos');
  await expect(page.locator('#s-qd-qualidade')).toContainText('Artigo sem peso');
  expect(erros).toEqual([]);
});
