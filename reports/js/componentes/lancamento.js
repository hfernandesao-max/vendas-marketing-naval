// Lançamento linha a linha (contagens, conferências de carga, valores por documento): o mesmo comportamento em todo
// o portal, para quem escreve muitos números seguidos não se perder nem errar.
//   - a linha em que se está a escrever fica destacada; Enter passa à seguinte (como o Tab) e, na última, ao botão
//     de gravar; Ctrl+Enter grava de qualquer linha; o número já escrito fica seleccionado para se corrigir logo;
//   - visto verde nas linhas já preenchidas e contador «N de M preenchidos», com «Ir ao primeiro em falta»;
//   - procurar artigo pelo código ou pelo nome nas listas longas;
//   - valores fora do normal (casas decimais num artigo à unidade, quantidade muito acima do esperado) ficam a âmbar,
//     com a explicação, e pedem confirmação antes de gravar;
//   - rascunho opcional neste navegador (só o que mudou), reposto se a página voltar a abrir.
import { t } from '../i18n/i18n.js';
import { n0 } from '../formatos.js';
import { el } from '../util.js';
import { botao, janela } from './janelas.js';
import { apagarRascunho, guardarRascunho, lerRascunho } from './rascunho.js';

const LIMIAR_PROCURA = 8;
// valor de um campo para o rascunho e para «em falta» (a caixa de visto conta pelo estado, não pelo value)
const valor = (i) => (i.type === 'checkbox' ? (i.checked ? '1' : '') : i.value);
const definir = (i, v) => (i.type === 'checkbox' ? (i.checked = v === '1') : (i.value = v));
const norm = (s) =>
  String(s || '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase();

/** Artigos vendidos à unidade (caixa, pacote, unidade): a quantidade não leva casas decimais. */
export const aUnidade = (un) => !un || /^(un|und|unid|unidade|uni|cx|caixa|pac|pacote|emb|fardo|fd|pc|pç|pca)\.?s?$/i.test(String(un).trim());

/**
 * Regra comum de valor fora do normal para quantidades: casas decimais num artigo à unidade; mais do dobro do
 * esperado (com pelo menos 10 de diferença); ou um número muito grande (≥ 10 000) que costuma ser engano.
 */
export function quantidadeAtipica(valor, { unidade, esperado } = {}) {
  if (valor === '' || valor == null) return null;
  const v = Number(valor);
  if (!isFinite(v)) return null;
  if (aUnidade(unidade) && Math.abs(v - Math.round(v)) > 1e-9) return t('casas decimais num artigo à unidade');
  if (esperado != null && esperado >= 0 && v > 2 * esperado && v - esperado >= 10) return t('mais do dobro do esperado ({e})', { e: n0(esperado) });
  if (v >= 10000) return t('número muito alto: confirme');
  return null;
}

/** Diferença viva ao lado do campo: ✓ verde se bate com o esperado, senão a diferença a vermelho (+3, −5). */
export function diferencaViva(input, esperado) {
  const s = el('span', 'lc-dif');
  s.setAttribute('aria-live', 'polite');
  const actual = () => {
    if (input.value === '') {
      s.textContent = '';
      s.className = 'lc-dif';
      return;
    }
    const d = Math.round((Number(input.value) - Number(esperado)) * 1000) / 1000;
    s.textContent = Math.abs(d) < 1e-9 ? '✓' : (d > 0 ? '+' : '−') + n0(Math.abs(d));
    s.className = 'lc-dif ' + (Math.abs(d) < 1e-9 ? 'lc-dif-ok' : 'lc-dif-mal');
    s.title = Math.abs(d) < 1e-9 ? t('Igual ao pedido') : t('Diferença face ao pedido');
  };
  input.addEventListener('input', actual);
  actual();
  return s;
}

/**
 * Liga o comportamento a uma tabela já desenhada.
 * opts:
 *   tabela   — a <table> (a 1.ª linha com <th> é o cabeçalho);
 *   linhas   — [{ tr, campos: [input…], codigo?, texto?, atipico?: () => texto|null, preenchida?: () => bool }];
 *   gravar   — o botão que grava (Enter na última linha e Ctrl+Enter vão para ele);
 *   visto    — acrescenta a coluna do visto verde (por omissão sim);
 *   progresso— mostra «N de M preenchidos» e «Ir ao primeiro em falta» (por omissão sim);
 *   procurar — caixa de procura (por omissão a partir de 8 linhas);
 *   chave    — rascunho neste navegador (só os valores que mudaram);
 *   fixar    — elemento que fica sempre visível no fundo do ecrã no telemóvel (por omissão o botão de gravar).
 * Devolve { barra, actualizar, limparRascunho, confirmar(seguir), emFalta }.
 */
export function lancamento(opts) {
  const { tabela, linhas, gravar } = opts;
  const visto = opts.visto !== false;
  const comProgresso = opts.progresso !== false;
  const comProcura = opts.procurar != null ? opts.procurar : linhas.length >= LIMIAR_PROCURA;
  tabela.classList.add('lc-tab');
  if (gravar) (opts.fixar || gravar).classList.add('lc-gravar');
  const ordem = () => linhas.filter((x) => !x.tr.hidden).flatMap((x) => x.campos.filter((i) => !i.disabled && !i.readOnly && !i.hidden));
  const preenchida = (x) => (x.preenchida ? x.preenchida() : x.campos.every((i) => valor(i) !== ''));
  const emFalta = () => linhas.filter((x) => !preenchida(x));

  // cabeçalho do visto
  const cab = tabela.querySelector('tr');
  if (visto && cab && cab.querySelector('th')) {
    const th = el('th', 'lc-visto-th');
    th.setAttribute('aria-label', t('Preenchido'));
    cab.append(th);
  }
  const prog = el('span', 'lc-prog');
  prog.setAttribute('aria-live', 'polite');
  const irFalta = botao(t('Ir ao primeiro em falta'), 'ghost sm', () => {
    const x = emFalta()[0];
    if (!x) return;
    if (x.tr.hidden && procura) {
      procura.value = '';
      filtrar();
    }
    const i = x.campos.find((c) => valor(c) === '' && !c.disabled) || x.campos[0];
    if (i) i.focus();
    x.tr.scrollIntoView({ block: 'center', behavior: 'smooth' });
  });

  const marcar = (x) => {
    x.tr.classList.toggle('lc-feita', preenchida(x));
    const txt = x.atipico ? x.atipico() : null;
    x.tr.classList.toggle('lc-atipico', !!txt);
    if (txt) {
      if (!x.aviso) {
        x.aviso = el('span', 'lc-atipico-txt');
        const alvo = x.campos[x.campos.length - 1];
        alvo.parentElement.append(x.aviso);
      }
      x.aviso.textContent = '⚠ ' + txt;
      x.aviso.hidden = false;
    } else if (x.aviso) x.aviso.hidden = true;
  };
  const actualizar = () => {
    linhas.forEach(marcar);
    if (!comProgresso) return;
    const falta = emFalta().length;
    prog.textContent = t('{a} de {b} preenchidos', { a: n0(linhas.length - falta), b: n0(linhas.length) });
    prog.classList.toggle('lc-prog-ok', falta === 0);
    irFalta.hidden = falta === 0;
  };

  // rascunho: só o que mudou em relação ao valor com que a linha abriu
  const id = (x, k) => (x.codigo != null ? x.codigo : linhas.indexOf(x)) + (x.campos.length > 1 ? '#' + k : '');
  linhas.forEach((x) => (x.iniciais = x.campos.map(valor)));
  const guardar = () => {
    if (!opts.chave) return;
    const v = {};
    linhas.forEach((x) => x.campos.forEach((i, k) => valor(i) !== x.iniciais[k] && (v[id(x, k)] = valor(i))));
    guardarRascunho(opts.chave, v);
  };

  linhas.forEach((x) => {
    if (visto) {
      const c = el('td', 'lc-visto', '✓');
      c.setAttribute('aria-hidden', 'true');
      x.tr.append(c);
    }
    x.campos.forEach((i) => {
      i.addEventListener('input', () => {
        marcar(x);
        actualizar();
        guardar();
      });
      i.addEventListener('change', () => {
        actualizar();
        guardar();
      });
      i.addEventListener('focus', () => {
        x.tr.classList.add('lc-activa');
        if (i.select && i.type !== 'checkbox') i.select();
      });
      i.addEventListener('blur', () => x.tr.classList.remove('lc-activa'));
      i.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' || e.shiftKey || e.altKey) return;
        if (i.tagName === 'TEXTAREA' && !(e.ctrlKey || e.metaKey)) return;
        e.preventDefault();
        e.stopPropagation();
        if (e.ctrlKey || e.metaKey) {
          if (gravar && !gravar.disabled) gravar.click();
          return;
        }
        const o = ordem();
        const prox = o[o.indexOf(i) + 1];
        if (prox) prox.focus();
        else if (gravar) gravar.focus();
      });
    });
  });

  // repõe o rascunho e avisa as páginas (mostrar o motivo da diferença, recalcular totais)
  let repostos = 0;
  if (opts.chave) {
    const r = lerRascunho(opts.chave);
    if (r)
      linhas.forEach((x) =>
        x.campos.forEach((i, k) => {
          const v = r.v[id(x, k)];
          if (v == null) return;
          definir(i, v);
          i.dispatchEvent(new Event(i.type === 'checkbox' ? 'change' : 'input', { bubbles: true }));
          repostos++;
        }),
      );
  }

  // procura pelo código ou pelo nome
  let procura = null;
  const filtrar = () => {
    const q = norm(procura.value).trim();
    linhas.forEach((x) => (x.tr.hidden = !!q && !norm((x.codigo || '') + ' ' + (x.texto || x.tr.textContent)).includes(q)));
  };
  const barra = el('div', 'lc-barra');
  if (comProcura) {
    procura = el('input');
    procura.type = 'search';
    procura.className = 'lc-procura';
    procura.placeholder = t('Procurar artigo (código ou nome)');
    procura.setAttribute('aria-label', t('Procurar artigo (código ou nome)'));
    procura.addEventListener('input', filtrar);
    procura.addEventListener('keydown', (e) => {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      const i = ordem()[0];
      if (i) i.focus();
    });
    barra.append(procura);
  }
  if (comProgresso) barra.append(prog, irFalta);
  if (repostos) barra.append(el('span', 'note lc-reposto', t('Rascunho reposto: {n} valor(es) por gravar.', { n: n0(repostos) })));
  barra.hidden = !barra.childNodes.length;
  actualizar();

  /** Antes de gravar: com valores fora do normal, mostra-os e pede para corrigir ou gravar assim mesmo. */
  const confirmar = (seguir) => {
    const at = linhas.filter((x) => x.atipico && x.atipico());
    if (!at.length) return seguir();
    janela(t('Valores fora do normal'), t('Confirme estes valores antes de gravar: podem ser engano de digitação.'), (b, ft, fechar) => {
      const ul = el('ul', 'lc-lista');
      at.forEach((x) =>
        ul.append(el('li', null, (x.codigo ? x.codigo + ' · ' : '') + (x.texto || '') + ' — ' + x.campos.map((i) => i.value).join(' / ') + ': ' + x.atipico())),
      );
      b.append(ul);
      ft.append(
        botao(t('Corrigir'), 'ghost', () => {
          fechar();
          const i = at[0].campos[0];
          if (i) setTimeout(() => i.focus(), 0);
        }),
        botao(t('Gravar assim mesmo'), null, () => {
          fechar();
          seguir();
        }),
      );
    });
  };
  return {
    barra,
    actualizar,
    emFalta,
    confirmar,
    limparRascunho: () => opts.chave && apagarRascunho(opts.chave),
  };
}
