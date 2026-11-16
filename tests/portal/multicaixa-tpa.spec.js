// Multicaixa por TPA: relatório (fecho × Pinguim BO por máquina, totais por banco e conta, sem fecho, a rever),
// «Conferido no extracto», registo dos terminais e destinatários do resumo; e, na folha de caixa, o talão de fecho
// com TPA, período, operações e total a movimentar (com o TPA da rota já escolhido).
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const TERMINAIS = [
  { tpa: '00444134', estab: '0000287047', empresa: 'NAVAL', banco: 'BPC', conta: '0065023555 06011', rota: '1001', activo: true, nota: '' },
  { tpa: '00516385', estab: '', empresa: 'NAVAL', banco: 'BAI', conta: '', rota: '1002', activo: true, nota: '' },
];
const RELATORIO = {
  de: '2026-11-13',
  ate: '2026-11-13',
  pinguim_ligado: true,
  referencias: true,
  fechos: [
    {
      dia: '2026-11-13',
      tpa: '00516385',
      periodo: '87',
      rota: '1002',
      empresa: 'NAVAL',
      banco: 'BAI',
      conta: '',
      operacoes: 4,
      valor: 412000,
      pinguim: 452000,
      diferenca: -40000,
      conferido: null,
      partilhado: false,
    },
    {
      dia: '2026-11-13',
      tpa: '00444134',
      periodo: '103',
      rota: '1001',
      empresa: 'NAVAL',
      banco: 'BPC',
      conta: '0065023555 06011',
      operacoes: 6,
      valor: 845000,
      pinguim: 845000,
      diferenca: 0,
      conferido: { ref: 'MOV-1', data: '2026-11-16' },
      partilhado: false,
    },
  ],
  bancos: [
    { empresa: 'NAVAL', banco: 'BAI', conta: '', tpa: 1, valor: 412000, conferido: 0 },
    { empresa: 'NAVAL', banco: 'BPC', conta: '0065023555 06011', tpa: 1, valor: 845000, conferido: 845000 },
  ],
  sem_fecho: [{ dia: '2026-11-13', rota: '1005', pinguim: 25000, tpa: '' }],
  rever: [{ dia: '2026-11-13', documento: 'FR 1002/2231', rota: '1002', valor: 40000, ref: '', motivo: 'sem_referencia' }],
  totais: { fechos: 1257000, pinguim: 1322000, diferenca: -65000, conferido: 845000, por_conferir: 412000, n_fechos: 2, n_conferidos: 1, operacoes: 10 },
};

test('Multicaixa por TPA: relatório por máquina, banco e conta; conferir no extracto; registar um TPA', async ({ page }) => {
  const pedidos = [];
  let terminais = TERMINAIS.slice();
  await page.route('**/v1/multicaixa-tpa**', (r) => {
    const q = r.request();
    const base = {
      ok: true,
      gere: true,
      terminais,
      empresas: ['NAVAL', 'HIGITEC', 'REFITECH'],
      bancos: ['BPC', 'BAI', 'ATLANTICO'],
      promotores: { 1001: 'Andrea Santos', 1002: 'Cristina Sachuma' },
      destinatarios: ['financeiro@naval.co.ao'],
    };
    if (q.method() === 'POST') {
      const b = q.postDataJSON();
      pedidos.push(b);
      if (b.accao === 'terminal') terminais = terminais.concat([{ ...b, activo: true }]);
      if (b.accao === 'destinatarios') return r.fulfill({ json: { ...base, destinatarios: b.destinatarios } });
      return r.fulfill({ json: { ...base, terminais } });
    }
    expect(q.url()).toContain('de=');
    return r.fulfill({ json: { ...base, relatorio: RELATORIO } });
  });
  const erros = await abrir(page, '#multicaixa-tpa?de=2026-11-13&ate=2026-11-13');
  await expect(page.locator('.tile', { hasText: 'Diferença' })).toContainText(/65\s?000,00/);
  const porTpa = page.locator('.sec', { hasText: 'Por TPA (máquina)' });
  await expect(porTpa.locator('tr')).toHaveCount(4); // cabeçalho, 2 fechos, total
  await expect(porTpa.locator('tr', { hasText: '00516385' })).toContainText('Diferença');
  await expect(porTpa.locator('tr', { hasText: '00444134' })).toContainText('Conferido');
  await expect(porTpa.locator('tr', { hasText: '00444134' })).toContainText('16-11-2026 · MOV-1');
  await expect(page.locator('.sec', { hasText: 'Multicaixa sem talão de fecho' })).toContainText('1005');
  await expect(page.locator('.sec', { hasText: 'Por empresa, banco e conta' })).toContainText('0065023555 06011');
  await expect(page.locator('.sec', { hasText: 'Pagamentos a rever' })).toContainText('FR 1002/2231');
  // conferir no extracto: referência e data do movimento
  await porTpa.getByRole('button', { name: 'Conferir no extracto o TPA 00516385, período 87' }).click();
  const dlg = page.locator('dialog[open]');
  await dlg.getByLabel('Referência do movimento').fill('MOV-2');
  await dlg.getByRole('button', { name: 'Marcar como conferido' }).click();
  await expect
    .poll(() => pedidos.find((x) => x.accao === 'conferir'))
    .toMatchObject({ dia: '2026-11-13', tpa: '00516385', periodo: '87', ref: 'MOV-2', data: '2026-11-14' });
  // registo dos terminais
  await page.getByRole('button', { name: 'Terminais (TPA)' }).click();
  await expect(page.locator('.sec', { hasText: 'Terminais (TPA)' }).locator('tr')).toHaveCount(3);
  await page.getByRole('button', { name: 'Registar TPA' }).click();
  const t = page.locator('dialog[open]');
  await t.getByLabel('Ident. TPA (8 dígitos)').fill('00445736');
  await t.getByLabel('Empresa').selectOption('HIGITEC');
  await t.getByLabel('Banco').selectOption('BPC');
  await t.getByRole('button', { name: 'Gravar' }).click();
  await expect.poll(() => pedidos.find((x) => x.accao === 'terminal')).toMatchObject({ tpa: '00445736', empresa: 'HIGITEC', banco: 'BPC', activo: true });
  await expect(page.locator('.sec', { hasText: 'Terminais (TPA)' })).toContainText('00445736');
  // destinatários do resumo de fim de dia
  await page.getByRole('button', { name: 'Resumo por correio' }).click();
  await page.getByLabel('Destinatários (um endereço por linha)').fill('financeiro@naval.co.ao\nvanda@naval.co.ao');
  await page.getByRole('button', { name: 'Gravar destinatários' }).click();
  await expect.poll(() => pedidos.find((x) => x.accao === 'destinatarios')).toMatchObject({ destinatarios: ['financeiro@naval.co.ao', 'vanda@naval.co.ao'] });
  expect(erros).toEqual([]);
});

