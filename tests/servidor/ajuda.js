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

/** Entra com uma conta e devolve o contexto (com a sessão) e a resposta da entrada. */
export async function entrar(login, senha = SENHA) {
  const ctx = await request.newContext({ baseURL: BASE });
  const resposta = await ctx.post('/geo/login.php', {
    form: { usuario: login, senha, redirect: '../reports/', csrf: await tokenEntrada(ctx) },
    maxRedirects: 0,
  });
  return { ctx, resposta };
}

/** Código anti-falsificação da sessão (necessário nos pedidos de alteração). */
export async function csrf(ctx) {
  return (await (await ctx.get('/reports/data/sessao.php')).json()).csrf;
}

/** Texto da mensagem de erro mostrada na página de entrada. */
export async function erroEntrada(resposta) {
  const html = await resposta.text();
  const m = html.match(/class="error-alert">[\s\S]*?<span>([^<]*)<\/span>/);
  return m ? m[1].trim() : '';
}

export const vendasDoDia = async (ctx) => (await ctx.get(`/reports/data/api.php?${DIA}&include_items=1`)).json();

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
