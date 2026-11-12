// Conciliação da carrinha: matriz de acessos, contagem cega, âmbito por rota, CSRF, motivo obrigatório e aprovação.
import { test, expect } from '@playwright/test';
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { entrar, API, csrf } from './ajuda.js';

test.describe.configure({ mode: 'serial' });
const PRIV = 'tests/servidor/ambiente/dados-privados';
const dia = new Date(Date.now() + 3600e3).toISOString().slice(0, 10); // Luanda (UTC+1)
const rota = (q) => ({
  s0: { A1: { n: 'Fraldas M', q: 10 } },
  s1: { A1: { n: 'Fraldas M', q: q } },
  v: { A1: { n: 'Fraldas M', u: 'CX', q: 26, t: 26000 } },
  mov: { A1: { c: 20, d: 0 } },
});

test.beforeAll(() => {
  const d = `${PRIV}/carrinha/${dia}`;
  rmSync(d, { recursive: true, force: true });
  mkdirSync(d, { recursive: true });
  writeFileSync(
    `${d}/_dados.json`,
    JSON.stringify({ data: dia, gerado: dia + ' 18:00:00', movimentos: 'separados', precos: { A1: 1000 }, rotas: { 1001: rota(4), 1002: rota(4) } }),
  );
});

test('conciliação da carrinha: acessos, contagem cega, âmbito, motivo e aprovação', async () => {
  const URL = API + 'conciliacao-carrinha?data=' + dia;
  expect((await (await entrar('bo')).ctx.get(URL)).status()).toBe(403);
  const sup = (await entrar('sup')).ctx;
  const vs = await (await sup.get(URL)).json();
  expect(vs.rotas.map((r) => r.rota)).toEqual(['1001']);
  expect(vs.gere).toBe(false);
  expect(vs.rotas[0].linhas[0].esperado).toBe(4); // quem só vê, vê tudo
  expect(
    (await sup.post(API + 'conciliacao-carrinha', { data: { accao: 'contar', data: dia, rota: '1001', contagem: { A1: 1 }, csrf: await csrf(sup) } })).status(),
  ).toBe(403);

  const log = (await entrar('log')).ctx;
  const v = await (await log.get(URL)).json();
  expect(v.gere).toBe(true);
  expect(v.rotas[0].cega).toBe(true);
  expect(Object.keys(v.rotas[0].linhas[0])).not.toContain('esperado');
  const c = await csrf(log);
  expect((await log.post(API + 'conciliacao-carrinha', { data: { accao: 'contar', data: dia, rota: '1001', contagem: { A1: 3 } } })).status()).toBe(403);
  const r = await (await log.post(API + 'conciliacao-carrinha', { data: { accao: 'contar', data: dia, rota: '1001', contagem: { A1: 3 }, csrf: c } })).json();
  expect(r.resumo).toMatchObject({ estado: 'por_validar', diferencas: 1, falta_kz: 1000 });
  const post = async (ctx, corpo) => {
    const x = await ctx.post(API + 'conciliacao-carrinha', { data: { data: dia, rota: '1001', ...corpo, csrf: await csrf(ctx) } });
    return { status: x.status(), json: await x.json() };
  };
  const sadm = (await entrar('sadm')).ctx;
  const dirCtx = (await entrar('dir')).ctx;
  // 1. só quem contou valida; o coordenador não aprova antes da validação
  expect((await post(sadm, { accao: 'validar' })).status).toBe(403);
  expect((await post(sadm, { accao: 'aprovar', motivos: { A1: { motivo: 'avaria' } } })).status).toBe(409);
  expect((await post(log, { accao: 'validar' })).json.resumo.estado).toBe('por_aprovar');
  // 2. coordenador (1.º nível): quem contou não aprova (segregação de funções); motivo obrigatório
  expect((await post(log, { accao: 'aprovar', motivos: { A1: { motivo: 'avaria' } } })).status).toBe(403);
  expect((await post(sadm, { accao: 'aprovar', motivos: {} })).status).toBe(400);
  expect((await post(dirCtx, { accao: 'aprovar', motivos: { A1: { motivo: 'avaria' } } })).status).toBe(403); // perfil sem o 1.º nível
  expect((await post(sadm, { accao: 'aprovar', motivos: { A1: { motivo: 'avaria' } } })).json.resumo.estado).toBe('por_fechar');
  // 3. responsável (2.º nível): nem o coordenador nem quem contou fecham
  expect((await post(sadm, { accao: 'fechar' })).status).toBe(403);
  expect((await post(log, { accao: 'fechar' })).status).toBe(403);
  const a = (await post(dirCtx, { accao: 'fechar' })).json;
  expect(a.resumo.estado).toBe('aprovada');
  const dir = await (await (await entrar('dir')).ctx.get(URL)).json();
  const r1 = dir.rotas.find((x) => x.rota === '1001');
  expect(r1.resumo.estado).toBe('aprovada');
  expect(r1).toMatchObject({ contado_por: 'log', validado_por: 'log', coordenado_por: 'sadm', aprovado_por: 'dir' });
  expect(r1.motivos.A1.motivo).toBe('avaria');
  expect(dir.rotas.find((x) => x.rota === '1002').resumo.estado).toBe('por_contar');
});

