// Estados de carregamento e de erro das páginas, como nas aplicações de referência (Material Design, Atlassian,
// Stripe): enquanto os dados chegam, o esqueleto da página (barras cinzentas no lugar do conteúdo, a frase continua
// para os leitores de ecrã); quando a leitura falha, diz porquê (sem ligação, sessão terminada, sem acesso, erro do
// servidor, com a hora) e dá «Tentar de novo». Aplica-se a todas as páginas depois de desenhadas, sem mudar cada uma.
import { ULTIMA_FALHA } from '../api.js';
import { t } from '../i18n/i18n.js';
import { el } from '../util.js';

const FALHAS = () => [t('Não foi possível ler os dados.'), t('Não foi possível desenhar esta página.')];
const RECENTE = 2 * 60000;

/** Motivo da última falha de leitura, se for recente; '' quando não se sabe. */
export function motivoFalha(agora = Date.now()) {
  const f = ULTIMA_FALHA;
  if (!f.em || agora - f.em > RECENTE) return '';
  const hora = new Date(f.em).toTimeString().slice(0, 5);
  let m;
  if (f.estado === 0) m = t('Sem ligação ao servidor do portal: verifique a rede.');
  else if (f.estado === 401) m = t('A sessão terminou: entre de novo.');
  else if (f.estado === 403) m = f.erro || t('O seu perfil não tem acesso a esta informação.');
  else if (f.estado >= 500) m = f.erro || t('O servidor teve um erro ao ler os dados.');
  else m = f.erro || t('O servidor devolveu uma resposta inesperada.');
  return t('{m} ({h})', { m, h: hora });
}

function esqueleto(caixa) {
  if (caixa.classList.contains('esqueleto')) return;
  const frase = caixa.textContent.trim();
  caixa.classList.add('esqueleto');
  caixa.setAttribute('aria-busy', 'true');
  caixa.setAttribute('role', 'status');
  const barras = el('div', 'esq-barras');
  barras.setAttribute('aria-hidden', 'true');
  ['l', 'm', 'c', 'l', 'm'].forEach((x) => barras.append(el('i', 'esq-' + x)));
  caixa.replaceChildren(el('span', 'esq-frase', frase), barras);
}

function erro(caixa) {
  if (caixa.classList.contains('com-erro')) return;
  caixa.classList.add('com-erro');
  caixa.setAttribute('role', 'alert');
  const frase = caixa.textContent.trim();
  const m = motivoFalha();
  const b = el('button', 'btn sm', t('Tentar de novo'));
  b.type = 'button';
  // volta a abrir a página no mesmo sítio (endereço, relatório e filtros ficam; a sessão mantém-se)
  b.addEventListener('click', () => location.reload());
  caixa.replaceChildren(el('strong', 'erro-frase', frase));
  if (m) caixa.append(el('span', 'erro-motivo', m));
  caixa.append(b);
}

/** Melhora os estados vazios da página: «A carregar…» → esqueleto; falha de leitura → motivo e «Tentar de novo». */
export function melhorarEstados(raiz) {
  if (!raiz) return;
  const falhas = FALHAS();
  raiz.querySelectorAll('.glass.empty').forEach((c) => {
    if (c.children.length) return; // só caixas de texto simples (as outras já têm conteúdo próprio)
    const x = c.textContent.trim();
    if (falhas.includes(x)) erro(c);
    else if (x.endsWith('…') && x.length <= 60) esqueleto(c);
  });
}

/**
 * Explicações longas no cabeçalho das secções: uma linha à vista e «Saber mais» para o resto (como o Stripe e o
 * Atlassian: o essencial primeiro, a explicação a pedido). O texto fica todo na página (leitores de ecrã, pesquisa).
 */
const NOTA_LONGA = 140;
export function recolherNotas(raiz) {
  if (!raiz) return;
  raiz.querySelectorAll('.sec-h > div:first-child > p').forEach((p) => {
    if (p.classList.contains('nota-curta') || p.textContent.trim().length <= NOTA_LONGA || p.querySelector('button, a, input, select')) return;
    p.classList.add('nota-curta');
    const b = el('button', 'linkbtn nota-mais', t('Saber mais'));
    b.type = 'button';
    b.setAttribute('aria-expanded', 'false');
    b.addEventListener('click', () => {
      const aberta = p.classList.toggle('aberta');
      b.textContent = aberta ? t('Mostrar menos') : t('Saber mais');
      b.setAttribute('aria-expanded', aberta ? 'true' : 'false');
    });
    p.after(b);
  });
}

/**
 * Tabelas feitas à parte (fora do componente das tabelas, que já se ajusta sozinho): num ecrã estreito, se a tabela
 * não cabe, cada linha passa a cartão «coluna: valor», como nas tabelas principais — nunca uma barra horizontal.
 */
export function ajustarTabelas(raiz) {
  if (!raiz) return;
  raiz.querySelectorAll('.tw > table, .cc-scroll > table').forEach((tb) => {
    if (tb.querySelector('[data-c]') || tb.classList.contains('cartoes')) return; // tabela do componente
    const w = tb.parentElement;
    if (w.clientWidth === 0 || w.clientWidth >= 560 || w.scrollWidth <= w.clientWidth + 1) return;
    const rot = [...tb.querySelectorAll('thead tr:last-child th, tr:first-child > th')].map((th) => th.textContent.trim());
    tb.querySelectorAll('tbody tr, tfoot tr').forEach((tr) =>
      [...tr.children].forEach((td, i) => {
        if (rot[i] && !td.dataset.l) td.dataset.l = rot[i];
      }),
    );
    tb.classList.add('cartoes');
  });
}
