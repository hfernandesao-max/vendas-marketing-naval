// Cargas (PO): número PO, conferência no cais com motivo das diferenças, validação do Back-Office com segregação de
// funções, ordem da Guia de Remessa ao Pinguim BO e o robô a executá-la nos ecrãs (página de ensaio do Pinguim BO).
import { test, expect, request } from '@playwright/test';
import { execFile } from 'node:child_process';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { entrar, API, BASE, csrf } from './ajuda.js';

test.describe.configure({ mode: 'serial' });
const CHAVE = 'chave-de-teste-do-robo-pinguim-0123456789abcdef';
const amanha = new Date(Date.now() + 86400000).toISOString().slice(0, 10);
let numero = '';

const post = async (ctx, rota, corpo) => {
  const x = await ctx.post(API + rota, { data: { ...corpo, csrf: await csrf(ctx) } });
  return { status: x.status(), json: await x.json() };
};

test('PO: emitida pelo promotor, conferida no cais com motivo, validada pelo Back-Office (segregação)', async () => {
  const sup = (await entrar('pro')).ctx; // promotor da rota 1002 (a 1001 é dos testes da conciliação)
  const c = await post(sup, 'requisicoes', {
    accao: 'criar',
    rota: '1002',
    data: amanha,
    linhas: [
      { codigo: 'C1', artigo: 'Fralda Naval', quantidade: 10 },
      { codigo: 'C2', artigo: 'Sabão verde', quantidade: 4 },
    ],
  });
  expect(c.status).toBe(200);
  numero = c.json.requisicao.numero;
  expect(numero).toMatch(/^PO-\d{4}-\d{6}$/);
  const e = await post(sup, 'requisicoes', { accao: 'emitir', numero, versao: c.json.requisicao.versao });
  expect(e.json.requisicao.estado).toBe('emitida');
  // o Fiel de Armazém (LOG) confere: diferença sem motivo é recusada; com motivo passa
  const log = (await entrar('log')).ctx;
  const sem = await post(log, 'requisicoes', { accao: 'confirmar_carga', numero, entregue: { C1: 10, C2: 3 } });
  expect(sem.status).toBe(400);
  expect(sem.json.erro).toContain('motivo');
  const ok = await post(log, 'requisicoes', { accao: 'confirmar_carga', numero, entregue: { C1: 10, C2: 3 }, motivos: { C2: { codigo: 'falta_armazem' } } });
  expect(ok.status).toBe(200);
  expect(ok.json.requisicao).toMatchObject({ estado: 'carregada', fase: 'conferida', confirmado_por: 'log' });
  expect(ok.json.requisicao.linhas[1]).toMatchObject({ entregue: 3, visto: true, motivo: { codigo: 'falta_armazem' } });
  // validação: o Back-Office; quem conferiu não valida; o promotor não tem o perfil
  expect((await post(sup, 'requisicoes', { accao: 'validar', numero })).status).toBe(403);
  expect((await post(log, 'requisicoes', { accao: 'validar', numero })).status).toBe(403);
  const bo = (await entrar('bo')).ctx;
  const lista = await (await bo.get(API + 'ordens-pinguim')).json();
  expect(lista.por_validar.map((r) => r.numero)).toContain(numero);
  expect(lista.por_validar.find((r) => r.numero === numero).diferencas).toBe(1);
  const v = await post(bo, 'requisicoes', { accao: 'validar', numero });
  expect(v.status).toBe(200);
  expect(v.json.requisicao).toMatchObject({ fase: 'validada', validado_por: 'bo', ordem: 'GUIA-' + numero });
  expect((await post(bo, 'requisicoes', { accao: 'validar', numero })).status).toBe(409);
  const o = (await (await bo.get(API + 'ordens-pinguim')).json()).ordens.find((x) => x.id === 'GUIA-' + numero);
  expect(o).toMatchObject({ estado: 'por_executar', modo: 'robo', rota: '1002', armazem_destino: '10001002', referencia: numero });
  expect(o.linhas.map((l) => [l.codigo, l.quantidade])).toEqual([
    ['C1', 10],
    ['C2', 3],
  ]);
});

test('robô: só com a chave; executa a ordem nos ecrãs do Pinguim BO e devolve o número do documento @navegador', async () => {
  const anon = await request.newContext({ baseURL: BASE });
  expect((await anon.get(API + 'robo/ordens')).status()).toBe(401);
  expect((await anon.get(API + 'robo/ordens', { headers: { Authorization: 'Bearer errada' } })).status()).toBe(401);
  await anon.dispose();
  const saida = await new Promise((ok, ko) =>
    execFile(
      process.execPath,
      ['ferramentas/robo-pinguim.mjs', '--uma-vez'],
      {
        env: {
          ...process.env,
          NAVAL_ENV: 'nao-existe.env',
          ROBO_PORTAL_URL: BASE,
          ROBO_PINGUIM_CHAVE: CHAVE,
          ROBO_PINGUIM_URL: BASE + '/tests/dados/pinguim-falso',
          ROBO_PINGUIM_UTILIZADOR: 'robo',
          ROBO_PINGUIM_SENHA: 'segredo',
          ROBO_RECEITA: 'ferramentas/robo-pinguim.receita.exemplo.json',
          ROBO_NOME: 'ensaio',
          DADOS_PRIVADOS: mkdtempSync(join(tmpdir(), 'robo-')),
        },
        timeout: 90000,
      },
      (e, out, err) => (e ? ko(new Error(String(e.message) + out + err)) : ok(out)),
    ),
  );
  expect(saida).toContain('GUIA-' + numero);
  const bo = (await entrar('bo')).ctx;
  const o = (await (await bo.get(API + 'ordens-pinguim')).json()).ordens.find((x) => x.id === 'GUIA-' + numero);
  expect(o).toMatchObject({ estado: 'gerada', executor: 'robo:ensaio', documento: { numero: 'GTC 2026/0001', por: 'robo:ensaio' } });
  // nada mais na fila
  const r = await request.newContext({ baseURL: BASE });
  expect(await (await r.get(API + 'robo/ordens', { headers: { Authorization: 'Bearer ' + CHAVE } })).json()).toMatchObject({ ok: true, ordem: null });
  await r.dispose();
});

test('ordens: Back-Office cancela com motivo; executada à mão; perfis sem acesso', async () => {
  const bo = (await entrar('bo')).ctx;
  const id = 'GUIA-' + numero;
  expect((await post(bo, 'ordens-pinguim', { accao: 'executada_manual', id, documento: 'GTC 2026/9' })).status).toBe(409); // já gerada
  expect((await post(bo, 'ordens-pinguim', { accao: 'cancelar', id, motivo: 'x' })).status).toBe(400);
  expect((await (await entrar('pro')).ctx.get(API + 'ordens-pinguim')).status()).toBe(403);
  expect((await post((await entrar('log')).ctx, 'ordens-pinguim', { accao: 'repetir', id })).status).toBe(403); // LOG só vê
  const c = await post(bo, 'ordens-pinguim', { accao: 'cancelar', id, motivo: 'Guia anulada no Pinguim BO' });
  expect(c.json.ordem.estado).toBe('cancelada');
});
