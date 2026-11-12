// Páginas de stock: data = fim do período geral; rotas, armazéns e artigo pelos filtros gerais (sem filtros
// próprios na página); um só pedido por data; erro da base de dados mostrado, nunca dados antigos.
import { test, expect } from '@playwright/test';
import { abrir } from './dados.js';

const linha = (data, rota, codigo, produto, q) => ({ codigo, produto, artigo: produto, armazem: 'Armazém ' + rota + ' - FHR PIV', quantidade: q, data, rota });
function stockDe(data) {
  const car = (rota, itens) => ({
    chave: data + '|' + rota,
    data,
    rota,
    promotor: 'Promotor ' + rota,
    carrinha: 'Carrinha ' + rota,
    viatura: '—',
    matricula: '—',
    motorista: '—',
    armazem: 'Armazém ' + rota + ' - FHR PIV',
    itens,
  });
  const c1 = [linha(data, '1001', '10000009', 'FRALDAS NAVAL MEDIUM', 12), linha(data, '1001', '10000333', 'SABÃO VERDE CONFIANÇA', 0)];
  const c2 = [linha(data, '1002', '10000010', 'FRALDAS NAVAL LARGE', 7), linha(data, '1002', '10000333', 'SABÃO VERDE CONFIANÇA', 25)];
  const arm = (cod, nome, centro, codigo, produto, q) => ({ data, cod_armazem: cod, armazem: nome, centro, codigo, produto, artigo: produto, quantidade: q });
  const la = [
    arm('1150', 'RTM REFITEC/FOODTEC', 'FOODTEC', '20000001', 'ARROZ 25KG', 300),
    arm('1151', 'HIGITEC PIV - NON FOOD', 'HIGITEC', '10000009', 'FRALDAS NAVAL MEDIUM', 140),
  ];
  return {
    origem: 'SQL Server (dbo.GET_STOCK)',
    data,
    datas_disponiveis: [data],
    ultima_data: data,
    dados_ate: data,
    carrinhas: [car('1001', c1), car('1002', c2)],
    armazens: [
      { data, cod_armazem: '1150', armazem: 'RTM REFITEC/FOODTEC', centro: 'FOODTEC', total_quantidade: 300 },
      { data, cod_armazem: '1151', armazem: 'HIGITEC PIV - NON FOOD', centro: 'HIGITEC', total_quantidade: 140 },
    ],
    linhas_carrinhas: [...c1, ...c2],
    linhas_armazens: la,
  };
}
async function simularStock(page) {
  const pedidos = [];
  await page.route('**/v1/stock**', (r) => {
    const u = new URL(r.request().url());
    pedidos.push(u.searchParams.get('data') + '|' + u.searchParams.get('armazem'));
    const d = u.searchParams.get('data');
    return r.fulfill({ json: stockDe(d.slice(0, 4) + '-' + d.slice(4, 6) + '-' + d.slice(6, 8)) });
  });
  return pedidos;
}

test('stock por carrinha: data do fim do período, só filtros gerais, sem linhas a zero', async ({ page }) => {
  const pedidos = await simularStock(page);
  const erros = await abrir(page, '#stock-carrinha');
  await expect(page.locator('section[id^="s-sc-car-"]')).toHaveCount(2);
  expect(pedidos).toEqual(['20261026|todos']); // um pedido, data = fim do período (mês até hoje), todas as carrinhas
  await expect(page.locator('#stock-btn-consultar, #stock-inp-data, #stock-sel-armazem')).toHaveCount(0); // sem filtros próprios
  await expect(page.locator('#filtros2')).toBeVisible();
  const qtds = await page.locator('section[id^="s-sc-car-"] tbody tr td:last-child').allTextContents();
  expect(qtds.length).toBe(3);
  expect(qtds.every((q) => q.trim() !== '0')).toBe(true);
  await expect(page.locator('#page')).toContainText('SABÃO VERDE CONFIANÇA');
  expect(erros).toEqual([]);
});

test('stock por carrinha: filtro geral de rotas e de artigo, sem nova consulta à base de dados', async ({ page }) => {
  const pedidos = await simularStock(page);
  await abrir(page, '#stock-carrinha?rota=1002');
  await expect(page.locator('section[id^="s-sc-car-"]')).toHaveCount(1);
  await expect(page.locator('#s-sc-car-1002')).toBeVisible();
  await page.evaluate(() => (location.hash = '#stock-carrinha?artigo=fraldas'));
  // os filtros gerais somam-se: rota 1002 (mantida) e artigo «fraldas»
  await expect(page.locator('section[id^="s-sc-car-"] tbody tr')).toHaveCount(1);
  await expect(page.locator('#s-sc-car-1002')).toContainText('FRALDAS NAVAL LARGE');
  expect(pedidos).toHaveLength(1);
});

test('stock por armazém: filtro geral de armazéns', async ({ page }) => {
  await simularStock(page);
  await abrir(page, '#stock-armazem?armazem=1151');
  const linhas = page.locator('#s-sa-detalhe tbody tr');
  await expect(linhas).toHaveCount(1);
  await expect(linhas.first()).toContainText('HIGITEC');
  await expect(page.locator('#filtros2')).toContainText('HIGITEC PIV - NON FOOD');
});

