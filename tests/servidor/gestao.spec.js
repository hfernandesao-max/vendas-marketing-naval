// Gestão (análise e IA): alertas, previsão, indicadores, assistente (com a API da Anthropic simulada), resumo diário,
// painel da sala comercial e cartaz QR do cliente (página pública, pedidos pendentes e validação no back-office).
import { test, expect, request } from '@playwright/test';
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { entrar, API, BASE, csrf } from './ajuda.js';

test.describe.configure({ mode: 'serial' });
const PRIV = 'tests/servidor/ambiente/dados-privados';

test('matriz de acessos: cada recurso só para os perfis previstos', async () => {
  const casos = [
    ['dir', 'alertas', 200],
    ['pro', 'alertas', 403],
    ['log', 'alertas', 403],
    ['log', 'previsao', 403],
    ['sup', 'previsao', 200],
    ['fin', 'previsao', 403],
    ['dir', 'indicadores', 200],
    ['dir', 'painel', 200],
    ['sup', 'painel', 200],
    ['log', 'painel', 403],
    ['bo', 'indicadores', 403],
    ['dir', 'assistente', 200],
    ['com', 'assistente', 403],
    ['dir', 'resumo', 200],
    ['sup', 'resumo', 403],
    ['bo', 'pedidos-clientes', 200],
    ['log', 'pedidos-clientes', 403],
  ];
  for (const [conta, rec, estado] of casos) {
    const { ctx } = await entrar(conta);
    expect((await ctx.get(API + rec)).status(), `${conta} ${rec}`).toBe(estado);
  }
});

test('alertas, previsão e indicadores: formato e âmbito do supervisor (só as suas rotas)', async () => {
  const { ctx } = await entrar('dir');
  const a = await (await ctx.get(API + 'alertas')).json();
  expect(a.ok).toBe(true);
  expect(Array.isArray(a.alertas)).toBe(true);
  expect(a.dia).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  const p = await (await ctx.get(API + 'previsao')).json();
  expect(p.ok).toBe(true);
  expect(p.dias.length).toBe(7);
  const pn = await (await ctx.get(API + 'painel')).json();
  expect(Object.keys(pn)).toEqual(
    expect.arrayContaining(['kpis', 'tendencia', 'mes', 'abc_clientes', 'abc_artigos', 'distribuicao', 'por_rota', 'calor', 'carteira']),
  );
  expect(pn.tendencia.dias.length).toBe(90);
  expect(JSON.stringify(pn)).not.toContain('5000000002'); // sem NIF
  const i = await (await ctx.get(API + 'indicadores')).json();
  expect(Object.keys(i)).toEqual(expect.arrayContaining(['hoje', 'ontem', 'semana', 'mes', 'rotas', 'serie', 'ultima_venda']));
  // série diária desde o dia 1 do mês anterior até hoje (gráficos da app) e 14 dias por rota
  expect(i.serie[0].d).toMatch(/^\d{4}-\d{2}-01$/);
  expect(i.serie.at(-1).d).toBe(i.hoje.dia);
  expect(i.serie.find((x) => x.d === i.ontem.dia).t).toBeCloseTo(i.ontem.total, 2);
  for (const r of i.rotas) expect(r.serie.length).toBe(14);
  const sup = await entrar('sup');
  const s = await (await sup.ctx.get(API + 'alertas')).json();
  for (const x of s.alertas) expect(['1001', '']).toContain(x.rota);
  expect((await sup.ctx.get(API + 'alertas?dia=2099-01-01')).status()).toBe(400);
});

test('assistente: resposta com os números da ferramenta, fontes, consumo, CSRF e limite diário', async () => {
  rmSync(PRIV + '/ia', { recursive: true, force: true });
  const { ctx } = await entrar('dir');
  const e = await (await ctx.get(API + 'assistente')).json();
  expect(e.configurada).toBe(true);
  expect(e.limites.perguntas_dia).toBe(5);
  expect((await ctx.post(API + 'assistente', { data: { pergunta: 'Quanto vendemos?' } })).status()).toBe(403); // sem CSRF
  const c = await csrf(ctx);
  const r = await (await ctx.post(API + 'assistente', { data: { pergunta: 'Quanto vendemos a 24-10-2026?', historico: [], idioma: 'pt', csrf: c } })).json();
  expect(r.ok).toBe(true);
  // total da exportação de teste do Pinguim BO em 24-10-2026: 461 400 + 114 000 + 60 000
  expect(r.resposta).toContain('635 400 Kz');
  expect(r.fontes).toEqual(['vendas_resumo']);
  expect(r.tokens.entrada).toBeGreaterThan(0);
  expect((await (await ctx.get(API + 'assistente')).json()).uso.minhas_hoje).toBe(1);
  for (let k = 0; k < 4; k++) await ctx.post(API + 'assistente', { data: { pergunta: 'Outra pergunta', csrf: c } });
  const lim = await ctx.post(API + 'assistente', { data: { pergunta: 'Mais uma', csrf: c } });
  expect(lim.status()).toBe(429);
  // pergunta vazia ou demasiado longa
  rmSync(PRIV + '/ia', { recursive: true, force: true });
  expect((await ctx.post(API + 'assistente', { data: { pergunta: ' ', csrf: c } })).status()).toBe(400);
  expect((await ctx.post(API + 'assistente', { data: { pergunta: 'x'.repeat(1600), csrf: c } })).status()).toBe(400);
});

