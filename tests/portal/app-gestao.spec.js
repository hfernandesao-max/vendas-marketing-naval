// App de gestão «Naval Gestão» no telemóvel (gestao/index.html), com a API simulada: período Hoje · Semana · Mês,
// gráficos, «Precisa de atenção», classificação e detalhe da rota, alertas, aviso de dados incompletos e tema escuro.
import { test, expect, devices } from '@playwright/test';

const HOJE = '2026-11-06';
const dia = (n) => {
  const d = new Date(HOJE + 'T12:00:00Z');
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
};
// série desde 01-10-2026: domingos sem vendas, hoje a meio do dia
const serie = [];
for (let d = '2026-10-01', i = 0; d <= HOJE; i++, d = new Date(Date.parse(d + 'T12:00:00Z') + 864e5).toISOString().slice(0, 10)) {
  const ds = new Date(d + 'T12:00:00Z').getUTCDay();
  serie.push({ d, t: ds === 0 ? 0 : d === HOJE ? 612000 : 1100000 + ((i * 137) % 9) * 60000 });
}
const rota = (r, promotor, mes, ant, hoje, seq) => ({
  rota: r,
  promotor,
  hoje,
  previsto_hoje: hoje ? Math.round(hoje * 1.9) : 0,
  ontem: Math.round(mes / 5),
  habitual_ontem: Math.round(mes / 5.5),
  mes,
  mes_anterior: ant,
  variacao_mes: ant ? Math.round((mes / ant - 1) * 1000) / 1000 : null,
  sequencia: seq,
  serie: Array.from({ length: 14 }, (_, i) => (i % 7 === 2 ? 0 : Math.round(mes / 5 + i * 1500))),
});
const IND = {
  ok: true,
  hora: HOJE + ' 14:35',
  hoje: { dia: HOJE, total: 612000, documentos: 41, previsto: 1437381, progresso: 0.426 },
  ontem: { dia: dia(-1), total: 1340000, documentos: 88, habitual: 1210000, variacao: 0.107 },
  semana: { de: '2026-11-02', total: 5180000, anterior: 5420000, variacao: -0.044 },
  mes: { de: '2026-11-01', total: 5180000, anterior: 4860000, variacao: 0.066 },
  rotas: [
    rota('1011', 'Robel Tesfamichael', 1420000, 1210000, 160000, 4),
    rota('1002', 'Cristina Sachuma', 1130000, 1240000, 140000, 0),
    rota('1001', 'Andrea Santos', 980000, 900000, 0, 0),
    rota('1005', 'Mateus Kiala', 870000, 860000, 120000, 0),
  ],
  serie,
  ultima_venda: HOJE,
  compras: {
    hoje: { compras: 38, valor_medio: 16105, artigos_por_compra: 3.1 },
    mes: { compras: 301, valor_medio: 17209, artigos_por_compra: 3.4 },
    mes_anterior: { compras: 288, valor_medio: 16875, artigos_por_compra: 3.2 },
  },
  alertas: {
    critico: 2,
    aviso: 1,
    info: 3,
    principais: [
      { gravidade: 'critico', tipo: 'rota-sem-vendas', p: { nome: '1001 · Andrea Santos', dia: HOJE, com: 6, n: 6, ds: 5, esperado: 160000 } },
      {
        gravidade: 'critico',
        tipo: 'queda-dia',
        p: { nome: '1002 · Cristina Sachuma', dia: dia(-1), var: -0.41, real: 140000, esperado: 238000, n: 6, ds: 4 },
      },
      { gravidade: 'aviso', tipo: 'cliente-parado', p: { nome: 'Cantina Kilamba', rota: '1005', sem: 19, habitual: 6, n: 11, t: 420000, ultimo: dia(-19) } },
    ],
  },
};
const SESSAO = { login: 'dir', nome: 'Direcção', perfil: 'DIR', permissoes: { 'app-gestao': true, assistente: true, alertas: true }, idioma: 'pt', csrf: 'x' };

