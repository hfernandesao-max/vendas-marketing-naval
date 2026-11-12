// Relatório de Vendas diário (só com as fontes do dia completas; congelado, com PDF) e folha de caixa do Marketing.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const CTL = (pronto) => ({
  pinguim: { estado: 'ok', detalhe: '10 rotas com vendas, todas sincronizadas depois das 18:00.' },
  conciliacao: pronto ? { estado: 'ok', detalhe: '10 carrinhas conciliadas e fechadas.' } : { estado: 'pendente', detalhe: 'Carrinhas por fechar: 1006.' },
  sap_ficheiro: { estado: 'ok', detalhe: '40 documentos carregados no SAP.' },
  caixa: { estado: 'ok', detalhe: 'Folha de caixa aprovada por fin.' },
  sap_odata: { estado: 'por_ligar', detalhe: 'Conector SAP por ligar.' },
});
const RELATORIO = {
  numero: 'RV-20261025-v1',
  dia: '2026-10-25',
  versao: 1,
  gerado_em: '2026-10-26 08:00:00',
  gerado_por: 'dir',
  motivo: '',
  sha256: 'a'.repeat(64),
  controlos: CTL(true),
  dados: {
    dia: '2026-10-25',
    resumo: {
      documentos: 3,
      vendas: 1300,
      notas_credito: 200,
      dinheiro: 600,
      multicaixa: 400,
      outros: 0,
      por_receber: 500,
      entregue: 550,
      tpa: 400,
      dif_dinheiro: -50,
      dif_tpa: 0,
      promotores: 1,
    },
    promotores: [
      {
        rota: '1001',
        promotor: 'Andrea Santos',
        documentos: 2,
        notas_credito: 0,
        vendas: 1500,
        dinheiro: 600,
        multicaixa: 400,
        outros: 0,
        por_receber: 500,
        entregue: 550,
        tpa: 400,
        dif_dinheiro: -50,
        dif_tpa: 0,
      },
    ],
    registo: [
      { rota: '1001', promotor: 'Andrea Santos', documento: 'FR A/1', codigo: 'A1', artigo: 'Fraldas', unidade: 'CAR', quantidade: 2, preco: 500, valor: 1000 },
    ],
    stock: [
      {
        rota: '1001',
        promotor: 'Andrea Santos',
        codigo: 'A1',
        artigo: 'Fraldas',
        inicial: 5,
        carregado: 10,
        vendido: 2,
        devolvido: 0,
        final: 13,
        contado: 13,
        diferenca: 0,
      },
    ],
    caixa: {
      saldo_inicial: 100,
      entregas: 550,
      tpa: [{ rota: '1001', banco: 'BPC', empresa: 'HIGITEC', valor: 400 }],
      total_tpa: 400,
      saidas: [{ tipo: 'fundo_maneio', empresa: 'FOODTEC', valor: 0.0001 }],
      total_saidas: 0,
      saldo_teorico: 650,
      contagem_fisica: 650,
      diferenca: 0,
      explicacao: '',
      fechada_por: 'josefa',
      aprovada_por: 'fin',
    },
    sap: null,
  },
};

test('relatório de vendas: bloqueado com fontes em falta; gerado, mostra a conciliação por promotor e dá o PDF', async ({ page }) => {
  let pronto = false,
    gerado = false;
  const pedidos = [];
  await page.route('**/v1/relatorio-vendas**', (r) => {
    const req = r.request();
    if (req.method() === 'POST') {
      pedidos.push(JSON.parse(req.postData()));
      gerado = true;
      return r.fulfill({ json: { ok: true, relatorio: RELATORIO } });
    }
    if (new URL(req.url()).searchParams.get('versao')) return r.fulfill({ json: { ok: true, relatorio: RELATORIO } });
    return r.fulfill({
      json: {
        ok: true,
        pode_gerar: true,
        dia: '2026-10-25',
        pronto,
        controlos: CTL(pronto),
        versoes: gerado ? [{ numero: RELATORIO.numero, versao: 1, gerado_por: 'dir', gerado_em: RELATORIO.gerado_em, motivo: '' }] : [],
      },
    });
  });
  await page.addInitScript(() => (window.open = () => null));
  const erros = await abrir(page, '#relatorio-vendas');
  await expect(page.locator('.rv-lista li')).toHaveCount(5);
  await expect(page.locator('.rv-lista li.rv-pendente')).toContainText('Carrinhas por fechar: 1006');
  await expect(page.getByRole('button', { name: 'Gerar o relatório' })).toBeDisabled();
  pronto = true;
  await page.locator('.pc-barra').getByRole('button', { name: 'Actualizar' }).click();
  await page.getByRole('button', { name: 'Gerar o relatório' }).click();
  await expect(page.locator('.rv-cab')).toContainText('RV-20261025-v1');
  expect(pedidos[0]).toMatchObject({ accao: 'gerar', dia: '2026-10-25' });
  await page.click('a[href="#relatorio-vendas/rv-prom"]');
  await expect(page.locator('#s-rv-prom tbody tr').first()).toContainText('Andrea Santos');
  await expect(page.locator('#s-rv-prom .rv-dif').first()).toBeVisible(); // diferença do dinheiro a vermelho
  await page.click('a[href^="#relatorio-vendas/fecho-do-dia"]');
  await expect(page.getByRole('button', { name: 'Gerar nova versão' })).toBeVisible();
  const [d] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'PDF do relatório' }).click()]);
  expect(d.suggestedFilename()).toBe('RV-20261025-v1.pdf');
  const fs = await import('node:fs');
  const pdf = fs.readFileSync(await d.path(), 'latin1');
  expect(pdf).toContain('Andrea Santos');
  expect(pdf).toContain('aaaaaaaaaaaaaaaa'); // impressão digital
  expect(erros).toEqual([]);
});

