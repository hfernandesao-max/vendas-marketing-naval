// Campos de data (ISO 8601 + WAI-ARIA): a data escreve-se à mão ou escolhe-se no calendário.
//   - Mostra-se e escreve-se sempre dia-mês-ano (dd-mm-aaaa), como no resto do portal, seja qual for o idioma do
//     navegador; guarda-se e envia-se sempre em ISO 8601 (aaaa-mm-dd), que é o valor do campo nativo.
//   - Ao escrever, os hífenes entram sozinhos (13112026 → 13-11-2026); aceita também / e . e datas coladas em ISO
//     (2026-11-13). A data só conta quando é válida (dia que existe) e está dentro dos limites (min, max); senão o
//     campo fica marcado (aria-invalid) com a explicação, e o valor anterior mantém-se.
//   - Botão «Abrir o calendário» (e Alt+↓ ou F4 no campo) abre o calendário do navegador; ↑ e ↓ mudam um dia
//     (PgUp e PgDn um mês), como nos ERP de referência.
// É uma melhoria progressiva: o <input type="date"> continua a ser o campo de verdade (value, eventos «input» e
// «change», min e max), por isso todas as páginas funcionam sem mudanças; fica só visualmente escondido.
import { t } from '../i18n/i18n.js';

const ISO = /^(\d{4})-(\d{2})-(\d{2})$/;
const pad = (n) => String(n).padStart(2, '0');
const desc = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');

/** aaaa-mm-dd → dd-mm-aaaa ('' se vazia ou inválida). */
export const isoParaTexto = (iso) => {
  const m = ISO.exec(String(iso || ''));
  return m ? m[3] + '-' + m[2] + '-' + m[1] : '';
};

/** Texto escrito → aaaa-mm-dd, '' se vazio, ou null se não for uma data válida. Aceita dd-mm-aaaa, dd/mm/aaaa, dd.mm.aaaa, ddmmaaaa e ISO. */
export function textoParaIso(txt) {
  const s = String(txt || '').trim();
  if (!s) return '';
  let d, m, a;
  let r;
  if ((r = /^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$/.exec(s))) [, a, m, d] = r;
  else if ((r = /^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})$/.exec(s))) [, d, m, a] = r;
  else if ((r = /^(\d{2})(\d{2})(\d{4})$/.exec(s))) [, d, m, a] = r;
  else return null;
  const dt = new Date(Date.UTC(+a, +m - 1, +d));
  if (dt.getUTCFullYear() !== +a || dt.getUTCMonth() !== +m - 1 || dt.getUTCDate() !== +d) return null;
  return a + '-' + pad(m) + '-' + pad(d);
}

/** Soma dias (ou meses) a uma data ISO. */
function somar(iso, dias, meses = 0) {
  const m = ISO.exec(iso);
  if (!m) return iso;
  const dt = new Date(Date.UTC(+m[1], +m[2] - 1 + meses, +m[3] + dias));
  return dt.getUTCFullYear() + '-' + pad(dt.getUTCMonth() + 1) + '-' + pad(dt.getUTCDate());
}

/** Máscara ao escrever: só dígitos e hífenes, com os hífenes no sítio (dd-mm-aaaa). */
function mascara(s) {
  if (/^\d{4}-/.test(s)) return s.slice(0, 10); // ISO colado: deixa como está
  const dig = s.replace(/\D/g, '').slice(0, 8);
  if (dig.length <= 2) return dig + (s.length > dig.length && dig.length === 2 && /[-/.]$/.test(s) ? '-' : '');
  if (dig.length <= 4) return dig.slice(0, 2) + '-' + dig.slice(2);
  return dig.slice(0, 2) + '-' + dig.slice(2, 4) + '-' + dig.slice(4);
}

const ICONE =
  '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3.5" y="5" width="17" height="15" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>';