async function abrirApp(page, { ind = IND, fonte = { ok: true } } = {}) {
  await page.route('**/v1/sessao**', (r) => r.fulfill({ json: SESSAO }));
  await page.route('**/v1/indicadores**', (r) => r.fulfill({ json: ind }));
  await page.route('**/v1/vendas/estado**', (r) => r.fulfill({ json: fonte }));
  const erros = [];
  page.on('pageerror', (e) => erros.push(e.message));
  await page.goto('/gestao/index.html');
  await expect(page.locator('.ag-heroi')).toBeVisible();
  return erros;
}
const semCorte = (page) =>
  page.evaluate(() => {
    const larg = document.documentElement.clientWidth;
    return [...document.querySelectorAll('#ag-conteudo *')]
      .filter((e) => {
        const r = e.getBoundingClientRect();
        return r.width && (r.right > larg + 1 || r.left < -1);
      })
      .map((e) => e.className || e.tagName);
  });

// telemóvel (sem mudar de navegador: o perfil do iPhone escolheria o WebKit)
const IPHONE = { ...devices['iPhone 13'] };
delete IPHONE.defaultBrowserType;
test.use(IPHONE);

test('app de gestão: Hoje com período, gráficos, atenção, indicadores e melhores rotas', async ({ page }) => {
  const erros = await abrirApp(page);
  await expect(page.locator('#ag-h1')).toHaveText('Hoje');
  await expect(page.locator('#ag-estado')).toContainText('Actualizado às 14:35');
  const heroi = page.locator('.ag-heroi');
  await expect(heroi).toContainText('Vendas de hoje');
  await expect(heroi).toContainText('612 000 Kz');
  await expect(heroi).toContainText('43% da previsão do dia (1 437 381 Kz)');
  await expect(heroi.locator('rect.ag-b')).toHaveCount(14);
  await expect(heroi.locator('rect.ag-b.on')).toHaveCount(1);
  // semana: barras desta semana sobre a semana passada, variação com seta
  await page.locator('.ag-seg button', { hasText: 'Semana' }).click();
  await expect(heroi).toContainText('5 180 000 Kz');
  await expect(heroi.locator('.ag-var.desce')).toHaveText('▼ −4%');
  await expect(heroi.locator('rect.ag-fant')).toHaveCount(7);
  await expect(heroi.locator('.ag-eixo span').first()).toHaveText('seg');
  // mês: acumulado deste mês e do mês passado
  await page.locator('.ag-seg button', { hasText: 'Mês' }).click();
  await expect(heroi.locator('.ag-var.sobe')).toHaveText('▲ +7%');
  await expect(heroi.locator('polyline.ag-l')).toHaveCount(1);
  await expect(heroi.locator('polyline.ag-l-ant')).toHaveCount(1);
  // precisa de atenção: 3 alertas com atalho
  await expect(page.locator('.ag-sec', { hasText: 'Precisa de atenção' })).toBeVisible();
  await expect(page.locator('button.ag-al')).toHaveCount(3);
  await expect(page.locator('.ag-kpi').first()).toContainText('1,3 M Kz');
  await expect(page.locator('.ag-kpi', { hasText: 'Compras de clientes' })).toContainText('301');
  await expect(page.locator('.ag-kpi', { hasText: 'Valor médio por compra' })).toContainText('17 mil Kz');
  // contador dos alertas na barra de separadores
  await expect(page.locator('.ag-separadores .ag-badge')).toHaveText('3');
  expect(await semCorte(page)).toEqual([]);
  expect(erros).toEqual([]);
});

test('app de gestão: classificação das rotas e detalhe numa folha que sobe do fundo', async ({ page }) => {
  const erros = await abrirApp(page);
  await page.locator('.ag-separadores button', { hasText: 'Rotas' }).click();
  await expect(page.locator('#ag-h1')).toHaveText('Rotas');
  await expect(page.locator('.ag-resumo')).toHaveText('4 rotas · 3 a crescer · 1 a cair');
  await expect(page.locator('.ag-rota').first()).toContainText('1011 · Robel Tesfamichael');
  await expect(page.locator('.ag-rota').first()).toContainText('4 dias acima do habitual');
  await page.locator('.ag-seg button', { hasText: /^Hoje$/ }).click();
  await expect(page.locator('.ag-rota').nth(2)).toContainText('1005');
  await page.locator('.ag-rota').first().click();
  const f = page.locator('#ag-folha');
  await expect(f).toBeVisible();
  await expect(f).toContainText('Rota 1011');
  await expect(f.locator('rect.ag-b')).toHaveCount(14);
  await f.getByRole('button', { name: 'Fechar' }).click();
  await expect(f).toBeHidden();
  expect(await semCorte(page)).toEqual([]);
  expect(erros).toEqual([]);
});

