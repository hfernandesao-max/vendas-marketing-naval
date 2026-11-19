// Ficha do cliente (Clientes › Carteira de clientes): quatro separadores — dados e localização e visitas do OLAP;
// vendas e pagamentos (modo e tipo) das vendas do Pinguim BO do período; sem OLAP, a ficha continua com o resto.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { abrir, artigos, vendas } from './dados.js';

const carteira = JSON.parse(readFileSync('reports/data/clientes.json', 'utf8'));
const cli = carteira.find((x) => x.codigo && x.codigo !== '0000000000' && x.nome && x.rota);

// duas facturas do cliente: uma factura-recibo paga em numerário e uma factura a crédito com parte em Multicaixa
const doCliente = [
  {
    chave: 'FC1',
    data: '2026-10-12',
    hora: '09:30',
    rota: cli.rota,
    tipo: 'Factura-recibo',
    tipo_codigo: 'FR',
    documento: 'FR FC/2026/1',
    cliente: cli.nome,
    cliente_codigo: cli.codigo,
    nif: cli.nif,
    liquidado: true,
    total: 11400,
    total_sem_iva: 10000,
    valor_pago: 11400,
    pagamentos: [{ codigo: 'NU', meio: 'Numerário', valor: 11400 }],
    meio_pagamento: 'Numerário',
  },
  {
    chave: 'FC2',
    data: '2026-10-20',
    hora: '15:10',
    rota: cli.rota,
    tipo: 'Factura',
    tipo_codigo: 'FT',
    documento: 'FT FC/2026/2',
    cliente: cli.nome,
    cliente_codigo: cli.codigo,
    nif: cli.nif,
    liquidado: false,
    total: 22800,
    total_sem_iva: 20000,
    valor_pago: 10000,
    pagamentos: [{ codigo: 'MC', meio: 'Multicaixa', valor: 10000 }],
    meio_pagamento: 'Multicaixa',
  },
];
const fichaOlap = {
  ok: true,
  origem: 'olap',
  cliente: { codigo: cli.codigo, nome: cli.nome, nome_fiscal: 'Nome Fiscal, Lda', nif: null, tipologia: 'MINI-MERCADO', actualizado: null },
  localizacao: { municipio: 'VIANA', cidade: null, lat: -8.877767, lng: 13.330733, gps_invalido: false },
  atribuicao: {
    rota: cli.rota,
    rota_nome: cli.rota,
    vendedor: 'Promotor do OLAP',
    coordenador: null,
    gestor: null,
    distribuidor: 'Grupo Naval, Lda',
    canal: 'LUANDA',
    subcanal: 'MOBILIDADE',
    desde: '2026-10-01',
    dias_visita: ['Segunda-feira', 'Quinta-feira'],
  },
  visitas: [
    {
      data: '2026-10-20',
      hora: '15:05',
      rota: cli.rota,
      vendedor: 'P',
      resultado: 'Venda',
      venda: true,
      modo: 'presencial',
      sem_gps: false,
      valor: 22800,
      documento: 'FT FC/2026/2',
      distancia: 12,
      permanencia_min: 12,
      deslocacao_min: 15,
      intervalo_min: 27,
      proximo_m: 1100,
      proximo_mesmo_sitio: false,
      proximo_cliente: 'Cantina São José',
      lat: -8.8779,
      lng: 13.3308,
    },
    {
      data: '2026-10-15',
      hora: '11:00',
      rota: cli.rota,
      vendedor: 'P',
      resultado: 'Ausência responsável',
      venda: false,
      modo: 'remota',
      sem_gps: false,
      valor: null,
      documento: null,
      distancia: 1800,
      permanencia_min: null,
      deslocacao_min: null,
      intervalo_min: 95,
      proximo_m: 30,
      proximo_mesmo_sitio: true,
      proximo_cliente: 'Loja Vizinha',
      lat: -8.8615,
      lng: 13.3302,
    },
  ],
  resumo_visitas: {
    total: 2,
    com_venda: 1,
    presenciais: 1,
    remotas: 1,
    distancia_mediana: 1800,
    permanencia_mediana_min: 12,
    deslocacao_mediana_min: 15,
    com_tempo: 1,
    ultima: '2026-10-20',
    por_resultado: [
      { resultado: 'Venda', n: 1 },
      { resultado: 'Ausência responsável', n: 1 },
    ],
  },
  janela_dias: 180,
};

