// Ficheiro para o SAP: quem gera, correspondência das rotas, lotes e modelo original.
import { test, expect } from '@playwright/test';
import { createHash } from 'node:crypto';
import { readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { entrar, csrf } from './ajuda.js';

const PRIVADO = 'tests/servidor/ambiente/dados-privados';
const emails = () =>
  readdirSync(PRIVADO + '/email-saida')
    .filter((f) => f.endsWith('.json'))
    .map((f) => JSON.parse(readFileSync(PRIVADO + '/email-saida/' + f, 'utf8')));

test('Financeiro gera; TI só vê; Direcção não tem acesso', async () => {
  const fin = (await entrar('fin')).ctx;
  expect((await (await fin.get('/api/index.php/v1/sap')).json()).pode_gerar).toBe(true);
  const ti = (await entrar('ti')).ctx;
  expect((await (await ti.get('/api/index.php/v1/sap')).json()).pode_gerar).toBe(false);
  expect((await ti.post('/api/index.php/v1/sap', { data: { accao: 'config', csrf: await csrf(ti), config: {} } })).status()).toBe(403);
  expect((await (await entrar('dir')).ctx.get('/api/index.php/v1/sap')).status()).toBe(403);
});

test('o modelo do SAP é servido só a quem tem acesso e está intacto', async () => {
  const r = await (await entrar('fin')).ctx.get('/api/index.php/v1/sap/modelo');
  expect(r.status()).toBe(200);
  expect((await r.body()).subarray(0, 2).toString()).toBe('PK');
});

test('correspondência e lote: os documentos ficam marcados como enviados', async () => {
  const fin = (await entrar('fin')).ctx,
    c = await csrf(fin);
  const cfg = await (
    await fin.post('/api/index.php/v1/sap', {
      data: { accao: 'config', csrf: c, config: { prefixo: 'FHR PIV', moeda: 'AOA', unidade: 'CAR', rotas: { 1001: { local: 'rtm1' } } } },
    })
  ).json();
  expect(cfg.config.rotas['1001'].local).toBe('RTM1');
  const xlsx = Buffer.from('PK\u0003\u0004teste').toString('base64');
  const lote = await (
    await fin.post('/api/index.php/v1/sap', {
      data: { accao: 'lote', csrf: c, de: '2026-10-24', ate: '2026-10-24', documentos: ['FR0126S78515N/1'], linhas: 2, total: 461400, xlsx },
    })
  ).json();
  expect(lote.ok).toBe(true);
  expect(lote.lote).toMatchObject({ estado: 'emitido', sha256: expect.stringMatching(/^[0-9a-f]{64}$/) });
  const estado = await (await fin.get('/api/index.php/v1/sap')).json();
  expect(estado.exportados['FR0126S78515N/1'].lote).toBe(lote.lote.numero);
});

test('controlo de ficheiros emitidos: sem segunda emissão, segunda via só para auditoria, anulação com quatro olhos', async () => {
  const fin = (await entrar('fin')).ctx,
    c = await csrf(fin);
  const xlsx = Buffer.from('PK\u0003\u0004teste').toString('base64');
  const emitir = (ctx, cs, docs) =>
    ctx.post('/api/index.php/v1/sap', { data: { accao: 'lote', csrf: cs, de: '2026-10-24', ate: '2026-10-24', documentos: docs, linhas: 1, total: 1, xlsx } });

  // o mesmo documento não pode ser emitido outra vez (nem misturado com outros)
  const rep = await emitir(fin, c, ['FT0126S78516N/1', 'FR0126S78515N/1']);
  expect(rep.status()).toBe(409);
  expect((await rep.json()).duplicados).toEqual(['FR0126S78515N/1']);

  // quem emite não volta a descarregar; a auditoria tem a segunda via, marcada e igual ao original
  expect((await fin.get('/api/index.php/v1/sap/excel?numero=1')).status()).toBe(403);
  const aud = (await entrar('aud')).ctx;
  expect(await (await aud.get('/api/index.php/v1/sap')).json().then((d) => d.segunda_via)).toBe(true);
  const sv = await aud.get('/api/index.php/v1/sap/excel?numero=1');
  expect(sv.status()).toBe(200);
  expect(sv.headers()['content-disposition']).toContain('SEGUNDA_VIA_');
  expect((await sv.body()).toString()).toBe('PK\u0003\u0004teste');

  // novo lote; anulação: motivo obrigatório e por outra pessoa
  const l2 = (await (await emitir(fin, c, ['FT0126S78516N/1'])).json()).lote;
  const anular = (ctx, cs, motivo) => ctx.post('/api/index.php/v1/sap', { data: { accao: 'anular', csrf: cs, lote: l2.numero, motivo } });
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
  const conf = await (await bo.post('/api/index.php/v1/sap', { data: { accao: 'confirmar', csrf: cb, lote: l3.numero, referencia: 'SAP-4711' } })).json();
  expect(conf.lote).toMatchObject({ estado: 'carregado', carregado: { referencia: 'SAP-4711' } });
  const tarde = await bo.post('/api/index.php/v1/sap', { data: { accao: 'anular', csrf: cb, lote: l3.numero, motivo: 'tentativa depois do SAP' } });
  expect(tarde.status()).toBe(409);
  expect((await tarde.json()).erro).toContain('estorno');
});

test('SAP location automática: armazéns principais 1150 → RTM1 e 1151 → RTM2; origem do stock por rota', async () => {
  const fin = (await entrar('fin')).ctx;
  const d = await (await fin.get('/api/index.php/v1/sap')).json();
  expect(d.config.armazens['1150'].local).toBe('RTM1');
  expect(d.config.armazens['1151'].local).toBe('RTM2');
  expect(d.origens.fonte).toBeTruthy();
  expect(d.origens.rotas['1001'].local).toBe('RTM2');
  expect(Object.keys(d.origens.rotas['1001'].armazens)).toEqual(['1151']);
  // armazéns principais configuráveis; sem SAP location são recusados
  const c = await csrf(fin);
  const r = await fin.post('/api/index.php/v1/sap', { data: { accao: 'config', csrf: c, config: { armazens: { 1150: { nome: 'X', local: '' } } } } });
  expect(r.status()).toBe(400);
  // location por omissão: válida no SAP, nunca «N/A»; por omissão RTM2
  expect(d.config.local_omissao).toBe('RTM2');
  const na = await fin.post('/api/index.php/v1/sap', { data: { accao: 'config', csrf: c, config: { local_omissao: 'N/A' } } });
  expect(na.status()).toBe(400);
  expect((await na.json()).erro).toContain('N/A');
});

test('lote com linhas na location por omissão: a excepção fica registada no lote', async () => {
  const fin = (await entrar('fin')).ctx,
    c = await csrf(fin);
  const xlsx = Buffer.from('PK\u0003\u0004teste').toString('base64');
  const r = await (
    await fin.post('/api/index.php/v1/sap', {
      data: {
        accao: 'lote',
        csrf: c,
        de: '2026-10-25',
        ate: '2026-10-25',
        documentos: ['FR TESTE-OMISSAO/1'],
        linhas: 3,
        total: 1000,
        omissao: { linhas: 3, rotas: ['1009'], local: 'RTM2' },
        xlsx,
      },
    })
  ).json();
  expect(r.ok).toBe(true);
  expect(r.lote.omissao).toEqual({ linhas: 3, rotas: ['1009'], local: 'RTM2' });
});

test('envio automático: configuração guardada, email de teste sem lote e confirmação pela ligação do email', async ({ page }) => {
  const fin = (await entrar('fin')).ctx,
    c = await csrf(fin);
  const base = (await (await fin.get('/api/index.php/v1/sap')).json()).config;
  const g = await (
    await fin.post('/api/index.php/v1/sap', {
      data: { accao: 'config', csrf: c, config: { ...base, envio: { activo: false, hora: '16:00', para: 'sap@naval.co.ao', avisar: 'equipa@naval.co.ao' } } },
    })
  ).json();
  expect(g.config.envio).toEqual({ activo: false, hora: '16:00', para: ['sap@naval.co.ao'], avisar: ['equipa@naval.co.ao'] });
  const lotesAntes = (await (await fin.get('/api/index.php/v1/sap')).json()).lotes.length;
  const t = await (await fin.post('/api/index.php/v1/sap', { data: { accao: 'envio_teste', csrf: c, envio: g.config.envio } })).json();
  expect(t.ok).toBe(true);
  expect([t.teste.para.ok, t.teste.avisar.ok]).toEqual([true, true]);
  expect((await (await fin.get('/api/index.php/v1/sap')).json()).lotes.length).toBe(lotesAntes);
  expect(emails().some((m) => m.assunto.startsWith('[TEST / TESTE]') && m.para[0].emailAddress.address === 'sap@naval.co.ao')).toBe(true);

  // lote automático com a ligação «Já carreguei no SAP» (código de uso único; só a impressão digital fica guardada)
  const codigo = 'a'.repeat(40);
  const f = PRIVADO + '/sap/lotes.json';
  const lotes = JSON.parse(readFileSync(f, 'utf8'));
  const n = Math.max(0, ...lotes.map((l) => l.numero)) + 1;
  lotes.push({
    numero: n,
    estado: 'emitido',
    em: '2026-11-25 16:00:00',
    por: 'sistema',
    por_nome: 'Envio automático',
    de: '2026-11-25',
    ate: '2026-11-25',
    rotas: [],
    n_documentos: 1,
    linhas: 1,
    total: 1140,
    ficheiro: 'RTM_Sales_SAP_Upload_2026-11-25_lote' + n + '.xlsx',
    bytes: 4,
    sha256: '0'.repeat(64),
    documentos: ['FR LIGACAO/' + n],
    automatico: true,
    confirmacao: { hash: createHash('sha256').update(codigo).digest('hex'), expira: '2099-01-01 00:00:00' },
    envio: { assunto: 'RTM sales for SAP / Vendas RTM para o SAP · 25/11/2026 · batch / lote ' + n, para: ['sap@naval.co.ao'], ok: true },
  });
  writeFileSync(f, JSON.stringify(lotes));
  expect((await page.goto('/app/sap-carregado.php?l=' + n + '&c=' + 'b'.repeat(40))).status()).toBe(200);
  await expect(page.locator('#sap-invalida')).toBeVisible();
  await page.goto('/app/sap-carregado.php?l=' + n + '&c=' + codigo);
  await page.getByLabel('Your name · O seu nome').fill('Muzamil');
  await page.getByLabel('SAP reference · Referência do SAP').fill('SAP-5100023');
  await page.getByRole('button', { name: /Confirm loaded into SAP/ }).click();
  await expect(page.locator('#sap-ok')).toContainText('SAP-5100023');
  const l = (await (await fin.get('/api/index.php/v1/sap')).json()).lotes.find((x) => x.numero === n);
  expect(l).toMatchObject({ estado: 'carregado', carregado: { referencia: 'SAP-5100023', por_nome: 'Muzamil (email)' } });
  expect(l.confirmacao).toBeUndefined();
  const aviso = emails().find((m) => m.assunto === 'RE: RTM sales for SAP / Vendas RTM para o SAP · 25/11/2026 · batch / lote ' + n);
  expect(aviso.para.map((p) => p.emailAddress.address)).toEqual(['equipa@naval.co.ao', 'sap@naval.co.ao']);
  // a ligação é de uso único
  await page.goto('/app/sap-carregado.php?l=' + n + '&c=' + codigo);
  await expect(page.locator('#sap-invalida')).toBeVisible();
});
