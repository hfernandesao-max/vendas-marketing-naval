// Atribuição automática de acessos por funções: o super administrador diz as funções da pessoa e o portal atribui
// as permissões (perfil gerado), recusa combinações proibidas e actualiza as contas quando uma função muda.
import { test, expect } from '@playwright/test';
import { entrar, csrf, SENHA } from './ajuda.js';

test.describe.configure({ mode: 'serial' });

test('conta por funções: permissões calculadas, separação de funções e actualização ao mudar a função', async () => {
  const sadm = (await entrar('sadm')).ctx,
    c = await csrf(sadm);
  const pedir = async (corpo) => (await sadm.post('/api/index.php/v1/gestao', { data: { ...corpo, csrf: c, confirmacao: SENHA } })).json();
  const vista = async () => (await sadm.get('/api/index.php/v1/gestao')).json();
  const v0 = await vista();
  expect(v0.funcoes['C-CAIXA-LANCAR'].nome).toBe('Lançar e validar o fecho de caixa');

  const conta = (funcoes) => ({
    accao: 'guardar_conta',
    novo: true,
    conta: 'josefa.funcoes',
    nome: 'Josefa Funções',
    email: 'josefa.funcoes@naval.co.ao',
    perfil: 'COM',
    funcoes,
    activo: true,
    idioma: 'pt',
  });
  // lançar e aprovar a mesma caixa: recusado
  expect((await pedir(conta(['P-FIN', 'C-CAIXA-LANCAR', 'C-CAIXA-APROVAR']))).erro).toContain('Separação de funções');
  const r = await pedir(conta(['P-FIN', 'C-CAIXA-LANCAR']));
  expect(r.ok).toBe(true);
  const v1 = await vista();
  const u = v1.utilizadores.find((x) => x.login === 'josefa.funcoes');
  expect(u.funcoes).toEqual(['C-CAIXA-LANCAR', 'P-FIN']);
  expect(u.perfil).toMatch(/^F[0-9A-F]{7}$/);
  expect(v1.gerados).toContain(u.perfil);
  expect(v1.matriz['caixa-marketing'][u.perfil]).toBe('G');
  expect(v1.matriz['caixa-aprovacao-final'][u.perfil]).toBe('');

  // a função muda: a conta fica actualizada sozinha
  const f = v1.funcoes['C-CAIXA-LANCAR'];
  const g = await pedir({ accao: 'guardar_funcao', codigo: 'C-CAIXA-LANCAR', ...f, permissoes: { ...f.permissoes, 'relatorio-vendas': 'E' } });
  expect(g).toMatchObject({ ok: true, contas: 1 });
  expect((await vista()).matriz['relatorio-vendas'][u.perfil]).toBe('E');

  // a matriz não altera um perfil gerado
  await pedir({ accao: 'matriz', matriz: { 'caixa-marketing': { [u.perfil]: 'V' } } });
  expect((await vista()).matriz['caixa-marketing'][u.perfil]).toBe('G');

  // só o super administrador gere funções
  const ti = (await entrar('ti')).ctx;
  const n = await ti.post('/api/index.php/v1/gestao', { data: { accao: 'guardar_funcao', codigo: 'X-Y', nome: 'X', csrf: await csrf(ti) } });
  expect(n.status()).toBe(403);
});
