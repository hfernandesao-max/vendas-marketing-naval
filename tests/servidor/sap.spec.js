// Ficheiro para o SAP: quem gera, correspondência das rotas, lotes e modelo original.
import { test, expect } from '@playwright/test';
import { entrar, csrf } from './ajuda.js';

test('Financeiro gera; TI só vê; Direcção não tem acesso', async () => {
  const fin = (await entrar('fin')).ctx;
  expect((await (await fin.get('/reports/data/sap.php')).json()).pode_gerar).toBe(true);
  const ti = (await entrar('ti')).ctx;
  expect((await (await ti.get('/reports/data/sap.php')).json()).pode_gerar).toBe(false);
  expect((await ti.post('/reports/data/sap.php', { data: { accao: 'config', csrf: await csrf(ti), config: {} } })).status()).toBe(403);
  expect((await (await entrar('dir')).ctx.get('/reports/data/sap.php')).status()).toBe(403);
});

test('o modelo do SAP é servido só a quem tem acesso e está intacto', async () => {
  const r = await (await entrar('fin')).ctx.get('/reports/data/sap.php?modelo=1');
  expect(r.status()).toBe(200);
  expect((await r.body()).subarray(0, 2).toString()).toBe('PK');
});

test('correspondência e lote: os documentos ficam marcados como enviados', async () => {
  const fin = (await entrar('fin')).ctx,
    c = await csrf(fin);
  const cfg = await (
    await fin.post('/reports/data/sap.php', {
      data: { accao: 'config', csrf: c, config: { prefixo: 'FHR PIV', moeda: 'AOA', unidade: 'CAR', rotas: { 1001: { local: 'rtm1' } } } },
    })
  ).json();
  expect(cfg.config.rotas['1001'].local).toBe('RTM1');
  const xlsx = Buffer.from('PK\u0003\u0004teste').toString('base64');
  const lote = await (
    await fin.post('/reports/data/sap.php', {
      data: { accao: 'lote', csrf: c, de: '2026-10-24', ate: '2026-10-24', documentos: ['FR0126S78515N/1'], linhas: 2, total: 461400, xlsx },
    })
  ).json();
  expect(lote.ok).toBe(true);
  expect(lote.lote).toMatchObject({ estado: 'emitido', sha256: expect.stringMatching(/^[0-9a-f]{64}$/) });
  const estado = await (await fin.get('/reports/data/sap.php')).json();
  expect(estado.exportados['FR0126S78515N/1'].lote).toBe(lote.lote.numero);
});

test('controlo de ficheiros emitidos: sem segunda emissão, segunda via só para auditoria, anulação com quatro olhos', async () => {
  const fin = (await entrar('fin')).ctx,
    c = await csrf(fin);
  const xlsx = Buffer.from('PK\u0003\u0004teste').toString('base64');
  const emitir = (ctx, cs, docs) =>
    ctx.post('/reports/data/sap.php', { data: { accao: 'lote', csrf: cs, de: '2026-10-24', ate: '2026-10-24', documentos: docs, linhas: 1, total: 1, xlsx } });

  // o mesmo documento não pode ser emitido outra vez (nem misturado com outros)
  const rep = await emitir(fin, c, ['FT0126S78516N/1', 'FR0126S78515N/1']);
  expect(rep.status()).toBe(409);
  expect((await rep.json()).duplicados).toEqual(['FR0126S78515N/1']);

  // quem emite não volta a descarregar; a auditoria tem a segunda via, marcada e igual ao original
  expect((await fin.get('/reports/data/sap.php?xlsx=1')).status()).toBe(403);
  const aud = (await entrar('aud')).ctx;
  expect(await (await aud.get('/reports/data/sap.php')).json().then((d) => d.segunda_via)).toBe(true);
  const sv = await aud.get('/reports/data/sap.php?xlsx=1');
  expect(sv.status()).toBe(200);
  expect(sv.headers()['content-disposition']).toContain('SEGUNDA_VIA_');
  expect((await sv.body()).toString()).toBe('PK\u0003\u0004teste');

  // novo lote; anulação: motivo obrigatório e por outra pessoa
  const l2 = (await (await emitir(fin, c, ['FT0126S78516N/1'])).json()).lote;
  const anular = (ctx, cs, motivo) => ctx.post('/reports/data/sap.php', { data: { accao: 'anular', csrf: cs, lote: l2.numero, motivo } });
  expect((await anular(fin, c, 'ficheiro perdido no envio')).status()).toBe(403);
  const bo = (await entrar('bo')).ctx,
    cb = await csrf(bo);
  expect((await anular(bo, cb, 'curto')).status()).toBe(400);
  const an = await (await anular(bo, cb, 'ficheiro perdido no envio')).json();
  expect(an.lote).toMatchObject({ estado: 'anulado', anulado: { por: 'bo', motivo: 'ficheiro perdido no envio' } });
  // anulado: os documentos ficam livres para um novo lote, com outro número
  const l3 = (await (await emitir(fin, c, ['FT0126S78516N/1'])).json()).lote;
  expect(l3.numero).toBe(l2.numero + 1);

  // carregado no SAP: confirmado e já não pode ser anulado
  const conf = await (await bo.post('/reports/data/sap.php', { data: { accao: 'confirmar', csrf: cb, lote: l3.numero, referencia: 'SAP-4711' } })).json();
  expect(conf.lote).toMatchObject({ estado: 'carregado', carregado: { referencia: 'SAP-4711' } });
  const tarde = await bo.post('/reports/data/sap.php', { data: { accao: 'anular', csrf: cb, lote: l3.numero, motivo: 'tentativa depois do SAP' } });
  expect(tarde.status()).toBe(409);
  expect((await tarde.json()).erro).toContain('estorno');
});
