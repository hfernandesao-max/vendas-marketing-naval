// Contrato da API central (api/index.php/v1/…): token JWT obrigatório, erros sempre em JSON,
// âmbito por perfil e as acções de gestão.
import { test, expect, request } from '@playwright/test';
import { entrar, csrf, BASE, DIA, SENHA } from './ajuda.js';

test.describe.configure({ mode: 'serial' });

test('sem token, todos os serviços respondem 401 em JSON', async () => {
  const anon = await request.newContext({ baseURL: BASE });
  for (const s of ['vendas?' + DIA, 'sessao', 'dados/rotas', 'cadastros', 'gestao', 'plano', 'sap', 'sincronizacao', 'estrutura-dados']) {
    const r = await anon.get('/api/index.php/v1/' + s);
    expect(r.status(), s).toBe(401);
    expect(r.headers()['content-type'], s).toContain('application/json');
    expect((await r.json()).erro, s).toBeTruthy();
  }
});

test('erros de pedido e de acesso vêm em JSON com o código certo', async () => {
  const fin = (await entrar('fin')).ctx;
  const datas = await fin.get('/api/index.php/v1/vendas?start_date=2026&end_date=x');
  expect(datas.status()).toBe(400);
  expect(await datas.json()).toMatchObject({ ok: false, erro: 'Datas inválidas.' });
  expect((await fin.get('/api/index.php/v1/vendas/diagnostico')).status()).toBe(403);
  expect((await fin.get('/api/index.php/v1/dados/outro')).status()).toBe(404);
  expect((await fin.get('/api/index.php/v1/gestao')).status()).toBe(403);
});

test('estado da ligação e diagnóstico do Pinguim BO', async () => {
  const ping = await (await (await entrar('log')).ctx.get('/api/index.php/v1/vendas/estado')).json();
  expect(ping.ok).toBe(true);
  expect(ping.origem).toBe('Pinguim BO'); // endereço interno só para SADM/TI (ver seguranca.spec.js)
  const diag = await (await (await entrar('sadm')).ctx.get('/api/index.php/v1/vendas/diagnostico')).json();
  expect(diag).toMatchObject({ env_encontrado: true, credenciais: true, pinguim_http: 200 });
  expect(diag.pinguim_urls).toEqual(['http://127.0.0.1:8801/index.php']);
});

test('vendas sem linhas de artigos devolvem a lista de documentos', async () => {
  const v = await (await (await entrar('fin')).ctx.get('/api/index.php/v1/vendas?' + DIA)).json();
  expect(Array.isArray(v)).toBe(true);
  expect(v[0]).toHaveProperty('pagamentos');
});

test('dados fixos com o âmbito do perfil: rotas, cargas e clientes', async () => {
  const sup = (await entrar('sup')).ctx;
  const rotas = await (await sup.get('/api/index.php/v1/dados/rotas')).json();
  expect(rotas.length).toBeGreaterThan(1);
  const cargas = await (await sup.get('/api/index.php/v1/dados/cargas')).json();
  expect(cargas.length).toBeGreaterThan(0);
  expect(cargas.every((c) => c.rota === '1001')).toBe(true);
  const clientes = await (await sup.get('/api/index.php/v1/dados/clientes')).json();
  expect(clientes.every((c) => c.rota === '1001')).toBe(true);

  const com = await (await (await entrar('com')).ctx.get('/api/index.php/v1/dados/clientes')).json();
  expect(new Set(com.map((c) => c.rota)).size).toBeGreaterThan(1);
});

test('registo das exportações: permitido e recusado conforme a matriz', async () => {
  const dir = (await entrar('dir')).ctx;
  expect(
    await (await dir.post('/api/index.php/v1/exportacoes', { data: { csrf: await csrf(dir), pagina: 'vendas-dia', completo: false, linhas: 3 } })).json(),
  ).toEqual({ ok: true });
  const sup = (await entrar('sup')).ctx;
  const r = await sup.post('/api/index.php/v1/exportacoes', { data: { csrf: await csrf(sup), pagina: 'vendas-dia', completo: true } });
  expect(r.status()).toBe(403);
  expect((await r.json()).erro).toContain('não permite exportar');
});

