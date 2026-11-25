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

test('versão nova do painel no servidor: o ecrã recarrega-se sozinho (as actualizações chegam à TV sem tocar nela)', async ({ page }) => {
  let versao = 'v1';
  let pedidos = 0;
  await page.route('**/v1/sala', (r) => {
    pedidos++;
    return r.fulfill({ json: { ...SALA, versao } });
  });
  await page.clock.install({ time: new Date('2026-11-03T15:43:00+01:00') });
  await page.goto('/sala/index.html#t=' + 'a'.repeat(64));
  await expect(page.locator('#s-titulo')).toHaveText('Vendas de hoje');
  await page.evaluate(() => (window.__marca = 1));
  await page.clock.runFor(121000);
  await expect.poll(() => pedidos).toBe(2);
  expect(await page.evaluate(() => window.__marca)).toBe(1); // a mesma versão não recarrega
  versao = 'v2';
  const recarregou = page.waitForEvent('load');
  await page.clock.runFor(121000);
  await recarregou;
  expect(await page.evaluate(() => window.__marca)).toBeUndefined();
  await expect(page.locator('#s-titulo')).toBeVisible();
});

// ── ecrãs de gestão avançados ──
const HORAS = Array.from({ length: 15 }, (_, i) => String(6 + i).padStart(2, '0'));
const AVANCADOS = {
  acumulado: {
    horas: HORAS.map((h, i) => ({ h, hoje: i <= 9 ? i * 1500000 : null, habitual: i * 1400000, objectivo: i * 1600000, parte: i / 14 })),
    ref: { dias: 4, tipo: 'mesmo_dia' },
    total: 13500000,
    previsto: 22400000,
    parte_agora: 0.64,
    ritmo: -0.06,
    estimado: 21000000,
  },
  previsao: {
    dia: { actual: 13500000, estimado: 21000000, objectivo: 22400000, pelo_ritmo: true, minimo: 19000000, maximo: 24000000, progresso: 0.94 },
    mes: {
      ate_ontem: 400000000,
      hoje: 21000000,
      resto: 60000000,
      dias_resto: 4,
      estimado: 481000000,
      mes_anterior: 450000000,
      variacao: 0.069,
      ref: { de: '2026-10-01', ate: '2026-10-31' },
    },
  },
  calor: {
    de: '2026-10-06',
    ate: '2026-11-02',
    linhas: ['seg', 'ter', 'qua', 'qui', 'sex', 'sáb'].map((dia, k) => ({
      dia,
      v: HORAS.map((_, i) => Math.round(((i * 7 + k * 13) % 100) * (dia === 'sex' && i === 4 ? 0 : 1)) || (dia === 'sex' && i === 4 ? 100 : 5)),
    })),
    pico: { dia: 'sex', h: '10' },
    semanas: 4,
  },
  funil: {
    etapas: [
      { id: 'planeados', nome: 'Clientes no plano', n: 240 },
      { id: 'compraram', nome: 'Compraram', n: 150 },
      { id: 'prova', nome: 'Com prova de visita (GPS)', n: 120 },
      { id: 'acima', nome: 'Compra acima da média', n: 45 },
    ],
    fora_plano: 22,
  },
  recordes: {
    desde: '2026-09-01',
    dias: 55,
    melhor_dia: { d: '2026-10-17', t: 31000000 },
    melhor_igual: { d: '2026-10-20', t: 27000000 },
    ontem: { posicao: 3, de: 55 },
    melhor_mes: { m: '2026-10', t: 450000000 },
    meses: 2,
    mes_posicao: 1,
    hoje: { t: 13500000, face_recorde: 0.435, bate_recorde: false, bate_igual: false },
  },
  contagem: {
    dias_trabalho: 5,
    dias_calendario: 6,
    fim: '2026-11-30',
    mes_ate_hoje: 413500000,
    media_dia: 20000000,
    igualar: { alvo: 450000000, falta: 36500000, por_dia: 7300000, atingido: false, face_media: -0.635 },
    recorde: { alvo: 450000000, falta: 36500000, por_dia: 7300000, atingido: false, face_media: -0.635, m: '2026-10' },
  },
  artigos: {
    actual: { de: '2026-10-27', ate: '2026-11-02' },
    ref: { de: '2026-09-29', ate: '2026-10-26' },
    sobem: [
      { c: '10000333', n: 'SABÃO VERDE EM BARRA 8UN CONFIANCA 16×1.3kg', var: 0.42, t: 5870000, ref: 4100000 },
      { c: '10000015', n: 'FRALDAS DE BEBÉ MIMO MÉDIO 4 X 48', var: 0.18, t: 2100000, ref: 1800000 },
    ],
    descem: [{ c: '10000267', n: 'SABONETE CONFIANCA VERMELHO MÉDIO 72 × 90 g', var: -0.31, t: 900000, ref: 1300000 }],
  },
  pareto: {
    de: '2026-08-04',
    ate: '2026-11-02',
    clientes: 640,
    n80: 150,
    parte80: 0.234,
    curva: [10, 20, 30, 40, 50, 60, 70, 80, 90, 100].map((p) => ({ p, v: Math.min(1, 1 - Math.pow(1 - p / 100, 4)) })),
    parados: 12,
    parados_peso: 0.07,
  },
  quadrante: {
    de: '2026-10-06',
    rotas: ROTAS.map((r, i) => ({ rota: r.rota, promotor: r.promotor, compras_dia: 8 + i * 1.3, valor_medio: 200000 + ((i * 37) % 11) * 30000, dias: 24 })),
    mediana: { compras_dia: 14.5, valor_medio: 320000 },
  },
  stock: {
    artigos: 80,
    ruptura: 1,
    criticos: 3,
    dias_venda: 24,
    lista: [
      { c: '10000385', n: 'SABÃO CASTANHO COM PERFUME CONFIANCA EXTRA 20PC*850G', stock: 0, dia: 12.5, dias: 0 },
      { c: '10000015', n: 'FRALDAS DE BEBÉ MIMO MÉDIO 4 X 48', stock: 30, dia: 14, dias: 2.1 },
      { c: '10000333', n: 'SABÃO VERDE EM BARRA 8UN CONFIANCA 16×1.3kg', stock: 300, dia: 40, dias: 7.5 },
    ],
  },
  operacao: {
    linhas: [
      { id: 'caixa', nome: 'Fecho de caixa por aprovar', n: 1, atraso: 0 },
      { id: 'carrinha', nome: 'Conciliações das carrinhas por fechar', n: 6, atraso: 2 },
      { id: 'po', nome: 'PO por validar ou aprovar', n: 0, atraso: 0 },
      { id: 'cais', nome: 'Cargas por conferir e guias por gerar', n: 3, atraso: 0 },
      { id: 'sap', nome: 'Ficheiros SAP por confirmar', n: 0, atraso: 0 },
      { id: 'pinguim', nome: 'Dias por fechar no Pinguim BO', n: 1, atraso: 1 },
    ],
    total: 11,
    atraso: 3,
  },
  mapa: {
    rotas: ROTAS.slice(0, 6).map((r, i) => ({
      rota: r.rota,
      promotor: r.promotor,
      pos: [-8.83 - i * 0.02, 13.23 + i * 0.03],
      hora: '15:' + String(10 + i).padStart(2, '0'),
      percurso: [
        [-8.8, 13.2],
        [-8.81 - i * 0.01, 13.21 + i * 0.02],
        [-8.83 - i * 0.02, 13.23 + i * 0.03],
      ],
      vendas: 10 + i,
      prova: 8 + i,
      km: 20,
    })),
    territorios: {
      1001: [
        [-8.79, 13.19],
        [-8.79, 13.25],
        [-8.85, 13.25],
        [-8.85, 13.19],
      ],
    },
  },
  momentos: [{ id: '2026-11-03|rota-1004', tipo: 'rota', titulo: 'Rota 1004 atingiu o objectivo de hoje', rota: '1004', promotor: 'Nelson Bumba' }],
};
const ECRAS_AVANCADOS = [
  ['acumulado', 'Ritmo acumulado × objectivo'],
  ['previsao', 'Previsão de fecho'],
  ['calor', 'Mapa de calor das vendas'],
  ['funil', 'Funil das visitas'],
  ['mapa', 'Rotas no mapa'],
  ['pareto', 'Concentração dos clientes'],
  ['artigos', 'Artigos em destaque'],
  ['quadrante', 'Quadrante das rotas'],
  ['stock', 'Cobertura do stock'],
  ['operacao', 'Saúde da operação'],
  ['recordes', 'Recordes'],
  ['contagem', 'Contagem do mês'],
];

