// Valor do portal: totais, parcelas por definir, tabela por mês e edição dos parâmetros pela Direcção.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const NOMES = {
  custo_hora_kz: 'Custo de uma hora de trabalho (Kz)',
  min_fecho: 'Minutos poupados por rota fechada automaticamente',
  min_conciliacao: 'Minutos poupados por rota conciliada no portal (em vez de papel ou Excel)',
  min_sap: 'Minutos poupados por ficheiro SAP gerado',
  min_relatorio: 'Minutos poupados por relatório exportado',
  perda_referencia_kz: 'Perda média de stock por rota e por mês antes do portal (Kz)',
  licenca_utilizador_kz: 'Licença de uma solução comercial equivalente, por utilizador e por mês (Kz)',
  reconstrucao_kz: 'Custo de fazer o portal de novo com uma empresa de software (Kz)',
  anos_amortizacao: 'Anos de amortização do custo de reconstrução',
  cambio_eur_kz: 'Câmbio: Kz por 1 euro (para mostrar também em euros)',
};
const mes = (m, total) => ({
  mes: m,
  rotas: 10,
  conciliadas: 40,
  automaticas: 30,
  falta_kz: 20000,
  sobra_kz: 0,
  ficheiros_sap: 2,
  exportacoes: 12,
  utilizadores: 25,
  anulados: null,
  anulados_kz: null,
  horas: 27,
  tempo_kz: total ? 81000 : null,
  stock_kz: null,
  licencas_kz: null,
  construcao_kz: null,
  total_kz: total ? 81000 : null,
});
const vista = (custo) => ({
  ok: true,
  gere: true,
  meses: ['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'].map((m) => mes(m, custo > 0)),
  totais: {
    horas: 162,
    tempo_kz: custo ? 486000 : null,
    total_kz: custo ? 486000 : null,
    anual_kz: custo ? 972000 : null,
    falta_kz: 120000,
    conciliadas: 240,
    automaticas: 180,
    ficheiros_sap: 12,
    exportacoes: 72,
    anulados: null,
    anulados_kz: null,
  },
  meses_com_dados: 6,
  parametros: {
    custo_hora_kz: custo,
    min_fecho: 20,
    min_conciliacao: 15,
    min_sap: 120,
    min_relatorio: 15,
    perda_referencia_kz: 0,
    licenca_utilizador_kz: 0,
    reconstrucao_kz: 0,
    anos_amortizacao: 5,
    cambio_eur_kz: 0,
  },
  nomes: NOMES,
  por_definir: custo
    ? ['perda_referencia_kz', 'licenca_utilizador_kz', 'reconstrucao_kz']
    : ['custo_hora_kz', 'perda_referencia_kz', 'licenca_utilizador_kz', 'reconstrucao_kz'],
  pinguim: false,
  alterado_em: custo ? '2026-11-09 08:00:00' : null,
  alterado_por: custo ? 'dir' : null,
});

test('valor do portal: por definir, horas reais, e a Direcção grava o custo da hora', async ({ page }) => {
  let pedido = null;
  await page.route('**/v1/valor**', (r) => {
    if (r.request().method() === 'POST') {
      pedido = r.request().postDataJSON();
      return r.fulfill({ json: vista(3000) });
    }
    return r.fulfill({ json: vista(0) });
  });
  const erros = await abrir(page, '#valor-portal');
  const tile = (txt) => page.locator('.tile').filter({ hasText: txt }).locator('.v');
  await expect(tile('Valor para a Naval (12 meses)')).toHaveText('por definir');
  await expect(tile('Horas de trabalho libertadas')).toContainText('162');
  await expect(page.locator('#page')).toContainText('Por definir pela Direcção');
  await expect(page.locator('#s-valor-meses tbody tr')).toHaveCount(6);
  await page.locator('button', { hasText: 'Parâmetros do cálculo' }).click();
  const custo = page.locator('dialog.dlg label', { hasText: 'Custo de uma hora de trabalho' }).locator('input');
  await custo.fill('3000');
  await page.locator('dialog.dlg button', { hasText: 'Gravar' }).click();
  await expect.poll(() => pedido && pedido.parametros.custo_hora_kz).toBe(3000);
  expect(pedido.accao).toBe('parametros');
  await expect(tile('Valor para a Naval (12 meses)')).toContainText('486');
  await expect(page.locator('#page')).toContainText('Parâmetros alterados em 09-11-2026 por dir');
  expect(erros).toEqual([]);
});