/** Melhora um <input type="date"> (uma vez): campo de texto dd-mm-aaaa + botão do calendário. */
export function melhorarData(nat) {
  if (!nat || nat.dataset.dataIso === '1' || nat.type !== 'date') return;
  nat.dataset.dataIso = '1';
  const caixa = document.createElement('span');
  caixa.className = 'data-iso';
  const txt = document.createElement('input');
  txt.type = 'text';
  txt.className = 'data-txt' + (nat.className ? ' ' + nat.className : '');
  txt.inputMode = 'numeric';
  txt.autocomplete = 'off';
  txt.spellcheck = false;
  txt.maxLength = 10;
  txt.placeholder = t('dd-mm-aaaa');
  // o nome acessível passa para o campo que se usa (o nativo sai da ordem de tabulação e dos leitores de ecrã)
  ['aria-label', 'aria-labelledby', 'aria-describedby', 'title', 'required'].forEach((a) => {
    if (nat.hasAttribute(a)) txt.setAttribute(a, nat.getAttribute(a));
  });
  if (nat.id) {
    const lab = document.querySelector('label[for="' + nat.id + '"]');
    if (lab && !txt.hasAttribute('aria-label') && !txt.hasAttribute('aria-labelledby')) {
      const s = lab.querySelector('span');
      if (s && s.id) txt.setAttribute('aria-labelledby', s.id);
      else txt.setAttribute('aria-label', lab.textContent.trim());
    }
    txt.id = nat.id + '-txt';
  }
  const dica = document.createElement('span');
  dica.className = 'data-erro';
  dica.id = (nat.id || 'd' + Math.random().toString(36).slice(2, 8)) + '-erro';
  dica.setAttribute('role', 'alert');
  dica.hidden = true;
  txt.setAttribute('aria-describedby', ((txt.getAttribute('aria-describedby') || '') + ' ' + dica.id).trim());
  const bt = document.createElement('button');
  bt.type = 'button';
  bt.className = 'data-cal';
  bt.innerHTML = ICONE;
  bt.setAttribute('aria-label', t('Abrir o calendário'));
  bt.title = t('Abrir o calendário');
  nat.tabIndex = -1;
  nat.setAttribute('aria-hidden', 'true');
  nat.classList.add('data-nativo');
  nat.parentNode.insertBefore(caixa, nat);
  caixa.append(txt, bt, nat, dica);
  const dv = caixa.parentNode && caixa.parentNode.querySelector('.dv');
  if (dv) dv.hidden = true;

  const mostrar = () => {
    txt.value = isoParaTexto(desc.get.call(nat));
    erro('');
  };
  // o valor do campo nativo continua a mandar: quem o mudar por código vê o texto actualizado
  Object.defineProperty(nat, 'value', {
    configurable: true,
    get: () => desc.get.call(nat),
    set: (v) => {
      desc.set.call(nat, v);
      mostrar();
    },
  });
  const erro = (m) => {
    dica.textContent = m;
    dica.hidden = !m;
    txt.classList.toggle('cx-falta', !!m);
    txt.setAttribute('aria-invalid', m ? 'true' : 'false');
    caixa.classList.toggle('invalida', !!m);
  };
  const fora = (iso) =>
    (nat.min && iso < nat.min && t('A data não pode ser anterior a {d}.', { d: isoParaTexto(nat.min) })) ||
    (nat.max && iso > nat.max && t('A data não pode ser posterior a {d}.', { d: isoParaTexto(nat.max) })) ||
    '';
  /** Grava no campo nativo (e avisa a página) se a data for válida; devolve verdadeiro se ficou. */
  const confirmar = (iso, avisarSeIgual) => {
    if (iso === null) {
      erro(t('Data inválida: escreva dia-mês-ano (ex.: {e}).', { e: isoParaTexto(somar(new Date().toISOString().slice(0, 10), 0)) }));
      return false;
    }
    if (iso === '' && nat.required) {
      erro(t('Indique a data.'));
      return false;
    }
    const f = iso && fora(iso);
    if (f) {
      erro(f);
      return false;
    }
    erro('');
    if (iso === desc.get.call(nat) && !avisarSeIgual) return true;
    desc.set.call(nat, iso);
    nat.dispatchEvent(new Event('input', { bubbles: true }));
    nat.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  };
  txt.addEventListener('input', () => {
    const fim = txt.selectionStart === txt.value.length;
    const m = mascara(txt.value);
    if (m !== txt.value) txt.value = m;
    if (fim) txt.setSelectionRange(m.length, m.length);
    // completa: confirma logo (como os campos de data com máscara das apps de referência)
    if (txt.value.length === 10) {
      const iso = textoParaIso(txt.value);
      if (confirmar(iso) && iso) txt.value = isoParaTexto(iso);
    } else erro('');
  });
  txt.addEventListener('blur', () => {
    const iso = textoParaIso(txt.value);
    if (confirmar(iso)) mostrar();
  });
  const abrir = () => {
    try {
      if (typeof nat.showPicker === 'function') return nat.showPicker();
    } catch (e) {
      /* sem gesto do utilizador ou navegador antigo: usa-se o campo nativo */
    }
    nat.classList.add('data-nativo-visivel');
    nat.focus();
    nat.click();
  };
  bt.addEventListener('click', abrir);
  txt.addEventListener('keydown', (e) => {
    const k = e.key;
    if (k === 'F4' || (e.altKey && k === 'ArrowDown')) {
      e.preventDefault();
      return abrir();
    }
    if (k === 'Enter') {
      const iso = textoParaIso(txt.value);
      if (confirmar(iso)) mostrar();
      return;
    }
    const passo = { ArrowUp: [1, 0], ArrowDown: [-1, 0], PageUp: [0, 1], PageDown: [0, -1] }[k];
    if (passo && !e.altKey) {
      e.preventDefault();
      const base = textoParaIso(txt.value) || desc.get.call(nat) || new Date().toISOString().slice(0, 10);
      const iso = somar(base, passo[0], passo[1]);
      if (!fora(iso) && confirmar(iso)) txt.value = isoParaTexto(iso);
    }
  });
  // escolha no calendário do navegador
  nat.addEventListener('change', () => {
    nat.classList.remove('data-nativo-visivel');
    mostrar();
  });
  mostrar();
}

/** Melhora todos os campos de data que aparecerem na página (as páginas criam-nos com entrada('date')). */
export function vigiarDatas(raiz = document.body) {
  const tratar = (n) => {
    if (n.nodeType !== 1) return;
    if (n.matches && n.matches('input[type="date"]')) melhorarData(n);
    if (n.querySelectorAll) n.querySelectorAll('input[type="date"]').forEach(melhorarData);
  };
  tratar(raiz);
  new MutationObserver((ms) => ms.forEach((m) => m.addedNodes.forEach(tratar))).observe(raiz, { childList: true, subtree: true });
}
