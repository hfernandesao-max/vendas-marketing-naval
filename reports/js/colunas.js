// Colunas de cada tabela à medida de cada pessoa (ISO 9241-110, individualização): ordem, colunas escondidas e
// largura. A escolha segue a conta (v1/preferencias: igual em qualquer computador ou telemóvel) e fica também no
// navegador para a tabela já sair certa no arranque. Chave: «página:tabela».
//   {o: [ids pela ordem escolhida], h: [ids escondidos], w: {id: largura em px}}
import { postJson } from './servidor.js';

const K = 'vm-colunas';
let M = {};
try {
  const v = JSON.parse(localStorage.getItem(K) || '{}');
  if (v && typeof v === 'object' && !Array.isArray(v)) M = v;
} catch (e) {
  /* sem armazenamento */
}
const guardarLocal = () => {
  try {
    localStorage.setItem(K, JSON.stringify(M));
  } catch (e) {
    /* sem armazenamento: vale até fechar a página */
  }
};

/** Arranjo guardado para a tabela (ou null: a ordem e as colunas de origem). */
export const layoutDe = (chave) => M[chave] || null;

/** Arranjos vindos da conta (v1/sessao): mandam sobre os do navegador. */
export function definirColunas(v) {
  if (!v || typeof v !== 'object' || Array.isArray(v)) return;
  M = v;
  guardarLocal();
}

// envio agrupado: arrastar ou dimensionar várias vezes seguidas faz um só pedido ao servidor
let timer = null;
const pendentes = {};
/** Grava o arranjo de uma tabela (null repõe a de origem). */
export function gravarLayout(chave, L) {
  const vazio = !L || (!(L.o || []).length && !(L.h || []).length && !Object.keys(L.w || {}).length);
  if (vazio) delete M[chave];
  else M[chave] = L;
  guardarLocal();
  pendentes[chave] = vazio ? null : L;
  clearTimeout(timer);
  timer = setTimeout(() => {
    const x = { ...pendentes };
    Object.keys(pendentes).forEach((k) => delete pendentes[k]);
    postJson('preferencias', { colunas: x }).catch(() => {});
  }, 800);
}
