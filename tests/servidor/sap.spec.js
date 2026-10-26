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
  const estado = await (await fin.get('/reports/data/sap.php')).json();
  expect(estado.exportados['FR0126S78515N/1'].lote).toBe(lote.lote.numero);
  expect((await fin.get(`/reports/data/sap.php?xlsx=${lote.lote.numero}`)).status()).toBe(200);
});
