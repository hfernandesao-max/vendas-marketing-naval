// Plano de cargas produzido na plataforma: exportar Excel (rascunho identificado ou versão publicada),
// publicar com ou sem envio por email e enviar por email aos destinatários escolhidos.
import { test, expect } from '@playwright/test';
import { createRequire } from 'node:module';
import { abrir } from './dados.js';

const require = createRequire(import.meta.url);
const ExcelJS = require('exceljs');

const ROTA = {
  rota: '1001',
  vendedor: 'Ana',
  sai: true,
  armazem: 'HIGITEC',
  viagens: 1,
  zonas: ['Samba'],
  quantidades: { fraldas: 90, sabao: 10 },
  motorista: 'Adão Paulo',
  telefone: '924516916',
  matricula: 'LD-58-73-GY',
  viatura: 'Canter',
  transportadora: 'Velotrans',
  factura: 'FT 1',
  obs: '',
};
const resposta = (plano) => ({
  data: '2026-10-26',
  plano,
  anterior: null,
  rotas: [{ rota: '1001', promotor: 'Ana' }],
  listas: {
    artigos: [
      { id: 'fraldas', nome: 'Fraldas' },
      { id: 'sabao', nome: 'Sabão' },
    ],
    destinatarios: [
      { nome: 'Transportes', email: 'transportes@naval.co.ao' },
      { nome: 'Armazém', email: 'armazem@naval.co.ao' },
    ],
    motoristas: [{ nome: 'Adão Paulo' }],
    viaturas: ['Canter'],
    transportadoras: ['Velotrans'],
    armazens: ['HIGITEC'],
    zonas: ['Samba'],
  },
  gere: true,
  exportar: true,
  email_configurado: true,
  ligacao: '',
});
const RASCUNHO = { data: '2026-10-26', rev: 3, rotas: [ROTA], publicado: null, envios: [], alterado: '2026-10-26 07:50:00' };
const PUBLICADO = {
  ...RASCUNHO,
  publicado: { versao: 1, rev: 3, em: '2026-10-26 08:00:00', por: 'log', por_nome: 'Logística', nota: '', rotas: [ROTA] },
};

async function abrirPlano(page, plano, pedidos, extra = {}) {
  await page.route('**/v1/plano**', (r) => {
    const q = r.request();
    if (q.method() === 'POST') {
      const corpo = q.postDataJSON();
      pedidos.push(corpo);
      if (corpo.accao === 'publicar') return r.fulfill({ json: { ok: true, plano: PUBLICADO, email: corpo.enviar ? { ok: true, para: corpo.para } : null } });
      if (corpo.accao === 'enviar') return r.fulfill({ json: { ok: true, plano: PUBLICADO, email: { ok: true, para: corpo.para } } });
      if (corpo.accao === 'listas') return r.fulfill({ json: { ok: true, listas: { ...resposta(plano).listas, ...(extra.listas || {}), ...corpo.listas } } });
      return r.fulfill({ json: { ok: true, plano } });
    }
    if (q.url().includes('historico')) return r.fulfill({ json: { dias: [] } });
    return r.fulfill({ json: { ...resposta(plano), ...extra } });
  });
  await page.route('**/v1/sessao*', (r) => r.fulfill({ json: { csrf: 'x' } }));
  return abrir(page, '#plano-cargas');
}

test('rascunho: exportar Excel identificado como não publicado; publicar sem enviar por email', async ({ page }) => {
  const pedidos = [];
  const erros = await abrirPlano(page, RASCUNHO, pedidos);
  await expect(page.locator('.pc-dcampo .dv')).toHaveText('26-10-2026');
  await expect(page.locator('#pc-enviar')).toHaveCount(0);
  const [d] = await Promise.all([page.waitForEvent('download'), page.click('#pc-excel')]);
  expect(d.suggestedFilename()).toBe('PLANO_DE_CARGAS_26.10.2026_RASCUNHO.xlsx');
  const wb = new ExcelJS.Workbook();
  await wb.xlsx.readFile(await d.path());
  expect(String(wb.worksheets[0].getCell('A1').value)).toContain('RASCUNHO — NÃO PUBLICADO');
  await expect.poll(() => pedidos.some((p) => p.accao === 'exportado')).toBe(true);

  await page.click('#pc-publicar');
  const dlg = page.locator('dialog[open]');
  await expect(dlg.getByRole('group', { name: 'Destinatários' })).toBeVisible();
  await dlg.getByText('Enviar por correio electrónico agora').click();
  await expect(dlg.getByRole('group', { name: 'Destinatários' })).toBeHidden();
  await dlg.getByRole('button', { name: 'Publicar sem enviar' }).click();
  await expect.poll(() => pedidos.find((p) => p.accao === 'publicar')).toMatchObject({ enviar: false, para: [] });
  expect(erros).toEqual([]);
});

