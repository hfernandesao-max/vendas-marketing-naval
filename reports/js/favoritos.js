// Favoritos de cada pessoa (ISO 9241-110, individualização): até 6 funções fixadas no topo do menu, por ordem de
// escolha. Seguem a conta (v1/preferencias: iguais em qualquer computador ou telemóvel) e ficam também no navegador
// para o menu já sair certo no arranque.
import { postJson } from './servidor.js';

export const MAX_FAVORITOS = 6;
const K = 'vm-favoritos';
const ouvintes = [];
let lista = [];
try {
  const v = JSON.parse(localStorage.getItem(K) || '[]');
  if (Array.isArray(v)) lista = v.filter((x) => typeof x === 'string').slice(0, MAX_FAVORITOS);
} catch (e) {
  /* sem armazenamento */
}

const guardarLocal = () => {
  try {
    localStorage.setItem(K, JSON.stringify(lista));
  } catch (e) {
    /* sem armazenamento: vale até fechar a página */
  }
};
const avisar = () => ouvintes.forEach((f) => f(lista));

export const favoritos = () => lista.slice();
export const eFavorito = (id) => lista.includes(id);
export const aoMudarFavoritos = (f) => ouvintes.push(f);

/** Lista vinda da conta (v1/sessao): manda sobre a do navegador. */
export function definirFavoritos(v) {
  if (!Array.isArray(v)) return;
  lista = v.filter((x) => typeof x === 'string').slice(0, MAX_FAVORITOS);
  guardarLocal();
  avisar();
}

/** Fixa ou retira uma função; devolve false quando a lista já está cheia. */
export function alternarFavorito(id) {
  if (eFavorito(id)) lista = lista.filter((x) => x !== id);
  else if (lista.length >= MAX_FAVORITOS) return false;
  else lista = [...lista, id];
  guardarLocal();
  avisar();
  postJson('preferencias', { favoritos: lista }).catch(() => {});
  return true;
}
