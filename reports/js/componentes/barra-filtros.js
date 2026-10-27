// Design system dos filtros: campo de pesquisa, escolha (lista com caixas de selecção num painel) e
// etiquetas dos filtros activos com «Limpar filtros». Usado na barra de filtros de todas as páginas e
// nas barras próprias das páginas de stock.
import { t } from '../i18n/i18n.js';
import { el, svg } from '../util.js';

const LUPA =
  '<svg class="fb-lupa" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>';
const SETA = '<svg class="fb-seta" viewBox="0 0 24 24" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>';
export const NENHUM = '__NONE__';
let seq = 0;

/** Painel aberto neste momento (só um de cada vez); fecha-o antes de redesenhar. */
export let fecharAberto = null;

/**
 * Campo de pesquisa. imediato: aplica a cada tecla (páginas que não se redesenham por inteiro);
 * caso contrário aplica com Enter, ao limpar ou ao sair do campo.
 */
export function pesquisa({ rotulo, valor = '', sugestoes = [], imediato = false, aoMudar }) {
  const w = el('div', 'fb-pesq' + (valor ? ' on' : ''));
  w.innerHTML = LUPA; // nosemgrep: insecure-innerhtml, insecure-document-method -- SVG estático do próprio código (sem dados)
  const i = el('input');
  i.type = 'search';
  i.value = valor;
  i.placeholder = rotulo;
  i.autocomplete = 'off';
  i.setAttribute('aria-label', rotulo);
  if (sugestoes.length) {
    const d = el('datalist');
    d.id = 'fb-dl-' + ++seq;
    sugestoes.slice(0, 1500).forEach((v) => {
      const o = el('option');
      o.value = v;
      d.append(o);
    });
    w.append(d);
    i.setAttribute('list', d.id);
  }
  let ultimo = valor;
  const ir = () => {
    const v = i.value.trim();
    w.classList.toggle('on', !!v);
    if (v === ultimo) return;
    ultimo = v;
    aoMudar(v);
  };
  if (imediato) i.addEventListener('input', ir);
  i.addEventListener('change', ir);
  i.addEventListener('search', ir);
  w.append(i);
  w.definir = (v) => {
    i.value = v;
    ir();
  };
  return w;
}

/**
 * Escolha num painel com caixas de selecção.
 *   opcoes: [{id, titulo, subtitulo?}]; selecionados: ids ([] = todos; [NENHUM] = nenhum, se permitirNenhum)
 *   multipla: várias opções (aplica ao fechar o painel, ou a cada clique com imediato); única: aplica ao escolher
 *   aoMudar(selecionados)
 */
