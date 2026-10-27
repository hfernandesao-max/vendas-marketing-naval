// Componentes de página: tabela (ordenar, procurar, ajustar ao ecrã), quadros de indicadores, secções e avisos.
import { exportarExcel } from '../excel.js';
import { dl, kz, mkz, n0, pct } from '../formatos.js';
import { LANG, t } from '../i18n/i18n.js';
import { atual, paginaAtual, podeExportar } from '../perfil.js';
import { st } from '../periodo.js';
import { el, sum } from '../util.js';

// Durante o desenho de uma página, cada tabela e cada grupo de indicadores fica registado aqui para o Excel e o CSV.
export let REG = { tabelas: [], kpis: [] };
export const LIM = {},
  TODAS = {};
export let FITS = [];
const PASSO = 20;
const ORD = {};
export const numType = (c) => c.type && c.type !== 'text' && c.type !== 'date';
export const rawVal = (c, r) => (c.xl ? c.xl(r) : c.get ? c.get(r) : r[c.key]);
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
  return document.createTextNode(v == null || v === '' ? '—' : String(v));
}
export function tabela(o) {
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
  if (podeExportar(atual)) tools.append(bx);
  h.append(hl, tools);
  sec.append(h);
  const tw = el('div', 'tw');
  const tb = el('table');
  tw.append(tb);
  sec.append(tw);
  const more = el('div', 'more');
  more.hidden = true;
  sec.append(more);
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
            String(val(r, c) ?? '')
              .toLowerCase()
              .includes(q),
          ),
        )
      : o.rows;
  function body() {
    const rows = filtradas();
    const col = o.cols.find((c) => c.key === ord.key) || o.cols[0];
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
    d.append(el('span', 'l', x.l));
    const v = el('span', 'v', x.v);
    if (x.tone) v.style.color = `var(--${x.tone})`;
    d.append(v);
    if (x.s) d.append(el('span', 's', x.s));
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