async function abrirFicha(page, respostaOlap) {
  const pedidos = [];
  await page.route('**/v1/clientes/ficha**', (r) => {
    pedidos.push(new URL(r.request().url()).searchParams.get('codigo'));
    return respostaOlap(r);
  });
  const erros = await abrir(page, '#clientes', { api: (r) => r.fulfill({ json: { vendas: [...vendas, ...doCliente], artigos } }) });
  await page.locator('#q-cl-d').fill(cli.codigo);
  await page.locator('#s-cl-d .cf-abrir').first().click();
  const dlg = page.locator('dialog.cf-dlg');
  await expect(dlg).toBeVisible();
  return { dlg, pedidos, erros };
}

test('ficha do cliente: dados e localização, vendas, pagamentos (modo e tipo) e visitas em separadores', async ({ page }) => {
  const { dlg, pedidos, erros } = await abrirFicha(page, (r) => r.fulfill({ json: fichaOlap }));
  await expect(dlg.getByRole('tab')).toHaveText(['Dados e localização', 'Vendas', 'Pagamentos', 'Visitas e tempos', 'Mapa']);
  await expect(dlg.getByRole('tab', { name: 'Dados e localização' })).toHaveAttribute('aria-selected', 'true');
  expect(pedidos).toEqual([cli.codigo]);

  // 1. dados e localização (OLAP + carteira)
  const dados = dlg.locator('#cf-p-dados');
  await expect(dados).toContainText('VIANA');
  await expect(dados).toContainText('Promotor do OLAP');
  await expect(dados).toContainText('Segunda-feira, Quinta-feira');
  await expect(dados).toContainText('LUANDA · MOBILIDADE');
  await expect(dados.getByRole('link', { name: 'Abrir no mapa' })).toHaveAttribute('href', /query=-8\.877767,13\.330733$/);
  await expect(dados).toContainText('Fonte: OLAP.');

  // 2. vendas do período (Pinguim BO): só os documentos deste cliente
  await dlg.getByRole('tab', { name: 'Vendas' }).click();
  const vd = dlg.locator('#cf-p-vendas');
  await expect(vd).toBeVisible();
  await expect(dados).toBeHidden();
  await expect(vd.locator('.tile').filter({ hasText: 'Documentos' }).locator('.v')).toHaveText('2');
  await expect(vd.locator('.cf-tab tbody tr')).toHaveCount(2);
  await expect(vd.locator('.cf-tab tbody tr').first()).toContainText('FT FC/2026/2');
  await expect(vd).toContainText('Fonte: Pinguim BO');

  // 3. pagamentos: modo (meio de cobrança) e tipo (pronto pagamento × a crédito, com o pendente)
  await dlg.getByRole('tab', { name: 'Pagamentos' }).click();
  const pg = dlg.locator('#cf-p-pagamentos');
  const modo = pg.locator('.cf-bloco').filter({ hasText: 'Modo de pagamento' }).first();
  await expect(modo.locator('tbody tr')).toHaveCount(2);
  await expect(modo).toContainText('Numerário');
  await expect(modo).toContainText('Multicaixa');
  const tipo = pg.locator('.cf-bloco').filter({ hasText: 'Tipo de pagamento' });
  await expect(tipo.locator('tbody tr').filter({ hasText: 'Pronto pagamento (factura-recibo)' })).toBeVisible();
  const credito = tipo.locator('tbody tr').filter({ hasText: 'A crédito (factura)' });
  await expect(credito.locator('.pill')).toBeVisible(); // 12 800 Kz por receber
  await expect(pg.locator('.tile').filter({ hasText: 'Pendente' }).locator('.v')).not.toHaveText('—');

  // 4. visitas e tempos (OLAP): promotor, tempo no cliente e deslocação até ao seguinte; as setas mudam de separador
  await dlg.getByRole('tab', { name: 'Pagamentos' }).press('ArrowRight');
  await expect(dlg.getByRole('tab', { name: 'Visitas e tempos' })).toBeFocused();
  const vs = dlg.locator('#cf-p-visitas');
  await expect(vs).toBeVisible();
  const hist = vs.locator('.cf-bloco').filter({ hasText: 'Histórico de visitas' }).locator('tbody tr');
  await expect(hist).toHaveCount(2);
  await expect(hist.first()).toContainText('12 min');
  await expect(hist.first()).toContainText('15 min');
  await expect(hist.first()).toContainText('1,1 km · Cantina São José');
  await expect(hist.nth(1)).toContainText('mesmo sítio');
  await expect(vs).toContainText('Ausência responsável');
  await expect(vs.locator('.tile').filter({ hasText: 'Com venda' }).locator('.v')).toHaveText('1');
  await expect(vs.locator('.tile').filter({ hasText: 'Tempo no cliente' }).locator('.v')).toHaveText('12 min');

  // 5. mapa: o cliente (quadrado), o sítio de cada visita (verde com venda) e o percurso de cada dia
  await dlg.getByRole('tab', { name: 'Mapa' }).click();
  const mp = dlg.locator('#cf-p-mapa');
  await expect(mp.locator('svg.cf-mapa')).toBeVisible();
  await expect(mp.locator('rect.cf-mapa-cliente')).toHaveCount(1);
  await expect(mp.locator('circle.cf-mapa-venda')).toHaveCount(1);
  await expect(mp.locator('circle.cf-mapa-visita')).toHaveCount(1);
  await expect(mp.locator('.cf-dias a').first()).toHaveAttribute('href', `#geo-terreno?data=2026-10-20&rota=${encodeURIComponent(cli.rota)}`);

  await dlg.getByRole('button', { name: 'Fechar' }).click();
  await expect(dlg).toHaveCount(0);
  expect(erros).toEqual([]);
});

