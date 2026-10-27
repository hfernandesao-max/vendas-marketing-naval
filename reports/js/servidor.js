// Comunicação com o servidor: pedidos POST com a protecção CSRF da sessão e carregamento,
// a pedido, das bibliotecas de terceiros (vendor/) que só algumas páginas usam.
import { apiFetch } from './api.js';
import { SESSAO } from './perfil.js';

const SEM_RESPOSTA = { ok: false, erro: 'Sem resposta do servidor.' };

/** POST JSON à API (rota relativa a v1/) com o token de acesso e o código CSRF; devolve sempre um objecto ({ok:false, erro} quando falha a rede ou o JSON). */
export const postJson = (rota, corpo) =>
  apiFetch(rota, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ...corpo, csrf: SESSAO && SESSAO.csrf }),
  }).then(
    (r) => r.json().catch(() => ({ ...SEM_RESPOSTA })),
    () => ({ ...SEM_RESPOSTA }),
  );

const scripts = {};
/** Carrega um script de vendor/ uma única vez; se falhar, a próxima chamada tenta de novo. */
export function carregarScript(ficheiro, global) {
  if (global && window[global]) return Promise.resolve();
  if (!scripts[ficheiro])
    scripts[ficheiro] = new Promise((ok, ko) => {
      const s = document.createElement('script');
      s.src = 'vendor/' + ficheiro;
      s.onload = ok;
      s.onerror = () => {
        delete scripts[ficheiro];
        s.remove();
        ko(new Error('Não foi possível carregar ' + ficheiro));
      };
      document.head.appendChild(s);
    });
  return scripts[ficheiro];
}
