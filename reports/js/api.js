// Cliente central da API (v1): o único caminho entre o front-end e o back-end.
//
// Segurança (OWASP JWT / RFC 8725):
//  - o token de acesso (JWT, 15 min) fica só em memória: nunca em localStorage, sessionStorage nem cookies
//    legíveis pelo JavaScript, para não ser roubado por um script injectado;
//  - obtém-se trocando a sessão aberta na página de entrada (cookie HttpOnly) com o código CSRF da página
//    (<meta name="csrf-token">), e renova-se sozinho 1 minuto antes de expirar ou depois de um 401;
//  - os pedidos vão só para a própria origem (same-origin), com «Authorization: Bearer».
// Sem servidor PHP (testes locais com ficheiros estáticos) os pedidos seguem sem token.
import { download } from './componentes/blocos.js';

export const API = '../api/index.php/v1/';

let token = null;
let expira = 0;
let aPedir = null;
let semServidor = false;

const csrfPagina = () => {
  const m = document.querySelector('meta[name="csrf-token"]');
  return m ? m.getAttribute('content') || '' : '';
};

/** Token de acesso válido (pede um novo quando falta menos de 1 minuto ou quando `novo` é verdadeiro). */
export function obterToken(novo = false) {
  if (semServidor) return Promise.resolve(null);
  if (!novo && token && Date.now() < expira - 60000) return Promise.resolve(token);
  if (!aPedir)
    aPedir = fetch(API + 'auth/token', {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfPagina() },
      body: '{}',
    })
      .then((r) => {
        if (!(r.headers.get('content-type') || '').includes('application/json')) {
          semServidor = true; // ficheiros estáticos (testes locais): sem API
          return null;
        }
        return r.json().then((d) => {
          if (r.ok && d && d.access_token) {
            token = d.access_token;
            expira = Date.now() + (d.expires_in || 900) * 1000;
            return token;
          }
          token = null;
          return null;
        });
      })
      .catch(() => null)
      .finally(() => {
        aPedir = null;
      });
  return aPedir;
}

/** Esquece o token (ex.: ao terminar a sessão). */
export function esquecerToken() {
  token = null;
  expira = 0;
}

/**
 * Pedido à API, com o mesmo contrato do fetch (devolve a Response). `rota` é relativa a v1/
 * (ex.: 'vendas?start_date=…'). Repete uma vez com um token novo quando a resposta é 401.
 */
export async function apiFetch(rota, init = {}) {
  const pedir = (tk) =>
    fetch(API + rota, {
      ...init,
      credentials: 'same-origin',
      cache: init.cache || 'no-cache', // valida com ETag (304) em vez de transferir de novo
      headers: { ...(init.headers || {}), ...(tk ? { Authorization: 'Bearer ' + tk } : {}) },
    });
  const tk = await obterToken();
  let r = await pedir(tk);
  if (r.status === 401 && tk) {
    const novo = await obterToken(true);
    if (novo) r = await pedir(novo);
  }
  return r;
}

/** GET que devolve o JSON (ou null se falhar). */
export const apiJson = (rota) =>
  apiFetch(rota)
    .then((r) => (r.ok && (r.headers.get('content-type') || '').includes('application/json') ? r.json() : null))
    .catch(() => null);

/** Transfere um ficheiro da API (Excel do plano de cargas, segunda via do SAP) com o token. */
export async function apiDescarregar(rota, nomeOmissao) {
  const r = await apiFetch(rota);
  if (!r.ok) {
    const d = await r.json().catch(() => null);
    throw new Error((d && d.erro) || 'Ficheiro não disponível.');
  }
  const cd = r.headers.get('content-disposition') || '';
  const m = cd.match(/filename="?([^";]+)"?/i);
  download(m ? m[1] : nomeOmissao, await r.blob());
}
