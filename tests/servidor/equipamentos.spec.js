// Equipamentos no servidor (base SQLite de teste): matriz de acessos, âmbito por rota, acções com CSRF e a página
// pública do código QR, que não mostra dados internos.
import { test, expect, request } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { API, BASE, csrf, entrar } from './ajuda.js';

test('Equipamentos: lista para a Logística; o supervisor só vê a sua rota; sem a página na matriz → 403', async () => {
  const log = await entrar('log');
  const d = await (await log.ctx.get(API + 'equipamentos')).json();
  expect(d.disponivel).toBe(true);
  expect(d.equipamentos.length).toBeGreaterThanOrEqual(6);
  expect(d.pode).toMatchObject({ movimentar: true, administrar: true });

  const sup = await entrar('sup');
  const s = await (await sup.ctx.get(API + 'equipamentos')).json();
  expect(s.equipamentos.length).toBeGreaterThan(0);
  expect(s.equipamentos.every((e) => e.rota === '1001')).toBe(true);
  expect(s.pode).toMatchObject({ movimentar: false, administrar: false });
  expect((await sup.ctx.get(API + 'equipamentos/ficha?id=NAV-SMT-0003')).status()).toBe(403);

  const com = await entrar('com');
  expect((await com.ctx.get(API + 'equipamentos')).status()).toBe(403);
});

test('Equipamentos: alterações exigem CSRF e a permissão; registo e ficha', async () => {
  const sup = await entrar('sup');
  const r1 = await sup.ctx.post(API + 'equipamentos', { data: { accao: 'registar', tipo: 'SMT', num_serie: 'X1', csrf: await csrf(sup.ctx) } });
  expect(r1.status()).toBe(403);

  const log = await entrar('log');
  expect((await log.ctx.post(API + 'equipamentos', { data: { accao: 'registar', tipo: 'SMT', num_serie: 'X1' } })).status()).toBe(403);
  const r = await log.ctx.post(API + 'equipamentos', { data: { accao: 'registar', tipo: 'SMT', num_serie: 'SERV-' + Date.now(), csrf: await csrf(log.ctx) } });
  expect(r.status()).toBe(200);
  const id = (await r.json()).equipamento.id;
  expect(id).toMatch(/^NAV-SMT-\d{4}$/);
  const f = await (await log.ctx.get(API + 'equipamentos/ficha?id=' + id.toLowerCase())).json();
  expect(f.equipamento.estado).toBe('EM_STOCK');
  expect(f.eventos[0].tipo).toBe('ENTRADA');
});

test('Código QR: sem sessão, página pública sem dados internos; com sessão e permissão, abre a ficha', async () => {
  const anon = await request.newContext({ baseURL: BASE });
  const r = await anon.get('/equipamentos/ficha.php?id=nav-smt-0001', { maxRedirects: 0 });
  expect(r.status()).toBe(200);
  const html = await r.text();
  expect(html).toContain('NAV-SMT-0001');
  expect(html).toContain('propriedade do Grupo Naval');
  expect(html).toContain('+244 900 000 000');
  expect(html).not.toContain('Andrea');
  expect(html).not.toContain('1001');
  expect((await anon.get('/equipamentos/ficha.php?id=x%27or1', { maxRedirects: 0 })).status()).toBe(404);

  const log = await entrar('log');
  const q = await log.ctx.get('/equipamentos/ficha.php?id=NAV-SMT-0001', { maxRedirects: 0 });
  expect(q.status()).toBe(302);
  expect(q.headers().location).toBe('../reports/#equipamentos?id=NAV-SMT-0001&origem=qr');

  const com = await entrar('com');
  const c = await com.ctx.get('/equipamentos/ficha.php?id=NAV-SMT-0001', { maxRedirects: 0 });
  expect(c.status()).toBe(200);
  expect(await c.text()).toContain('não tem acesso');
});

test('Assinatura com a própria conta: o colaborador (perfil sem acesso ao módulo) só vê e assina o documento em seu nome', async () => {
  const assinatura = JSON.parse(readFileSync(new URL('../dados/assinatura-teste.json', import.meta.url), 'utf8'));
  const log = await entrar('log');
  const c = await (
    await log.ctx.post(API + 'equipamentos', {
      data: { accao: 'colaborador', nome: 'Promotor Teste', rota: '1002', utilizador: 'pro', csrf: await csrf(log.ctx) },
    })
  ).json();
  expect(c.ok).toBe(true);
  const e = await (
    await log.ctx.post(API + 'equipamentos', { data: { accao: 'emitir', tipo: 'CONVENCAO_PROVA', id_colaborador: c.id, csrf: await csrf(log.ctx) } })
  ).json();
  const num = e.documento.numero;

  const pro = await entrar('pro');
  expect((await pro.ctx.get(API + 'equipamentos')).status()).toBe(403);
  expect((await pro.ctx.get(API + 'equipamentos/documentos')).status()).toBe(403);
  const d = await pro.ctx.get(API + 'equipamentos/documentos?numero=' + num);
  expect(d.status()).toBe(200);
  expect((await pro.ctx.get(API + 'equipamentos/pdf?numero=' + num)).headers()['content-type']).toContain('application/pdf');
  const a = await (
    await pro.ctx.post(API + 'equipamentos', { data: { accao: 'assinar', numero: num, aceito: true, ...assinatura, csrf: await csrf(pro.ctx) } })
  ).json();
  expect(a.ok).toBe(true);
  expect(a.assinaturas[0].metodo).toBe('CONTA_PORTAL');
  // a 2.ª assinatura (responsável) não é do colaborador
  const b = await pro.ctx.post(API + 'equipamentos', { data: { accao: 'assinar', numero: num, aceito: true, ...assinatura, csrf: await csrf(pro.ctx) } });
  expect(b.status()).toBe(403);
  // documento de outra pessoa: recusado
  const outro = await (
    await log.ctx.post(API + 'equipamentos', { data: { accao: 'emitir', tipo: 'CONVENCAO_PROVA', id_colaborador: 2, csrf: await csrf(log.ctx) } })
  ).json();
  expect((await pro.ctx.get(API + 'equipamentos/documentos?numero=' + outro.documento.numero)).status()).toBe(403);
  // o responsável conclui
  const f = await (
    await log.ctx.post(API + 'equipamentos', { data: { accao: 'assinar', numero: num, aceito: true, ...assinatura, csrf: await csrf(log.ctx) } })
  ).json();
  expect(f.documento.estado).toBe('ASSINADO');
});