test('erro da base de dados: mostra o motivo e permite tentar de novo; nunca dados antigos', async ({ page }) => {
  let n = 0;
  await page.route('**/v1/stock**', (r) => {
    n++;
    return r.fulfill({ status: 503, json: { ok: false, erro: 'A base de dados demorou demasiado a calcular o stock.' } });
  });
  await abrir(page, '#stock-carrinha');
  await expect(page.locator('#page')).toContainText('Não foi possível calcular o stock');
  await expect(page.locator('#page')).toContainText('A base de dados demorou demasiado');
  await expect(page.locator('section[id^="s-sc-car-"]')).toHaveCount(0);
  await page.click('#page button:has-text("Tentar de novo")');
  await expect.poll(() => n).toBe(2);
});

test('stock por carrinha: mostra de que armazém vem o stock; SADM confirma o armazém certo com um clique', async ({ page }) => {
  await page.route('**/v1/sessao*', (r) =>
    r.fulfill({ json: { login: 'henrique', perfil: 'SADM', csrf: 'x', permissoes: { 'stock-carrinha': 'A', inicio: 'A' } } }),
  );
  let enviado = null;
  let confirmado = false;
  await page.route('**/v1/stock**', (r) => {
    if (r.request().method() === 'POST') {
      enviado = r.request().postDataJSON();
      confirmado = true;
      return r.fulfill({ json: { ok: true, confirmadas: { 1002: { codigo: '10001002' } } } });
    }
    const d = stockDe('2026-10-26');
    d.carrinhas[0].confirmado = true;
    d.carrinhas[0].armazens_origem = [{ codigo: '10001001', nome: 'Armazém 1001 - FHR PIV', como: 'confirmado' }];
    d.carrinhas[1].confirmado = confirmado;
    d.carrinhas[1].armazens_origem = confirmado
      ? [{ codigo: '10001002', nome: 'Armazém 1002 - FHR PIV', como: 'confirmado' }]
      : [
          { codigo: '10001002', nome: 'Armazém 1002 - FHR PIV', como: 'codigo' },
          { codigo: 'MR02', nome: 'Depósito 1002 antigo', como: 'nome' },
        ];
    return r.fulfill({ json: d });
  });
  await abrir(page, '#stock-carrinha');
  await expect(page.locator('#s-sc-car-1001 .stock-origem')).toContainText('Armazém confirmado');
  await expect(page.locator('#s-sc-car-1001 .stock-origem button')).toHaveCount(0);
  const o2 = page.locator('#s-sc-car-1002 .stock-origem');
  await expect(o2).toContainText('Armazém por confirmar');
  await expect(o2).toContainText('MR02 · Depósito 1002 antigo (pelo nome)');
  await expect(o2).toContainText('junta 2 armazéns');
  await o2.locator('.stock-origem-arm', { hasText: '10001002' }).locator('button').click();
  await expect.poll(() => enviado).toMatchObject({ accao: 'confirmar_carrinha', rota: '1002', codigo: '10001002', csrf: 'x' });
  await expect(page.locator('#s-sc-car-1002 .stock-origem')).toContainText('Armazém confirmado');
});

test('stock por carrinha: coluna da requisição ao armazém — âmbar a carregar, azul depois de confirmar a carga', async ({ page }) => {
  await simularStock(page);
  let estado = 'emitida';
  const pedidos = [];
  const req = () => ({
    numero: 'RQ-2026-000007',
    estado,
    emitida_em: '2026-10-26 17:00:00',
    emitida_por: 'pro',
    confirmado_em: estado === 'carregada' ? '2026-10-26 18:00:00' : null,
    confirmado_por: estado === 'carregada' ? 'log' : null,
    linhas: [
      { codigo: '10000009', artigo: 'FRALDAS NAVAL MEDIUM', quantidade: 8, entregue: estado === 'carregada' ? 8 : undefined },
      { codigo: '10000777', artigo: 'DETERGENTE NOVO', quantidade: 3, entregue: estado === 'carregada' ? 2 : undefined },
    ],
  });
  await page.route('**/v1/requisicoes**', (r) => {
    if (r.request().method() === 'POST') {
      pedidos.push(JSON.parse(r.request().postData()));
      estado = 'carregada';
      return r.fulfill({ json: { ok: true, requisicao: req() } });
    }
    return r.fulfill({ json: { ok: true, gere: true, eu: 'log', rotas: { 1001: [req()] } } });
  });
  const erros = await abrir(page, '#stock-carrinha');
  const sec = page.locator('#s-sc-car-1001');
  await expect(sec.locator('th', { hasText: 'Requisição ao armazém' })).toBeVisible();
  await expect(sec.locator('.req-a-carregar')).toHaveCount(2);
  await expect(sec.locator('tr', { hasText: 'DETERGENTE NOVO' })).toContainText('3'); // requisitado, ainda não está na carrinha
  await expect(sec.locator('.req-linha')).toContainText('A carregar · RQ-2026-000007');
  await expect(page.locator('#s-sc-car-1002 th', { hasText: 'Requisição ao armazém' })).toHaveCount(0);
  await sec.getByRole('button', { name: 'Confirmar carga' }).click();
  const dlg = page.locator('dialog[open]');
  await dlg.getByLabel('Entregue · DETERGENTE NOVO').fill('2');
  await dlg.getByRole('button', { name: 'Confirmar carga' }).click();
  await expect(sec.locator('.req-carregado')).toHaveCount(2);
  await expect(sec.locator('.req-a-carregar')).toHaveCount(0);
  await expect(sec.locator('.req-linha')).toContainText('Carregado · RQ-2026-000007');
  expect(pedidos[0]).toMatchObject({ accao: 'confirmar_carga', numero: 'RQ-2026-000007', entregue: { 10000009: 8, 10000777: 2 } });
  expect(erros).toEqual([]);
});
