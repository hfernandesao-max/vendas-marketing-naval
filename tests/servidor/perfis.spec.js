// Perfis criados no portal pelo super administrador: criar, usar numa conta, ajustar na matriz, apagar.
import { test, expect } from '@playwright/test';
import { entrar, csrf, SENHA } from './ajuda.js';

test.describe.configure({ mode: 'serial' });

test('criar perfil a partir de outro, criar uma conta com ele e ajustar a matriz', async () => {
  const sadm = (await entrar('sadm')).ctx,
    c = await csrf(sadm);
  const pedir = async (corpo) => (await sadm.post('/api/index.php/v1/gestao', { data: { ...corpo, csrf: c, confirmacao: SENHA } })).json();

  // validações
  expect((await pedir({ accao: 'guardar_perfil', novo: true, codigo: 'TI', nome: 'Outro' })).erro).toContain('Já existe');
  expect((await pedir({ accao: 'guardar_perfil', novo: true, codigo: 'x', nome: 'Mau' })).erro).toContain('código');
  expect((await pedir({ accao: 'guardar_perfil', novo: true, codigo: 'ZZ', nome: 'Super', base: 'SADM' })).erro).toContain('super administrador');
  expect((await pedir({ accao: 'guardar_perfil', codigo: 'COM', nome: 'Mudado' })).erro).toContain('sistema');

  // novo perfil copiado do Financeiro
  const r = await pedir({ accao: 'guardar_perfil', novo: true, codigo: 'TES', nome: 'Tesouraria', base: 'FIN', por_rota: false, dois_passos: false });
  expect(r).toMatchObject({ ok: true, perfil: 'TES' });
  const vista = await (await sadm.get('/api/index.php/v1/gestao')).json();
  expect(vista.perfis.TES).toBe('Tesouraria');
  expect(vista.matriz['sap-exportar'].TES).toBe(vista.matriz['sap-exportar'].FIN);
  expect(vista.perfis_info.find((p) => p.codigo === 'TES')).toMatchObject({ sistema: false, base: 'FIN', contas: 0, activo: true });

  // conta com o perfil novo: entra e tem as permissões copiadas
  const conta = await pedir({
    accao: 'guardar_conta',
    novo: true,
    conta: 'tes.teste',
    nome: 'Tesouraria Teste',
    email: 'tes@naval.co.ao',
    perfil: 'TES',
    activo: true,
  });
  expect(conta.ok).toBe(true);
  expect(await (await entrar('tes.teste', conta.senha)).resposta.text()).toContain('id="nova_senha"');

  // ajustar na matriz e não poder apagar com contas
  expect(await pedir({ accao: 'matriz', matriz: { ranking: { TES: 'E' } } })).toEqual({ ok: true, alteracoes: 1 });
  expect((await pedir({ accao: 'apagar_perfil', codigo: 'TES' })).erro).toContain('tes.teste');
  expect((await pedir({ accao: 'guardar_perfil', codigo: 'TES', nome: 'Tesouraria', activo: false })).erro).toContain('contas activas');

  // limitar às rotas exige rotas nas contas
  expect((await pedir({ accao: 'guardar_perfil', codigo: 'TES', nome: 'Tesouraria', por_rota: true, activo: true })).erro).toContain('tes.teste');
});

test('perfil sem contas: desactivar e apagar; só o super administrador cria perfis', async () => {
  const sadm = (await entrar('sadm')).ctx,
    c = await csrf(sadm);
  const pedir = async (corpo) => (await sadm.post('/api/index.php/v1/gestao', { data: { ...corpo, csrf: c, confirmacao: SENHA } })).json();
  expect((await pedir({ accao: 'guardar_perfil', novo: true, codigo: 'TMP', nome: 'Temporário', base: '' })).ok).toBe(true);
  expect((await pedir({ accao: 'guardar_perfil', codigo: 'TMP', nome: 'Temporário', activo: false })).ok).toBe(true);
  expect(Object.keys((await (await sadm.get('/api/index.php/v1/gestao')).json()).perfis)).not.toContain('TMP');
  expect(await pedir({ accao: 'apagar_perfil', codigo: 'TMP' })).toEqual({ ok: true });

  const ti = (await entrar('ti')).ctx;
  const r = await ti.post('/api/index.php/v1/gestao', { data: { accao: 'guardar_perfil', novo: true, codigo: 'XX', nome: 'X', csrf: await csrf(ti) } });
  expect(r.status()).toBe(403);
});
