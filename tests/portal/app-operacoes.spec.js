// App de operações «Naval Operações» no telemóvel (operacoes/index.html), com a API simulada: o vendedor regista as
// notas que entrega, a Logística conta a carrinha às cegas e o armazém abre a PO pelo número (ou QR) e confere a carga.
import { test, expect, devices } from '@playwright/test';

test.use({ ...devices['Pixel 7'] });

const SESSAO = { login: 'pro4', nome: 'Vendedor 4', perfil: 'PRO', permissoes: {}, idioma: 'pt', csrf: 'x' };

/** Abre a app com as tarefas que o servidor daria a esta conta (o PHP põe-nas no <meta name="naval-tarefas">). */
async function abrir(page, tarefas, sessao = SESSAO) {
  await page.route('**/operacoes/index.html', async (r) => {
    const resp = await r.fetch();
    const html = (await resp.text()).replace('<head>', '<head><meta name="naval-tarefas" content="' + tarefas + '">');
    return r.fulfill({ response: resp, body: html, headers: { ...resp.headers(), 'content-type': 'text/html; charset=utf-8' } });
  });
  await page.route('**/v1/sessao**', (r) => r.fulfill({ json: sessao }));
  const erros = [];
  page.on('pageerror', (e) => erros.push(e.message));
  await page.goto('/operacoes/index.html');
  return erros;
}

test('operações: o vendedor regista as notas que entrega e envia à tesouraria', async ({ page }) => {
  const pedidos = [];
  let declaracao = null;
  await page.route('**/v1/caixa-entrega**', (r) => {
    if (r.request().method() === 'POST') {
      const p = JSON.parse(r.request().postData());
      pedidos.push(p);
      declaracao = { total: 53150, em: '2026-11-22 16:05:00', notas: p.notas };
      return r.fulfill({ json: { ok: true, declaracao } });
    }
    return r.fulfill({
      json: {
        ok: true,
        dia: '2026-11-22',
        notas: [5000, 2000, 1000, 500, 200],
        folha: 'aberta',
        rotas: [{ rota: '1004', promotor: 'Vendedor 4', vendido_dinheiro: 53150, declaracao, contada: false }],
      },
    });
  });
  const erros = await abrir(page, 'entrega');
  await expect(page.locator('#op-h1')).toHaveText('Entregar dinheiro');
  await expect(page.locator('#op-tarefas')).toBeHidden();
  const k = page.locator('.op-entrega');
  await expect(k).toContainText('Vendido em dinheiro hoje (Pinguim BO): 53');
  await page.getByLabel(/Notas de 5.?000 Kz/).fill('10');
  await page.keyboard.press('Enter');
  await expect(page.getByLabel(/Notas de 2.?000 Kz/)).toBeFocused();
  await page.getByLabel(/Notas de 1.?000 Kz/).fill('3');
  await page.getByLabel('Moedas (valor em Kz)').fill('150');
  await expect(k.locator('.op-total')).toContainText('53');
  await expect(k).toContainText('Igual ao vendido em dinheiro');
  await page.getByRole('button', { name: 'Enviar à tesouraria' }).click();
  await expect.poll(() => pedidos.length).toBe(1);
  expect(pedidos[0]).toMatchObject({ accao: 'declarar', rota: '1004', notas: { 5000: 10, 1000: 3, moedas: 150 } });
  await expect(page.locator('.op-entrega')).toContainText('Enviado à tesouraria às 16:05');
  expect(erros).toEqual([]);
});

test('operações: a Logística conta a carrinha às cegas, linha a linha, e grava', async ({ page }) => {
  const pedidos = [];
  await page.route('**/v1/conciliacao-carrinha**', (r) => {
    if (r.request().method() === 'POST') {
      pedidos.push(JSON.parse(r.request().postData()));
      return r.fulfill({ json: { ok: true, resumo: { diferencas: 0 } } });
    }
    return r.fulfill({
      json: {
        ok: true,
        rotas: [
          { rota: '1002', promotor: 'Cristina', resumo: { estado: 'aprovada', diferencas: 0 }, linhas: [] },
          {
            rota: '1004',
            promotor: 'Vendedor 4',
            resumo: { estado: 'por_contar', diferencas: 0 },
            linhas: [
              { codigo: 'A1', artigo: 'Fraldas M', unidade: 'UN', contado: null },
              { codigo: 'B2', artigo: 'Sabão', unidade: 'UN', contado: null },
            ],
          },
        ],
      },
    });
  });
  const erros = await abrir(page, 'contagem,carga', { ...SESSAO, login: 'log', perfil: 'LOG' });
  await expect(page.locator('#op-tarefas button')).toHaveText(['Contar a carrinha', 'Carregar PO']);
  // a que pede acção primeiro
  await expect(page.locator('.op-carrinha').first()).toHaveAttribute('data-rota', '1004');
  await page.getByRole('button', { name: 'Contar esta carrinha' }).click();
  await expect(page.getByLabel('Fraldas M')).toBeFocused();
  await page.keyboard.type('3');
  await page.keyboard.press('Enter');
  await expect(page.getByLabel('Sabão')).toBeFocused();
  await page.keyboard.type('2');
  await page.keyboard.press('Enter');
  await expect(page.getByRole('button', { name: 'Gravar contagem' })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect.poll(() => pedidos.length).toBe(1);
  expect(pedidos[0]).toMatchObject({ accao: 'contar', rota: '1004', contagem: { A1: 3, B2: 2 } });
  expect(erros).toEqual([]);
});

test('operações: o armazém abre a PO pelo número e confere a carga, com o motivo de cada diferença', async ({ page }) => {
  const pedidos = [];
  const po = {
    numero: 'PO-2026-000412',
    rota: '1008',
    promotor: 'Daniel M.',
    estado: 'emitida',
    pronta_carga: true,
    emitida_por: 'sup',
    linhas: [
      { codigo: '10000332', artigo: 'FRALDAS NAVAL MEDIUM', quantidade: 400 },
      { codigo: '10000292', artigo: 'SABÃO VERDE', quantidade: 30 },
    ],
  };
  await page.route('**/v1/requisicoes**', (r) => {
    if (r.request().method() === 'POST') {
      pedidos.push(JSON.parse(r.request().postData()));
      return r.fulfill({ json: { ok: true, requisicao: { ...po, estado: 'carregada' } } });
    }
    return r.fulfill({ json: { ok: true, requisicao: po } });
  });
  const erros = await abrir(page, 'carga', { ...SESSAO, login: 'fiel', perfil: 'LOG' });
  await page.getByLabel('Número da PO').fill('po-2026-000412');
  await page.getByRole('button', { name: 'Abrir' }).click();
  const k = page.locator('.op-conferir');
  await expect(k).toContainText('PO-2026-000412');
  const sabao = page.getByLabel('Carregado · SABÃO VERDE');
  await sabao.fill('28');
  await expect(k.locator('tr', { has: sabao }).locator('.lc-dif')).toHaveText('−2');
  await page.getByRole('button', { name: 'Confirmar a carga' }).click();
  await expect(k.locator('.op-erro')).toContainText('Indique o motivo');
  await page.getByLabel('Motivo · SABÃO VERDE').selectOption('falta_armazem');
  await page.getByRole('button', { name: 'Confirmar a carga' }).click();
  await expect.poll(() => pedidos.length).toBe(1);
  expect(pedidos[0]).toMatchObject({
    accao: 'confirmar_carga',
    numero: 'PO-2026-000412',
    entregue: { 10000332: 400, 10000292: 28 },
    motivos: { 10000292: { codigo: 'falta_armazem' } },
  });
  expect(erros).toEqual([]);
});