test('publicado: enviar por email só aos destinatários escolhidos', async ({ page }) => {
  const pedidos = [];
  await abrirPlano(page, PUBLICADO, pedidos);
  await page.click('#pc-enviar');
  const dlg = page.locator('dialog[open]');
  await dlg.getByLabel('Transportes · transportes@naval.co.ao').uncheck();
  await dlg.getByRole('button', { name: 'Enviar', exact: true }).click();
  await expect.poll(() => pedidos.find((p) => p.accao === 'enviar')).toMatchObject({ data: '2026-10-26', para: ['armazem@naval.co.ao'] });
});

test('dia novo: o cartão de cada rota vem pré-preenchido com o último registo, sem requisição', async ({ page }) => {
  const pedidos = [];
  const sug = { ...ROTA, origem: '2026-10-22', factura: undefined, obs: undefined, telefone: '924516916' };
  const erros = await abrirPlano(page, null, pedidos, { sugestoes: [sug] });
  const c = page.locator('.pc-card').first();
  await expect(c.getByLabel('Motorista')).toHaveValue('Adão Paulo');
  await expect(c.getByLabel('Telefone')).toHaveValue('924516916');
  await expect(c.getByLabel('Matrícula')).toHaveValue('LD-58-73-GY');
  await expect(c.getByLabel('Transportadora')).toHaveValue('Velotrans');
  await expect(c.getByLabel('Fraldas')).toHaveValue('90');
  await expect(c.getByLabel('Requisição ao armazém')).toHaveValue('');
  await expect(c.locator('.pc-info')).toContainText('22-10');
  await expect(c.locator('.pc-err')).toHaveCount(0);
  await expect(c.locator('.pc-av')).toContainText('sem requisição ao armazém emitida');
  expect(erros).toEqual([]);
});

test('limite de peso: não deixa carregar acima do que ainda cabe na viatura (capacidade − o que já leva)', async ({ page }) => {
  const pedidos = [];
  const base = resposta(RASCUNHO);
  base.listas.artigos = [
    { id: 'fraldas', nome: 'Fraldas', peso: 8 },
    { id: 'sabao', nome: 'Sabão', peso: 20 },
  ];
  base.listas.capacidades = { 'LD-58-73-GY': 1000 };
  const erros = await abrirPlano(page, RASCUNHO, pedidos, { listas: base.listas, na_carrinha: { 1001: 160 } });
  const c = page.locator('.pc-card').first();
  // 90 × 8 + 10 × 20 = 920 kg planeados; 1000 − 160 = 840 kg disponíveis → assinalado
  await expect(c.locator('.pc-peso')).toHaveClass(/excede/);
  const fraldas = c.getByLabel('Fraldas');
  await fraldas.fill('200');
  await expect(fraldas).toHaveValue('80'); // (840 − 200) ÷ 8
  await expect(c.locator('.pc-peso')).toContainText('Peso: 840 kg de 840 kg disponíveis');
  await expect(c.locator('.pc-peso')).not.toHaveClass(/excede/);
  expect(erros).toEqual([]);
});

