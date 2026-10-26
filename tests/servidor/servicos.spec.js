// Contrato dos serviços JSON (reports/data/*.php) depois da organização em camadas:
// sessão obrigatória, erros sempre em JSON, âmbito por perfil e as acções de gestão.
import { test, expect, request } from '@playwright/test';
import { entrar, csrf, BASE, DIA, SENHA } from './ajuda.js';

test.describe.configure({ mode: 'serial' });

test('sem sessão, todos os serviços respondem 401 em JSON', async () => {
  const anon = await request.newContext({ baseURL: BASE });
  for (const s of ['api.php?' + DIA, 'sessao.php', 'dados.php?f=rotas', 'cadastros.php', 'gestao.php', 'plano.php', 'sap.php', 'registar.php']) {
    const r = await anon.get('/reports/data/' + s);
    expect(r.status(), s).toBe(401);
    expect(r.headers()['content-type'], s).toContain('application/json');
    expect((await r.json()).erro, s).toBeTruthy();
  }
});

test('erros de pedido e de acesso vêm em JSON com o código certo', async () => {
  const fin = (await entrar('fin')).ctx;
  const datas = await fin.get('/reports/data/api.php?start_date=2026&end_date=x');
  expect(datas.status()).toBe(400);
  expect(await datas.json()).toMatchObject({ ok: false, erro: 'Datas inválidas.' });
  expect((await fin.get('/reports/data/api.php?diag=1')).status()).toBe(403);
  expect((await fin.get('/reports/data/dados.php?f=outro')).status()).toBe(400);
  expect((await fin.get('/reports/data/gestao.php')).status()).toBe(403);
});

test('estado da ligação e diagnóstico do Pinguim BO', async () => {
  const ping = await (await (await entrar('log')).ctx.get('/reports/data/api.php?ping=1')).json();
  expect(ping.ok).toBe(true);
  expect(ping.origem).toBe('127.0.0.1:8801');
  const diag = await (await (await entrar('sadm')).ctx.get('/reports/data/api.php?diag=1')).json();
  expect(diag).toMatchObject({ env_encontrado: true, credenciais: true, pinguim_http: 200 });
  expect(diag.pinguim_urls).toEqual(['http://127.0.0.1:8801/index.php']);
});

test('vendas sem linhas de artigos devolvem a lista de documentos', async () => {
  const v = await (await (await entrar('fin')).ctx.get('/reports/data/api.php?' + DIA)).json();
  expect(Array.isArray(v)).toBe(true);
  expect(v[0]).toHaveProperty('pagamentos');
});

test('dados fixos com o âmbito do perfil: rotas, cargas e clientes', async () => {
  const sup = (await entrar('sup')).ctx;
  const rotas = await (await sup.get('/reports/data/dados.php?f=rotas')).json();
  expect(rotas.length).toBeGreaterThan(1);
  const cargas = await (await sup.get('/reports/data/dados.php?f=cargas')).json();
  expect(cargas.length).toBeGreaterThan(0);
  expect(cargas.every((c) => c.rota === '1001')).toBe(true);
  const clientes = await (await sup.get('/reports/data/dados.php?f=clientes')).json();
  expect(clientes.every((c) => c.rota === '1001')).toBe(true);

  const com = await (await (await entrar('com')).ctx.get('/reports/data/dados.php?f=clientes')).json();
  expect(new Set(com.map((c) => c.rota)).size).toBeGreaterThan(1);
});

test('registo das exportações: permitido e recusado conforme a matriz', async () => {
  const dir = (await entrar('dir')).ctx;
  expect(await (await dir.post('/reports/data/registar.php', { data: { pagina: 'vendas-dia', completo: false, linhas: 3 } })).json()).toEqual({ ok: true });
  const sup = (await entrar('sup')).ctx;
  const r = await sup.post('/reports/data/registar.php', { data: { pagina: 'vendas-dia', completo: true } });
  expect(r.status()).toBe(403);
  expect((await r.json()).erro).toContain('não permite exportar');
});

test('cadastros: resposta JSON com dados ou com a indicação de onde configurar', async () => {
  const r = await (await entrar('com')).ctx.get('/reports/data/cadastros.php');
  expect([200, 404]).toContain(r.status());
  const d = await r.json();
  if (r.status() === 404) expect(d.detalhe).toContain('RTM_CLIENTES_JSON');
  else expect(d).toHaveProperty('cadastros');
});

test('gestão: repor palavra-passe, dois passos e matriz (com repor a original)', async () => {
  const sadm = (await entrar('sadm')).ctx,
    c = await csrf(sadm);
  const pedir = async (corpo) => (await sadm.post('/reports/data/gestao.php', { data: { ...corpo, csrf: c, confirmacao: SENHA } })).json();

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
  expect((await (await bo.get('/reports/data/sessao.php')).json()).permissoes.ranking).toBe('V');
  expect(await pedir({ accao: 'repor_matriz' })).toEqual({ ok: true });
  expect((await (await bo.get('/reports/data/sessao.php')).json()).permissoes.ranking).toBe('');

  const vista = await (await sadm.get('/reports/data/gestao.php')).json();
  expect(vista.pode_gerir).toBe(true);
  expect(vista.utilizadores.find((u) => u.login === 'com')).toMatchObject({ perfil: 'COM', senha_temporaria: true });
  expect(vista.utilizadores.some((u) => 'senha' in u)).toBe(false);
});

test('plano de cargas: reenviar e email de teste', async () => {
  const log = (await entrar('log')).ctx,
    c = await csrf(log);
  const t = await (await log.post('/reports/data/plano.php', { data: { accao: 'teste_email', csrf: c } })).json();
  expect(t).toMatchObject({ ok: true, para: 'log@naval.co.ao' });
  const r = await (await log.post('/reports/data/plano.php', { data: { accao: 'reenviar', csrf: c, data: '2026-10-20' } })).json();
  expect(r.ok).toBe(true);
  expect(r.email.para).toContain('transportes@naval.co.ao');
  const nada = await log.post('/reports/data/plano.php', { data: { accao: 'reenviar', csrf: c, data: '2026-01-01' } });
  expect(nada.status()).toBe(400);
});

test('sair termina a sessão', async () => {
  const fin = (await entrar('fin')).ctx;
  await fin.get('/geo/auth/logout.php', { maxRedirects: 0 });
  expect((await fin.get('/reports/data/sessao.php')).status()).toBe(401);
});