test('folha de caixa com o registo dos TPA: talão de fecho com TPA (o da rota), período, operações e total a movimentar', async ({ page }) => {
  let caixa = {
    dia: '2026-10-26',
    estado: 'aberta',
    versao: 1,
    saldo_inicial: 0,
    saldo_inicial_origem: 'dia 2026-10-25',
    entregas: [],
    tpa: [],
    saidas: [],
    contagem_fisica: null,
    explicacao: '',
    total_entregas: 0,
    total_tpa: 0,
    total_saidas: 0,
    saldo_teorico: 0,
    diferenca: null,
  };
  const pedidos = [];
  await page.route('**/v1/caixa**', (r) => {
    const q = r.request();
    if (q.method() === 'POST') {
      const b = q.postDataJSON();
      pedidos.push(b);
      caixa = { ...caixa, tpa: b.tpa, versao: caixa.versao + 1 };
      return r.fulfill({ json: { ok: true, caixa } });
    }
    return r.fulfill({
      json: {
        ok: true,
        gere: true,
        aprova: [],
        eu: 'josefa',
        caixa,
        esperado: { 1001: { documentos: 2, dinheiro: 0, multicaixa: 20000 } },
        promotores: { 1001: 'Andrea Santos', 1002: 'Cristina Sachuma' },
        saidas: { deposito_banco: 'Depósito no banco' },
        bancos: ['BPC', 'BAI'],
        empresas: ['NAVAL', 'HIGITEC'],
        terminais: TERMINAIS,
      },
    });
  });
  const erros = await abrir(page, '#caixa-marketing');
  const s = page.locator('.cx-sec', { hasText: 'Talões de fecho do TPA' });
  // linha sugerida para o promotor com Multicaixa no Pinguim BO, já com o TPA da rota e sem valor (às cegas)
  await expect(s.getByLabel('Promotor · 1')).toHaveValue('1001');
  await expect(s.getByLabel('TPA · 1')).toHaveValue('00444134');
  await expect(s.getByLabel('Total a movimentar · 1')).toHaveValue('');
  await s.getByLabel('Período (Per) · 1').fill('104');
  await s.getByLabel('Operações · 1').fill('2');
  await s.getByLabel('Total a movimentar · 1').fill('20000');
  await page.getByRole('button', { name: 'Gravar', exact: true }).click();
  await expect.poll(() => pedidos.length).toBe(1);
  expect(pedidos[0].tpa[0]).toMatchObject({ rota: '1001', tpa: '00444134', periodo: '104', operacoes: '2', valor: 20000 });
  expect(erros).toEqual([]);
});