test('requisição de reposição: criada pela conciliação, editada e emitida pelo promotor da rota, carga confirmada pelo armazém', async () => {
  const RQ = API + 'requisicoes';
  const sup = (await entrar('sup')).ctx;
  const lista = await (await sup.get(RQ + '?de=' + dia + '&ate=' + dia)).json();
  const r = lista.requisicoes.find((x) => x.rota === '1001');
  expect(r).toMatchObject({ estado: 'rascunho', origem: { tipo: 'conciliacao' } });
  expect(r.linhas.map((l) => [l.codigo, l.quantidade])).toEqual([['A1', 26]]);
  const post = async (ctx, corpo) => {
    const x = await ctx.post(RQ, { data: { ...corpo, csrf: await csrf(ctx) } });
    return { status: x.status(), json: await x.json() };
  };
  // fora do âmbito: o promotor da rota 1002 não vê nem altera a requisição da 1001
  const pro = (await entrar('pro')).ctx;
  expect((await pro.get(RQ + '?numero=' + r.numero)).status()).toBe(404);
  expect((await post(pro, { accao: 'gravar', numero: r.numero, versao: r.versao, linhas: [] })).status).toBe(404);
  expect((await post((await entrar('bo')).ctx, { accao: 'emitir', numero: r.numero })).status).toBe(403);
  // rascunho: juntar artigo e gravar; versão antiga recusada
  const g = (
    await post(sup, { accao: 'gravar', numero: r.numero, versao: r.versao, linhas: [...r.linhas, { codigo: 'B9', artigo: 'Sabão', quantidade: 4 }], nota: '' })
  ).json.requisicao;
  expect(g.linhas.length).toBe(2);
  expect((await post(sup, { accao: 'gravar', numero: r.numero, versao: r.versao, linhas: [] })).status).toBe(409);
  // emitida: fechada; quem emitiu não confirma a carga; o armazém confirma
  expect((await post(sup, { accao: 'emitir', numero: r.numero, versao: g.versao })).json.requisicao.estado).toBe('emitida');
  expect((await post(sup, { accao: 'gravar', numero: r.numero, versao: g.versao + 1, linhas: [] })).status).toBe(409);
  const log = (await entrar('log')).ctx;
  const carga = await (await log.get(RQ + '?carga=1&data=' + dia)).json();
  expect(carga.rotas['1001'][0].estado).toBe('emitida');
  expect((await post(sup, { accao: 'confirmar_carga', numero: r.numero, entregue: {} })).status).toBe(403);
  const c = (await post(log, { accao: 'confirmar_carga', numero: r.numero, entregue: { B9: 3 } })).json.requisicao;
  expect(c.estado).toBe('carregada');
  expect(c.linhas.map((l) => l.entregue)).toEqual([26, 3]);
});

test('correcção do registo de quem contou / validou: só o super administrador, com motivo, fica no histórico', async () => {
  const URL = API + 'conciliacao-carrinha';
  const post = async (ctx, corpo) => {
    const x = await ctx.post(URL, { data: { data: dia, ...corpo, csrf: await csrf(ctx) } });
    return { status: x.status(), json: await x.json() };
  };
  const corpo = {
    accao: 'corrigir_registo',
    rotas: ['1001', '1002'],
    contado_por: 'log',
    validado_por: 'sadm',
    motivo: 'contagem feita pelo Manuel e lançada na conta do Henrique',
  };
  expect((await post((await entrar('log')).ctx, corpo)).status).toBe(403);
  const sadm = (await entrar('sadm')).ctx;
  expect((await post(sadm, { ...corpo, motivo: 'curto' })).status).toBe(400);
  expect((await post(sadm, { ...corpo, contado_por: 'Manuel Queia' })).status).toBe(400); // só utilizadores da lista
  const r = await post(sadm, corpo);
  expect(r.json.rotas).toContain('1001');
  const v = await (await sadm.get(URL + '?data=' + dia)).json();
  expect(v.pode.corrigir).toBe(true);
  expect(v.utilizadores).toEqual(expect.arrayContaining(['log', 'sadm']));
  const x = v.rotas.find((y) => y.rota === '1001');
  expect(x).toMatchObject({ contado_por: 'log', validado_por: 'sadm' });
  expect(x.historico.at(-1)).toMatchObject({ accao: 'correccao_registo', motivo: corpo.motivo });
  // repetir não muda nada nem duplica o histórico
  expect((await post(sadm, corpo)).json.rotas).toEqual([]);
});
