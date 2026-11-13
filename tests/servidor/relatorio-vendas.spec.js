// Folha de caixa (permissões, segregação de funções) e Relatório de Vendas (só com as fontes do dia completas).
import { test, expect } from '@playwright/test';
import { rmSync } from 'node:fs';
import { entrar, API, csrf } from './ajuda.js';

test.describe.configure({ mode: 'serial' });
const dia = new Date(Date.now() + 3600e3 - 86400e3).toISOString().slice(0, 10); // ontem, em Luanda
test.beforeAll(() => {
  rmSync('tests/servidor/ambiente/dados-privados/caixa', { recursive: true, force: true });
  rmSync('tests/servidor/ambiente/dados-privados/relatorio-vendas', { recursive: true, force: true });
});

test('folha de caixa: quem lança valida; 1.º nível e aprovação final por pessoas diferentes; perfis sem acesso não vêem', async () => {
  expect((await (await entrar('sup')).ctx.get(API + 'caixa?dia=' + dia)).status()).toBe(403);
  const fin = (await entrar('fin')).ctx;
  const v = await (await fin.get(API + 'caixa?dia=' + dia)).json();
  expect(v.gere).toBe(true);
  const post = async (ctx, corpo) => {
    const x = await ctx.post(API + 'caixa', { data: { dia, ...corpo, csrf: await csrf(ctx) } });
    return { status: x.status(), json: await x.json() };
  };
  const dir = (await entrar('dir')).ctx;
  expect((await post(dir, { accao: 'gravar', versao: 0 })).status).toBe(403); // a direcção só vê
  const g = await post(fin, {
    accao: 'gravar',
    versao: v.caixa.versao,
    saldo_inicial: 1000,
    entregas: [{ rota: '1001', notas: { 500: 1 } }],
    saidas: [{ banco: 'BPC', lacre: 'LAC-123', valor: 300 }],
  });
  expect(g.json.caixa.saldo_teorico).toBe(1200);
  expect((await post(fin, { accao: 'fechar', versao: g.json.caixa.versao, contagem: 1100, explicacao: '' })).status).toBe(400); // diferença por explicar
  const f = await post(fin, { accao: 'fechar', versao: g.json.caixa.versao, contagem: 1200 });
  expect(f.json.caixa.estado).toBe('fechada');
  expect((await post(fin, { accao: 'aprovar', versao: f.json.caixa.versao })).status).toBe(403);
  const bo = (await entrar('bo')).ctx;
  expect((await post(bo, { accao: 'aprovar', versao: f.json.caixa.versao })).status).toBe(403); // um colega da tesouraria não aprova
  const vd = await dir.get(API + 'caixa?dia=' + dia).then((r) => r.json());
  expect(vd.aprova).toEqual([2]);
  // por omissão ninguém tem o 1.º nível: circuito de um só nível (quem lança valida, o responsável aprova)
  expect(vd.dois_niveis).toBe(false);
  // depósito por aprovar impede a aprovação da folha; quem lançou não aprova o depósito
  expect((await post(dir, { accao: 'aprovar', versao: f.json.caixa.versao })).status).toBe(409);
  const id = f.json.caixa.saidas[0].id;
  expect((await post(fin, { accao: 'aprovar_deposito', id, versao: f.json.caixa.versao })).status).toBe(403);
  const d = await post(dir, { accao: 'aprovar_deposito', id, versao: f.json.caixa.versao });
  expect(d.json.caixa.saidas[0]).toMatchObject({ lacre: 'LAC-123', aprovado_por: 'dir' });
  const a = await post(dir, { accao: 'aprovar', versao: d.json.caixa.versao });
  expect(a.json.caixa).toMatchObject({ estado: 'aprovada', aprovada_por: 'dir' });
});

test('relatório de vendas: sem as fontes completas não se gera; perfis sem acesso não vêem', async () => {
  expect((await (await entrar('sup')).ctx.get(API + 'relatorio-vendas?dia=' + dia)).status()).toBe(403);
  const dir = (await entrar('dir')).ctx;
  const v = await (await dir.get(API + 'relatorio-vendas?dia=' + dia)).json();
  expect(v.pode_gerar).toBe(true);
  expect(v.controlos.caixa.estado).toBe('ok'); // aprovada no teste anterior
  expect(v.pronto).toBe(false); // sem base de dados do Pinguim BO no ambiente de testes
  expect(v.controlos.sap_odata.estado).toBe('por_ligar');
  const x = await dir.post(API + 'relatorio-vendas', { data: { accao: 'gerar', dia, csrf: await csrf(dir) } });
  expect(x.status()).toBe(409);
  expect((await x.json()).controlos.pinguim.estado).not.toBe('ok');
  const bo = (await entrar('bo')).ctx;
  expect((await bo.post(API + 'relatorio-vendas', { data: { accao: 'gerar', dia, csrf: await csrf(bo) } })).status()).toBe(403); // só vê
});