test('cadastros: resposta JSON com dados ou com a indicação de onde configurar', async () => {
  const r = await (await entrar('com')).ctx.get('/api/index.php/v1/cadastros');
  expect([200, 404]).toContain(r.status());
  const d = await r.json();
  if (r.status() === 404) expect(d.detalhe).toContain('RTM_CLIENTES_JSON');
  else expect(d).toHaveProperty('cadastros');
});

test('gestão: repor palavra-passe, dois passos e matriz (com repor a original)', async () => {
  const sadm = (await entrar('sadm')).ctx,
    c = await csrf(sadm);
  const pedir = async (corpo) => (await sadm.post('/api/index.php/v1/gestao', { data: { ...corpo, csrf: c, confirmacao: SENHA } })).json();

  const s = await pedir({ accao: 'senha', conta: 'com' });
  expect(s.senha).toHaveLength(16);
  // com a palavra-passe temporária a entrada pede logo uma nova
  expect(await (await entrar('com', s.senha)).resposta.text()).toContain('id="nova_senha"');

  const mfa = await pedir({ accao: 'dois_passos', conta: 'bo', activar: true });
  expect(mfa.uri).toContain('otpauth://totp/');
  expect(await pedir({ accao: 'dois_passos', conta: 'bo', activar: false })).toEqual({ ok: true });
  expect((await pedir({ accao: 'dois_passos', conta: 'sadm', activar: false })).erro).toContain('própria conta');

  expect(await pedir({ accao: 'matriz', matriz: { ranking: { BO: 'V' } } })).toEqual({ ok: true, alteracoes: 1 });
  expect((await pedir({ accao: 'matriz', matriz: { ranking: { BO: 'G' } } })).erro).toContain('Nível não permitido');
  const bo = (await entrar('bo')).ctx;
  expect((await (await bo.get('/api/index.php/v1/sessao')).json()).permissoes.ranking).toBe('V');
  expect(await pedir({ accao: 'repor_matriz' })).toEqual({ ok: true });
  expect((await (await bo.get('/api/index.php/v1/sessao')).json()).permissoes.ranking).toBe('');

  const vista = await (await sadm.get('/api/index.php/v1/gestao')).json();
  expect(vista.pode_gerir).toBe(true);
  expect(vista.utilizadores.find((u) => u.login === 'com')).toMatchObject({ perfil: 'COM', senha_temporaria: true });
  expect(vista.utilizadores.some((u) => 'senha' in u)).toBe(false);
});

test('plano de cargas: reenviar e email de teste', async () => {
  const log = (await entrar('log')).ctx,
    c = await csrf(log);
  const t = await (await log.post('/api/index.php/v1/plano', { data: { accao: 'teste_email', csrf: c } })).json();
  expect(t).toMatchObject({ ok: true, para: 'log@naval.co.ao' });
  const r = await (await log.post('/api/index.php/v1/plano', { data: { accao: 'reenviar', csrf: c, data: '2026-10-20' } })).json();
  expect(r.ok).toBe(true);
  expect(r.email.para).toContain('transportes@naval.co.ao');
  const nada = await log.post('/api/index.php/v1/plano', { data: { accao: 'reenviar', csrf: c, data: '2026-01-01' } });
  expect(nada.status()).toBe(400);
});

test('sincronização dos smartphones: só leitura, âmbito pela matriz e indicação quando não há base de dados', async () => {
  const fin = (await entrar('fin')).ctx;
  expect((await fin.get('/api/index.php/v1/sincronizacao')).status()).toBe(403);
  const r = await (await entrar('log')).ctx.get('/api/index.php/v1/sincronizacao');
  expect(r.headers()['content-type']).toContain('application/json');
  const d = await r.json();
  if (d.disponivel) expect(Array.isArray(d.rotas)).toBe(true);
  else expect(d.erro).toBeTruthy();
});