test('app de gestão: alertas com contagens e texto completo', async ({ page }) => {
  await abrirApp(page);
  await page.locator('button.ag-al').first().click();
  await expect(page.locator('#ag-h1')).toHaveText('Alertas');
  await expect(page.locator('.ag-conta.critico strong')).toHaveText('2');
  await expect(page.locator('article.ag-al')).toHaveCount(3);
  await expect(page.locator('article.ag-al').first()).toContainText('Confirmar se a rota saiu');
});

test('app de gestão: aviso de dados incompletos (Pinguim BO sem ligação ou dias sem vendas)', async ({ page }) => {
  await abrirApp(page, { fonte: { ok: false, erro: 'x' } });
  await expect(page.locator('.ag-faixa')).toContainText('Pinguim BO sem ligação');
  await expect(page.locator('.ag-ponto-est.erro')).toHaveCount(1);
  const vazio = {
    ...IND,
    hoje: { ...IND.hoje, total: 0, documentos: 0 },
    ontem: { ...IND.ontem, total: 0, variacao: -1 },
    ultima_venda: '2026-11-03',
  };
  await page.unrouteAll({ behavior: 'ignoreErrors' });
  await abrirApp(page, { ind: vazio });
  await expect(page.locator('.ag-faixa')).toContainText('Sem vendas registadas desde 03-11-2026');
});

test('app de gestão: tema escuro e ecrã pequeno sem nada cortado', async ({ browser }) => {
  const SE = { ...devices['iPhone SE'] };
  delete SE.defaultBrowserType;
  const ctx = await browser.newContext({ ...SE, colorScheme: 'dark' });
  const page = await ctx.newPage();
  const erros = await abrirApp(page);
  const fundo = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
  expect(fundo).toBe('rgb(7, 14, 34)');
  expect(await semCorte(page)).toEqual([]);
  if (process.env.CAPTURAS) {
    const pasta = process.env.CAPTURAS;
    await page.screenshot({ path: pasta + '/g-escuro.png', fullPage: true });
  }
  expect(erros).toEqual([]);
  await ctx.close();
});