export function escolha({ titulo, icone = 'filtro', opcoes, selecionados = [], multipla = true, permitirNenhum = false, imediato = false, procurar, aoMudar }) {
  let sel = selecionados.slice();
  let inicial = sel.join('\u0001');
  const w = el('div', 'fb-ms');
  const b = el('button', 'fb-ms-btn');
  b.type = 'button';
  b.setAttribute('aria-haspopup', 'true');
  b.setAttribute('aria-expanded', 'false');
  const pop = el('div', 'fb-pop');
  pop.hidden = true;
  pop.setAttribute('role', 'dialog');
  pop.setAttribute('aria-label', titulo);
  const total = opcoes.length;
  const nenhum = () => sel.includes(NENHUM);
  // Com «Todos»: nas listas com «Limpar» (stock) todas aparecem marcadas, como no Excel; nas outras
  // nenhuma aparece marcada e cada clique escolhe só essa opção
  const marcado = (id) => !nenhum() && (sel.length === 0 ? multipla && permitirNenhum : sel.includes(id));

  const botaoTexto = () => {
    let txt, n;
    if (nenhum()) {
      txt = t('Nenhum selecionado');
      n = '0';
    } else if (!sel.length || (multipla && sel.length === total)) {
      txt = t('Todos');
      n = multipla ? String(total) : '';
    } else if (sel.length === 1) {
      const o = opcoes.find((x) => x.id === sel[0]);
      txt = o ? o.titulo : sel[0];
      n = multipla ? '1' : '';
    } else {
      txt = t('{n} selecionados', { n: sel.length });
      n = String(sel.length);
    }
    b.innerHTML = svg(icone); // nosemgrep: insecure-innerhtml, insecure-document-method -- SVG estático do próprio código (sem dados)
    b.append(el('span', 'fb-ms-t', titulo + ': ' + txt));
    if (n) b.append(el('span', 'fb-n', n));
    b.insertAdjacentHTML('beforeend', SETA);
    w.classList.toggle('on', sel.length > 0 && !(multipla && sel.length === total));
  };

  // cabeçalho do painel
  const h = el('div', 'fb-pop-h');
  h.append(el('span', null, titulo));
  const acc = el('div', 'fb-pop-acc');
  const bTodos = el('button', null, t('Todos'));
  bTodos.type = 'button';
  bTodos.addEventListener('click', (ev) => {
    ev.stopPropagation();
    mudar([]);
    if (!multipla) fechar();
  });
  acc.append(bTodos);
  if (multipla && permitirNenhum) {
    const bNenhum = el('button', null, t('Limpar'));
    bNenhum.type = 'button';
    bNenhum.addEventListener('click', (ev) => {
      ev.stopPropagation();
      mudar([NENHUM]);
    });
    acc.append(bNenhum);
  }
  h.append(acc);
  pop.append(h);
  const filtro = procurar || total > 10 ? el('input', 'fb-pop-procura') : null;
  if (filtro) {
    filtro.type = 'search';
    filtro.placeholder = procurar || t('Procurar…');
    filtro.setAttribute('aria-label', filtro.placeholder);
    filtro.addEventListener('input', () => desenharLista());
    pop.append(filtro);
  }
  const lista = el('div', 'fb-pop-lista');
  pop.append(lista);

  function desenharLista() {
    const q = filtro ? filtro.value.trim().toLowerCase() : '';
    lista.replaceChildren();
    opcoes
      .filter((o) => !q || (o.titulo + ' ' + (o.subtitulo || '') + ' ' + o.id).toLowerCase().includes(q))
      .forEach((o) => {
        const item = el('label', 'fb-item');
        const c = el('input');
        c.type = multipla ? 'checkbox' : 'radio';
        c.name = 'fb-' + seq;
        c.checked = marcado(o.id);
        c.addEventListener('change', (ev) => {
          ev.stopPropagation();
          if (!multipla) {
            mudar([o.id]);
            fechar();
            return;
          }
          let nova;
          if (nenhum()) nova = c.checked ? [o.id] : [NENHUM];
          else if (!sel.length) nova = c.checked ? [o.id] : permitirNenhum ? opcoes.map((x) => x.id).filter((id) => id !== o.id) : [];
          else if (c.checked) nova = [...sel, o.id];
          else nova = sel.filter((id) => id !== o.id);
          if (nova.length === total) nova = [];
          if (!nova.length && !c.checked) nova = permitirNenhum ? [NENHUM] : [];
          mudar(nova);
        });
        const info = el('div', 'fb-item-info');
        info.append(el('span', 'fb-item-t', o.titulo));
        if (o.subtitulo) info.append(el('span', 'fb-item-s', o.subtitulo));
        item.append(c, info);
        lista.append(item);
      });
    if (!lista.childNodes.length) lista.append(el('p', 'fb-vazio', t('Sem resultados.')));
  }
  function mudar(nova) {
    sel = nova;
    botaoTexto();
    desenharLista();
    if (imediato || !multipla) {
      inicial = sel.join('\u0001');
      aoMudar(sel.slice());
    }
  }
  const fora = (ev) => {
    if (!w.contains(ev.target)) fechar();
  };
  const tecla = (ev) => {
    if (ev.key === 'Escape') {
      fechar();
      b.focus();
    }
  };
  function fechar() {
    if (pop.hidden) return;
    pop.hidden = true;
    b.setAttribute('aria-expanded', 'false');
    document.removeEventListener('mousedown', fora);
    document.removeEventListener('keydown', tecla);
    if (fecharAberto === fechar) fecharAberto = null;
    if (sel.join('\u0001') !== inicial) {
      inicial = sel.join('\u0001');
      aoMudar(sel.slice());
    }
  }
  b.addEventListener('click', () => {
    if (!pop.hidden) {
      fechar();
      return;
    }
    if (fecharAberto) fecharAberto();
    desenharLista();
    pop.hidden = false;
    // Abre para o lado onde cabe (botões encostados à direita abrem para a esquerda)
    pop.classList.toggle('fb-pop-dir', w.getBoundingClientRect().left + pop.offsetWidth > document.documentElement.clientWidth - 8);
    b.setAttribute('aria-expanded', 'true');
    fecharAberto = fechar;
    setTimeout(() => {
      document.addEventListener('mousedown', fora);
      document.addEventListener('keydown', tecla);
      if (filtro) filtro.focus();
    }, 0);
  });
  botaoTexto();
  w.append(b, pop);
  w.definir = (nova) => mudar(nova);
  return w;
}

/** Etiquetas dos filtros activos: [{txt, remover}] e o botão «Limpar filtros». */
export function etiquetas(lista, limparTudo) {
  const bar = el('div', 'fb-etiq');
  lista.forEach((x) => {
    const p = el('span', 'fb-etiqueta');
    p.append(document.createTextNode(x.txt));
    const r = el('button', null, '×');
    r.type = 'button';
    r.title = t('Remover filtro');
    r.setAttribute('aria-label', t('Remover filtro') + ': ' + x.txt);
    r.addEventListener('click', x.remover);
    p.append(r);
    bar.append(p);
  });
  if (lista.length && limparTudo) {
    const c = el('button', 'fb-limpar', t('Limpar filtros'));
    c.type = 'button';
    c.addEventListener('click', limparTudo);
    bar.append(c);
  }
  bar.hidden = !lista.length;
  return bar;
}
