// Janelas de diálogo e campos de formulário reutilizáveis.
import { t } from '../i18n/i18n.js';
import { el, toast } from '../util.js';

// ---- janelas (diálogos) ----
export function janela(titulo, nota, montar) {
  const d = el('dialog', 'dlg'),
    f = el('form'),
    h = el('div', 'dlg-h'),
    b = el('div', 'dlg-b'),
    ft = el('div', 'dlg-f');
  h.append(el('h3', null, titulo));
  if (nota) h.append(el('p', null, nota));
  f.addEventListener('submit', (e) => e.preventDefault());
  f.append(h, b, ft);
  d.append(f);
  document.body.append(d);
  const fechar = () => {
    if (d.open) d.close();
  };
  d.addEventListener('close', () => setTimeout(() => d.remove(), 0));
  montar(b, ft, fechar, d);
  d.showModal();
  return d;
}
export const campo = (rotulo, input) => {
  const l = el('label', 'campo');
  l.append(el('span', null, rotulo), input);
  return l;
};
export const entrada = (tipo, valor, attrs) => {
  const i = el('input');
  i.type = tipo;
  if (valor != null) i.value = valor;
  Object.assign(i, attrs || {});
  return i;
};
export const botao = (txt, cls, fn) => {
  const b = el('button', 'btn' + (cls ? ' ' + cls : ''), txt);
  b.type = 'button';
  if (fn) b.addEventListener('click', fn);
  return b;
};
export const msgErro = () => {
  const p = el('p', 'erro');
  p.hidden = true;
  p.setAttribute('role', 'alert');
  return p;
};
export function confirmarAccao(titulo, texto, rotulo, accao) {
  janela(titulo, texto, (b, ft, fechar) => {
    const erro = msgErro();
    b.append(erro);
    const go = botao(rotulo, '', () => {
      go.disabled = true;
      accao().then((d) => {
        go.disabled = false;
        if (d.cancelado) return;
        if (!d.ok) {
          erro.textContent = t(d.erro || 'Não foi possível concluir.');
          erro.hidden = false;
          return;
        }
        fechar();
      });
    });
    ft.append(botao(t('Cancelar'), 'ghost', fechar), go);
  });
}
export const copiar = (txt) => {
  try {
    navigator.clipboard.writeText(txt).then(
      () => toast(t('Copiado.'), 2500),
      () => {},
    );
  } catch (e) {
    /* sem área de transferência */
  }
};
