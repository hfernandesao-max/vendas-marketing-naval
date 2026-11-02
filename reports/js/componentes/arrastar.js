// Arrastar e largar os quadros de uma grelha de gráficos (rato; teclado: Alt + ← / → no título do quadro). Cada quadro
// tem a chave em data-grafico; a função aoMudar recebe a nova ordem das chaves.
import { t } from '../i18n/i18n.js';

export function arrastavel(grelha, aoMudar) {
  let origem = null;
  const ordem = () => [...grelha.children].map((c) => c.dataset.grafico).filter(Boolean);
  const limpar = () => grelha.querySelectorAll('.alvo-antes, .alvo-depois').forEach((c) => c.classList.remove('alvo-antes', 'alvo-depois'));
  [...grelha.children].forEach((card) => {
    if (!card.dataset.grafico) return;
    const pega = card.querySelector('.viz-h') || card;
    pega.classList.add('pega');
    pega.title = t('Arraste para mudar o lugar do gráfico (ou Alt + setas)');
    // só o título arrasta (os gráficos continuam com as dicas e os cliques)
    pega.addEventListener('pointerdown', () => (card.draggable = true));
    card.addEventListener('dragstart', (e) => {
      origem = card;
      card.classList.add('a-arrastar');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', card.dataset.grafico);
    });
    card.addEventListener('dragend', () => {
      card.draggable = false;
      card.classList.remove('a-arrastar');
      limpar();
      origem = null;
    });
    card.addEventListener('dragover', (e) => {
      if (!origem || origem === card) return;
      e.preventDefault();
      const b = card.getBoundingClientRect();
      const vertical = card.tagName === 'LI';
      const antes = vertical ? e.clientY < b.top + b.height / 2 : e.clientX < b.left + b.width / 2;
      limpar();
      card.classList.add(antes ? 'alvo-antes' : 'alvo-depois');
    });
    card.addEventListener('drop', (e) => {
      if (!origem || origem === card) return;
      e.preventDefault();
      const antes = card.classList.contains('alvo-antes');
      limpar();
      grelha.insertBefore(origem, antes ? card : card.nextSibling);
      aoMudar(ordem());
    });
    pega.tabIndex = 0;
    pega.setAttribute('aria-roledescription', t('quadro que se pode mover'));
    pega.addEventListener('keydown', (e) => {
      if (!e.altKey || (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight')) return;
      e.preventDefault();
      if (e.key === 'ArrowLeft' && card.previousElementSibling) grelha.insertBefore(card, card.previousElementSibling);
      else if (e.key === 'ArrowRight' && card.nextElementSibling) grelha.insertBefore(card.nextElementSibling, card);
      else return;
      pega.focus();
      aoMudar(ordem());
    });
  });
}

/** Ordena uma lista de quadros pela ordem de chaves indicada (as chaves desconhecidas ficam no fim, pela ordem de origem). */
export function ordenar(quadros, ordem) {
  if (!ordem || !ordem.length) return quadros;
  const pos = new Map(ordem.map((k, i) => [k, i]));
  return quadros
    .map((q, i) => ({ q, i, p: pos.has(q.dataset.grafico) ? pos.get(q.dataset.grafico) : 1000 + i }))
    .sort((a, b) => a.p - b.p)
    .map((x) => x.q);
}