test('activar a gestão de contas: recusada quando a lista nova já existe', async () => {
  const sadm = (await entrar('sadm')).ctx,
    c = await csrf(sadm);
  const vista = await (await sadm.get('/api/index.php/v1/gestao')).json();
  expect(vista).toMatchObject({ pode_activar: false, compatibilidade: false, pode_gerir: true });
  const r = await sadm.post('/api/index.php/v1/gestao', { data: { accao: 'activar_gestao', csrf: c, contas: {}, aprovado_por: ['A', 'B'] } });
  expect(r.status()).toBe(403);
});

test('estrutura de dados: só TI, AUD e SADM; sem base de dados indica o motivo; fica nos registos', async () => {
  expect((await (await entrar('fin')).ctx.get('/api/index.php/v1/estrutura-dados')).status()).toBe(403);
  const ti = (await entrar('ti')).ctx;
  const r = await ti.get('/api/index.php/v1/estrutura-dados');
  expect(r.status()).toBe(200);
  const d = await r.json();
  expect(d.perfis).toHaveProperty('SADM');
  expect(d.matriz['estrutura-dados']).toMatchObject({ TI: 'E', AUD: 'E', FIN: '' });
  if (!d.bd.disponivel) expect(d.bd.erro).toBeTruthy();
  expect((await (await ti.get('/api/index.php/v1/sessao')).json()).permissoes['estrutura-dados']).toBe('E');
});

test('exportar dados: permitido a DIR, FIN e AUD (nível E) e registado; recusado aos restantes', async () => {
  const fin = (await entrar('fin')).ctx;
  const ok = await fin.post('/api/index.php/v1/exportacoes', {
    data: { csrf: await csrf(fin), pagina: 'exportar-dados', completo: false, linhas: 10, periodo: '20-10-2026 – 26-10-2026' },
  });
  expect(await ok.json()).toEqual({ ok: true });
  for (const conta of ['log', 'sup', 'ti']) {
    const c = (await entrar(conta)).ctx;
    const r = await c.post('/api/index.php/v1/exportacoes', { data: { csrf: await csrf(c), pagina: 'exportar-dados', completo: false } });
    expect(r.status(), conta).toBe(403);
  }
  expect((await (await fin.get('/api/index.php/v1/sessao')).json()).permissoes['exportar-dados']).toBe('E');
  // sem o código CSRF o registo é recusado (mesmo para quem pode exportar)
  expect((await fin.post('/api/index.php/v1/exportacoes', { data: { pagina: 'exportar-dados', completo: false } })).status()).toBe(403);
});

test('sair termina a sessão', async () => {
  const fin = (await entrar('fin')).ctx;
  await fin.post('/geo/auth/logout.php', { form: { csrf: await csrf(fin) }, maxRedirects: 0 });
  expect((await fin.get('/api/index.php/v1/sessao')).status()).toBe(401);
});

test('exportação em todos os menus: níveis E na sincronização, no plano de cargas e nos acessos (revisão de acessos)', async () => {
  const dir = await (await (await entrar('dir')).ctx.get('/api/index.php/v1/sessao')).json();
  expect(dir.permissoes).toMatchObject({ sincronizacao: 'E', 'plano-cargas': 'E' });
  const ti = await (await (await entrar('ti')).ctx.get('/api/index.php/v1/sessao')).json();
  expect(ti.permissoes.acessos).toBe('E');
  const aud = (await entrar('aud')).ctx;
  expect(
    await (await aud.post('/api/index.php/v1/exportacoes', { data: { csrf: await csrf(aud), pagina: 'acessos', completo: false, linhas: 5 } })).json(),
  ).toEqual({ ok: true });
});