test('folha de caixa: entregas, talões do TPA e saídas; gravar e fechar com a contagem', async ({ page }) => {
  let caixa = {
    dia: '2026-10-26',
    estado: 'aberta',
    versao: 1,
    saldo_inicial: 1000,
    saldo_inicial_origem: 'dia 2026-10-25',
    entregas: [],
    tpa: [],
    saidas: [],
    contagem_fisica: null,
    explicacao: '',
    total_entregas: 0,
    total_tpa: 0,
    total_saidas: 0,
    saldo_teorico: 1000,
    diferenca: null,
  };
  const pedidos = [];
  await page.route('**/v1/caixa**', (r) => {
    const req = r.request();
    if (req.method() === 'POST') {
      const b = JSON.parse(req.postData());
      pedidos.push(b);
      if (b.accao === 'gravar') caixa = { ...caixa, entregas: b.entregas, tpa: b.tpa, saidas: b.saidas, versao: caixa.versao + 1 };
      if (b.accao === 'fechar')
        caixa = {
          ...caixa,
          estado: 'fechada',
          contagem_fisica: b.contagem,
          fechada_por: 'josefa',
          diferenca: b.contagem - 1300,
          explicacao: b.explicacao,
          versao: caixa.versao + 1,
        };
      return r.fulfill({ json: { ok: true, caixa } });
    }
    return r.fulfill({
      json: {
        ok: true,
        gere: true,
        caixa,
        promotores: { 1001: 'Andrea Santos', 1002: 'Cristina Sachuma' },
        saidas: {
          deposito_banco: 'Depósito no banco',
          fundo_maneio: 'Fundo de maneio',
          transferencia_gestao: 'Transferência para a gestão',
          outro: 'Outra saída',
        },
        bancos: ['BPC', 'ATLANTICO'],
        empresas: ['NAVAL', 'HIGITEC', 'FOODTEC'],
      },
    });
  });
  const erros = await abrir(page, '#caixa-marketing');
  const secs = page.locator('.cx-sec');
  await secs.nth(0).getByRole('button', { name: 'Juntar linha' }).click();
  await page.getByLabel('Promotor · 1').first().selectOption('1001');
  await page.locator('.cx-sec').nth(0).getByLabel('Valor · 1').fill('500');
  await page.locator('.cx-sec').nth(1).getByRole('button', { name: 'Juntar linha' }).click();
  await page.locator('.cx-sec').nth(1).getByLabel('Promotor · 1').selectOption('1002');
  await page.locator('.cx-sec').nth(1).getByLabel('Banco · 1').selectOption('BPC');
  await page.locator('.cx-sec').nth(1).getByLabel('Valor · 1').fill('300');
  await page.locator('.cx-sec').nth(2).getByRole('button', { name: 'Juntar linha' }).click();
  await page.locator('.cx-sec').nth(2).getByLabel('Tipo · 1').selectOption('transferencia_gestao');
  await page.locator('.cx-sec').nth(2).getByLabel('Valor · 1').fill('200');
  await expect(page.locator('#cx-estado')).toContainText('Alterações por gravar');
  await expect(page.locator('.tile', { hasText: 'Saldo final teórico' })).toContainText(/1\s?300,00/);
  await page.getByRole('button', { name: 'Gravar', exact: true }).click();
  await expect.poll(() => pedidos.length).toBe(1);
  expect(pedidos[0]).toMatchObject({
    accao: 'gravar',
    dia: expect.any(String),
    versao: 1,
    entregas: [{ rota: '1001', valor: 500 }],
    tpa: [{ rota: '1002', banco: 'BPC', valor: 300 }],
    saidas: [{ tipo: 'transferencia_gestao', valor: 200 }],
  });
  await page.getByRole('button', { name: 'Fechar a caixa' }).click();
  const dlg = page.locator('dialog[open]');
  await dlg.getByLabel('Dinheiro contado na caixa').fill('1290');
  await expect(dlg).toContainText('Diferença');
  await dlg.getByLabel('Explicação da diferença').fill('troco dado a mais');
  await dlg.getByRole('button', { name: 'Fechar a caixa' }).click();
  await expect(page.locator('.cx-cab')).toContainText('Fechada, por aprovar');
  expect(pedidos[1]).toMatchObject({ accao: 'fechar', contagem: 1290, explicacao: 'troco dado a mais' });
  await expect(page.getByRole('button', { name: 'Aprovar a caixa' })).toBeVisible();
  await expect(page.locator('.cx-tab input')).toHaveCount(0);
  expect(erros).toEqual([]);
});

