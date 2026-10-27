// Plano de cargas: rascunho, conflito entre duas pessoas, validação, publicação com email e quem vê o quê.
import { test, expect } from '@playwright/test';
import { entrar, csrf } from './ajuda.js';

const DATA = '2026-10-20';
const rota = (r, extra = {}) => ({
  rota: r,
  vendedor: 'Teste',
  sai: true,
  armazem: 'HIGITEC',
  viagens: 1,
  zonas: ['Avô Cumbe', 'AVO-CUMBE', 'Samba'],
  quantidades: { fraldas: 90, sabao: 10, sabonete: 0 },
  motorista: 'Adão Paulo',
  telefone: '924516916',
  matricula: 'ld-58-73-gy',
  viatura: 'Canter',
  transportadora: 'Velotrans',
  ...extra,
});
// Excel mínimo (cabeçalho de um ficheiro zip): o conteúdo real é gerado no navegador
const XLSX = Buffer.from('PK\u0003\u0004teste').toString('base64');

test.describe.configure({ mode: 'serial' });

test('a Logística guarda o rascunho; nomes de zona repetidos juntam-se; conflito de versão', async () => {
  const log = (await entrar('log')).ctx,
    c = await csrf(log);
  const r = await (await log.post('/reports/data/plano.php', { data: { accao: 'guardar', csrf: c, data: DATA, rev: 0, rotas: [rota('1001')] } })).json();
  expect(r.ok).toBe(true);
  expect(r.plano.rev).toBe(1);
  expect(r.plano.rotas[0].zonas).toEqual(['Avô Cumbe', 'Samba']);
  expect(r.plano.rotas[0].matricula).toBe('LD-58-73-GY');
  const conflito = await log.post('/reports/data/plano.php', { data: { accao: 'guardar', csrf: c, data: DATA, rev: 0, rotas: [] } });
  expect(conflito.status()).toBe(409);
});

test('publicar exige destinatários e rotas completas', async () => {
  const log = (await entrar('log')).ctx,
    c = await csrf(log);
  const r = await log.post('/reports/data/plano.php', {
    data: { accao: 'publicar', csrf: c, data: DATA, rev: 1, rotas: [rota('1001', { motorista: '', zonas: [] })], xlsx: XLSX },
  });
  expect(r.status()).toBe(400);
  const erros = (await r.json()).erros.join(' ');
  expect(erros).toContain('falta o motorista');
  expect(erros).toContain('destinatários');
});

test('publicar envia o email à lista e só a versão publicada chega aos outros perfis', async () => {
  const log = (await entrar('log')).ctx,
    c = await csrf(log);
  const l = await (
    await log.post('/reports/data/plano.php', {
      data: { accao: 'listas', csrf: c, listas: { destinatarios: [{ nome: 'Transportes', email: 'transportes@naval.co.ao' }] } },
    })
  ).json();
  expect(l.ok).toBe(true);
  const p = await (
    await log.post('/reports/data/plano.php', {
      data: {
        accao: 'publicar',
        csrf: c,
        data: DATA,
        rev: 1,
        rotas: [rota('1001'), rota('1002', { motorista: 'Domingos Filipe', matricula: 'LD-51-12-IJ' })],
        xlsx: XLSX,
      },
    })
  ).json();
  expect(p.ok).toBe(true);
  expect(p.plano.publicado.versao).toBe(1);
  expect(p.email).toMatchObject({ ok: true, para: ['transportes@naval.co.ao'] });

  const sup = await (await (await entrar('sup')).ctx.get(`/reports/data/plano.php?data=${DATA}`)).json();
  expect(sup.gere).toBe(false);
  expect(sup.plano.rotas.map((r) => r.rota)).toEqual(['1001']);
  expect((await (await entrar('fin')).ctx.get(`/reports/data/plano.php?data=${DATA}`)).status()).toBe(403);
});

test('publicar sem enviar; enviar depois só aos destinatários escolhidos da lista; rascunho exportado fica nos registos', async () => {
  const log = (await entrar('log')).ctx,
    c = await csrf(log);
  await log.post('/reports/data/plano.php', {
    data: {
      accao: 'listas',
      csrf: c,
      listas: {
        destinatarios: [
          { nome: 'Transportes', email: 'transportes@naval.co.ao' },
          { nome: 'Armazém', email: 'armazem@naval.co.ao' },
        ],
      },
    },
  });
  const DIA = '2026-10-21';
  const g = await (await log.post('/reports/data/plano.php', { data: { accao: 'guardar', csrf: c, data: DIA, rev: 0, rotas: [rota('1001')] } })).json();
  const p = await (
    await log.post('/reports/data/plano.php', {
      data: { accao: 'publicar', csrf: c, data: DIA, rev: g.plano.rev, rotas: [rota('1001')], xlsx: XLSX, enviar: false },
    })
  ).json();
  expect(p.ok).toBe(true);
  expect(p.email).toBeNull();
  expect(p.plano.publicado.versao).toBe(1);
  expect(p.plano.envios || []).toHaveLength(0);

  // destinatário fora da lista: recusado (só a lista controlada pela Logística)
  const fora = await log.post('/reports/data/plano.php', { data: { accao: 'enviar', csrf: c, data: DIA, para: ['alguem@externo.com'] } });
  expect(fora.status()).toBe(400);
  const e = await (await log.post('/reports/data/plano.php', { data: { accao: 'enviar', csrf: c, data: DIA, para: ['ARMAZEM@naval.co.ao'] } })).json();
  expect(e.ok).toBe(true);
  expect(e.email.para).toEqual(['armazem@naval.co.ao']);

  expect((await (await log.post('/reports/data/plano.php', { data: { accao: 'exportado', csrf: c, data: DIA } })).json()).ok).toBe(true);
  // quem só vê não envia nem publica
  const sup = (await entrar('sup')).ctx;
  expect((await sup.post('/reports/data/plano.php', { data: { accao: 'enviar', csrf: await csrf(sup), data: DIA } })).status()).toBe(403);
});
