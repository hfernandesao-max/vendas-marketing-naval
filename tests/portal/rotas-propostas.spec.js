// Terreno › Rotas propostas: fila de aprovação das rotas novas (divisão de rota cheia e zona sem rota), impacto em
// visitas e carga, aprovar com o código da rota nova (atribui os clientes) e lista para registar no Pinguim BO.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const territorio = [
  [-8.85, 13.29],
  [-8.85, 13.31],
  [-8.83, 13.31],
  [-8.83, 13.29],
];
const base = () => ({
  ok: true,
  decide: true,
  estados: {},
  propostas: [
    {
      id: 'RP-0002',
      tipo: 'divisao',
      estado: 'pendente',
      estado_nome: 'Pendente de aprovação',
      origem_rota: '1001',
      motivo: 'Rota 1001 com 95% de ocupação há 14 dias seguidos (171 visitas por semana para 180).',
      territorio,
      centro: [-8.84, 13.3],
      clientes: [
        { c: '569716347978000001', n: 'Loja Bom Preço', rota: '1001', lat: -8.845, lng: 13.295 },
        { c: '569716347978000002', n: 'Cantina São José', rota: '1001', lat: -8.835, lng: 13.305 },
      ],
      potenciais: 0,
      impacto: {
        clientes: 2,
        visitas_semana: 4,
        capacidade_visitas: 180,
        ocupacao: 0.022,
        ocupacao_origem_antes: 0.95,
        ocupacao_origem_depois: 0.6,
        vendas_semana: 49000,
        kg_dia: null,
        capacidade_kg: 1500,
        carga: null,
        artigos_sem_peso: 7,
      },
      criada: '2026-10-26',
      decisao: null,
      historico: [],
    },
    {
      id: 'RP-0001',
      tipo: 'zona',
      estado: 'rejeitada',
      estado_nome: 'Rejeitada',
      origem_rota: '',
      motivo: '55 estabelecimentos comerciais numa zona sem rota.',
      territorio,
      centro: [-8.7, 13.4],
      clientes: [],
      potenciais: 55,
      impacto: {
        clientes: 0,
        visitas_semana: 55,
        capacidade_visitas: 180,
        ocupacao: 0.306,
        vendas_semana: null,
        kg_dia: null,
        capacidade_kg: 1500,
        carga: null,
      },
      criada: '2026-10-20',
      decisao: { accao: 'rejeitar', por: 'dir', nome: 'Direcção', em: '2026-10-21 09:00:00', nota: 'Sem viatura disponível' },
      historico: [],
    },
  ],
});

test('rotas propostas: impacto, aprovar com o código da rota nova e lista para registar no Pinguim BO', async ({ page }) => {
  let estado = base();
  const pedidos = [];
  await page.route('**/v1/rotas-propostas**', (r) => {
    if (r.request().method() === 'POST') {
      const b = JSON.parse(r.request().postData());
      pedidos.push(b);
      if (b.accao === 'aprovar' && !b.rota_nova)
        return r.fulfill({ json: { ok: false, erro: 'Indique o código da rota nova (letras, algarismos, «-» ou «_»).' } });
      const p = estado.propostas[0];
      Object.assign(p, {
        estado: 'aprovada',
        estado_nome: 'Aprovada: por registar no Pinguim BO',
        decisao: {
          accao: 'aprovar',
          por: 'dir',
          nome: 'Direcção',
          em: '2026-10-26 10:00:00',
          rota_nova: b.rota_nova,
          promotor: b.promotor,
          atribuidos: p.clientes.map((c) => c.c),
          conflitos: [],
        },
      });
      return r.fulfill({ json: { ok: true, id: p.id, estado: 'aprovada', rota_nova: b.rota_nova, atribuidos: 2, conflitos: [] } });
    }
    return r.fulfill({ json: estado });
  });
  const erros = await abrir(page, '#rotas-propostas');
  const pend = page.locator('.rp-proposta[data-id="RP-0002"]');
  await expect(pend).toBeVisible();
  await expect(page.locator('.rp-proposta')).toHaveCount(1); // filtro: só as pendentes
  await expect(pend).toContainText('Dividir uma rota cheia');
  await expect(pend).toContainText('95,0% → 60,0%');
  await expect(pend).toContainText('por saber: os artigos não têm peso no Pinguim BO');
  await expect(pend.locator('svg.rp-mapa circle')).toHaveCount(2);
  await expect(page.locator('.tile').filter({ hasText: 'Pendentes de aprovação' }).locator('.v')).toHaveText('1');

  // sem código da rota nova: o servidor recusa e a mensagem aparece
  await pend.getByRole('button', { name: 'Aprovar e atribuir os clientes' }).click();
  await expect(pend.locator('.erro')).toContainText('Indique o código da rota nova');
  await pend.getByLabel('Código da rota nova · RP-0002').fill('1015');
  await pend.getByLabel('Promotor da rota nova · RP-0002').fill('Novo Promotor');
  await pend.getByRole('button', { name: 'Aprovar e atribuir os clientes' }).click();
  await expect(page.locator('#toast')).toContainText('Rota 1015 aprovada: 2 clientes atribuídos no portal.');
  expect(pedidos.at(-1)).toMatchObject({ accao: 'aprovar', id: 'RP-0002', rota_nova: '1015', promotor: 'Novo Promotor' });

  // aprovada: sai da lista das pendentes e aparece em «Aprovadas» com a lista para o Pinguim BO
  await expect(page.locator('.rp-proposta')).toHaveCount(0);
  await page.getByRole('tab', { name: 'Aprovadas' }).click();
  const apr = page.locator('.rp-proposta[data-id="RP-0002"]');
  await expect(apr).toContainText('Aprovada por Direcção');
  await expect(apr.locator('.rp-lista tbody tr')).toHaveCount(2);
  await expect(apr.locator('.rp-lista tbody tr').first()).toContainText('1015');
  const [desc] = await Promise.all([page.waitForEvent('download'), apr.getByRole('button', { name: 'Descarregar a lista (CSV)' }).click()]);
  expect(desc.suggestedFilename()).toBe('RP-0002_rota-1015_clientes.csv');
  // todas: também a rejeitada, com o motivo
  await page.getByRole('tab', { name: 'Todas' }).click();
  await expect(page.locator('.rp-proposta[data-id="RP-0001"]')).toContainText('Sem viatura disponível');
  expect(erros).toEqual([]);
});

test('rotas propostas: quem só vê não decide; sem propostas, explica porquê', async ({ page }) => {
  const so = base();
  so.decide = false;
  await page.route('**/v1/rotas-propostas**', (r) => r.fulfill({ json: so }));
  const erros = await abrir(page, '#rotas-propostas');
  const pend = page.locator('.rp-proposta[data-id="RP-0002"]');
  await expect(pend).toContainText('Aguarda a decisão');
  await expect(pend.getByRole('button', { name: 'Aprovar e atribuir os clientes' })).toHaveCount(0);
  expect(erros).toEqual([]);
});

test('rotas propostas: sem propostas pendentes', async ({ page }) => {
  await page.route('**/v1/rotas-propostas**', (r) => r.fulfill({ json: { ok: true, decide: true, estados: {}, propostas: [], pendentes: 0 } }));
  const erros = await abrir(page, '#rotas-propostas');
  await expect(page.locator('#page')).toContainText('Sem propostas pendentes');
  expect(erros).toEqual([]);
});
