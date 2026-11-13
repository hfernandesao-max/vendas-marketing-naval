// Escolha de artigo pelo teclado (padrão «combobox» com lista da WAI-ARIA 1.2, como a ajuda de valores F4 dos ERP):
//   - Enter, F4 ou ↓ abre a lista dos artigos activos; escrever filtra por código ou nome (sem acentos, várias palavras);
//   - ↑ ↓ (e PgUp, PgDn, Home, End) percorrem a lista; a mesma tecla (Enter ou F4) selecciona; Esc fecha;
//   - depois de seleccionar, o foco passa ao campo seguinte (a quantidade), para lançar só com o teclado.
// O rato também funciona (clicar numa linha selecciona). Mostra até 200 resultados de cada vez.
import { normTxt } from '../filtros.js';
import { n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { el } from '../util.js';

let seq = 0;
const MAX = 200;

/**
 * artigos: [{codigo, artigo, disponivel?}]. depois(artigo): chamado ao seleccionar. Devolve {caixa, input,
 * escolhido(), limpar()}: a caixa (campo + lista) a pôr na página e o artigo seleccionado (ou null).
 */
export function escolherArtigo(artigos, { rotulo, marcador, depois } = {}) {
  const id = 'art-' + ++seq;
  const caixa = el('div', 'art-combo');
  const input = el('input');
  input.type = 'text';
  input.autocomplete = 'off';
  input.placeholder = marcador || t('Artigo (nome ou código)');
  input.setAttribute('role', 'combobox');
  input.setAttribute('aria-autocomplete', 'list');
  input.setAttribute('aria-expanded', 'false');
  input.setAttribute('aria-controls', id + '-lista');
  if (rotulo) input.setAttribute('aria-label', rotulo);
  const lista = el('ul', 'art-lista');
  lista.id = id + '-lista';
  lista.setAttribute('role', 'listbox');
  if (rotulo) lista.setAttribute('aria-label', rotulo);
  lista.hidden = true;
  const ajuda = el('span', 'note art-ajuda', t('Enter ou F4: ver a lista · ↑ ↓: escolher · Enter: seleccionar · Esc: fechar'));
  caixa.append(input, lista, ajuda);

  let vis = [];
  let act = -1;
  let sel = null;
  const etiqueta = (a) => a.codigo + ' · ' + a.artigo;

  const filtrar = () => {
    const termos = normTxt(input.value).split(/\s+/).filter(Boolean);
    // o texto do artigo já escolhido não filtra (ao reabrir mostra a lista toda)
    if (sel && input.value === etiqueta(sel)) termos.length = 0;
    vis = artigos.filter((a) => {
      const txt = normTxt(a.codigo + ' ' + a.artigo);
      return termos.every((w) => txt.includes(w));
    });
  };
  const marcar = (i) => {
    act = Math.max(0, Math.min(vis.length - 1, i));
    [...lista.children].forEach((li, k) => {
      const on = k === act;
      li.classList.toggle('on', on);
      li.setAttribute('aria-selected', on ? 'true' : 'false');
      if (on) li.scrollIntoView({ block: 'nearest' });
    });
    if (vis[act]) input.setAttribute('aria-activedescendant', id + '-o' + act);
    else input.removeAttribute('aria-activedescendant');
  };
  const desenhar = () => {
    lista.textContent = '';
    if (!vis.length) {
      const li = el('li', 'art-vazio note', artigos.length ? t('Nenhum artigo com «{q}».', { q: input.value.trim() }) : t('A carregar…'));
      li.setAttribute('role', 'option');
      li.setAttribute('aria-disabled', 'true');
      lista.append(li);
      return;
    }
    vis.slice(0, MAX).forEach((a, k) => {
      const li = el('li', 'art-op');
      li.id = id + '-o' + k;
      li.setAttribute('role', 'option');
      li.append(el('b', null, a.codigo), el('span', null, a.artigo));
      if (a.disponivel != null) li.append(el('span', 'note', t('disponível: {q}', { q: n0(a.disponivel) })));
      // mousedown (não click) para escolher antes de o campo perder o foco
      li.addEventListener('mousedown', (e) => {
        e.preventDefault();
        act = k;
        escolher();
      });
      lista.append(li);
    });
    if (vis.length > MAX) lista.append(el('li', 'art-vazio note', t('Mais {n} artigos: escreva para filtrar.', { n: n0(vis.length - MAX) })));
  };
  const abrir = () => {
    filtrar();
    desenhar();
    lista.hidden = false;
    input.setAttribute('aria-expanded', 'true');
    const i = sel ? vis.indexOf(sel) : -1;
    marcar(i >= 0 ? i : 0);
  };
  const fechar = () => {
    lista.hidden = true;
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
  };
  const escolher = () => {
    const a = vis[act];
    if (!a) return;
    sel = a;
    input.value = etiqueta(a);
    fechar();
    if (depois) depois(a);
  };

  input.addEventListener('input', () => {
    sel = null;
    abrir();
  });
  input.addEventListener('keydown', (e) => {
    const aberta = !lista.hidden;
    const k = e.key;
    if (k === 'Enter' || k === 'F4') {
      e.preventDefault();
      if (aberta && vis[act]) escolher();
      else if (!aberta) abrir();
      return;
    }
    if (k === 'ArrowDown' || k === 'ArrowUp') {
      e.preventDefault();
      if (!aberta) return abrir();
      marcar(act + (k === 'ArrowDown' ? 1 : -1));
      return;
    }
    if (!aberta) return;
    if (k === 'PageDown' || k === 'PageUp') {
      e.preventDefault();
      marcar(act + (k === 'PageDown' ? 10 : -10));
    } else if (k === 'Home' && e.ctrlKey) {
      e.preventDefault();
      marcar(0);
    } else if (k === 'End' && e.ctrlKey) {
      e.preventDefault();
      marcar(Math.min(vis.length, MAX) - 1);
    } else if (k === 'Escape') {
      e.preventDefault();
      fechar();
    } else if (k === 'Tab') {
      fechar();
    }
  });
  input.addEventListener('blur', () => setTimeout(fechar, 120));

  return {
    caixa,
    input,
    escolhido: () => sel || artigos.find((a) => input.value.trim() === etiqueta(a) || input.value.trim() === a.codigo) || null,
    limpar: () => {
      sel = null;
      input.value = '';
    },
  };
}
