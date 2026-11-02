// Logística › Conciliação da carrinha (contagem cega, diferenças e aprovação com motivo) e limite de peso no plano de cargas.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const linhaCompleta = (codigo, artigo, esperado, contado = null) => ({
  codigo,
  artigo,
  unidade: 'CX',
  inicial: 10,
  carregado: 20,
  vendido: 30 - esperado,
  devolvido: 0,
  final: esperado,
  esperado,
  contado,
  diferenca: contado == null ? null : contado - esperado,
  preco: 1000,
  valor: contado == null ? null : (contado - esperado) * 1000,
  alertas: [],
});
const MOTIVOS = { avaria: 'Produto danificado', falta: 'Falta sem justificação', outro: 'Outro (explicar na nota)' };

test('conciliação: contagem cega, diferenças e aprovação com motivo', async ({ page }) => {
  let estado = 'por_contar';
  const pedidos = [];
  await page.route('**/v1/conciliacao-carrinha**', (r) => {
    if (r.request().method() === 'POST') {
      const b = JSON.parse(r.request().postData());
      pedidos.push(b);
      if (b.accao === 'contar') {
        estado = 'por_aprovar';
        return r.fulfill({ json: { ok: true, resumo: { diferencas: 1 } } });
      }
      if (!b.motivos.A1 || !b.motivos.A1.motivo) return r.fulfill({ json: { ok: false, erro: 'Indique o motivo de cada diferença:', erros: ['Fraldas M'] } });
      estado = 'aprovada';
      return r.fulfill({ json: { ok: true } });
    }
    const contada = estado !== 'por_contar';
    const linhas = contada
      ? [linhaCompleta('A1', 'Fraldas M', 4, 3), linhaCompleta('B2', 'Sabão', 2, 2)]
      : [
          { codigo: 'A1', artigo: 'Fraldas M', unidade: 'CX', contado: null, alertas: [] },
          { codigo: 'B2', artigo: 'Sabão', unidade: 'CX', contado: null, alertas: [] },
        ];
    return r.fulfill({
      json: {
        ok: true,
        gere: true,
        data: '2026-10-26',
        movimentos: 'separados',
        motivos: MOTIVOS,
        rotas: [
          {
            rota: '1001',
            promotor: 'Andrea Santos',
            cega: !contada,
            linhas,
            motivos: estado === 'aprovada' ? { A1: { motivo: 'avaria', nota: '' } } : {},
            resumo: { estado, artigos: 2, alertas: 0, diferencas: contada ? 1 : 0, falta_kz: contada ? 1000 : 0, sobra_kz: 0 },
            historico: [],
          },
        ],
      },
    });
  });
  const erros = await abrir(page, '#conciliacao-carrinha');
  const rota = page.locator('.cc-rota', { hasText: '1001' });
  await expect(rota).toContainText('Por contar');
  await rota.getByRole('button', { name: 'Contar' }).click();
  // cega: só o artigo e a quantidade que voltou
  await expect(rota.locator('th')).toHaveText(['Artigo', 'Quantidade que voltou']);
  await rota.getByLabel('Fraldas M').fill('3');
  await rota.getByRole('button', { name: 'Gravar contagem' }).click();
  await expect(rota.locator('.erro')).toContainText('Preencha todos os artigos');
  await rota.getByLabel('Sabão').fill('2');
  await rota.getByRole('button', { name: 'Gravar contagem' }).click();
  await expect.poll(() => pedidos.length).toBe(1);
  expect(pedidos[0]).toMatchObject({ accao: 'contar', rota: '1001', contagem: { A1: 3, B2: 2 } });
  await expect(page.locator('.cc-rota', { hasText: '1001' })).toContainText('Por aprovar');
  const det = page.locator('.cc-detalhe');
  await expect(det.locator('tr.cc-falta')).toContainText('-1');
  await expect(page.locator('#toast')).not.toBeVisible({ timeout: 15000 });
  await det.getByRole('button', { name: 'Aprovar com as diferenças' }).click();
  await expect(det.locator('.erro')).toContainText('Fraldas M');
  await det.getByLabel('Motivo · Fraldas M').selectOption('avaria');
  await expect(page.locator('#toast')).not.toBeVisible({ timeout: 15000 });
  await det.getByRole('button', { name: 'Aprovar com as diferenças' }).click();
  await expect(page.locator('.cc-rota', { hasText: '1001' })).toContainText('Aprovada');
  expect(pedidos[2].motivos.A1.motivo).toBe('avaria');
  expect(erros).toEqual([]);
});
