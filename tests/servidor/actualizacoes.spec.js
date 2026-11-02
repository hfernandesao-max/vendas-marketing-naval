// Página «Actualizações» (v1/actualizacoes): só o super administrador; alterações com CSRF e confirmação da identidade.
import { test, expect } from '@playwright/test';
import { entrar, API, SENHA } from './ajuda.js';

test('outros perfis: 403', async () => {
  for (const conta of ['ti', 'dir', 'log']) {
    const { ctx } = await entrar(conta);
    expect((await ctx.get(API + 'actualizacoes')).status(), conta).toBe(403);
  }
});

test('super administrador: vista sem GitHub configurado; decisões só com CSRF e confirmação da identidade', async () => {
  const { ctx } = await entrar('sadm');
  const r = await ctx.get(API + 'actualizacoes');
  expect(r.status()).toBe(200);
  const d = await r.json();
  expect(d.ok).toBe(true);
  expect(d.configurada).toBe(false);
  expect(d.exige_aprovacao).toBe(true);
  expect(d.pendentes).toEqual([]);
  expect(d.erro).toContain('ACTUALIZACAO_GITHUB_TOKEN');
  expect(JSON.stringify(d)).not.toContain('sha256');
  // sem CSRF: recusado
  expect((await ctx.post(API + 'actualizacoes', { data: { accao: 'aprovar', versao: 'v1' } })).status()).toBe(403);
  const csrf = (await (await ctx.get(API + 'sessao')).json()).csrf;
  // com CSRF: pede a confirmação da identidade antes de qualquer decisão
  const c = await (await ctx.post(API + 'actualizacoes', { data: { accao: 'aprovar', versao: 'v1', csrf } })).json();
  expect(c.confirmar).toBeTruthy();
  // palavra-passe confirmada: a versão desconhecida (sem GitHub) dá erro claro, nada é gravado
  const e = await ctx.post(API + 'actualizacoes', { data: { accao: 'aprovar', versao: 'v1', csrf, confirmacao: SENHA } });
  expect(e.status()).toBe(502);
  expect((await e.json()).erro).toContain('ACTUALIZACAO_GITHUB_TOKEN');
  // acção desconhecida e repor sem nenhuma actualização automática feita
  expect((await ctx.post(API + 'actualizacoes', { data: { accao: 'apagar', csrf } })).status()).toBe(400);
  expect((await ctx.post(API + 'actualizacoes', { data: { accao: 'reverter', csrf } })).status()).toBe(409);
});
