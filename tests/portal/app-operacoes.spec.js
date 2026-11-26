// App de operações «RTM Operações» no telemóvel (operacoes/index.html), com a API simulada: o vendedor regista as
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
  // ecrã simples: o botão grande lê o QR; sem câmara, escreve-se o número (recolhido)
  await expect(page.getByRole('button', { name: 'Ler o QR da PO' })).toBeVisible();
  await page.getByText('Sem câmara? Escrever o número da PO').click();
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

test('operações: o vendedor vê as suas PO com o percurso e pede mercadoria extra com o stock do armazém', async ({ page }) => {
  const pedidos = [];
  const lista = [
    {
      numero: 'PO-2026-000431',
      rota: '1007',
      estado: 'emitida',
      origem: { tipo: 'conciliacao' },
      precisa_aprovacao: true,
      pronta_carga: true,
      emitida_por: 'automatico',
      emitida_em: '2026-11-23 15:58:00',
      aprovada_por: 'automatico',
      aprovada_em: '2026-11-23 15:58:00',
      criado_em: '2026-11-23 15:58:00',
      linhas: [{ codigo: '10000332', artigo: 'Fraldas Naval Medium', quantidade: 10 }],
    },
    {
      numero: 'PO-2026-000430',
      rota: '1007',
      estado: 'rascunho',
      versao: 2,
      origem: { tipo: 'manual' },
      precisa_aprovacao: true,
      criado_em: '2026-11-23 15:00:00',
      linhas: [{ codigo: '10000292', artigo: 'Sabão verde', quantidade: 5 }],
    },
  ];
  await page.route('**/v1/requisicoes**', (r) => {
    const u = r.request().url();
    if (r.request().method() === 'POST') {
      const p = JSON.parse(r.request().postData());
      pedidos.push(p);
      return r.fulfill({
        json: { ok: true, requisicao: { numero: p.numero || 'PO-2026-000432', versao: 1, estado: p.accao === 'emitir' ? 'emitida' : 'rascunho' } },
      });
    }
    if (u.includes('catalogo=1'))
      return r.fulfill({
        json: {
          ok: true,
          artigos: [
            { codigo: '10000386', artigo: 'Sabão 6 barras Extra', unidade: 'UN', disponivel: 240 },
            { codigo: '10000385', artigo: 'Soap brown Extra', unidade: 'UN', disponivel: 120 },
          ],
        },
      });
    return r.fulfill({ json: { ok: true, requisicoes: lista, rotas: { 1007: 'Awet' }, nomes: {} } });
  });
  const erros = await abrir(page, 'mercadoria', { ...SESSAO, login: 'pro7' });
  const reposicao = page.locator('.op-po-cartao[data-numero="PO-2026-000431"]');
  await expect(reposicao).toContainText('Aprovada');
  await expect(reposicao).toContainText('aprovada sozinha pelas regras');
  // o rascunho emite-se na app
  await page.locator('.op-po-cartao[data-numero="PO-2026-000430"]').getByRole('button', { name: 'Confirmar e emitir' }).click();
  await expect.poll(() => pedidos.length).toBe(1);
  expect(pedidos[0]).toMatchObject({ accao: 'emitir', numero: 'PO-2026-000430', versao: 2 });
  // pedido extra: procura, quantidade, aviso acima do disponível
  await page.getByRole('button', { name: 'Pedir mais mercadoria' }).click();
  await page.getByLabel('Quantidade · Sabão 6 barras Extra').fill('12');
  await page.getByLabel('Quantidade · Sabão castanho Extra').fill('300');
  await expect(page.locator('.lc-atipico-txt')).toContainText('acima do disponível (120)');
  await page.getByLabel('Procurar artigo (código ou nome)').fill('soap');
  await expect(page.getByLabel('Quantidade · Sabão 6 barras Extra')).toBeHidden();
  await page.getByRole('button', { name: 'Emitir o pedido' }).click();
  await page.locator('dialog[open]').getByRole('button', { name: 'Gravar assim mesmo' }).click();
  await expect.poll(() => pedidos.length).toBe(3);
  expect(pedidos[1]).toMatchObject({
    accao: 'criar',
    rota: '1007',
    linhas: [
      { codigo: '10000386', quantidade: 12 },
      { codigo: '10000385', quantidade: 300 },
    ],
  });
  expect(pedidos[2]).toMatchObject({ accao: 'emitir', numero: 'PO-2026-000432' });
  expect(erros).toEqual([]);
});

