// Suporte e incidentes (ISO/IEC 20000-1, 27035) e Conformidade (ISO/IEC 27001, 27701, 22301), com contas reais.
import { test, expect } from '@playwright/test';
import { rmSync } from 'node:fs';
import { entrar, API, csrf } from './ajuda.js';

test.describe.configure({ mode: 'serial' });
const PRIV = 'tests/servidor/ambiente/dados-privados';
test.beforeAll(() => rmSync(`${PRIV}/suporte`, { recursive: true, force: true }));

test('suporte: abrir, gerir, resolver com causa e lições, avaliar; cada um vê os seus', async () => {
  const com = (await entrar('log')).ctx;
  const cc = await csrf(com);
  const r = await (
    await com.post(API + 'suporte', {
      data: { accao: 'criar', tipo: 'incidente', prioridade: 'P1', titulo: 'Portal parado na loja', descricao: 'Ecrã branco', csrf: cc },
    })
  ).json();
  expect(r.ok, JSON.stringify(r)).toBe(true);
  const id = r.pedido.id;
  expect(id).toMatch(/^SUP-\d{6}$/);
  // outra pessoa não vê o pedido
  const fin = (await entrar('bo')).ctx;
  expect((await (await fin.get(API + 'suporte')).json()).pedidos.map((p) => p.id)).not.toContain(id);
  // quem gere (TI) vê, atende e resolve; num P1 a resolução exige a causa e as lições
  const ti = (await entrar('ti')).ctx;
  const ct = await csrf(ti);
  const v = await (await ti.get(API + 'suporte')).json();
  expect(v.gere).toBe(true);
  expect(v.pedidos.map((p) => p.id)).toContain(id);
  await ti.post(API + 'suporte', { data: { accao: 'atender', id, csrf: ct } });
  const sem = await ti.post(API + 'suporte', { data: { accao: 'resolver', id, texto: 'Reiniciado', csrf: ct } });
  expect(sem.status()).toBe(400);
  const res = await (
    await ti.post(API + 'suporte', {
      data: { accao: 'resolver', id, texto: 'Reiniciado o Apache', causa: 'Memória do PHP', licoes: 'Alerta de memória', csrf: ct },
    })
  ).json();
  expect(res.pedido.estado).toBe('resolvido');
  // quem abriu avalia e fecha; o TI não pode avaliar por ele
  expect((await ti.post(API + 'suporte', { data: { accao: 'avaliar', id, satisfacao: 5, csrf: ct } })).status()).toBe(403);
  const fe = await (await com.post(API + 'suporte', { data: { accao: 'avaliar', id, satisfacao: 4, csrf: cc } })).json();
  expect(fe.pedido.estado).toBe('fechado');
  expect(fe.pedido.historico.map((h) => h.accao)).toEqual(['criado', 'atender', 'resolver', 'avaliar']);
  // sem CSRF, recusado
  expect((await com.post(API + 'suporte', { data: { accao: 'criar', tipo: 'pedido', prioridade: 'P4', titulo: 'Sem csrf' } })).status()).toBe(403);
});

test('conformidade: TI e auditoria vêem; outros não; só o SADM confirma a revisão de acessos', async () => {
  const ti = (await entrar('ti')).ctx;
  const v = await (await ti.get(API + 'conformidade?verificar=1')).json();
  expect(v.ok).toBe(true);
  expect(v.verificacao.ok).toBe(true);
  expect(v.verificacao.encadeadas).toBeGreaterThan(0);
  expect(v.pode_confirmar).toBe(false);
  expect(v.retencao.map((r) => r.id)).toContain('registos');
  expect((await (await entrar('aud')).ctx.get(API + 'conformidade')).status()).toBe(200);
  expect((await (await entrar('log')).ctx.get(API + 'conformidade')).status()).toBe(403);
  const ct = await csrf(ti);
  expect((await ti.post(API + 'conformidade', { data: { accao: 'confirmar_revisao', trimestre: v.revisao_actual, csrf: ct } })).status()).toBe(403);
});

test('não conformidade: a resolução exige a causa e a acção correctiva', async () => {
  const log = (await entrar('log')).ctx;
  const r = await (
    await log.post(API + 'suporte', {
      data: { accao: 'criar', tipo: 'nao_conformidade', prioridade: 'P3', titulo: 'Revisão de acessos fora do prazo', csrf: await csrf(log) },
    })
  ).json();
  expect(r.ok, JSON.stringify(r)).toBe(true);
  const ti = (await entrar('ti')).ctx;
  const ct = await csrf(ti);
  const id = r.pedido.id;
  const sem = await ti.post(API + 'suporte', { data: { accao: 'resolver', id, texto: 'Revista', causa: 'Férias', csrf: ct } });
  expect(sem.status()).toBe(400);
  const ok = await (
    await ti.post(API + 'suporte', {
      data: { accao: 'resolver', id, texto: 'Revista', causa: 'Férias', correctiva: 'Aviso 7 dias antes do fim do trimestre', csrf: ct },
    })
  ).json();
  expect(ok.pedido.estado).toBe('resolvido');
  expect(ok.pedido.correctiva).toBe('Aviso 7 dias antes do fim do trimestre');
});

test('SGSI: 93 controlos, riscos, RoPA e documentos; só o SADM aprova, uma vez por versão', async () => {
  rmSync(`${PRIV}/conformidade/aprovacoes.json`, { force: true });
  const ti = (await entrar('ti')).ctx;
  const v = await (await ti.get(API + 'conformidade')).json();
  expect(v.sgsi.controlos).toHaveLength(93);
  expect(v.sgsi.documentos.map((d) => d.id)).toEqual(['ambito', 'manual', 'objectivos', 'soa', 'riscos', 'ropa', 'auditoria']);
  expect(v.sgsi.documentos.every((d) => d.estado === 'por_aprovar')).toBe(true);
  expect(v.sgsi.nao_conformidades.total).toBeGreaterThan(0);
  expect((await ti.post(API + 'conformidade', { data: { accao: 'aprovar_documento', doc: 'soa', csrf: await csrf(ti) } })).status()).toBe(403);
  const sadm = (await entrar('sadm')).ctx;
  const cs = await csrf(sadm);
  const a = await (await sadm.post(API + 'conformidade', { data: { accao: 'aprovar_documento', doc: 'soa', notas: 'Reunião', csrf: cs } })).json();
  expect(a.ok, JSON.stringify(a)).toBe(true);
  const soa = a.sgsi.documentos.find((d) => d.id === 'soa');
  expect(soa.estado).toBe('aprovado');
  expect(soa.aprovacao.hash).toBe(soa.hash);
  expect((await sadm.post(API + 'conformidade', { data: { accao: 'aprovar_documento', doc: 'soa', csrf: cs } })).status()).toBe(409);
});
