// Atalhos de teclado (como o GitHub, o Gmail e o Linear): «g» seguido de uma letra vai para a página; «?» mostra a
// lista. Não actuam a escrever num campo nem com uma janela aberta. Só aparecem as páginas que a pessoa pode ver.
// «/» e Ctrl+K (procurar) estão na barra superior.
import { podeVer } from '../perfil.js';
import { t } from '../i18n/i18n.js';
import { el } from '../util.js';
import { botao, janela } from './janelas.js';

export const ATALHOS = [
  ['i', 'inicio', 'Início'],
  ['v', 'vendas-promotor', 'Vendas'],
  ['d', 'documentos', 'Documentos de venda'],
  ['l', 'clientes', 'Carteira de clientes'],
  ['r', 'requisicoes-carrinha', 'Requisições ao armazém'],
  ['c', 'conciliacao-carrinha', 'Conciliação da carrinha'],
  ['f', 'caixa-marketing', 'Fecho de caixa'],
  ['s', 'sap-exportar', 'Vendas para o SAP'],
  ['a', 'alertas', 'Alertas'],
];
const disponiveis = () => ATALHOS.filter(([, id]) => id === 'inicio' || podeVer(id));

function ajuda() {
  if (document.querySelector('dialog.atalhos-dlg[open]')) return;
  janela(t('Atalhos de teclado'), t('Carregue em «{g}» e depois na letra.', { g: 'g' }), (b, ft, fechar, d) => {
    d.classList.add('atalhos-dlg');
    const l = el('dl', 'atalhos-l');
    const linha = (teclas, txt) => {
      const dt = el('dt');
      teclas.forEach((k, i) => {
        if (i) dt.append(' ');
        dt.append(el('kbd', null, k));
      });
      l.append(dt, el('dd', null, txt));
    };
    disponiveis().forEach(([k, , nome]) => linha(['g', k], t(nome)));
    linha(['/'], t('Procurar'));
    linha(['Ctrl', 'K'], t('Procurar'));
    linha(['↑', '↓'], t('Linha anterior ou seguinte (na ficha aberta)'));
    linha(['Esc'], t('Fechar'));
    linha(['?'], t('Atalhos de teclado'));
    b.append(l);
    ft.append(botao(t('Fechar'), null, fechar));
  });
}

let montado = false;
export function montarAtalhos() {
  if (montado) return;
  montado = true;
  let g = 0;
  document.addEventListener('keydown', (ev) => {
    if (ev.ctrlKey || ev.metaKey || ev.altKey) return;
    const alvo = ev.target;
    if (alvo && alvo.closest && alvo.closest('input, textarea, select, [contenteditable="true"]')) return;
    if (document.querySelector('dialog[open]')) return;
    if (ev.key === '?') {
      ev.preventDefault();
      ajuda();
      return;
    }
    if (ev.key === 'g' || ev.key === 'G') {
      g = Date.now();
      return;
    }
    if (g && Date.now() - g < 1500) {
      g = 0;
      const a = disponiveis().find(([k]) => k === ev.key.toLowerCase());
      if (!a) return;
      ev.preventDefault();
      location.hash = '#' + a[1];
    }
  });
}
