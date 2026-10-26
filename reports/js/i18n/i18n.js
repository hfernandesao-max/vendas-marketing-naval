// Idioma do portal (PT/EN) e tradução dos textos.
import { EN } from './en.js';

export let LANG = 'pt';
try {
  LANG = localStorage.getItem('vm-lang') === 'en' ? 'en' : 'pt';
} catch (e) {}
export const t = (s, p) => {
  let r = LANG === 'en' && EN[s] != null ? EN[s] : s;
  if (p)
    Object.keys(p).forEach((k) => {
      r = r.split('{' + k + '}').join(p[k]);
    });
  return r;
};

// Alteração do estado partilhado: só este módulo altera estas variáveis; os outros lêem-nas.
export function definirIdioma(valor) {
  LANG = valor;
}
