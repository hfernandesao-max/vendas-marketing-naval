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

test('ecrã em open space: só percentagens, tendências e contagens — sem Kz, sem nomes e sem classificações', async ({ page }) => {
  const ABERTA = {
    ok: true,
    local: 'aberta',
    config: { ecras: ['hoje', 'destaques'], segundos: 10 },
    hora: '2026-11-03 15:42:10',
    hoje: { progresso: 0.87, documentos: 214 },
    ontem: { variacao: 0.12 },
    semana: { variacao: -0.04 },
    mes: { variacao: 0.21 },
    compras: { hoje: { compras: 180 } },
    rotas: [
      { rota: '1004', progresso_hoje: 1.05, variacao_mes: 0.23, sequencia: 4, ontem_acima: true },
      { rota: '1007', progresso_hoje: 0.4, variacao_mes: -0.1, sequencia: 0, ontem_acima: false },
    ],
  };
  await page.route('**/v1/sala', (r) => r.fulfill({ json: ABERTA }));
  await page.clock.install({ time: new Date('2026-11-03T15:43:00+01:00') });
  await page.goto('/sala/index.html#t=' + 'a'.repeat(64));
  await expect(page.locator('#s-titulo')).toHaveText('Vendas de hoje');
  await expect(page.locator('.s-gigante')).toHaveText('87 %');
  await expect(page.locator('#s-ecra')).toContainText('214');
  await expect(page.locator('#s-ecra')).toContainText('▲ +21 %');
  await expect(page.locator('body')).not.toContainText('Kz');
  await expect(page.locator('#s-seguinte')).toHaveText('A seguir: Destaques');
  await page.clock.runFor(11000);
  await expect(page.locator('#s-titulo')).toHaveText('Destaques');
  await expect(page.locator('#s-ecra')).toContainText('Rota 1004');
  // reconhecimento positivo: a rota abaixo do objectivo nunca aparece
  await expect(page.locator('#s-ecra')).not.toContainText('1007');
  await expect(page.locator('body')).not.toContainText('Kz');
});

test('portal: ligação do ecrã com o local, o IP do último acesso e «Fixar a este IP»; guia do que pode estar à vista', async ({ page }) => {
  const { abrir } = await import('./dados.js');
  const pedidos = [];
  let fixo = null;
  const lig = () => ({
    ok: true,
    ligacoes: [
      {
        id: 'a1',
        nome: 'Sala comercial · LCD',
        local: 'interna',
        criado: '2026-11-25 10:00:00',
        ultimo_acesso: '2026-11-25 11:00:00',
        ip: '192.168.41.103',
        ...(fixo ? { ip_fixo: fixo } : {}),
        revogado: null,
      },
    ],
  });
  await page.route('**/v1/sala/ligacoes**', (r) => {
    if (r.request().method() === 'POST') {
      const b = JSON.parse(r.request().postData());
      pedidos.push(b);
      fixo = b.accao === 'fixar_ip' ? '192.168.41.103' : null;
    }
    return r.fulfill({ json: lig() });
  });
  await page.setViewportSize({ width: 1366, height: 768 });
  const erros = await abrir(page, '#painel-sala');
  const l = page.locator('.gst-lig').first();
  await expect(l).toContainText('Sala interna');
  await expect(l).toContainText('último acesso do IP 192.168.41.103');
  await l.getByRole('button', { name: 'Fixar a este IP' }).click();
  await expect(page.locator('.gst-lig').first()).toContainText('presa ao IP 192.168.41.103');
  expect(pedidos[0]).toMatchObject({ accao: 'fixar_ip', id: 'a1' });
  await expect(page.locator('#page')).toContainText('O que pode estar à vista em cada local');
  expect(erros).toEqual([]);
});

const EXTRA = {
  serie: Array.from({ length: 14 }, (_, i) => ({ d: '2026-10-' + String(10 + i).padStart(2, '0'), t: i === 13 ? 9000000 : 6000000 + i * 100000 })),
  horas: Array.from({ length: 15 }, (_, i) => ({ h: String(6 + i).padStart(2, '0'), n: i < 10 ? i * 2 : 0 })),
  visitas: {
    planeados: 120,
    compraram: 80,
    fora_plano: 15,
    conversao: 0.667,
    melhores: [{ rota: '1004', promotor: 'Nelson Bumba', planeados: 12, compraram: 11, conversao: 0.917 }],
  },
  terreno: { rotas: 11, a_vender: 9, objectivo: [{ rota: '1004', promotor: 'Nelson Bumba', progresso: 1.2 }] },
  clientes: { hoje: 68, mes: 900, mes_anterior: 800, valor_medio_mes: 300000 },
};

