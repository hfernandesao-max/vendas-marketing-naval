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

test('Página pública do QR: contacto gravado no portal, PT e EN, cabeçalhos estritos; leitura de um equipamento perdido fica na ficha', async () => {
  const log = await entrar('log');
  const ck = await csrf(log.ctx);
  expect((await log.ctx.post(API + 'equipamentos', { data: { accao: 'contacto', tel: 'abc', email: '', csrf: ck } })).status()).toBe(400);
  expect((await log.ctx.post(API + 'equipamentos', { data: { accao: 'contacto', tel: '', email: '', csrf: ck } })).status()).toBe(400);
  const g = await log.ctx.post(API + 'equipamentos', { data: { accao: 'contacto', tel: '+244 923 111 222', email: 'Equipamentos@naval.co.ao', csrf: ck } });
  expect(g.status()).toBe(200);
  expect((await g.json()).contacto).toMatchObject({ tel: '+244 923 111 222', email: 'equipamentos@naval.co.ao', origem: 'portal', alterado_por: 'log' });
  expect((await (await log.ctx.get(API + 'equipamentos')).json()).contacto.origem).toBe('portal');
  // o supervisor (sem «Administrar») não vê nem altera o contacto
  const sup = await entrar('sup');
  expect((await (await sup.ctx.get(API + 'equipamentos')).json()).contacto).toBeUndefined();
  expect((await sup.ctx.post(API + 'equipamentos', { data: { accao: 'contacto', tel: '+244 900 000 001', csrf: await csrf(sup.ctx) } })).status()).toBe(403);

  // equipamento dado como perdido
  const r = await log.ctx.post(API + 'equipamentos', {
    data: { accao: 'registar', tipo: 'IMP', num_serie: 'PERD-' + Date.now(), mac: '00:11:22:33:44:' + String((Date.now() % 90) + 10), csrf: ck },
  });
  const id = (await r.json()).equipamento.id;
  expect((await log.ctx.post(API + 'equipamentos', { data: { accao: 'evento', id, tipo: 'PERDA_ROUBO', observacoes: 'Teste', csrf: ck } })).status()).toBe(200);

  const anon = await request.newContext({ baseURL: BASE });
  const p = await anon.get('/equipamentos/ficha.php?id=' + id.toLowerCase(), { maxRedirects: 0 });
  expect(p.status()).toBe(200);
  const csp = p.headers()['content-security-policy'];
  expect(csp).toContain("frame-ancestors 'none'");
  expect(csp).toContain("default-src 'none'");
  expect(p.headers()['referrer-policy']).toBe('no-referrer');
  const html = await p.text();
  expect(html).toContain(id);
  expect(html).toContain('Impressora móvel');
  expect(html).toContain('Mobile printer');
  expect(html).toContain('This device is the property of Grupo Naval');
  expect(html).toContain('tel:+244923111222');
  expect(html).toContain('equipamentos@naval.co.ao');
  expect(html).not.toContain('<script');
  expect(html).not.toContain('PERD-');
  expect(html).not.toContain('perdido');
  // a resposta é a mesma para um ID que não existe
  const x = await (await anon.get('/equipamentos/ficha.php?id=NAV-IMP-9999', { maxRedirects: 0 })).text();
  expect(x).toContain('NAV-IMP-9999');
  expect(x).toContain('This device is the property of Grupo Naval');

  const f = await (await log.ctx.get(API + 'equipamentos/ficha?id=' + id)).json();
  expect(f.eventos.some((e) => e.tipo === 'LEITURA_QR' && /Leitura pública/.test(e.observacoes || ''))).toBe(true);

  // pré-visualização com sessão: a página pública, sem redireccionar
  const v = await log.ctx.get('/equipamentos/ficha.php?publica=1&id=' + id, { maxRedirects: 0 });
  expect(v.status()).toBe(200);
  expect(await v.text()).toContain('Pré-visualização da página pública');
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
