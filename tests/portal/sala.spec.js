// Painel da sala comercial (ecrã de TV): legível à distância, quatro ecrãs, frescura dos dados e sem cortes.
import { test, expect } from '@playwright/test';

const ROTAS = Array.from({ length: 11 }, (_, i) => ({
  rota: String(1001 + i),
  promotor: [
    'Andrea Santos',
    'Cristina Sachuma',
    'Joceil Prata',
    'Nelson Bumba',
    'Olindo Baião',
    'Rosa Vemba',
    'Awet Weldemenkorios',
    'Daniel Mihreteab',
    'Efrem Bokre',
    'Teresa Lemos',
    'Robel Tesfamichael',
  ][i],
  hoje: (11 - i) * 310000,
  previsto_hoje: 2400000,
  ontem: 1500000 + i * 1000,
  habitual_ontem: 1400000,
  mes: (11 - i) * 2900000,
  mes_anterior: 20000000,
  variacao_mes: i % 3 === 0 ? 0.18 - i / 100 : -0.05,
  sequencia: i === 2 ? 4 : 0,
}));
const SALA = {
  ok: true,
  config: { ecras: ['hoje', 'rank-hoje', 'rank-mes', 'destaques'], segundos: 20 },
  hora: '2026-11-03 15:42:10',
  hoje: { total: 20460000, documentos: 74, previsto: 26400000 },
  ontem: { total: 18200000, variacao: 0.12 },
  semana: { total: 38660000, variacao: -0.04 },
  mes: { total: 30539500, variacao: 0.21 },
  compras: { hoje: { compras: 68, valor_medio: 300882, artigos_por_compra: 2.4 } },
  rotas: ROTAS,
};

test.use({ viewport: { width: 1920, height: 1080 } });

test('ecrã de TV: quatro ecrãs legíveis a 5 m, sem cortes, com frescura e ecrã seguinte', async ({ page }) => {
  await page.route('**/v1/sala', (r) => r.fulfill({ json: SALA }));
  await page.clock.install({ time: new Date('2026-11-03T15:43:00+01:00') });
  await page.goto('/sala/index.html#t=' + 'a'.repeat(64));
  const titulos = ['Vendas de hoje', 'Classificação de hoje', 'Classificação do mês', 'Destaques'];
  for (let i = 0; i < titulos.length; i++) {
    await expect(page.locator('#s-titulo')).toHaveText(titulos[i]);
    await expect(page.locator('#s-seguinte')).toHaveText('A seguir: ' + titulos[(i + 1) % 4]);
    await expect(page.locator('#s-act')).toContainText('actualizado às 15:42');
    // texto visível nunca abaixo de 26 px num ecrã 1080p, e nada fora do ecrã
    const r = await page.evaluate(() => {
      let min = 99;
      for (const e of document.querySelectorAll('body *')) {
        if (!e.childNodes.length || ![...e.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim())) continue;
        const b = e.getBoundingClientRect();
        if (!b.width) continue;
        min = Math.min(min, parseFloat(getComputedStyle(e).fontSize));
      }
      return { min, sw: document.documentElement.scrollWidth, sh: document.documentElement.scrollHeight };
    });
    expect(r.min).toBeGreaterThanOrEqual(26);
    expect(r.sw).toBeLessThanOrEqual(1920);
    expect(r.sh).toBeLessThanOrEqual(1080);
    await page.clock.runFor(20000);
  }
  // ecrã 1: valor do dia por extenso, objectivo com percentagem, variações com símbolo e palavra
  await expect(page.locator('#s-titulo')).toHaveText('Vendas de hoje');
  await expect(page.locator('.s-gigante')).toHaveText(/20\s460\s000 Kz/);
  await expect(page.locator('.s-meta')).toContainText('78 %');
  await expect(page.locator('.s-delta.desce').first()).toContainText('▼ −4 %');
  // classificação: no máximo 8 linhas; pódio marcado com número (não só cor)
  await page.clock.runFor(20000);
  await expect(page.locator('.s-linha')).toHaveCount(8);
  await expect(page.locator('.s-pos.podio')).toHaveText(['1', '2', '3']);
  // dados parados há mais de 10 minutos: aviso no rodapé
  await page.route('**/v1/sala', (r) => r.abort());
  await page.clock.runFor(11 * 60000);
  await expect(page.locator('#s-act')).toHaveClass('velho');
  await expect(page.locator('#s-act')).toContainText('Sem dados novos desde as 15:42');
});