test('operações: notas com imagem e botões − e +; o pedido começa-se durante o dia (rascunho) e continua-se depois', async ({ page }) => {
  await page.route('**/v1/caixa-entrega**', (r) =>
    r.fulfill({
      json: { ok: true, dia: '2026-11-24', notas: [5000, 2000, 1000], folha: 'aberta', rotas: [{ rota: '1007', promotor: 'Awet', vendido_dinheiro: 12000 }] },
    }),
  );
  const pedidos = [];
  await page.route('**/v1/requisicoes**', (r) => {
    const u = r.request().url();
    if (r.request().method() === 'POST') {
      const p = JSON.parse(r.request().postData());
      pedidos.push(p);
      return r.fulfill({
        json: { ok: true, requisicao: { numero: p.numero || 'PO-2026-000500', versao: (p.versao || 0) + 1, estado: 'rascunho', rota: '1007' } },
      });
    }
    if (u.includes('catalogo=1'))
      return r.fulfill({
        json: { ok: true, artigos: [{ codigo: '10000386', artigo: 'Sabão 6 barras Extra', disponivel: 240, tem_imagem: true }] },
      });
    return r.fulfill({
      json: {
        ok: true,
        rotas: { 1007: 'Awet' },
        nomes: {},
        requisicoes: [
          {
            numero: 'PO-2026-000499',
            rota: '1007',
            estado: 'rascunho',
            versao: 3,
            origem: { tipo: 'manual' },
            criado_em: '2026-11-24 09:00:00',
            linhas: [{ codigo: '10000386', artigo: 'Sabão 6 barras Extra', quantidade: 4 }],
          },
        ],
      },
    });
  });
  await page.route('**/v1/artigo-imagem**', (r) => r.fulfill({ path: 'assets/naval-emblema-64.png', contentType: 'image/png' }));
  const erros = await abrir(page, 'entrega,mercadoria', { ...SESSAO, login: 'pro7' });
  await expect(page.locator('.op-nota-img svg').first()).toBeVisible();
  await page.getByRole('button', { name: /Mais uma nota de 1.?000 Kz/ }).click();
  await page.getByRole('button', { name: /Mais uma nota de 1.?000 Kz/ }).click();
  await expect(page.getByLabel(/Notas de 1.?000 Kz/)).toHaveValue('2');
  await page.getByRole('button', { name: /Menos uma nota de 1.?000 Kz/ }).click();
  await expect(page.getByLabel(/Notas de 1.?000 Kz/)).toHaveValue('1');
  // pedidos: o rascunho de manhã continua-se, com as quantidades já escritas, e grava-se sem emitir
  await page.getByRole('tab', { name: 'Pedir mercadoria' }).click();
  await page.locator('.op-po-cartao[data-numero="PO-2026-000499"]').getByRole('button', { name: 'Continuar o pedido' }).click();
  const q = page.getByLabel('Quantidade · Sabão 6 barras Extra');
  await expect(q).toHaveValue('4');
  await expect(page.locator('.op-art-img')).toHaveCount(1);
  await q.fill('9');
  await page.getByRole('button', { name: 'Guardar e continuar depois' }).click();
  await expect.poll(() => pedidos.length).toBe(1);
  expect(pedidos[0]).toMatchObject({ accao: 'gravar', numero: 'PO-2026-000499', versao: 3, linhas: [{ codigo: '10000386', quantidade: 9 }] });
  expect(erros).toEqual([]);
});