test('ecrãs de gestão avançados na sala interna: todos desenham, sem cortes, e o momento do dia aparece uma vez', async ({ page }) => {
  const erros = [];
  page.on('pageerror', (e) => erros.push(e.message));
  await page.route('**/v1/sala', (r) =>
    r.fulfill({
      json: { ...SALA, local: 'interna', config: { ecras: [...ECRAS_AVANCADOS.map((e) => e[0]), 'momentos'], segundos: 10 }, ...EXTRA, ...AVANCADOS },
    }),
  );
  await page.clock.install({ time: new Date('2026-11-03T15:43:00+01:00') });
  await page.goto('/sala/index.html#t=' + 'a'.repeat(64));
  for (let i = 0; i < ECRAS_AVANCADOS.length; i++) {
    await expect(page.locator('#s-titulo')).toHaveText(ECRAS_AVANCADOS[i][1]);
    await expect(page.locator('#s-ecra')).not.toContainText('ficará disponível');
    const r = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.scrollHeight]);
    expect(r, ECRAS_AVANCADOS[i][0]).toEqual([1920, 1080]);
    // nada do ecrã passa para o rodapé
    const fora = await page.evaluate(() => {
      const lim = document.querySelector('footer').getBoundingClientRect().top;
      return [...document.querySelectorAll('#s-ecra *')]
        .filter((e) => e.getBoundingClientRect().bottom > lim + 1)
        .map((e) => e.className.baseVal ?? e.className);
    });
    expect(fora, ECRAS_AVANCADOS[i][0]).toEqual([]);
    if (process.env.CAPTURAS) await page.screenshot({ path: 'test-results/sala-' + ECRAS_AVANCADOS[i][0] + '.png' });
    if (i === 0) {
      // o momento do dia entra a seguir ao primeiro ecrã, uma só vez
      await page.clock.runFor(10500);
      await expect(page.locator('#s-titulo')).toHaveText('Momento do dia');
      await expect(page.locator('#s-ecra')).toContainText('Rota 1004 atingiu o objectivo de hoje');
      await expect(page.locator('#s-ecra')).toContainText('Parabéns, Nelson Bumba!');
    }
    await page.clock.runFor(10500);
  }
  // o momento já foi mostrado: não volta a aparecer nas voltas seguintes
  for (let i = 0; i < 4; i++) {
    await expect(page.locator('#s-titulo')).not.toHaveText('Momento do dia');
    await page.clock.runFor(10000);
  }
  expect(erros).toEqual([]);
});

