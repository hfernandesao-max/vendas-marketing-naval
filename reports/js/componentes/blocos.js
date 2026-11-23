// Componentes de página: tabela (ordenar, procurar, ajustar ao ecrã), quadros de indicadores, secções e avisos.
import { exportarExcel } from '../excel.js';
import { dl, kz, mkz, n0, pct } from '../formatos.js';
import { LANG, t } from '../i18n/i18n.js';
import { atual, paginaAtual, podeExportar } from '../perfil.js';
import { st } from '../periodo.js';
import { el, sum } from '../util.js';
import { celulaLigada, tipoLigacao } from './ligacoes.js';
import { gravarLayout, layoutDe } from '../colunas.js';
import { CAMPOS_ARTIGO, tArtigo } from '../i18n/artigos.js';

// Durante o desenho de uma página, cada tabela e cada grupo de indicadores fica registado aqui para o Excel e o CSV.
export let REG = { tabelas: [], kpis: [] };
export const LIM = {},
  TODAS = {};
export let FITS = [];
const PASSO = 20;
const ORD = {};
export const numType = (c) => c.type && c.type !== 'text' && c.type !== 'date';
// nomes de artigos na língua do ecrã (também no Excel); o resto como vem
const deArtigo = (c, v) => (c.key && CAMPOS_ARTIGO.has(c.key) && typeof v === 'string' ? tArtigo(v) : v);
export const rawVal = (c, r) => (c.xl ? c.xl(r) : deArtigo(c, c.get ? c.get(r) : r[c.key]));
export function download(name, blob) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = name;
  document.body.appendChild(a);
  a.click();
  setTimeout(() => {
    URL.revokeObjectURL(a.href);
    a.remove();
  }, 800);
}
const slug = (s) =>
  s
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '');
export const fname = (s, ext) => `${slug(t('Portal RTM'))}_${slug(s)}_${dl(st.de)}_${dl(st.ate)}.${ext}`;

function cellNode(c, r, max) {
  const v = c.get ? c.get(r) : r[c.key];
  if (c.render) return c.render(v, r);
  // drill-down: documento, cliente, artigo e rota (ou promotor) abrem a ficha respectiva (componentes/ligacoes.js)
  const lig = !c.type && tipoLigacao(c);
  if (lig) return celulaLigada(lig, v, r);
  if (c.type === 'kz') return document.createTextNode(kz(v));
  if (c.type === 'mkz') return document.createTextNode(mkz(v));
  if (c.type === 'n') return document.createTextNode(n0(v));
  if (c.type === 'pct') return document.createTextNode(pct(v));
  if (c.type === 'date') return document.createTextNode(dl(v));
  if (c.type === 'bar') {
    const w = el('span', 'cbar');
    const i = el('i');
    i.style.width = (max ? Math.max(2, (v / max) * 90) : 0) + 'px';
    w.append(document.createTextNode(mkz(v)), i);
    return w;
  }
  return document.createTextNode(v == null || v === '' ? '—' : String(deArtigo(c, v)));
}
// Colunas à medida de cada pessoa: ordem (arrastar o nome da coluna), colunas escondidas (botão direito no cabeçalho
// ou botão «Colunas») e largura (arrastar a borda direita do nome; duplo clique repõe). Guardado na conta.
const LARG_MIN = 48,
  LARG_MAX = 900;