test('resumo diário: pré-visualização em HTML e envio de teste só para quem pede', async () => {
  const { ctx } = await entrar('dir');
  const d = await (await ctx.get(API + 'resumo')).json();
  expect(d.ok).toBe(true);
  expect(d.html).toContain('Resumo diário');
  expect(d.html).toContain('text-align:justify');
  const r = await (await ctx.post(API + 'resumo', { data: { accao: 'teste', csrf: await csrf(ctx) } })).json();
  expect(r.ok).toBe(true);
  expect(r.para).toBe('dir@naval.co.ao');
});

test('painel da sala: ligação criada pelo SADM, dados só agregados, revogação imediata', async () => {
  rmSync(PRIV + '/sala', { recursive: true, force: true });
  const { ctx } = await entrar('sadm');
  const c = await csrf(ctx);
  expect((await ctx.post(API + 'sala/ligacoes', { data: { accao: 'criar', nome: '', csrf: c } })).status()).toBe(400);
  const r = await (await ctx.post(API + 'sala/ligacoes', { data: { accao: 'criar', nome: 'Sala de teste', csrf: c } })).json();
  expect(r.codigo).toMatch(/^[a-f0-9]{64}$/);
  expect(JSON.stringify(r.ligacoes)).not.toContain(r.codigo);
  const tv = await request.newContext({ baseURL: BASE });
  const s = await tv.get(API + 'sala', { headers: { 'X-Painel-Codigo': r.codigo } });
  expect(s.status()).toBe(200);
  const j = await s.json();
  expect(Object.keys(j.rotas[0] || { rota: 1 })).not.toContain('clientes');
  expect(JSON.stringify(j)).not.toMatch(/nif|5000000002|Loja B/i);
  expect((await tv.get(API + 'sala', { headers: { 'X-Painel-Codigo': 'f'.repeat(64) } })).status()).toBe(403);
  // ecrãs e ordem escolhidos no portal (arrastados), devolvidos ao ecrã
  expect((await ctx.post(API + 'sala/ligacoes', { data: { accao: 'configurar', id: r.id, ecras: ['xpto'], csrf: c } })).status()).toBe(400);
  await ctx.post(API + 'sala/ligacoes', { data: { accao: 'configurar', id: r.id, ecras: ['destaques', 'hoje'], segundos: 30, csrf: c } });
  expect((await (await tv.get(API + 'sala', { headers: { 'X-Painel-Codigo': r.codigo } })).json()).config).toEqual({
    ecras: ['destaques', 'hoje'],
    segundos: 30,
  });
  await ctx.post(API + 'sala/ligacoes', { data: { accao: 'revogar', id: r.id, csrf: c } });
  expect((await tv.get(API + 'sala', { headers: { 'X-Painel-Codigo': r.codigo } })).status()).toBe(403);
  // quem não gere o painel: 403
  const dir = await entrar('dir');
  expect((await dir.ctx.get(API + 'sala/ligacoes')).status()).toBe(403);
});

