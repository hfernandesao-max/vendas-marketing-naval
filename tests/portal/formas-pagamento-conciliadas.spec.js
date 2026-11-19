// As formas de pagamento conciliadas pertencem a Finanças, não ao painel principal.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

test('Início não mostra o resumo; aparece em Finanças > Multicaixa por TPA > Formas de pagamento', async ({ page }) => {
  await page.route('**/v1/multicaixa-tpa**', (r) => {
    const q = new URL(r.request().url()).searchParams;
    if (q.get('formas') !== '1') return r.fulfill({ json: { ok: true, relatorio: {} } });
    return r.fulfill({
      json: {
        ok: true,
        gere: false,
        promotores: {},
        correcoes: [
          {
            id: 'FP-2026-00045',
            dia: '2026-11-13',
            estado: 'aprovada',
            pinguim: { numerario: 0, multicaixa: 1000, invalida: 0 },
            numerario: 600,
            multicaixa: 400,
          },
          {
            id: 'FP-2026-00046',
            dia: '2026-11-13',
            estado: 'pendente',
            pinguim: { numerario: 0, multicaixa: 500, invalida: 0 },
            numerario: 300,
            multicaixa: 200,
          },
        ],
        reconciliacao: {
          pinguim_ligado: true,
          totais: { numerario: 0, entregue: 0, multicaixa: 0, talaos: 0, invalida: 0 },
          excepcoes: [],
          linhas: [],
        },
      },
    });
  });
  const erros = await abrir(page, '#inicio');
  await expect(page.locator('.fp-conc')).toHaveCount(0);
  await page.goto('/reports/index.html#multicaixa-tpa?de=2026-11-13&ate=2026-11-13&vista=formas');
  const q = page.locator('.fp-conc');
  await expect(q).toContainText('Formas de pagamento conciliadas');
  await expect(q).toContainText('1 documento(s) com a forma de pagamento corrigida e aprovada');
  const linha = (rot) => q.locator('tr', { hasText: rot }).locator('td');
  await expect(linha('Numerário').nth(1)).toHaveText(/^0,00/);
  await expect(linha('Numerário').nth(2)).not.toHaveText(/^0,00/);
  await expect(linha('Numerário').nth(3)).toHaveText(/^\+/);
  await expect(linha('Multicaixa').nth(3)).toHaveText(/^-/);
  await expect(q.locator('tr.fp-conc-tot td').nth(3)).toContainText('0,00');
  expect(erros).toEqual([]);
});