test('ecrãs novos na sala interna: ritmo por hora, 14 dias em Kz, visitas com as melhores rotas, rotas no terreno e clientes', async ({ page }) => {
  await page.route('**/v1/sala', (r) =>
    r.fulfill({ json: { ...SALA, local: 'interna', config: { ecras: ['ritmo', 'tendencia', 'visitas', 'rotas', 'clientes'], segundos: 10 }, ...EXTRA } }),
  );
  await page.clock.install({ time: new Date('2026-11-03T15:43:00+01:00') });
  await page.goto('/sala/index.html#t=' + 'a'.repeat(64));
  const ecra = page.locator('#s-ecra');
  await expect(page.locator('#s-titulo')).toHaveText('Ritmo do dia');
  await expect(page.locator('.s-graf-col')).toHaveCount(15);
  await expect(page.locator('.s-graf-col.destaque')).toHaveCount(1); // a hora actual (15h)
  await expect(ecra).toContainText('Hora de maior movimento');
  await page.clock.runFor(11000);
  await expect(page.locator('#s-titulo')).toHaveText('Últimos 14 dias');
  await expect(page.locator('.s-graf-col')).toHaveCount(14);
  await expect(page.locator('.s-graf-col.destaque .s-graf-v')).toHaveText('9,00 M');
  await page.clock.runFor(10000);
  await expect(page.locator('#s-titulo')).toHaveText('Visitas de hoje');
  await expect(page.locator('.s-gigante')).toHaveText('67 %');
  await expect(ecra).toContainText('Nelson Bumba');
  await page.clock.runFor(10000);
  await expect(ecra).toContainText('9 / 11');
  await expect(page.locator('.s-chip')).toHaveText('✔ 1004 · Nelson');
  await page.clock.runFor(10000);
  await expect(ecra).toContainText('Valor médio por compra');
  // nada fora do ecrã
  const r = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.scrollHeight]);
  expect(r).toEqual([1920, 1080]);
});

test('ecrãs novos em open space: 14 dias em índice (100 = média), visitas sem rotas, objectivo só com o número da rota', async ({ page }) => {
  const ABERTA = {
    ok: true,
    local: 'aberta',
    config: { ecras: ['tendencia', 'visitas', 'rotas', 'hoje'], segundos: 10 },
    hora: '2026-11-03 15:42:10',
    hoje: { progresso: 4.5, documentos: 214 },
    ontem: { variacao: 36.46 },
    semana: { variacao: 0.12 },
    mes: { variacao: 0.2 },
    compras: { hoje: { compras: 180 } },
    rotas: [],
    serie: EXTRA.serie.map((x, i) => ({ d: x.d, indice: i === 13 ? 140 : 95 })),
    horas: EXTRA.horas,
    visitas: { planeados: 120, compraram: 80, fora_plano: 15, conversao: 0.667 },
    terreno: { rotas: 11, a_vender: 9, objectivo: [{ rota: '1004' }] },
    clientes: { hoje: 68, mes: 900, mes_anterior: 800 },
  };
  await page.route('**/v1/sala', (r) => r.fulfill({ json: ABERTA }));
  await page.clock.install({ time: new Date('2026-11-03T15:43:00+01:00') });
  await page.goto('/sala/index.html#t=' + 'a'.repeat(64));
  await expect(page.locator('#s-titulo')).toHaveText('Últimos 14 dias');
  await expect(page.locator('#s-ecra')).toContainText('100 = média');
  await expect(page.locator('.s-graf-col.destaque .s-graf-v')).toHaveText('140');
  await page.clock.runFor(11000);
  await expect(page.locator('#s-titulo')).toHaveText('Visitas de hoje');
  await expect(page.locator('.s-mini')).toHaveCount(0);
  await page.clock.runFor(10000);
  await expect(page.locator('.s-chip')).toHaveText('✔ 1004');
  await page.clock.runFor(10000);
  // referência muito pequena: sem percentagens absurdas
  await expect(page.locator('.s-gigante')).toHaveText('> 300 %');
  await expect(page.locator('#s-ecra')).toContainText('▲ muito acima');
  await expect(page.locator('body')).not.toContainText('Kz');
});