test('folha de caixa: sugere o que cada promotor recebeu, conta notas e fecha com a contagem por notas', async ({ page }) => {
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
    const req = r.request();
    if (req.method() === 'POST') {
      const b = JSON.parse(req.postData());
      pedidos.push(b);
      if (b.accao === 'gravar') caixa = { ...caixa, entregas: b.entregas, tpa: b.tpa, saidas: b.saidas, versao: caixa.versao + 1 };
      if (b.accao === 'fechar') caixa = { ...caixa, estado: 'fechada', contagem_fisica: b.contagem, versao: caixa.versao + 1 };
      return r.fulfill({ json: { ok: true, caixa } });
    }
    return r.fulfill({
      json: {
        ok: true,
        gere: true,
        caixa,
        notas: [5000, 2000, 1000, 500, 200],
        promotores: { 1001: 'Andrea Santos', 1002: 'Cristina Sachuma' },
        esperado: { 1001: { documentos: 3, dinheiro: 12000, multicaixa: 0 }, 1002: { documentos: 2, dinheiro: 0, multicaixa: 8000 } },
        saidas: {
          deposito_banco: 'Depósito no banco',
          fundo_maneio: 'Fundo de maneio',
          transferencia_gestao: 'Transferência para a gestão',
          outro: 'Outra saída',
        },
        bancos: ['BPC', 'ATLANTICO'],
        empresas: ['NAVAL', 'HIGITEC', 'FOODTEC'],
      },
    });
  });
  const erros = await abrir(page, '#caixa-marketing');
  await expect(page.locator('.cx-sugerido')).toBeVisible();
  const ent = page.locator('.cx-sec').filter({ hasText: 'Dinheiro entregue pelos promotores' });
  await expect(ent.getByLabel('Valor · 1')).toHaveValue('12000');
  await expect(page.locator('.cx-sec').filter({ hasText: 'Talões do TPA' }).getByLabel('Valor · 1')).toHaveValue('8000');
  // contar as notas da entrega: 2 × 5000 + 1 × 1000 + 500 em moedas = 11 500 → diferença de 500 na conferência
  await ent.getByRole('button', { name: 'Contar notas' }).click();
  const dlg = page.locator('dialog[open]');
  await dlg.getByLabel('Notas de 5000 Kz').fill('2');
  await dlg.getByLabel('Notas de 1000 Kz').fill('1');
  await dlg.getByLabel('Moedas (valor em Kz)').fill('500');
  await expect(dlg.locator('.cx-notas-tot')).toContainText(/11\s?500,00/);
  await dlg.getByRole('button', { name: 'Confirmar' }).click();
  await expect(ent.getByLabel('Valor · 1')).toHaveValue('11500');
  await expect(ent).toContainText('2 × 5');
  const conf = page.locator('.cx-sec').filter({ hasText: 'Conferência por promotor' });
  await expect(conf.locator('tr', { hasText: '1001' }).locator('.rv-dif')).toContainText('500,00');
  await page.locator('.cx-sec').filter({ hasText: 'Talões do TPA' }).getByLabel('Banco · 1').selectOption('BPC');
  await page.getByRole('button', { name: 'Gravar', exact: true }).click();
  await expect.poll(() => pedidos.length).toBe(1);
  expect(pedidos[0].entregas[0]).toMatchObject({ rota: '1001', valor: 11500, notas: { 5000: 2, 1000: 1, moedas: 500 } });
  // fecho: contagem física por notas
  await page.getByRole('button', { name: 'Fechar a caixa' }).click();
  const f = page.locator('dialog[open]');
  await f.getByLabel('Notas de 5000 Kz').fill('2');
  await f.getByLabel('Notas de 1000 Kz').fill('1');
  await f.getByLabel('Moedas (valor em Kz)').fill('500');
  await expect(f.getByLabel('Dinheiro contado na caixa')).toHaveValue('11500');
  await f.getByRole('button', { name: 'Fechar a caixa' }).click();
  await expect.poll(() => pedidos.length).toBe(2);
  expect(pedidos[1]).toMatchObject({ accao: 'fechar', contagem: 11500, notas: { 5000: 2, 1000: 1, moedas: 500 } });
  expect(erros).toEqual([]);
});