test('app de gestão: uma versão nova no servidor aplica-se sozinha quando a app volta ao ecrã', async ({ page }) => {
  let versao = 'v2026.11.06.41';
  let aberturas = 0;
  page.on('load', () => aberturas++);
  await page.route('**/v1/sessao**', (r) => r.fulfill({ json: { ...SESSAO, versao } }));
  await page.route('**/v1/indicadores**', (r) => r.fulfill({ json: IND }));
  await page.route('**/v1/vendas/estado**', (r) => r.fulfill({ json: { ok: true } }));
  await page.goto('/gestao/index.html');
  await expect(page.locator('.ag-heroi')).toBeVisible();
  // mesma versão: não recarrega
  await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
  await page.waitForTimeout(400);
  expect(aberturas).toBe(1);
  // versão nova instalada: recarrega sozinha, sem pedir nada à pessoa
  versao = 'v2026.11.06.42';
  await Promise.all([page.waitForEvent('load'), page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')))]);
  await expect(page.locator('.ag-heroi')).toBeVisible();
  expect(aberturas).toBe(2);
});

test('app de gestão: ao vivo em Hoje; Rotas «Agora» com paradas primeiro, stock na carrinha e ficha da rota', async ({ page }) => {
  await page.clock.setFixedTime(new Date('2026-11-06T14:40:00+01:00'));
  const sess = { ...SESSAO, permissoes: { ...SESSAO.permissoes, inicio: 'V', 'conciliacao-carrinha': 'V', 'stock-carrinha': 'V' } };
  const v = (rota, hora, total, cliente, nc) => ({ rota, hora, total, cliente, nc: !!nc, data: HOJE, documento: 'FR ' + rota + hora });
  let pedidos = 0;
  await page.route('**/v1/sessao**', (r) => r.fulfill({ json: sess }));
  await page.route('**/v1/indicadores**', (r) => r.fulfill({ json: IND }));
  // v1/vendas devolve a LISTA de documentos (é assim no servidor: em 12-11-2026 a app esperava {vendas} e falhava)
  await page.route(
    (u) => u.pathname.endsWith('/v1/vendas'),
    (r) => {
      pedidos++;
      return r.fulfill({
        json: [
          v('1011', '14:31', 90000, 'Cantina Kilamba'),
          v('1011', '13:05', 70000, 'Loja Sol'),
          v('1002', '12:10', 140000, 'Mercado 1'),
          v('1005', '14:02', 120000, 'Cantina Bela'),
          v('1005', '14:20', -10000, 'Cantina Bela', true),
        ],
      });
    },
  );
  await page.route('**/v1/vendas/estado**', (r) => r.fulfill({ json: { ok: true } }));
  await page.route('**/v1/painel-carrinha**', (r) =>
    r.fulfill({
      json: {
        ok: true,
        rotas: [
          { rota: '1011', fecho: 'por_contar' },
          { rota: '1002', fecho: 'fechada_auto' },
          { rota: '1005', fecho: 'por_aprovar' },
        ],
      },
    }),
  );
  await page.route('**/v1/stock**', (r) =>
    r.fulfill({
      json: {
        carrinhas: [
          {
            rota: '1011',
            armazem: 'Armazém 1011 - FHR PIV',
            total_quantidade: 85,
            n_artigos: 2,
            itens: [
              { codigo: '10000009', produto: 'FRALDAS DE BEBÉ NAVAL MEDIUM 4 X 48', quantidade: 65 },
              { codigo: '10000386', produto: 'SABÃO VERDE 6 BARRAS', quantidade: 20 },
            ],
          },
        ],
        armazens: [],
      },
    }),
  );
  const erros = [];
  page.on('pageerror', (e) => erros.push(e.message));
  await page.goto('/gestao/index.html');
  // Hoje: cartão ao vivo no topo
  const vivo = page.locator('.ag-vivo');
  await expect(vivo).toContainText('Ao vivo');
  await expect(vivo).toContainText('410 000 Kz');
  await expect(vivo.locator('.ag-mini').first()).toContainText('há 9 min');
  // Rotas abre em «Agora»: 1002 parada (desde as 12:10) e 1001 sem vendas primeiro
  await page.locator('.ag-separadores button', { hasText: 'Rotas' }).click();
  await expect(page.locator('.ag-seg [aria-selected=true]')).toHaveText('Agora');
  const rs = page.locator('.ag-rota-ao');
  await expect(rs.first()).toContainText('1002');
  await expect(rs.first()).toContainText('Parada');
  await expect(rs.nth(1)).toContainText('1001');
  await expect(rs.nth(1)).toContainText('Sem vendas');
  await expect(page.locator('.ag-resumo').first()).toContainText('2 a pedir atenção');
  await expect(page.locator('.ag-resumo').first()).toContainText('2 carrinhas por fechar');
  await expect(rs.filter({ hasText: '1011' })).toContainText('Na carrinha: 2 artigos · 85 un.');
  // ficha da rota: stock na carrinha agora, fecho e últimas vendas
  await rs.filter({ hasText: '1011' }).click();
  const f = page.locator('#ag-folha');
  await expect(f).toBeVisible();
  await expect(f).toContainText('Stock na carrinha agora');
  await expect(f).toContainText('2 artigos · 85 unidades');
  await expect(f).toContainText('FRALDAS DE BEBÉ NAVAL MEDIUM 4 X 48');
  await expect(f).toContainText('Fecho do dia');
  await expect(f).toContainText('Por contar');
  await expect(f.locator('.ag-feed-l', { hasText: 'Cantina Kilamba' })).toHaveCount(1);
  await f.locator('button', { hasText: 'Fechar' }).click();
  // a 1005 tem nota de crédito e não tem stock no Pinguim BO
  await rs.filter({ hasText: '1005' }).click();
  await expect(f).toContainText('Sem stock na carrinha no Pinguim BO.');
  await expect(f.locator('.ag-feed-l', { hasText: 'nota de crédito' })).toHaveCount(1);
  await f.locator('button', { hasText: 'Fechar' }).click();
  expect(pedidos).toBe(1);
  expect(await semCorte(page)).toEqual([]);
  expect(erros).toEqual([]);
});

test('app de gestão: notificações — ligar com um toque, escolher o que recebe, teste e caixa de recebidas', async ({ page }) => {
  // telefone simulado: app no ecrã principal, autorização e serviço de push
  await page.addInitScript(() => {
    Object.defineProperty(navigator, 'standalone', { value: true });
    let sub = null;
    const fake = {
      endpoint: 'https://web.push.apple.com/QGuQ-teste',
      toJSON: () => ({ endpoint: 'https://web.push.apple.com/QGuQ-teste', keys: { p256dh: 'BPub', auth: 'Auth' } }),
      unsubscribe: async () => {
        sub = null;
        return true;
      },
    };
    const reg = { pushManager: { getSubscription: async () => sub, subscribe: async (o) => ((window.__chave = o.applicationServerKey.length), (sub = fake)) } };
    Object.defineProperty(navigator, 'serviceWorker', { value: { getRegistration: async () => reg, ready: Promise.resolve(reg), register: async () => reg } });
    window.PushManager = function () {};
    window.Notification = { permission: 'default', requestPermission: async () => ((window.Notification.permission = 'granted'), 'granted') };
    navigator.setAppBadge = async (n) => (window.__badge = n);
    navigator.clearAppBadge = async () => (window.__badge = 0);
  });
  const posts = [];
  let subscrito = false;
  const vista = () => ({
    ok: true,
    chave: 'BOr6ZK4mGKVqcz6m1WXbP4Fq0lwxgFC9YUAZ3GQhlF8mJx7oVEP3sU8gcZJ0Oy3l8w7m3d8Fh1sXm2qJ0vBf0aA',
    subscrito,
    equipamentos: subscrito ? 1 : 0,
    silencio: { de: '20:00', ate: '07:00' },
    tipos: [
      {
        id: 'rota_parada',
        nome: 'Rota parada',
        descricao: 'Uma rota com vendas hoje está há mais de uma hora sem vender, ou ainda não vendeu às 10:30.',
        activo: true,
      },
      { id: 'resumo_dia', nome: 'Resumo do dia', descricao: 'Às 18:30: vendas do dia, documentos e a melhor rota.', activo: true },
    ],
    caixa: [
      {
        id: 'a',
        titulo: 'Rota 1011 · Robel parada',
        corpo: 'Sem vendas há 1 h 10 min.',
        data: HOJE + ' 11:00:00',
        url: '../gestao/#rotas',
        gravidade: 'aviso',
        lida: false,
      },
      {
        id: 'b',
        titulo: 'Resumo de hoje: 1 340 000 Kz',
        corpo: '88 documentos.',
        data: '2026-11-05 18:30:00',
        url: '../gestao/#hoje',
        gravidade: 'info',
        lida: true,
      },
    ],
    nao_lidas: posts.some((p) => p.accao === 'lidas') ? 0 : 1,
  });
  await page.route('**/v1/notificacoes**', (r) => {
    if (r.request().method() === 'POST') {
      const p = r.request().postDataJSON();
      posts.push(p);
      if (p.accao === 'subscrever') subscrito = true;
      if (p.accao === 'cancelar') subscrito = false;
    }
    return r.fulfill({ json: vista() });
  });
  const erros = await abrirApp(page);
  expect(await page.evaluate(() => window.__badge)).toBe(1); // número de por ler no ícone da app
  await page.locator('.ag-separadores button', { hasText: 'Alertas' }).click();
  await expect(page.locator('.ag-notif-linha')).toContainText('Desligadas');
  await page.locator('.ag-notif-linha').click();
  const c = page.locator('#ag-folha-notif .ag-notif');
  await expect(c).toContainText('como no WhatsApp');
  await c.locator('button', { hasText: 'Ligar notificações' }).click();
  await expect.poll(() => posts.map((p) => p.accao)).toEqual(expect.arrayContaining(['subscrever', 'testar']));
  expect(posts.find((p) => p.accao === 'subscrever').subscricao.endpoint).toBe('https://web.push.apple.com/QGuQ-teste');
  expect(await page.evaluate(() => window.__chave)).toBe(65); // chave pública VAPID (ponto P-256)
  await expect(c).toContainText('Ligadas');
  await expect(c.locator('[role=switch]')).toHaveCount(3); // 2 tipos + horas de silêncio
  await c.locator('.ag-notif-tipo', { hasText: 'Resumo do dia' }).locator('[role=switch]').click();
  await expect.poll(() => posts.find((p) => p.accao === 'preferencias')).toMatchObject({ tipos: { resumo_dia: false } });
  await page.locator('#ag-folha-notif button', { hasText: 'Fechar' }).click();
  await expect(page.locator('.ag-notif-linha')).toContainText('Ligadas neste telefone');
  // caixa: a nova realçada; ao fim de uns segundos fica lida e o número no ícone volta a zero
  await expect(page.locator('.ag-msg.nova')).toContainText('Rota 1011 · Robel parada');
  await expect(page.locator('.ag-msg').first()).toHaveAttribute('href', '../gestao/#rotas');
  await expect.poll(() => posts.some((p) => p.accao === 'lidas'), { timeout: 6000 }).toBe(true);
  await expect.poll(() => page.evaluate(() => window.__badge)).toBe(0);
  // desligar neste telefone
  await page.locator('.ag-notif-linha').click();
  await c.locator('button', { hasText: 'Desligar neste telefone' }).click();
  await expect.poll(() => posts.some((p) => p.accao === 'cancelar' && p.endpoint === 'https://web.push.apple.com/QGuQ-teste')).toBe(true);
  await expect(c.locator('button', { hasText: 'Ligar notificações' })).toBeVisible();
  expect(erros).toEqual([]);
});

test('app de gestão: ao tocar numa notificação abre o separador certo (#rotas; o antigo #agora também)', async ({ page }) => {
  await page.route('**/v1/sessao**', (r) => r.fulfill({ json: { ...SESSAO, permissoes: { ...SESSAO.permissoes, inicio: 'V' } } }));
  await page.route('**/v1/indicadores**', (r) => r.fulfill({ json: IND }));
  await page.route('**/v1/vendas**', (r) => r.fulfill({ json: [] }));
  await page.goto('/gestao/index.html#agora');
  await expect(page.locator('#ag-h1')).toHaveText('Rotas');
  await page.evaluate(() => (location.hash = '#alertas'));
  await expect(page.locator('#ag-h1')).toHaveText('Alertas');
});

test('app de gestão: Controlo — fecho previsto, recebido em dinheiro e Multicaixa por rota, clientes, artigos e riscos', async ({ page }) => {
  await page.clock.setFixedTime(new Date('2026-11-06T14:40:00+01:00'));
  const sess = { ...SESSAO, permissoes: { ...SESSAO.permissoes, inicio: 'V' } };
  const controlo = {
    fecho: { ate_hoje: 1000000, previsto: 2500000, mes_anterior: 2700000, variacao: -0.074, progresso: 0.4, dias_passados: 6, dias_mes: 30 },
    clientes: {
      activos: 42,
      activos_anterior: 40,
      novos: 3,
      por_recuperar: 5,
      valor_por_recuperar: 300000,
      recuperar: [{ codigo: 'B', nome: 'Cantina B', rota: '1001', valor: 120000, compras: 4 }],
    },
    artigos: [{ codigo: '10000009', nome: 'FRALDAS DE BEBÉ NAVAL MEDIUM 4 X 48', unidade: 'CX', quantidade: 120, valor: 900000, peso: 0.36, variacao: 0.12 }],
    rotas: [{ rota: '1011', promotor: 'Robel', total: 700000, docs: 40, nc: 1, dias: 5, docs_dia: 8, valor_medio: 17500, clientes: 30, peso_nc: 0.024 }],
    riscos: { top5_clientes: 0.31, maior_rota: { rota: '1011', peso: 0.28 }, notas_credito: 1, peso_notas_credito: 0.024 },
  };
  await page.route('**/v1/sessao**', (r) => r.fulfill({ json: sess }));
  const objectivos = {
    mes: '2026-11',
    rotas: [],
    total: {
      objectivo: 3000000,
      dias_uteis: 25,
      realizado: 1000000,
      cumprimento: 0.333,
      ritmo: 0.9,
      estado: 'abaixo',
      dias_restantes: 20,
      necessario_dia: 100000,
      projeccao: 2700000,
    },
  };
  await page.route('**/v1/indicadores**', (r) => r.fulfill({ json: { ...IND, controlo, objectivos } }));
  const pg = (codigo, meio, valor) => ({ pagamentos: [{ codigo, meio, valor }], liquidado: true, valor_pago: valor });
  await page.route(
    (u) => u.pathname.endsWith('/v1/vendas'),
    (r) =>
      r.fulfill({
        json: [
          { rota: '1011', promotor: 'Robel', hora: '14:31', total: 90000, cliente: 'A', ...pg('NU', 'Numerário', 90000) },
          { rota: '1011', promotor: 'Robel', hora: '13:05', total: 70000, cliente: 'B', ...pg('MC', 'Multicaixa', 70000) },
          { rota: '1002', promotor: 'Cristina', hora: '12:10', total: 140000, cliente: 'C', liquidado: false, valor_pago: 0, pagamentos: [] },
        ],
      }),
  );
  await page.route('**/v1/vendas/estado**', (r) => r.fulfill({ json: { ok: true } }));
  const erros = [];
  page.on('pageerror', (e) => erros.push(e.message));
  await page.goto('/gestao/index.html');
  // Hoje: recebido, em dinheiro e em Multicaixa no cartão ao vivo
  await expect(page.locator('.ag-vivo')).toContainText('160 mil Kz');
  await expect(page.locator('.ag-vivo')).toContainText('em dinheiro');
  await page.locator('.ag-separadores button', { hasText: 'Controlo' }).click();
  await expect(page.locator('#ag-h1')).toHaveText('Controlo');
  await expect(page.locator('.ag-heroi').first()).toContainText('Fecho previsto do mês');
  await expect(page.locator('.ag-heroi').first()).toContainText('2 500 000 Kz');
  // objectivo automático do mês e o ritmo necessário
  await expect(page.locator('.ag-objectivo')).toContainText('3 000 000 Kz');
  await expect(page.locator('.ag-objectivo')).toContainText('33% do objectivo');
  await expect(page.locator('.ag-objectivo .ag-mau')).toContainText('100 mil Kz por dia útil (20 dias)');
  const rec = page.locator('.ag-rec', { hasText: '1011' }).first();
  await expect(rec).toContainText('Dinheiro 90 mil Kz · Multicaixa 70 mil Kz');
  await expect(page.locator('.ag-rec', { hasText: '1002' }).first()).toContainText('por receber 140 mil Kz');
  await expect(page.locator('body')).toContainText('Cantina B');
  await expect(page.locator('body')).toContainText('FRALDAS DE BEBÉ NAVAL MEDIUM 4 X 48');
  await expect(page.locator('body')).toContainText('5 maiores clientes');
  // tocar no recebido de uma rota abre a ficha, com o recebido hoje
  await rec.click();
  await expect(page.locator('#ag-folha')).toContainText('Recebido hoje');
  await expect(page.locator('#ag-folha .ag-kpi', { hasText: 'Em dinheiro' })).toContainText('90 mil Kz');
  expect(await semCorte(page)).toEqual([]);
  expect(erros).toEqual([]);
});

test.describe('app de gestão: idioma do telefone', () => {
  test.use({ locale: 'en-GB' });
  test('telefone em inglês: a app abre em inglês, mesmo com a conta em português', async ({ page }) => {
    await page.route('**/v1/sessao**', (r) => r.fulfill({ json: { ...SESSAO, idioma: 'pt' } }));
    await page.route('**/v1/indicadores**', (r) => r.fulfill({ json: IND }));
    await page.goto('/gestao/index.html');
    await expect(page.locator('.ag-separadores')).toContainText('Today');
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
  });
});