test('portal: pré-visualização ao vivo do painel (o ecrã da TV com os dados da sessão), sala interna e open space', async ({ page }) => {
  const { abrir } = await import('./dados.js');
  const pedidos = [];
  await page.route('**/v1/sala/ligacoes**', (r) => r.fulfill({ json: { ok: true, ligacoes: [] } }));
  await page.route('**/v1/sala/previa**', (r) => {
    const local = new URL(r.request().url()).searchParams.get('local');
    pedidos.push(local);
    return r.fulfill({
      json:
        local === 'aberta'
          ? { ...SALA, local: 'aberta', rotas: [], hoje: { progresso: 0.8, documentos: 10 }, config: { ecras: ['hoje'], segundos: 20 } }
          : { ...SALA, local: 'interna', config: { ecras: ['hoje'], segundos: 20 } },
    });
  });
  await page.setViewportSize({ width: 1366, height: 900 });
  const erros = await abrir(page, '#painel-sala');
  await page.getByRole('link', { name: 'Pré-visualização ao vivo' }).first().click();
  const f = page.frameLocator('.sala-previa iframe');
  await expect(f.locator('#s-titulo')).toHaveText('Vendas de hoje');
  await expect(f.locator('.s-gigante')).toContainText('Kz');
  await page.locator('.sala-previa-local button', { hasText: 'Open space' }).click();
  await expect(f.locator('.s-gigante')).toHaveText('80 %');
  expect(pedidos).toContain('aberta');
  expect(erros).toEqual([]);
});

test('comparações: cada comparativo com as datas dos dois períodos; sem histórico diz desde quando (sem % absurdas); Vendas de hoje com as datas', async ({
  page,
}) => {
  const L = (id, nome, a, r, t, rt, v, motivo) => ({
    id,
    nome,
    actual: { de: a[0], ate: a[1], t },
    ref: r ? { de: r[0], ate: r[1], t: rt } : null,
    variacao: v,
    motivo: motivo || null,
  });
  const comparacoes = {
    inicio: '2026-11-03',
    linhas: [
      L('ontem_semana', 'Ontem × mesmo dia, semana passada', ['2026-11-24', '2026-11-24'], ['2026-11-17', '2026-11-17'], 9e6, 7.5e6, 0.2),
      L('semana', 'Semana até ontem × semana passada', ['2026-11-23', '2026-11-24'], ['2026-11-16', '2026-11-17'], 17e6, 15.7e6, 0.083),
      L('mes', 'Mês até ontem × mês anterior', ['2026-11-01', '2026-11-24'], null, 150e6, null, null, 'sem histórico · dados desde 03-11-2026'),
    ],
  };
  await page.route('**/v1/sala', (r) =>
    r.fulfill({ json: { ...SALA, local: 'interna', config: { ecras: ['comparacoes', 'hoje'], segundos: 10 }, comparacoes } }),
  );
  await page.clock.install({ time: new Date('2026-11-25T15:43:00+01:00') });
  await page.goto('/sala/index.html#t=' + 'a'.repeat(64));
  await expect(page.locator('#s-titulo')).toHaveText('Comparações');
  await expect(page.locator('.s-comp-l')).toHaveCount(3);
  await expect(page.locator('.s-comp-l').nth(0)).toContainText('ter 24 nov × ter 17 nov');
  await expect(page.locator('.s-comp-l').nth(0)).toContainText('▲ +20 %');
  await expect(page.locator('.s-comp-l').nth(1)).toContainText('23–24 nov × 16–17 nov');
  await expect(page.locator('.s-comp-l.sem')).toContainText('sem histórico · desde 03-11');
  await page.clock.runFor(11000);
  await expect(page.locator('#s-titulo')).toHaveText('Vendas de hoje');
  await expect(page.locator('.s-coluna')).toContainText('Semana até ontem');
  await expect(page.locator('.s-coluna')).toContainText('23–24 nov × 16–17 nov');
  await expect(page.locator('.s-coluna')).toContainText('sem histórico · desde 03-11');
});