test('carga pela requisição: quantidades, documento e armazém da requisição, só para leitura; motorista e viatura escolhidos nas tabelas', async ({ page }) => {
  const pedidos = [];
  const base = resposta(null);
  base.listas.motoristas = [
    { nome: 'Adão Paulo', telefone: '924516916', transportadora: 'Velotrans', matricula: 'LD-58-73-GY', carta_validade: '2027-05-01', activo: true },
    { nome: 'Carlos Velho', carta_validade: '2026-01-01', activo: true },
    { nome: 'Inactivo Sousa', activo: false },
  ];
  base.listas.frota = [{ matricula: 'LD-58-73-GY', marca: 'Mitsubishi', modelo: 'Canter', transportadora: 'Velotrans', kg: 3500, activa: true }];
  const carga = {
    dia_anterior: '2026-10-24',
    rotas: {
      1001: {
        requisicoes: [{ numero: 'RQ-2026-000123', estado: 'emitida' }],
        grupos: { fraldas: { na: 37, carregar: 150, total: 187 }, sabao: { na: 4, carregar: 0, total: 4 } },
        stock: 'contado',
        armazens: ['RTM HIGITEC PIV – NON FOOD'],
        rascunhos: [],
        sem_grupo: [{ codigo: '10000293', artigo: 'SOAP BROWN CONFIANC' }],
      },
    },
  };
  const erros = await abrirPlano(page, null, pedidos, { listas: base.listas, carga });
  const c = page.locator('.pc-card').first();
  // a carregar = a quantidade da requisição (o que já está na carrinha não entra), sem poder alterar
  await expect(c.getByLabel('Fraldas')).toHaveValue('150');
  await expect(c.getByLabel('Sabão')).toHaveValue('');
  await expect(c.getByLabel('Fraldas')).toHaveJSProperty('readOnly', true);
  await expect(c.getByLabel('Requisição ao armazém')).toHaveValue('RQ-2026-000123');
  await expect(c.getByLabel('Armazém de carga')).toHaveValue('RTM HIGITEC PIV – NON FOOD');
  await expect(c.getByLabel('Armazém de carga')).toHaveJSProperty('readOnly', true);
  await expect(c.locator('.pc-carga')).toContainText('contado no fecho de 24-10-2026');
  await expect(c.locator('.pc-carga')).toContainText('Fraldas: 37 + 150 = 187');
  await expect(c.locator('.pc-carga')).toContainText('10000293 SOAP BROWN CONFIANC');
  // só os motoristas activos e com a carta válida no dia
  const mot = c.getByLabel('Motorista');
  await expect(mot.locator('option')).toHaveText(['—', 'Adão Paulo']); // só o nome
  await mot.selectOption('Adão Paulo');
  await expect(c.getByLabel('Telefone')).toHaveValue('924516916');
  await expect(c.getByLabel('Matrícula')).toHaveValue('LD-58-73-GY');
  // a lista só tem a matrícula; marca e modelo ao lado, só para leitura
  await expect(c.getByLabel('Matrícula').locator('option', { hasText: 'LD-58-73-GY' })).toHaveText('LD-58-73-GY');
  await expect(c.getByLabel('Marca', { exact: true })).toHaveValue('Mitsubishi');
  await expect(c.getByLabel('Modelo', { exact: true })).toHaveValue('Canter');
  await expect(c.getByLabel('Marca', { exact: true })).toHaveJSProperty('readOnly', true);
  await expect(c.getByLabel('Modelo', { exact: true })).toHaveJSProperty('readOnly', true);
  await expect(c.getByLabel('Marca e modelo')).toHaveCount(0);
  await expect(c.getByLabel('Transportadora')).toHaveValue('Velotrans');
  expect(erros).toEqual([]);
});

test('listas: tabela de viaturas (matrícula, marca, modelo, capacidade) e motoristas com validade da carta e activo', async ({ page }) => {
  const pedidos = [];
  const base = resposta(null);
  base.listas.motoristas = [{ nome: 'Adão Paulo', telefone: '924516916', transportadora: 'Velotrans', matricula: '', carta_validade: '', activo: true }];
  base.listas.frota = [];
  const erros = await abrirPlano(page, null, pedidos, { listas: base.listas });
  await page.getByRole('button', { name: 'Listas', exact: true }).click();
  const d = page.locator('dialog[open]');
  await d.getByRole('button', { name: 'Motoristas', exact: true }).click();
  await d.getByRole('textbox', { name: 'Validade da carta de condução' }).fill('01-05-2027');
  await d.getByRole('button', { name: 'Viaturas', exact: true }).click();
  await d.getByRole('button', { name: 'Acrescentar' }).click();
  await d.getByLabel('Matrícula', { exact: true }).fill('LD-58-73-GY');
  await d.getByLabel('Marca', { exact: true }).fill('Mitsubishi');
  await d.getByLabel('Modelo', { exact: true }).fill('Canter');
  await d.getByLabel('Capacidade (kg)').fill('3500');
  await d.getByRole('button', { name: 'Guardar listas' }).click();
  await expect.poll(() => pedidos.find((p) => p.accao === 'listas')).toBeTruthy();
  await expect(page.locator('dialog[open]')).toHaveCount(0);
  const l = pedidos.find((p) => p.accao === 'listas').listas;
  expect(l.frota).toEqual([{ matricula: 'LD-58-73-GY', marca: 'Mitsubishi', modelo: 'Canter', transportadora: '', kg: '3500', activa: true }]);
  expect(l.motoristas[0]).toMatchObject({ nome: 'Adão Paulo', carta_validade: '2027-05-01', activo: true });
  expect(erros).toEqual([]);
});