test('operações: inventário do armazém — contagem às cegas, artigo fora da lista, terminar; validar com motivo ou pedir recontagem', async ({ page }) => {
  const posts = [];
  const lista = [
    {
      numero: 'INV-2026-000007',
      armazem: '1150',
      armazem_nome: 'Armazém Central',
      estado: 'por_validar',
      criado_por: 'tec',
      criado_em: '2026-11-24 08:00:00',
      contado_por: 'tec',
      resumo: { artigos: 2, contados: 2, diferencas: 1, faltas: 2, sobras: 0 },
    },
  ];
  const pode = { contar: true, validar: true, aprovar: false };
  await page.route('**/v1/inventario**', (r) => {
    const u = r.request().url();
    if (r.request().method() === 'POST') {
      const p = JSON.parse(r.request().postData());
      posts.push(p);
      if (p.accao === 'criar')
        return r.fulfill({
          json: {
            ok: true,
            pode,
            inventario: {
              numero: 'INV-2026-000008',
              armazem: '1150',
              armazem_nome: 'Armazém Central',
              estado: 'em_contagem',
              cega: true,
              recontar: [],
              linhas: [
                { codigo: 'A1', artigo: 'Fraldas M', contado: null },
                { codigo: 'B2', artigo: 'Sabão verde', contado: null },
              ],
            },
          },
        });
      return r.fulfill({
        json: { ok: true, pode, inventario: { numero: p.numero, estado: p.accao === 'terminar' ? 'por_validar' : 'em_contagem', linhas: [] } },
      });
    }
    if (u.includes('armazens=1')) return r.fulfill({ json: { ok: true, armazens: [{ codigo: '1150', nome: 'Armazém Central', artigos: 2 }] } });
    if (u.includes('numero=INV-2026-000007'))
      return r.fulfill({
        json: {
          ok: true,
          pode,
          motivos: { avaria: 'Produto danificado', outro: 'Outro (explicar na nota)' },
          inventario: {
            numero: 'INV-2026-000007',
            armazem_nome: 'Armazém Central',
            estado: 'por_validar',
            contado_por: 'tec',
            cega: false,
            resumo: { artigos: 2, diferencas: 1, faltas: 2, sobras: 0 },
            linhas: [
              { codigo: 'A1', artigo: 'Fraldas M', sistema: 100, contado: 98, diferenca: -2 },
              { codigo: 'B2', artigo: 'Sabão verde', sistema: 40, contado: 40, diferenca: 0 },
            ],
          },
        },
      });
    return r.fulfill({ json: { ok: true, pode, eu: 'ctrl', motivos: {}, lista } });
  });
  const erros = await abrir(page, 'inventario', { ...SESSAO, login: 'ctrl' });
  // nova contagem: escolher o armazém e contar às cegas (sem o stock do sistema)
  await page.getByRole('button', { name: 'Escolher o armazém' }).click();
  await page.getByRole('button', { name: 'Começar a contagem' }).click();
  await expect(page.locator('.op-inventario h2')).toContainText('INV-2026-000008');
  await expect(page.locator('.op-inventario')).not.toContainText('Sistema');
  await page.getByLabel('Fraldas M', { exact: true }).fill('98');
  await page.getByRole('button', { name: 'Mais um: Sabão verde' }).click();
  // artigo encontrado no armazém que não está na lista
  await page.getByText('Juntar um artigo que não está na lista').click();
  await page.getByLabel('Código do artigo').fill('Z9');
  await page.getByLabel('Nome do artigo').fill('Detergente');
  await page.getByRole('button', { name: 'Juntar', exact: true }).click();
  await page.getByLabel('Detergente', { exact: true }).fill('5');
  await page.getByRole('button', { name: 'Terminar a contagem' }).click();
  await expect.poll(() => posts.filter((p) => p.accao === 'terminar').length).toBe(1);
  expect(posts.find((p) => p.accao === 'terminar')).toMatchObject({
    numero: 'INV-2026-000008',
    contagem: { A1: 98, B2: 1, Z9: 5 },
    extra: { Z9: 'Detergente' },
  });
  // validar (o controlo de stock): o motivo da diferença ou a recontagem do artigo
  await page.locator('.op-inv[data-numero="INV-2026-000007"]').getByRole('button', { name: 'Validar' }).click();
  await expect(page.locator('.op-inv-dif')).toContainText('-2');
  await page.getByLabel('Pedir recontagem deste artigo').check();
  await page.getByRole('button', { name: 'Pedir a recontagem de 1 artigo(s)' }).click();
  await expect.poll(() => posts.filter((p) => p.accao === 'validar').length).toBe(1);
  expect(posts.find((p) => p.accao === 'validar')).toMatchObject({ numero: 'INV-2026-000007', recontar: ['A1'] });
  expect(erros).toEqual([]);
});