test('ecrãs de gestão avançados em open space: em %, índices e datas, sem Kz nem nomes', async ({ page }) => {
  const erros = [];
  page.on('pageerror', (e) => erros.push(e.message));
  const ab = {
    ok: true,
    local: 'aberta',
    config: { ecras: ['acumulado', 'previsao', 'calor', 'funil', 'recordes', 'contagem', 'momentos'], segundos: 10 },
    hora: '2026-11-03 15:42:10',
    hoje: { progresso: 0.6, documentos: 214 },
    ontem: {},
    semana: {},
    mes: {},
    rotas: [],
    acumulado: {
      horas: HORAS.map((h, i) => ({ h, hoje: i <= 9 ? i * 7 : null, habitual: i * 6, objectivo: Math.round((i * 100) / 14) })),
      ref: { dias: 4, tipo: 'mesmo_dia' },
      ritmo: 0.08,
      base: 'objectivo',
    },
    previsao: { dia: { progresso: 1.04, pelo_ritmo: true }, mes: { variacao: 0.07, dias_resto: 4, ref: { de: '2026-10-01', ate: '2026-10-31' } } },
    calor: AVANCADOS.calor,
    funil: AVANCADOS.funil,
    recordes: {
      desde: '2026-09-01',
      dias: 55,
      melhor_dia: { d: '2026-10-17' },
      melhor_igual: { d: '2026-10-20' },
      ontem: { posicao: 3, de: 55 },
      melhor_mes: { m: '2026-10' },
      meses: 2,
      mes_posicao: 1,
      hoje: { face_recorde: 0.43, bate_recorde: false, bate_igual: false },
    },
    contagem: {
      dias_trabalho: 5,
      dias_calendario: 6,
      fim: '2026-11-30',
      igualar: { atingido: false, face_media: 0.12 },
      recorde: { atingido: true, m: '2026-10' },
    },
    momentos: [{ id: '2026-11-03|obj-dia', tipo: 'objectivo_dia', titulo: 'Objectivo do dia atingido' }],
  };
  await page.route('**/v1/sala', (r) => r.fulfill({ json: ab }));
  await page.clock.install({ time: new Date('2026-11-03T15:43:00+01:00') });
  await page.goto('/sala/index.html#t=' + 'a'.repeat(64));
  await expect(page.locator('#s-titulo')).toHaveText('Ritmo acumulado × objectivo');
  await expect(page.locator('#s-ecra')).toContainText('▲ 8 %');
  await page.clock.runFor(10500);
  await expect(page.locator('#s-ecra')).toContainText('Parabéns, equipa!');
  for (const t of ['Previsão de fecho', 'Mapa de calor das vendas', 'Funil das visitas', 'Recordes', 'Contagem do mês']) {
    await page.clock.runFor(10500);
    await expect(page.locator('#s-titulo')).toHaveText(t);
    await expect(page.locator('body')).not.toContainText('Kz');
    const r = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.scrollHeight]);
    expect(r, t).toEqual([1920, 1080]);
  }
  await expect(page.locator('#s-ecra')).toContainText('✔ já conseguido');
  expect(erros).toEqual([]);
});
