// Excel completo: nome «RTM - Controlo de Gestão Consolidado-DD-MM-AAAA» com a data do dia em que é retirado.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

test('o Excel completo tem o nome do controlo de gestão consolidado e a data do dia', async ({ page }) => {
  await abrir(page, '#vendas-dia');
  const [d] = await Promise.all([page.waitForEvent('download', { timeout: 30_000 }), page.click('#xl-all')]);
  const x = new Date();
  const dia = `${String(x.getDate()).padStart(2, '0')}-${String(x.getMonth() + 1).padStart(2, '0')}-${x.getFullYear()}`;
  expect(d.suggestedFilename()).toBe(`RTM - Controlo de Gestão Consolidado-${dia}.xlsx`);
});

test('recebimentos usam «meio de recebimento» e mostram a taxa de cobrança', async ({ page }) => {
  await abrir(page, '#pagamentos');
  await expect(page.locator('#nav a[href="#pagamentos"]').first()).toContainText('Recebimentos por meio');
  await expect(page.locator('.tile', { hasText: 'Valor recebido' })).toContainText('Taxa de cobrança');
  await expect(page.locator('body')).not.toContainText('Meio de pagamento');
});