test('percurso do dia pedido no endereço do portal: só o dia e a rota, com formato válido, seguem para o mapa', async ({ page }) => {
  await abrir(page, '#clientes');
  const r = await page.evaluate(async () => {
    const { quadroParametros } = await import('/reports/js/paginas/geo.js');
    return [
      quadroParametros('#geo-terreno?data=2026-10-20&rota=1001'),
      quadroParametros('#geo-terreno?data=20-10-2026&rota=1001;x&outra=1'),
      quadroParametros('#geo-terreno'),
    ];
  });
  expect(r).toEqual(['?data=2026-10-20&rota=1001', '', '']);
});

test('ficha do cliente: sem OLAP, mostra o aviso e continua com os dados da carteira, vendas e pagamentos', async ({ page }) => {
  const { dlg, erros } = await abrirFicha(page, (r) => r.fulfill({ status: 503, json: { ok: false, erro: 'O servidor OLAP não está configurado.' } }));
  const dados = dlg.locator('#cf-p-dados');
  await expect(dados.locator('.cf-erro')).toContainText('O servidor OLAP não está configurado.');
  await expect(dados).toContainText(cli.codigo);
  await dlg.getByRole('tab', { name: 'Visitas e tempos' }).click();
  await expect(dlg.locator('#cf-p-visitas .cf-erro')).toContainText('O servidor OLAP não está configurado.');
  await dlg.getByRole('tab', { name: 'Mapa' }).click();
  await expect(dlg.locator('#cf-p-mapa .cf-erro')).toContainText('O servidor OLAP não está configurado.');
  await dlg.getByRole('tab', { name: 'Vendas' }).click();
  await expect(dlg.locator('#cf-p-vendas .cf-tab tbody tr')).toHaveCount(2);
  expect(erros).toEqual([]);
});
