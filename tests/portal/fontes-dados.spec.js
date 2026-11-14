// Camada de fontes: «Dados até hh:mm» com a fonte, aviso a âmbar com mais de 15 minutos, o ficheiro para o SAP só
// do Pinguim BO e a conferência OLAP × Pinguim BO na página Conformidade.
import { test, expect } from '@playwright/test';
import { abrir, vendas, artigos } from './dados.js';

const comCabecalhos = (ate, fonte, registar) => (r) => {
  if (registar) registar(r.request().url());
  return r.fulfill({ json: { vendas, artigos }, headers: { 'X-Dados-Ate': ate, 'X-Dados-Fonte': fonte } });
};

test('«Dados até» com a hora da fonte; aviso a âmbar quando os dados de hoje têm mais de 15 minutos', async ({ page }) => {
  let ate = '2026-10-26 11:55:00';
  await abrir(page, '#inicio', { periodo: null, api: (r) => comCabecalhos(ate, 'pinguim')(r) });
  const box = page.locator('#actual');
  await expect(box).toContainText('dados até 11:55');
  await expect(box).toContainText('Pinguim BO');
  await expect(box).not.toHaveClass(/atrasado/);
  ate = '2026-10-26 11:30:00';
  await page.reload();
  await page.waitForSelector('#page > *');
  await expect(box).toHaveClass(/atrasado/);
  await expect(box).toHaveAttribute('title', /mais de 15 minutos/);
});

test('período com o histórico do OLAP: diz a fonte; o ficheiro para o SAP lê de novo do Pinguim BO (fiscal)', async ({ page }) => {
  const urls = [];
  await abrir(page, '#inicio', {
    api: (r) => {
      const u = r.request().url();
      urls.push(u);
      return comCabecalhos('2026-10-26 11:58:00', u.includes('fiscal=1') ? 'pinguim' : 'misto')(r);
    },
  });
  await expect(page.locator('#actual')).toContainText('histórico do OLAP e hoje do Pinguim BO');
  expect(urls.some((u) => u.includes('fiscal=1'))).toBe(false);
  await page.evaluate(() => {
    location.hash = '#sap-exportar';
  });
  await expect.poll(() => urls.some((u) => u.includes('start_date') && u.includes('fiscal=1'))).toBe(true);
  await expect(page.locator('#actual')).not.toContainText('OLAP');
});

test('conformidade: conferência diária OLAP × Pinguim BO com o resultado de cada dia e as diferenças', async ({ page }) => {
  await page.route('**/v1/conformidade**', (r) =>
    r.fulfill({
      json: {
        ok: true,
        revisao_actual: '2026-T4',
        cifra: true,
        copia_externa: true,
        registos_externos: true,
        contas_dias: 90,
        estado: {},
        revisoes: [],
        copias: [],
        retencao: [],
        fontes: {
          vendas: 'paralelo',
          olap: true,
          contrato: '1.0',
          contrato_ok: true,
          contrato_faltam: [],
          disjuntor: null,
          conferido_ate: { dia: '2026-10-25', carga_fim: '2026-10-26 02:10:00' },
          dias: [
            { dia: '2026-10-25', conferido: true, em: '2026-10-26 06:01:00', carga_fim: '2026-10-26 02:10:00', documentos: 140, diferencas: [] },
            {
              dia: '2026-10-24',
              conferido: false,
              em: '2026-10-26 06:01:00',
              carga_fim: '2026-10-25 02:10:00',
              documentos: 133,
              diferencas: [{ rota: '1001', medida: 'total', pinguim: 1000, olap: 990 }],
            },
            { dia: '2026-10-23', conferido: null, diferencas: [] },
          ],
        },
      },
    }),
  );
  const erros = await abrir(page, '#conformidade/vigilancia');
  await expect(page.locator('.tile').filter({ hasText: 'Vendas: fonte do histórico' })).toContainText('Em conferência');
  await expect(page.locator('.tile').filter({ hasText: 'Dias conferidos (últimos 7)' }).locator('.v')).toHaveText('1 / 3');
  const tab = page.locator('#s-conf-fontes');
  await expect(tab.locator('tbody tr')).toHaveCount(3);
  await expect(tab.locator('tbody tr', { hasText: '24-10-2026' })).toContainText('Com diferenças');
  await expect(tab.locator('tbody tr', { hasText: '24-10-2026' })).toContainText('rota 1001: total com IVA Pinguim BO 1000 × OLAP 990');
  await expect(tab.locator('tbody tr').first()).toContainText('Conferido');
  expect(erros).toEqual([]);
});