let menuAberto = null;
function fecharMenuColunas() {
  if (!menuAberto) return;
  const m = menuAberto;
  menuAberto = null;
  m.remove();
  document.removeEventListener('pointerdown', foraDoMenu, true);
  document.removeEventListener('keydown', escMenu, true);
}
const foraDoMenu = (e) => {
  if (menuAberto && !menuAberto.contains(e.target)) fecharMenuColunas();
};
const escMenu = (e) => {
  if (e.key === 'Escape' && menuAberto) {
    e.preventDefault();
    const volta = menuAberto._volta;
    fecharMenuColunas();
    if (volta && volta.isConnected) volta.focus();
  }
};
export function tabela(o) {
  // arranjo das colunas desta pessoa (a lista de origem fica em «todas»; o.cols passa a ser a lista visível)
  const todas = o.cols.slice();
  const vistos = new Set();
  const ids = new Map();
  todas.forEach((c, i) => {
    let id = c.key != null ? String(c.key) : 'c' + i;
    while (vistos.has(id)) id += '_' + i;
    vistos.add(id);
    ids.set(c, id);
  });
  const idDe = (c) => ids.get(c);
  const chaveLayout = atual + ':' + o.id;
  const lerLayout = () => {
    const L = layoutDe(chaveLayout) || {};
    return { o: Array.isArray(L.o) ? L.o.slice() : [], h: Array.isArray(L.h) ? L.h.slice() : [], w: L.w && typeof L.w === 'object' ? { ...L.w } : {} };
  };
  // todas as colunas pela ordem escolhida (as que não estão na escolha mantêm o lugar de origem, a seguir)
  const ordenadas = (L) => {
    const pos = new Map(L.o.map((id, i) => [id, i]));
    return todas
      .map((c, i) => [c, pos.has(idDe(c)) ? pos.get(idDe(c)) : L.o.length + i])
      .sort((a, b) => a[1] - b[1])
      .map((x) => x[0]);
  };
  o.cols = [];
  const aplicarLayout = () => {
    const L = lerLayout();
    let vis = ordenadas(L).filter((c) => !L.h.includes(idDe(c)));
    if (!vis.length) vis = [todas[0]];
    o.cols.splice(0, o.cols.length, ...vis);
    return L;
  };
  let LAY = aplicarLayout();
  const mudarLayout = (f) => {
    const L = lerLayout();
    f(L);
    gravarLayout(chaveLayout, L);
    LAY = aplicarLayout();
    body();
  };
  const reg = { id: o.id, titulo: o.titulo, nota: o.nota, cols: o.cols, rows: o.rows, total: o.total };
  REG.tabelas.push(reg);
  const sec = el('section', 'glass sec');
  sec.id = 's-' + o.id;
  const h = el('div', 'sec-h');
  const hl = el('div');
  hl.append(el('h2', null, o.titulo));
  if (o.nota) hl.append(el('p', null, o.nota));
  const tools = el('div', 'sec-tools');
  let q = '';
  if (o.search !== false) {
    const s = el('input', 'search');
    s.type = 'search';
    s.placeholder = t('Procurar…');
    s.setAttribute('aria-label', t('Procurar…') + ' ' + o.titulo);
    s.id = 'q-' + o.id;
    s.addEventListener('input', () => {
      q = s.value.trim().toLowerCase();
      body();
    });
    tools.append(s);
  }
  const bx = el('button', 'btn sm');
  bx.type = 'button';
  bx.innerHTML = '<svg viewBox="0 0 24 24"><path d="M4 4h16v16H4zM4 9h16M9 9v11"/></svg>Excel';
  bx.addEventListener('click', () =>
    exportarExcel([{ pagina: paginaAtual().nome, grupo: paginaAtual().grupo, kpis: [], tabelas: [{ ...reg, rows: filtradas() }] }], o.titulo),
  );
  const bc = el('button', 'btn sm ghost col-btn');
  bc.type = 'button';
  bc.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4zM9.5 5v14M14.5 5v14"/></svg>'; // nosemgrep: insecure-innerhtml -- SVG estático
  bc.append(el('span', 'col-btn-t', t('Colunas')));
  bc.setAttribute('aria-label', t('Colunas'));
  bc.title = t('Escolher, ordenar e dimensionar as colunas (também com o botão direito no cabeçalho)');
  bc.setAttribute('aria-haspopup', 'dialog');
  bc.addEventListener('click', () => {
    const r = bc.getBoundingClientRect();
    abrirMenu(r.left, r.bottom + 4, bc);
  });
  tools.append(bc);
  if (podeExportar(atual)) tools.append(bx);
  h.append(hl, tools);
  sec.append(h);
  const tw = el('div', 'tw');
  const tb = el('table');
  tw.append(tb);
  sec.append(tw);
  // cabeçalho da tabela (título, nota, Procurar, Excel) e nomes das colunas fixos no topo ao descer muitas linhas
  sec.classList.add('sec-tab');
  if ('ResizeObserver' in window) new ResizeObserver(() => sec.style.setProperty('--cab-h', h.offsetHeight + 'px')).observe(h);
  // «fixo» só enquanto o cabeçalho está preso no topo (a marca do início da tabela já passou para cima do ecrã)
  if ('IntersectionObserver' in window) {
    const marca = el('div', 'sec-marca');
    marca.setAttribute('aria-hidden', 'true');
    sec.prepend(marca);
    new IntersectionObserver(([e]) =>
      sec.classList.toggle('fixo', !e.isIntersecting && e.boundingClientRect.top < (e.rootBounds ? e.rootBounds.top : 0) + 1),
    ).observe(marca);
  }
  const more = el('div', 'more');
  more.hidden = true;
  sec.append(more);
  function abrirMenu(x, y, volta) {
    fecharMenuColunas();
    const m = el('div', 'glass col-menu');
    m.setAttribute('role', 'dialog');
    m.setAttribute('aria-label', t('Colunas da tabela {t}', { t: o.titulo }));
    m._volta = volta;
    const desenhar = (foco) => {
      const L = lerLayout();
      const lista = ordenadas(L);
      m.replaceChildren(el('p', 'col-menu-t', t('Colunas')));
      const ul = el('ul');
      lista.forEach((c, i) => {
        const id = idDe(c);
        const li = el('li');
        const lab = el('label');
        const cb = el('input');
        cb.type = 'checkbox';
        cb.checked = !L.h.includes(id);
        cb.dataset.id = id;
        // pelo menos uma coluna fica sempre visível
        cb.disabled = cb.checked && lista.filter((x) => !L.h.includes(idDe(x))).length === 1;
        cb.addEventListener('change', () => {
          mudarLayout((N) => {
            N.h = cb.checked ? N.h.filter((z) => z !== id) : [...N.h.filter((z) => z !== id), id];
          });
          desenhar('cb:' + id);
        });
        lab.append(cb, document.createTextNode(c.label));
        const mover = (d, txt, seta) => {
          const b = el('button', 'linkbtn col-mov', seta);
          b.type = 'button';
          b.title = txt;
          b.setAttribute('aria-label', txt + ': ' + c.label);
          b.disabled = i + d < 0 || i + d >= lista.length;
          b.addEventListener('click', () => {
            mudarLayout((N) => {
              const ord = ordenadas(N).map(idDe);
              const [z] = ord.splice(i, 1);
              ord.splice(i + d, 0, z);
              N.o = ord;
            });
            desenhar((d < 0 ? 'up:' : 'dn:') + id);
          });
          b.dataset.f = (d < 0 ? 'up:' : 'dn:') + id;
          return b;
        };
        li.append(lab, mover(-1, t('Mover para cima'), '↑'), mover(1, t('Mover para baixo'), '↓'));
        ul.append(li);
      });
      m.append(ul);
      const rodapeM = el('div', 'col-menu-acc');
      const repor = el('button', 'btn sm ghost', t('Repor as colunas'));
      repor.type = 'button';
      repor.disabled = !L.o.length && !L.h.length && !Object.keys(L.w).length;
      repor.addEventListener('click', () => {
        gravarLayout(chaveLayout, null);
        LAY = aplicarLayout();
        body();
        desenhar();
      });
      const fechar = el('button', 'btn sm', t('Fechar'));
      fechar.type = 'button';
      fechar.addEventListener('click', () => {
        fecharMenuColunas();
        if (volta && volta.isConnected) volta.focus();
      });
      rodapeM.append(repor, fechar);
      m.append(
        el('p', 'col-menu-nota', t('Arraste o nome de uma coluna para a mudar de lugar e a borda direita para mudar a largura (duplo clique repõe).')),
        rodapeM,
      );
      const alvo = foco && (m.querySelector(`[data-id="${CSS.escape(foco.slice(3))}"]`) || null);
      const f = foco ? (foco.startsWith('cb:') ? alvo : m.querySelector(`[data-f="${CSS.escape(foco)}"]`)) : m.querySelector('input');
      if (f && !f.disabled) f.focus();
      else if (foco) (m.querySelector('input') || fechar).focus();
    };
    document.body.append(m);
    menuAberto = m;
    desenhar();
    // dentro do ecrã
    const r = m.getBoundingClientRect();
    m.style.left = Math.max(8, Math.min(x, innerWidth - r.width - 8)) + 'px';
    m.style.top = Math.max(8, Math.min(y, innerHeight - r.height - 8)) + 'px';
    document.addEventListener('pointerdown', foraDoMenu, true);
    document.addEventListener('keydown', escMenu, true);
  }
  // largura de uma coluna (cabeçalho e células)
  const porLargura = (i, w) => {
    tb.querySelectorAll(`[data-c="${i}"]`).forEach((e) => {
      if (w) {
        e.style.width = e.style.minWidth = e.style.maxWidth = w + 'px';
        e.classList.add('cw');
      } else {
        e.style.width = e.style.minWidth = e.style.maxWidth = '';
        e.classList.remove('cw');
      }
    });
  };
  let arrastada = null;
  // prioridade de cada coluna quando falta largura: 0 nunca sai; números maiores saem primeiro
  const prio = (c, i) =>
    i === 0
      ? -1
      : c.p != null
        ? c.p
        : c.wrap
          ? 2
          : c.type === 'date'
            ? 1
            : (c.type === 'kz' || c.type === 'bar') && c.sum
              ? 2
              : c.type === 'n' || c.type === 'pct'
                ? 3
                : c.type === 'kz'
                  ? 4
                  : 5;
  let ocultas = [];
  function fit() {
    if (!tw.isConnected) return;
    tb.classList.remove('compact');
    tb.querySelectorAll('[data-c]').forEach((e) => {
      e.hidden = false;
    });
    ocultas = [];
    // Ecrã estreito (telemóvel): linhas em cartões, todas as colunas visíveis
    const estreito = tw.clientWidth < 560;
    tb.classList.toggle('cartoes', estreito);
    if (estreito) {
      rodape();
      return;
    }
    const sobra = () => tw.scrollWidth > tw.clientWidth + 1;
    if (sobra()) tb.classList.add('compact');
    if (!TODAS[o.id]) {
      const ordem = o.cols
        .map((c, i) => [prio(c, i), i])
        .filter((x) => x[0] >= 0)
        .sort((a, b2) => b2[0] - a[0] || b2[1] - a[1]);
      for (const [, i] of ordem) {
        if (!sobra()) break;
        tb.querySelectorAll(`[data-c="${i}"]`).forEach((e) => {
          e.hidden = true;
        });
        ocultas.push(o.cols[i].label);
      }
    }
    rodape();
  }
  function rodape() {
    const n = (reg.sorted || []).length,
      lim = LIM[o.id] || PASSO;
    more.replaceChildren();
    const esq = el('span'),
      dir = el('div');
    if (n > lim) {
      esq.textContent = t('A mostrar {a} de {b}', { a: n0(lim), b: n0(n) });
      const m = el('button', 'linkbtn', t('Mostrar mais {n}', { n: Math.min(PASSO, n - lim) }));
      m.type = 'button';
      m.addEventListener('click', () => {
        LIM[o.id] = lim + PASSO;
        body();
      });
      const tAll = el('button', 'linkbtn', t('Mostrar todas'));
      tAll.type = 'button';
      tAll.addEventListener('click', () => {
        LIM[o.id] = n;
        body();
      });
      dir.append(m, tAll);
    } else if (lim > PASSO && n > PASSO) {
      const c = el('button', 'linkbtn', t('Mostrar menos'));
      c.type = 'button';
      c.addEventListener('click', () => {
        LIM[o.id] = PASSO;
        body();
      });
      dir.append(c);
    }
    if (ocultas.length) {
      const c = el('button', 'linkbtn', ocultas.length === 1 ? t('Mostrar 1 coluna oculta') : t('Mostrar {n} colunas ocultas', { n: ocultas.length }));
      c.type = 'button';
      c.title = ocultas.join(', ');
      c.addEventListener('click', () => {
        TODAS[o.id] = true;
        fit();
      });
      dir.append(c);
    } else if (TODAS[o.id]) {
      const c = el('button', 'linkbtn', t('Ajustar ao ecrã'));
      c.type = 'button';
      c.addEventListener('click', () => {
        TODAS[o.id] = false;
        fit();
      });
      dir.append(c);
    }
    more.append(esq, dir);
    more.hidden = !esq.textContent && !dir.childNodes.length;
  }
  FITS.push(fit);
  const ord = ORD[o.id] || (ORD[o.id] = { key: o.sort || o.cols[0].key, dir: o.dir || -1 });
  const val = (r, c) => (c.get ? c.get(r) : r[c.key]);
  const filtradas = () =>
    q
      ? o.rows.filter((r) =>
          o.cols.some((c) =>
            // procura no nome original e no nome traduzido do artigo
            (String(val(r, c) ?? '') + ' ' + String(deArtigo(c, val(r, c)) ?? '')).toLowerCase().includes(q),
          ),
        )
      : o.rows;
  function body() {
    const rows = filtradas();
    // o.sort pode ser um campo sem coluna (ex.: «ordem» do documento): ordena pelo valor da linha
    const col = o.cols.find((c) => c.key === ord.key) || (ord.key === o.sort ? { key: o.sort } : o.cols[0]);
    const sorted = [...rows].sort((a, b2) => {
      const x = val(a, col),
        y = val(b2, col);
      if (x == null) return 1;
      if (y == null) return -1;
      return (typeof x === 'number' ? x - y : String(x).localeCompare(String(y), LANG)) * ord.dir;
    });
    reg.sorted = sorted;
    const th = el('thead');
    const tr = el('tr');
    o.cols.forEach((c) => {
      const tt = el('th', numType(c) ? 'n' : null);
      tt.dataset.c = o.cols.indexOf(c);
      tt.setAttribute('aria-sort', c.key === ord.key ? (ord.dir > 0 ? 'ascending' : 'descending') : 'none');
      const bt = el('button', null, c.label + (c.key === ord.key ? (ord.dir > 0 ? ' ↑' : ' ↓') : ''));
      if (c.title) bt.title = c.title;
      bt.type = 'button';
      bt.addEventListener('click', () => {
        if (ord.key === c.key) ord.dir *= -1;
        else {
          ord.key = c.key;
          ord.dir = numType(c) ? -1 : 1;
        }
        body();
      });
      tt.append(bt);
      const ci = o.cols.indexOf(c),
        cid = idDe(c);
      // mudar de lugar: arrastar o nome da coluna para cima de outra
      tt.draggable = true;
      tt.dataset.cid = cid;
      tt.addEventListener('dragstart', (e) => {
        arrastada = cid;
        tt.classList.add('col-arrasta');
        e.dataTransfer.effectAllowed = 'move';
        try {
          e.dataTransfer.setData('text/plain', cid);
        } catch (er) {
          /* navegador antigo */
        }
      });
      tt.addEventListener('dragend', () => {
        arrastada = null;
        tb.querySelectorAll('.col-arrasta, .col-antes, .col-depois').forEach((x) => x.classList.remove('col-arrasta', 'col-antes', 'col-depois'));
      });
      tt.addEventListener('dragover', (e) => {
        if (!arrastada || arrastada === cid) return;
        e.preventDefault();
        const r = tt.getBoundingClientRect();
        const depois = e.clientX > r.left + r.width / 2;
        tt.classList.toggle('col-depois', depois);
        tt.classList.toggle('col-antes', !depois);
      });
      tt.addEventListener('dragleave', () => tt.classList.remove('col-antes', 'col-depois'));
      tt.addEventListener('drop', (e) => {
        if (!arrastada || arrastada === cid) return;
        e.preventDefault();
        const r = tt.getBoundingClientRect();
        const depois = e.clientX > r.left + r.width / 2;
        const mover = arrastada;
        arrastada = null;
        mudarLayout((N) => {
          const ord = ordenadas(N)
            .map(idDe)
            .filter((z) => z !== mover);
          const k = ord.indexOf(cid);
          ord.splice(depois ? k + 1 : k, 0, mover);
          N.o = ord;
        });
      });
      // botão direito: escolher as colunas
      tt.addEventListener('contextmenu', (e) => {
        e.preventDefault();
        abrirMenu(e.clientX, e.clientY, bt);
      });
      // largura: arrastar a borda direita; duplo clique repõe a largura automática
      const pega = el('span', 'col-res');
      pega.setAttribute('aria-hidden', 'true');
      pega.title = t('Arraste para mudar a largura; duplo clique repõe');
      pega.draggable = false;
      pega.addEventListener('dragstart', (e) => e.preventDefault());
      pega.addEventListener('pointerdown', (e) => {
        e.preventDefault();
        e.stopPropagation();
        tt.draggable = false;
        const x0 = e.clientX,
          w0 = tt.getBoundingClientRect().width;
        let w = Math.round(w0);
        pega.setPointerCapture(e.pointerId);
        const mov = (ev) => {
          w = Math.max(LARG_MIN, Math.min(LARG_MAX, Math.round(w0 + ev.clientX - x0)));
          porLargura(ci, w);
        };
        const fim = () => {
          pega.removeEventListener('pointermove', mov);
          pega.removeEventListener('pointerup', fim);
          pega.removeEventListener('pointercancel', fim);
          tt.draggable = true;
          const L = lerLayout();
          L.w[cid] = w;
          gravarLayout(chaveLayout, L);
          LAY = L;
        };
        pega.addEventListener('pointermove', mov);
        pega.addEventListener('pointerup', fim);
        pega.addEventListener('pointercancel', fim);
      });
      pega.addEventListener('click', (e) => e.stopPropagation());
      pega.addEventListener('dblclick', (e) => {
        e.stopPropagation();
        mudarLayout((N) => {
          delete N.w[cid];
        });
      });
      tt.append(pega);
      tr.append(tt);
    });
    th.append(tr);
    const tbody = el('tbody');
    const maxs = {};
    o.cols
      .filter((c) => c.type === 'bar')
      .forEach((c) => {
        maxs[c.key] = Math.max(0, ...rows.map((r) => +val(r, c) || 0));
      });
    sorted.slice(0, LIM[o.id] || PASSO).forEach((r) => {
      const tr2 = el('tr');
      o.cols.forEach((c, i) => {
        const td = el('td', (numType(c) || c.nowrap || c.type === 'date' ? 'n0' : 't') + (numType(c) ? ' n' : '') + (c.wrap ? ' w' : ''));
        td.dataset.c = i;
        td.dataset.l = c.label;
        td.append(cellNode(c, r, maxs[c.key]));
        tr2.append(td);
      });
      tbody.append(tr2);
    });
    tb.replaceChildren(th, tbody);
    if (!rows.length) {
      const e = el('tr');
      const td = el('td', 'empty', t('Sem dados no período escolhido.'));
      td.colSpan = o.cols.length;
      e.append(td);
      tbody.append(e);
    } else if (o.total) {
      const tf = el('tfoot');
      const r2 = el('tr');
      o.cols.forEach((c, i) => {
        const td = el('td', numType(c) ? 'n' : null);
        td.dataset.c = i;
        td.dataset.l = c.label;
        if (i === 0) td.textContent = t('Total');
        else if (c.sum) {
          const tot = c.sum === true ? sum(rows, (r) => val(r, c)) : c.sum(rows);
          td.textContent = c.type === 'n' ? n0(tot) : c.type === 'pct' ? pct(tot) : kz(tot);
        }
        r2.append(td);
      });
      tf.append(r2);
      tb.append(tf);
    }
    o.cols.forEach((c, i) => {
      const w = +(LAY.w || {})[idDe(c)] || 0;
      if (w) porLargura(i, Math.max(LARG_MIN, Math.min(LARG_MAX, w)));
    });
    fit();
    rodape();
  }
  body();
  return sec;
}
export function tiles(list) {
  REG.kpis.push(...list);
  const w = el('div', 'tiles');
  list.forEach((x) => {
    const d = el('div', 'glass tile' + (x.cls ? ' ' + x.cls : ''));
    // Rótulo e, à direita, a variação face ao período comparável (▲/▼, cor pelo sentido favorável)
    const l = el('span', 'l', x.var ? null : x.l);
    if (x.var) {
      l.classList.add('l-var');
      const lt = el('span', 'l-t', x.l);
      lt.title = x.l;
      l.append(lt);
      const vv = el('span', 'tile-var ' + (x.var.cls || ''), x.var.txt);
      if (x.var.title) vv.title = x.var.title;
      l.append(vv);
    }
    d.append(l);
    const v = el('span', 'v', x.v);
    if (x.tone) v.style.color = `var(--${x.tone})`;
    d.append(v);
    // Nota e, à direita, a mini-linha da tendência no período
    if (x.s || (x.spark && x.spark.length > 1)) {
      const sEl = el('span', 's' + (x.spark && x.spark.length > 1 ? ' s-spark' : ''));
      sEl.append(el('span', null, x.s || ''));
      if (x.spark && x.spark.length > 1) sEl.append(sparkline(x.spark, x.sparkTitulo));
      d.append(sEl);
    }
    // drill-down: o quadro abre o que o compõe (x.abrir), com o rato ou com Enter/Espaço
    if (x.abrir) {
      d.classList.add('tile-lig');
      d.tabIndex = 0;
      d.setAttribute('role', 'button');
      d.title = x.abrirTitulo || t('Ver o detalhe');
      d.addEventListener('click', () => x.abrir());
      d.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          x.abrir();
        }
      });
    }
    w.append(d);
  });
  // Valores que não cabem no quadro: reduz a letra de toda a fila por igual, até caberem.
  // Se nem com letra pequena cabem, os quadros ficam mais largos (passam a ocupar mais filas).
  const ajustar = (alargado) => {
    if (!w.isConnected) return;
    const vs = [...w.querySelectorAll('.tile .v')];
    if (!alargado) w.style.gridTemplateColumns = '';
    vs.forEach((v) => {
      v.style.fontSize = '';
    });
    const cabe = (v) => v.scrollWidth <= v.clientWidth + 1;
    const filas = {};
    vs.forEach((v) => {
      const k = Math.round(v.parentNode.getBoundingClientRect().top);
      (filas[k] = filas[k] || []).push(v);
    });
    let falta = 0;
    Object.values(filas).forEach((f) => {
      if (f.every(cabe)) return;
      let px = Math.max(...f.map((v) => parseFloat(getComputedStyle(v).fontSize)));
      const tenta = () => {
        f.forEach((v) => {
          v.style.fontSize = px + 'px';
        });
        return f.every(cabe);
      };
      // Mínimo de 15 px; já com os quadros alargados, desce até 11 px antes de deixar cortar
      while (px > (alargado ? 11 : 15) && !tenta()) px -= 1;
      f.forEach((v) => {
        if (!cabe(v)) {
          const t2 = v.parentNode;
          falta = Math.max(falta, v.scrollWidth + t2.offsetWidth - v.clientWidth + 2);
        }
      });
    });
    if (falta && !alargado) {
      w.style.gridTemplateColumns = `repeat(auto-fit, minmax(min(100%, ${Math.ceil(falta)}px), 1fr))`;
      ajustar(true);
    }
  };
  FITS.push(() => ajustar(false));
  return w;
}
/** Mini-linha da tendência (sparkline): só a forma, sem eixos; o último ponto marcado. */
export function sparkline(vals, titulo) {
  const W = 72,
    H = 22,
    p = 2;
  const max = Math.max(...vals),
    min = Math.min(...vals, 0),
    amp = max - min || 1;
  const pts = vals.map((v, i) => [p + (i * (W - 2 * p)) / (vals.length - 1), H - p - ((v - min) / amp) * (H - 2 * p)]);
  const ns = 'http://www.w3.org/2000/svg';
  const sv = document.createElementNS(ns, 'svg');
  sv.setAttribute('viewBox', `0 0 ${W} ${H}`);
  sv.setAttribute('width', W);
  sv.setAttribute('height', H);
  sv.setAttribute('class', 'spark');
  sv.setAttribute('role', 'img');
  sv.setAttribute('aria-label', titulo || t('Tendência no período'));
  const pl = document.createElementNS(ns, 'polyline');
  pl.setAttribute('points', pts.map((q) => q.map((n) => n.toFixed(1)).join(',')).join(' '));
  const c = document.createElementNS(ns, 'circle');
  const [lx, ly] = pts[pts.length - 1];
  c.setAttribute('cx', lx.toFixed(1));
  c.setAttribute('cy', ly.toFixed(1));
  c.setAttribute('r', '2.2');
  sv.append(pl, c);
  if (titulo) {
    const tt = document.createElementNS(ns, 'title');
    tt.textContent = titulo;
    sv.prepend(tt);
  }
  return sv;
}
export const pill = (txt, cls) => el('span', 'pill ' + cls, txt);
export const secBox = (titulo, nota, conteudo) => {
  const s = el('section', 'glass sec');
  const h = el('div', 'sec-h');
  const d = el('div');
  d.append(el('h2', null, titulo));
  if (nota) d.append(el('p', null, nota));
  h.append(d);
  s.append(h, conteudo);
  return s;
};
export function aguardar(pg, titulo, campos) {
  const s = el('section', 'glass sec');
  const h = el('div', 'sec-h');
  const d = el('div');
  d.append(el('h2', null, t('Aguarda exportação do Pinguim BO')));
  d.append(el('p', null, titulo));
  h.append(d);
  s.append(h);
  const ul = el('ul');
  ul.style.margin = '4px 0 6px';
  ul.style.color = 'var(--muted)';
  ul.style.lineHeight = '1.8';
  campos.forEach((c) => ul.append(el('li', null, t(c))));
  s.append(ul);
  s.append(
    el('p', 'note', t('Quando enviar esta exportação, a página passa a mostrar os dados automaticamente, com filtro de datas e exportação para Excel.')),
  );
  pg.append(s);
}

// Alteração do estado partilhado: só este módulo altera estas variáveis; os outros lêem-nas.
export function definirReg(valor) {
  REG = valor;
}
export function definirFits(valor) {
  FITS = valor;
}