test('cartaz QR do cliente: código aleatório, pedidos pendentes, armadilha para robôs, validação e limites', async () => {
  rmSync(PRIV + '/clientes-qr', { recursive: true, force: true });
  // agregado de ontem com o cliente X2 na rota 1002 (os cartazes só se criam para clientes com vendas na rota)
  const ontem = new Date(Date.now() - 86400000).toLocaleDateString('sv-SE', { timeZone: 'Africa/Luanda' });
  mkdirSync(PRIV + '/analise', { recursive: true });
  writeFileSync(
    `${PRIV}/analise/${ontem}.json`,
    JSON.stringify({
      v: 2,
      dia: ontem,
      fixo: false,
      rotas: { 1002: { t: 1000, d: 1, nc: 0 } },
      clientes: { X2: { n: 'Loja B', r: '1002', t: 1000, d: 1 } },
      artigos: {},
      ca: {},
    }),
  );
  const { ctx } = await entrar('bo');
  const c = await csrf(ctx);
  const g = await (await ctx.post(API + 'clientes-qr', { data: { rota: '1002', clientes: [{ cliente: 'X2' }], csrf: c } })).json();
  const codigo = g.codigos[0].codigo;
  expect(codigo).toMatch(/^[A-Za-z0-9_-]{22}$/);
  // voltar a imprimir mantém o código; «novo» substitui-o e o anterior deixa de funcionar
  const g2 = await (await ctx.post(API + 'clientes-qr', { data: { rota: '1002', clientes: [{ cliente: 'X2' }], csrf: c } })).json();
  expect(g2.codigos[0].codigo).toBe(codigo);

  const pub = await request.newContext({ baseURL: BASE });
  const v = await (await pub.get(API + 'cliente?k=' + codigo)).json();
  expect(v.nome).toBe('Loja B');
  expect(JSON.stringify(v)).not.toMatch(/5000000002|total|preco/i);
  expect((await pub.get(API + 'cliente?k=' + 'A'.repeat(22))).status()).toBe(404);
  // robô (campo-armadilha preenchido): resposta igual, nada gravado
  await pub.post(API + 'cliente', { data: { k: codigo, tipo: 'dados', dados: { morada: 'X' }, site: 'http://spam' } });
  const dados = await (
    await pub.post(API + 'cliente', {
      data: { k: codigo, tipo: 'dados', dados: { morada: 'Rua 1, Viana', contacto_telefone: '923 000 000', latitude: -8.9, longitude: 13.3 } },
    })
  ).json();
  expect(dados.ok).toBe(true);
  expect((await pub.post(API + 'cliente', { data: { k: codigo, tipo: 'consentimento', dados: { contacto_telefone: '923000000' } } })).status()).toBe(400); // sem aceitar
  await pub.post(API + 'cliente', {
    data: { k: codigo, tipo: 'consentimento', dados: { aceito: true, contacto_nome: 'Ana', contacto_telefone: '923000000' } },
  });

  const lista = (await (await ctx.get(API + 'pedidos-clientes')).json()).pedidos;
  expect(
    lista
      .filter((p) => p.estado === 'pendente')
      .map((p) => p.tipo)
      .sort(),
  ).toEqual(['consentimento', 'dados']);
  const pd = lista.find((p) => p.tipo === 'dados');
  expect(pd.conteudo.morada).toBe('Rua 1, Viana');
  // recusar sem motivo: 400; aprovar; decidir de novo: 409
  expect((await ctx.post(API + 'pedidos-clientes', { data: { id: pd.id, accao: 'recusar', motivo: '', csrf: c } })).status()).toBe(400);
  expect((await (await ctx.post(API + 'pedidos-clientes', { data: { id: pd.id, accao: 'aprovar', csrf: c } })).json()).pedido.estado).toBe('aprovado');
  expect((await ctx.post(API + 'pedidos-clientes', { data: { id: pd.id, accao: 'aprovar', csrf: c } })).status()).toBe(409);
  // quem só vê (DIR) não valida
  const dir = await entrar('dir');
  const pc = lista.find((p) => p.tipo === 'consentimento');
  expect((await dir.ctx.post(API + 'pedidos-clientes', { data: { id: pc.id, accao: 'aprovar', csrf: await csrf(dir.ctx) } })).status()).toBe(403);
  // cliente de outra rota ou inexistente: recusado (nome e rota vêm do servidor)
  expect((await ctx.post(API + 'clientes-qr', { data: { rota: '1002', clientes: [{ cliente: 'NAO-EXISTE' }], csrf: c } })).status()).toBe(400);
  // corpo demasiado grande na rota pública: 413
  expect((await pub.post(API + 'cliente', { data: { k: codigo, tipo: 'dados', dados: { observacoes: 'x'.repeat(20000) } } })).status()).toBe(413);
  // o back-office não vê o IP nem o navegador de quem fez o pedido
  expect(JSON.stringify(lista)).not.toMatch(/"ip"|navegador/);
  // limite diário por cliente (10 pedidos)
  let ultimo = 0;
  for (let k = 0; k < 9; k++) ultimo = (await pub.post(API + 'cliente', { data: { k: codigo, tipo: 'dados', dados: { horario: 'h' + k } } })).status();
  expect(ultimo).toBe(429);
  // código substituído: o antigo deixa de funcionar
  await ctx.post(API + 'clientes-qr', { data: { rota: '1002', clientes: [{ cliente: 'X2' }], novo: true, csrf: c } });
  expect((await pub.get(API + 'cliente?k=' + codigo)).status()).toBe(404);
});

test('vistas guardadas: por pessoa, com CSRF, só chaves válidas; null repõe a origem', async () => {
  const a = await entrar('dir');
  const c = await csrf(a.ctx);
  expect((await a.ctx.post(API + 'vistas', { data: { pagina: 'inicio', ordem: ['cobrado'] } })).status()).toBe(403);
  expect((await a.ctx.post(API + 'vistas', { data: { pagina: 'outra', ordem: [], csrf: c } })).status()).toBe(400);
  const g = await (await a.ctx.post(API + 'vistas', { data: { pagina: 'inicio', ordem: ['cobrado', 'tendencia', '<script>', 'cobrado'], csrf: c } })).json();
  expect(g.vistas.inicio).toEqual(['cobrado', 'tendencia']);
  const b = await entrar('com');
  expect((await (await b.ctx.get(API + 'vistas')).json()).vistas).toEqual({});
  await a.ctx.post(API + 'vistas', { data: { pagina: 'inicio', ordem: null, csrf: c } });
  expect((await (await a.ctx.get(API + 'vistas')).json()).vistas).toEqual({});
});
