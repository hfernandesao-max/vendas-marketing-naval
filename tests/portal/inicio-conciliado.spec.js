// Início: os quadros mostram o Pinguim BO sem nenhuma alteração; o quadro «Formas de pagamento conciliadas» mostra o
// Pinguim BO, o que se recebeu de facto (correcções aprovadas) e a diferença.
import { test, expect } from '@playwright/test';
import { abrir, vendas, artigos } from './dados.js';

test('Início: formas de pagamento conciliadas — Pinguim BO na venda × conciliado (aprovado) × diferença', async ({ page }) => {
  const f = vendas.find((v) => !v.nc && (v.pagamentos || []).length === 1 && v.pagamentos[0].codigo === 'MC');
  const total = +f.pagamentos[0].valor;
  const nu = Math.round(total * 0.6);
  // o documento vem como está no Pinguim BO; a correcção aprovada segue à parte
  const corrigida = { ...f, conciliacao: { id: 'FP-2026-00045', numerario: nu, multicaixa: total - nu, codigo_multicaixa: 'MC' } };
  const lista = vendas.map((v) => (v.documento === f.documento ? corrigida : v));
  const erros = await abrir(page, '#inicio', { api: (r) => r.fulfill({ json: { vendas: lista, artigos } }) });
  const q = page.locator('.ini-conc');
  await expect(q).toContainText('Formas de pagamento conciliadas');
  // os quadros de cima ficam com o Pinguim BO: o documento corrigido continua todo em Multicaixa
  await expect(page.locator('.tiles-kpi')).toBeVisible();
  await expect(q).toContainText('1 documento(s) com a forma de pagamento corrigida e aprovada');
  const linha = (rot) => q.locator('tr', { hasText: rot }).locator('td');
  await expect(linha('Numerário').nth(1)).toHaveText(/^0,00/);
  await expect(linha('Numerário').nth(2)).not.toHaveText(/^0,00/);
  await expect(linha('Numerário').nth(3)).toHaveText(/^\+/);
  await expect(linha('Multicaixa').nth(3)).toHaveText(/^-/);
  // o total não muda: só a forma
  await expect(q.locator('tr.ini-conc-tot td').nth(3)).toContainText('0,00');
  expect(erros).toEqual([]);
});
