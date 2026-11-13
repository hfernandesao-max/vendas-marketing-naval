// Substituição de uma factura de um dia anterior (nota de crédito + factura nova ao mesmo cliente no mesmo dia, com
// desconto): sem venda nova nem dinheiro. Fica fora das Cobranças e das Vendas por promotor, com o desconto em positivo
// nas «Rectificações de documentos anteriores», e continua nos Documentos de venda (como no Pinguim BO).
import { test, expect } from '@playwright/test';
import { abrir, artigos, vendas } from './dados.js';

const marca = { nota: 'NC R1004/2026/3', factura: 'FR R1004/2026/90', desconto: 20000, desconto_sem_iva: 17543.86 };
const base = {
  data: '2026-10-25',
  hora: '10:00',
  rota: '1004',
  cliente: 'CLIENTE SUBST',
  cliente_codigo: 'C900',
  nif: '5001784999',
  liquidado: true,
  substituicao: marca,
};
const SUBST = [
  {
    ...base,
    chave: 'S1',
    nc: true,
    tipo: 'Nota de crédito',
    documento: 'NC R1004/2026/3',
    total: -1500000,
    total_sem_iva: -1315789.47,
    valor_pago: 0,
    pagamentos: [
      { codigo: 'CMP', meio: 'Substituição de factura', valor: -1480000 },
      { codigo: 'CMP', meio: 'Desconto na substituição (sem movimento de dinheiro)', valor: -20000 },
    ],
    pagamentos_origem: [{ codigo: 'MC', meio: 'Multicaixa', valor: -1500000 }],
  },
  {
    ...base,
    chave: 'S2',
    nc: false,
    tipo: 'Factura-recibo',
    documento: 'FR R1004/2026/90',
    total: 1480000,
    total_sem_iva: 1298245.61,
    valor_pago: 1480000,
    pagamentos: [{ codigo: 'CMP', meio: 'Substituição de factura', valor: 1480000 }],
    pagamentos_origem: [{ codigo: 'MC', meio: 'Multicaixa', valor: 1480000 }],
  },
];
const api = (r) => r.fulfill({ json: { vendas: [...vendas, ...SUBST], artigos } });

test('substituição de factura: fora das cobranças e das vendas, desconto em positivo, nos documentos', async ({ page }) => {
  const erros = await abrir(page, '#pagamentos', { api });
  // os mesmos valores que sem a substituição (88 000 recebidos, 44 000 em dinheiro e 44 000 em Multicaixa): nada a negativo
  const t = page.locator('.tile');
  await expect(t.nth(0)).toContainText('88 000,00 Kz');
  await expect(t.nth(1)).toContainText('44 000,00 Kz');
  await expect(t.nth(2)).toContainText('44 000,00 Kz');
  await expect(t.nth(3)).toContainText('32 000,00 Kz');
  await expect(page.locator('#page')).not.toContainText('−20 000');
  const aviso = page.locator('.aviso-rect');
  await expect(aviso).toContainText('Rectificações de documentos anteriores (1)');
  await expect(aviso).toContainText('rota 1004 · NC R1004/2026/3 substitui uma factura anterior pela FR R1004/2026/90 · desconto ao cliente 20 000');
  // Vendas por promotor: idem
  await page.goto('/reports/index.html#vendas-promotor');
  await expect(page.locator('.aviso-rect')).toContainText('desconto ao cliente 20 000');
  await expect(page.locator('#page')).not.toContainText('−17 543');
  // Documentos de venda: os dois documentos, como no Pinguim BO
  await page.goto('/reports/index.html#documentos');
  await expect(page.locator('#page')).toContainText('NC R1004/2026/3');
  await expect(page.locator('#page')).toContainText('FR R1004/2026/90');
  expect(erros).toEqual([]);
});

test('substituição de factura no ficheiro para o SAP: os dois documentos seguem e a conciliação com as vendas explica o desconto', async ({ page }) => {
  const la = artigos.find((a) => a.rota === '1004');
  const linha = (doc, q, v) => ({
    ...la,
    chave: doc + '|1',
    documento: doc,
    data: '2026-10-25',
    quantidade: q,
    valor_sem_iva: v / 1.14,
    iva: v - v / 1.14,
    total: v,
    gross_total: v,
  });
  const L = [linha('NC R1004/2026/3', -1, -1500000), linha('FR R1004/2026/90', 1, 1480000)];
  const rotas = Object.fromEntries([...new Set(vendas.map((v) => v.rota))].map((r) => [r, { local: 'RTM' + r.slice(-1) }]));
  await page.route('**/v1/sap**', (r) =>
    r.fulfill({ json: { config: { prefixo: 'FHR PIV', moeda: 'AOA', unidade: 'CAR', rotas }, lotes: [], exportados: {}, pode_gerar: true } }),
  );
  const erros = await abrir(page, '#sap-exportar', { api: (r) => r.fulfill({ json: { vendas: [...vendas, ...SUBST], artigos: [...artigos, ...L] } }) });
  await expect(page.locator('.tile', { hasText: 'Total facturado' })).toContainText('inclui descontos em substituições de facturas: −20 000,00 Kz');
  // numerário e Multicaixa sem a substituição (como nas Cobranças): nada a negativo
  await expect(page.locator('.tile', { hasText: 'Multicaixa' })).not.toContainText('−');
  const nota = page.locator('#page .aviso-rect');
  await expect(nota).toContainText('Conciliação com as vendas');
  await expect(nota).toContainText('descontos em substituições de facturas 20 000,00 Kz');
  await expect(nota).toContainText('rota 1004 · NC R1004/2026/3 e FR R1004/2026/90: desconto 20 000,00 Kz');
  expect(erros).toEqual([]);
});
