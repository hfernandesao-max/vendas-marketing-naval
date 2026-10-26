// Matriz de acessos aplicada no servidor: entrada, perfis, âmbito por rota, NIF, bloqueio e gestão.
import { test, expect } from '@playwright/test';
import { entrar, csrf, erroEntrada, vendasDoDia, SENHA } from './ajuda.js';

test('entrada com o utilizador ou com o email', async () => {
  for (const id of ['fin', 'fin@naval.co.ao']) {
    const { resposta } = await entrar(id);
    expect(resposta.status()).toBe(302);
    expect(resposta.headers().location).toContain('../reports/');
  }
});

test('a sessão devolve o perfil, as rotas e as permissões de cada conta', async () => {
  const casos = { sup: ['SUP', ['1001']], log: ['LOG', null], sadm: ['SADM', null] };
  for (const [login, [perfil, rotas]] of Object.entries(casos)) {
    const { ctx } = await entrar(login);
    const s = await (await ctx.get('/reports/data/sessao.php')).json();
    expect(s.perfil).toBe(perfil);
    expect(s.rotas).toEqual(rotas);
    expect(s.csrf).toMatch(/^[0-9a-f]{32}$/);
  }
});

test('âmbito dos dados: rotas do supervisor e do promotor, NIF e Logística', async () => {
  const rotas = async (login) => (await vendasDoDia((await entrar(login)).ctx)).vendas.map((v) => v.rota).sort();
  expect(await rotas('sup')).toEqual(['1001']);
  expect(await rotas('pro')).toEqual(['1002']);
  expect(await rotas('fin')).toEqual(['1001', '1002', '1011']);

  const dir = await vendasDoDia((await entrar('dir')).ctx);
  expect(dir.vendas.every((v) => v.nif === '')).toBe(true);

  const log = await vendasDoDia((await entrar('log')).ctx);
  expect(log.vendas).toEqual([]);
  expect(log.artigos.length).toBeGreaterThan(0);
  expect(log.artigos.every((a) => a.total === null)).toBe(true);
});

test('três palavras-passe erradas bloqueiam a conta até o super administrador desbloquear', async () => {
  for (let i = 1; i <= 3; i++) {
    const { resposta } = await entrar('aud', 'errada');
    const msg = await erroEntrada(resposta);
    if (i < 3) expect(msg).toContain('antes de a conta ser bloqueada');
    else expect(msg).toContain('bloqueada');
  }
  expect(await erroEntrada((await entrar('aud', SENHA)).resposta)).toContain('bloqueada');

  // a Direcção não pode desbloquear; o super administrador pode
  const dir = (await entrar('dir')).ctx;
  expect((await dir.post('/reports/data/gestao.php', { data: { accao: 'desbloquear', conta: 'aud', csrf: await csrf(dir) } })).status()).toBe(403);
  const sadm = (await entrar('sadm')).ctx;
  const r = await sadm.post('/reports/data/gestao.php', { data: { accao: 'desbloquear', conta: 'aud', csrf: await csrf(sadm) } });
  expect((await r.json()).ok).toBe(true);
  expect((await entrar('aud')).resposta.status()).toBe(302);
});

test('alterar contas exige o super administrador, o código da sessão e a confirmação da identidade', async () => {
  const sadm = (await entrar('sadm')).ctx;
  const corpo = {
    accao: 'guardar_conta',
    novo: true,
    conta: 'novo.teste',
    nome: 'Novo Teste',
    email: 'novo.teste@naval.co.ao',
    perfil: 'COM',
    rotas: [],
    activo: true,
  };
  expect((await sadm.post('/reports/data/gestao.php', { data: { ...corpo, csrf: 'x' } })).status()).toBe(403);
  const c = await csrf(sadm);
  expect(await (await sadm.post('/reports/data/gestao.php', { data: { ...corpo, csrf: c } })).json()).toMatchObject({ ok: false, confirmar: 'senha' });
  const ok = await (await sadm.post('/reports/data/gestao.php', { data: { ...corpo, csrf: c, confirmacao: SENHA } })).json();
  expect(ok.ok).toBe(true);
  expect(ok.senha).toHaveLength(16);
  // email repetido é recusado
  const rep = await (await sadm.post('/reports/data/gestao.php', { data: { ...corpo, conta: 'outro.teste', csrf: c } })).json();
  expect(rep.erro).toContain('já está associado');
});

test('páginas do GEO respeitam a matriz', async () => {
  expect((await (await entrar('fin')).ctx.get('/geo/geo.php')).status()).toBe(403);
  expect((await (await entrar('dir')).ctx.get('/geo/geo.php')).status()).toBe(200);
});
