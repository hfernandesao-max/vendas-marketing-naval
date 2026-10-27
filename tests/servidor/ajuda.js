// Funções comuns aos testes do servidor: entrar com uma conta de teste e chamar os serviços.
import { request } from '@playwright/test';
import { createHmac } from 'node:crypto';

export const BASE = 'http://127.0.0.1:8810';
export const SENHA = 'Teste-12345!';
export const DIA = 'start_date=20261024&end_date=20261024';

/** Código anti-falsificação do formulário de entrada (lido da página, como faz o navegador). */
export async function tokenEntrada(ctx) {
  const html = await (await ctx.get('/geo/login.php')).text();
  return (html.match(/name="csrf" value="([^"]+)"/) || [])[1] || '';
}

export const API = '/api/index.php/v1/';

/** Código CSRF da página do portal (<meta name="csrf-token">), como o lê o cliente da API no navegador. */
export async function csrfPagina(ctx) {
  const html = await (await ctx.get('/reports/')).text();
  return (html.match(/name="csrf-token" content="([^"]+)"/) || [])[1] || '';
}

/** Troca a sessão por um token de acesso JWT (POST v1/auth/token). */
export async function pedirToken(ctx) {
  const r = await ctx.post(API + 'auth/token', { headers: { 'X-CSRF-Token': await csrfPagina(ctx) }, data: {} });
  return r.ok() ? (await r.json()).access_token : null;
}

/**
 * Contexto com o comportamento do cliente da API do portal: os pedidos a /api/ levam o token de acesso
 * («Authorization: Bearer»), pedido na primeira vez e renovado depois de um 401.
 */
export function comToken(ctx) {
  let token = null;
  // um Authorization explícito no pedido (testes de tokens inválidos) tem prioridade
  const comCab = (opcoes, tk) => ({ ...(opcoes || {}), headers: { ...(tk ? { Authorization: 'Bearer ' + tk } : {}), ...((opcoes || {}).headers || {}) } });
  const pedido = (metodo) => async (url, opcoes) => {
    if (!String(url).startsWith('/api/') || ((opcoes || {}).headers || {}).Authorization) return ctx[metodo](url, opcoes);
    if (!token) token = await pedirToken(ctx);
    let r = await ctx[metodo](url, comCab(opcoes, token));
    if (r.status() === 401 && token) {
      token = await pedirToken(ctx);
      if (token) r = await ctx[metodo](url, comCab(opcoes, token));
    }
    return r;
  };
  const metodos = { get: pedido('get'), post: pedido('post'), fetch: pedido('fetch'), head: pedido('head'), delete: pedido('delete') };
  return new Proxy(ctx, { get: (alvo, k) => (k in metodos ? metodos[k] : typeof alvo[k] === 'function' ? alvo[k].bind(alvo) : alvo[k]) });
}

/** Entra com uma conta e devolve o contexto (com a sessão e o token da API) e a resposta da entrada. */
export async function entrar(login, senha = SENHA) {
  const ctx = await request.newContext({ baseURL: BASE });
  const resposta = await ctx.post('/geo/login.php', {
    form: { usuario: login, senha, redirect: '../reports/', csrf: await tokenEntrada(ctx) },
    maxRedirects: 0,
  });
  return { ctx: comToken(ctx), resposta };
}

/** Código anti-falsificação da sessão (necessário nos pedidos de alteração). */
export async function csrf(ctx) {
  return (await (await ctx.get(API + 'sessao')).json()).csrf;
}

/** Texto da mensagem de erro mostrada na página de entrada. */
export async function erroEntrada(resposta) {
  const html = await resposta.text();
  const m = html.match(/class="error-alert">[\s\S]*?<span>([^<]*)<\/span>/);
  return m ? m[1].trim() : '';
}

export const vendasDoDia = async (ctx) => (await ctx.get(`${API}vendas?${DIA}&include_items=1`)).json();

/** Código TOTP (RFC 6238, 6 dígitos, 30 s) de um segredo base32, como a aplicação de autenticação. */
export function totp(segredo, quando = Date.now()) {
  const alf = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const bits = segredo
    .replace(/[^A-Z2-7]/gi, '')
    .toUpperCase()
    .split('')
    .map((c) => alf.indexOf(c).toString(2).padStart(5, '0'))
    .join('');
  const chave = Buffer.from((bits.match(/.{8}/g) || []).map((b) => parseInt(b, 2)));
  const passo = Buffer.alloc(8);
  passo.writeUInt32BE(Math.floor(quando / 30000), 4);
  const h = createHmac('sha1', chave).update(passo).digest();
  const o = h[19] & 0xf;
  return String((h.readUInt32BE(o) & 0x7fffffff) % 1000000).padStart(6, '0');
}
