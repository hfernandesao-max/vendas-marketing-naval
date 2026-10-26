// Gráficos SVG (colunas, barras, parte do todo) com dicas, teclado e vista em tabela.
import { FITS } from './blocos.js';
import { n0, nf1, pct } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { el, sum } from '../util.js';

// Forma escolhida pela pergunta: colunas para evolução no tempo, barras horizontais ordenadas para comparar,
// barra 100% para parte do todo. Uma só escala por gráfico, marcas finas, rótulos selectivos, dica ao passar
// o rato (e com o teclado) e vista em tabela. Paleta validada para o fundo escuro (azul, laranja, verde-água;
// azul, âmbar e vermelho para a antiguidade da dívida); o texto nunca usa a cor das séries.
const NS_SVG = 'http://www.w3.org/2000/svg';
export const COR = { s1: '#3987e5', s2: '#d95926', s3: '#199e70', neutro: '#5b6478', a1: '#3987e5', a2: '#c98500', a3: '#d03b3b' };
export const kzc = (v) => {
  const a = Math.abs(v || 0);
  if (a >= 1e9) return nf1.format(v / 1e9) + ' ' + t('mM');
  if (a >= 1e6) return nf1.format(v / 1e6) + ' M';
  if (a >= 1e3) return n0(Math.round(v / 1e3)) + ' ' + t('mil');
  return n0(v);
};
const svgEl = (tag, at, txt) => {
  const e = document.createElementNS(NS_SVG, tag);
  Object.entries(at || {}).forEach(([k, v]) => e.setAttribute(k, v));
  if (txt != null) e.textContent = txt;
  return e;
};
const cortar = (s, px, tam = 7) => {
  s = String(s || '');
  const n = Math.max(3, Math.floor(px / tam));
  return s.length > n ? s.slice(0, n - 1) + '…' : s;
};
// Escala «limpa»: máximo arredondado e 4 divisões
const escala = (max) => {
  if (!(max > 0)) return { max: 1, passo: 0.25 };
  const bruto = max / 4,
    mag = Math.pow(10, Math.floor(Math.log10(bruto))),
    f = bruto / mag;
  const passo = (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * mag;
  return { max: Math.ceil(max / passo) * passo, passo };
};
// Barra com as pontas de dados arredondadas (4px) e a base direita
const pathBarra = (x, y, w, h, vertical) => {
  const r = Math.min(4, vertical ? w / 2 : h / 2, vertical ? h : w);
  if (vertical) return `M${x},${y + h}V${y + r}Q${x},${y} ${x + r},${y}H${x + w - r}Q${x + w},${y} ${x + w},${y + r}V${y + h}Z`;
  return `M${x},${y}H${x + w - r}Q${x + w},${y} ${x + w},${y + r}V${y + h - r}Q${x + w},${y + h} ${x + w - r},${y + h}H${x}Z`;
};
const vizTip = (() => {
  let d = null;
  const pos = (e, alvo) => {
    const b = alvo.getBoundingClientRect(),
      x = e && e.clientX != null ? e.clientX : b.left + b.width / 2,
      y = e && e.clientY != null ? e.clientY : b.top;
    d.style.left = Math.max(8, Math.min(innerWidth - d.offsetWidth - 8, x + 12)) + 'px';
    d.style.top = (y - d.offsetHeight - 12 < 8 ? y + 18 : y - d.offsetHeight - 12) + 'px';
  };
  return {
    mostrar(e, alvo, titulo, linhas) {
      if (!d) {
        d = el('div', 'viz-tip');
        d.setAttribute('role', 'tooltip');
        document.body.append(d);
      }
      d.replaceChildren(
        el('div', 'vt-t', titulo),
        ...linhas.map(([v, l, cor]) => {
          const r = el('div', 'vt-l');
          const k = el('i');
          if (cor) k.style.background = cor;
          else k.style.visibility = 'hidden';
          r.append(k, el('b', null, v), el('span', null, l));
          return r;
        }),
      );
      d.hidden = false;
      pos(e, alvo);
    },
    esconder() {
      if (d) d.hidden = true;
    },
  };
})();
// Marca interactiva: dica ao passar o rato e com o teclado (Tab), destaque, e ligação opcional
function interactivo(g, titulo, linhas, href) {
  g.setAttribute('tabindex', '0');
  g.setAttribute('role', href ? 'link' : 'img');
  g.setAttribute('aria-label', titulo + ': ' + linhas.map(([v, l]) => l + ' ' + v).join(', '));
  g.classList.add('vx-m');
  const on = (e) => {
    g.classList.add('on');
    vizTip.mostrar(e, g, titulo, linhas);
  };
  const off = () => {
    g.classList.remove('on');
    vizTip.esconder();
  };
  g.addEventListener('pointermove', on);
  g.addEventListener('pointerleave', off);
  g.addEventListener('focus', on);
  g.addEventListener('blur', off);
  if (href) {
    const ir = () => {
      vizTip.esconder();
      location.hash = href;
    };
    g.addEventListener('click', ir);
    g.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') ir();
    });
    g.style.cursor = 'pointer';
  }
}
// Colunas (uma série ao longo do tempo), com linha de referência opcional
export function grafColunas(w, { itens, cor = COR.s1, fmt = kzc, ref }) {
  const H = 230,
    T = 22,
    B = 26,
    R = 8,
    max0 = Math.max(ref ? ref.valor : 0, ...itens.map((x) => x.valor), 0);
  const { max, passo } = escala(max0),
    L = Math.max(34, 8 + 7 * fmt(max).length);
  const sv = svgEl('svg', { viewBox: `0 0 ${w} ${H}`, width: w, height: H, class: 'vx' });
  const y = (v) => T + (H - T - B) * (1 - v / max);
  for (let v = 0; v <= max + 1e-9; v += passo) {
    sv.append(
      svgEl('line', { x1: L, x2: w - R, y1: y(v), y2: y(v), class: 'vx-grid' }),
      svgEl('text', { x: L - 6, y: y(v) + 4, class: 'vx-ax', 'text-anchor': 'end' }, fmt(v)),
    );
  }
  const banda = (w - L - R) / Math.max(1, itens.length),
    bw = Math.min(24, banda * 0.62);
  const cada = Math.max(1, Math.ceil(itens.length / Math.max(1, Math.floor((w - L - R) / 44))));
  const iMax = itens.reduce((m, x, i) => (x.valor > itens[m].valor ? i : m), 0);
  itens.forEach((x, i) => {
    const g = svgEl('g'),
      cx = L + banda * i + banda / 2,
      h = Math.max(x.valor > 0 ? 1 : 0, H - B - y(x.valor));
    g.append(svgEl('rect', { x: L + banda * i, y: T, width: banda, height: H - T - B, class: 'vx-hit' }));
    g.append(svgEl('path', { d: pathBarra(cx - bw / 2, H - B - h, bw, h, true), fill: cor, class: 'vx-bar' }));
    if ((i === iMax || i === itens.length - 1) && x.valor > 0)
      g.append(svgEl('text', { x: cx, y: H - B - h - 6, class: 'vx-val', 'text-anchor': 'middle' }, fmt(x.valor)));
    interactivo(g, x.titulo, x.linhas, x.href);
    sv.append(g);
    const ultimaMostrada = Math.floor((itens.length - 1) / cada) * cada;
    if (i % cada === 0 || (i === itens.length - 1 && (i - ultimaMostrada) * banda >= 44))
      sv.append(svgEl('text', { x: cx, y: H - 8, class: 'vx-ax', 'text-anchor': 'middle' }, x.rot));
  });
  if (ref) {
    sv.append(svgEl('line', { x1: L, x2: w - R, y1: y(ref.valor), y2: y(ref.valor), class: 'vx-ref' }));
    sv.append(svgEl('text', { x: w - R, y: y(ref.valor) - 6, class: 'vx-ax', 'text-anchor': 'end' }, ref.rot));
  }
  return sv;
}
// Barras horizontais ordenadas (comparar valores); cor por linha opcional (antiguidade)
export function grafBarras(w, { itens, cor = COR.s1, fmt = kzc }) {
  if (w < 480) return grafBarrasEmpilhadas(w, { itens, cor, fmt });
  const linha = 30,
    T = 4,
    H = T + itens.length * linha + 4;
  const Lr = Math.min(190, Math.max(90, w * 0.34)),
    Rv = Math.max(64, 8 + 7.2 * Math.max(...itens.map((x) => fmt(x.valor).length), 1));
  const max = Math.max(...itens.map((x) => x.valor), 0) || 1,
    larg = w - Lr - Rv - 8;
  const sv = svgEl('svg', { viewBox: `0 0 ${w} ${H}`, width: w, height: H, class: 'vx' });
  sv.append(svgEl('line', { x1: Lr, x2: Lr, y1: T, y2: H - 4, class: 'vx-grid' }));
  itens.forEach((x, i) => {
    const g = svgEl('g'),
      y0 = T + i * linha,
      bh = 16,
      bw = Math.max(x.valor > 0 ? 2 : 0, (x.valor / max) * larg);
    g.append(svgEl('rect', { x: 0, y: y0, width: w, height: linha, class: 'vx-hit' }));
    g.append(svgEl('text', { x: Lr - 10, y: y0 + linha / 2 + 4, class: 'vx-rot', 'text-anchor': 'end' }, cortar(x.rot, Lr - 14)));
    g.append(svgEl('path', { d: pathBarra(Lr, y0 + (linha - bh) / 2, bw, bh, false), fill: x.cor || cor, class: 'vx-bar' }));
    g.append(svgEl('text', { x: Lr + bw + 8, y: y0 + linha / 2 + 4, class: 'vx-val' }, fmt(x.valor)));
    interactivo(g, x.titulo || x.rot, x.linhas, x.href);
    sv.append(g);
  });
  return sv;
}
// Versão estreita (telemóvel): nome por cima, barra por baixo com o valor na ponta
function grafBarrasEmpilhadas(w, { itens, cor, fmt }) {
  const linha = 44,
    H = itens.length * linha + 2,
    Rv = Math.max(56, 8 + 7.2 * Math.max(...itens.map((x) => fmt(x.valor).length), 1));
  const max = Math.max(...itens.map((x) => x.valor), 0) || 1,
    larg = w - Rv - 4;
  const sv = svgEl('svg', { viewBox: `0 0 ${w} ${H}`, width: w, height: H, class: 'vx' });
  itens.forEach((x, i) => {
    const g = svgEl('g'),
      y0 = i * linha,
      bw = Math.max(x.valor > 0 ? 2 : 0, (x.valor / max) * larg);
    g.append(svgEl('rect', { x: 0, y: y0, width: w, height: linha, class: 'vx-hit' }));
    g.append(svgEl('text', { x: 0, y: y0 + 15, class: 'vx-rot' }, cortar(x.rot, w - 4)));
    g.append(svgEl('path', { d: pathBarra(0, y0 + 22, bw, 14, false), fill: x.cor || cor, class: 'vx-bar' }));
    g.append(svgEl('text', { x: bw + 8, y: y0 + 33, class: 'vx-val' }, fmt(x.valor)));
    interactivo(g, x.titulo || x.rot, x.linhas, x.href);
    sv.append(g);
  });
  return sv;
}
// Barra 100% (parte do todo) com legenda em texto: nome, valor e percentagem
export function grafParte(w, { partes, fmt = kzc }) {
  const tot = sum(partes, 'valor') || 1,
    H = 30,
    wrap = el('div', 'vx-parte');
  const sv = svgEl('svg', { viewBox: `0 0 ${w} ${H}`, width: w, height: H, class: 'vx' });
  let x = 0;
  const vis = partes.filter((p) => p.valor > 0);
  vis.forEach((p, i) => {
    const pw = (p.valor / tot) * w,
      g = svgEl('g'),
      gap = i < vis.length - 1 ? 2 : 0;
    const r = Math.min(4, (pw - gap) / 2),
      x0 = x,
      x1 = x + pw - gap;
    const d =
      vis.length === 1
        ? `M${x0 + r},4H${x1 - r}Q${x1},4 ${x1},${4 + r}V${H - 4 - r}Q${x1},${H - 4} ${x1 - r},${H - 4}H${x0 + r}Q${x0},${H - 4} ${x0},${H - 4 - r}V${4 + r}Q${x0},4 ${x0 + r},4Z`
        : i === 0
          ? `M${x0 + r},4H${x1}V${H - 4}H${x0 + r}Q${x0},${H - 4} ${x0},${H - 4 - r}V${4 + r}Q${x0},4 ${x0 + r},4Z`
          : i === vis.length - 1
            ? `M${x0},4H${x1 - r}Q${x1},4 ${x1},${4 + r}V${H - 4 - r}Q${x1},${H - 4} ${x1 - r},${H - 4}H${x0}Z`
            : `M${x0},4H${x1}V${H - 4}H${x0}Z`;
    g.append(svgEl('rect', { x: x0, y: 0, width: Math.max(pw, 1), height: H, class: 'vx-hit' }), svgEl('path', { d, fill: p.cor, class: 'vx-bar' }));
    interactivo(g, p.nome, [[fmt(p.valor), pct(p.valor / tot)]], p.href);
    sv.append(g);
    x += pw;
  });
  const leg = el('div', 'viz-leg');
  partes.forEach((p) => {
    const s = el('span');
    const k = el('i');
    k.style.background = p.cor;
    s.append(k, el('span', null, p.nome), el('b', null, fmt(p.valor)), el('em', null, pct(p.valor / tot)));
    leg.append(s);
  });
  wrap.append(sv, leg);
  return wrap;
}
// Cartão de um gráfico: título, frase-resumo, alternância gráfico/tabela e ligação para o relatório
export function cartaoGrafico({ titulo, sub, destino, desenhar, colunas, linhas }) {
  const c = el('section', 'glass viz-card'),
    h = el('div', 'viz-h'),
    hd = el('div');
  hd.append(el('h3', null, titulo));
  if (sub) hd.append(el('p', null, sub));
  const acc = el('div', 'viz-acc');
  const bt = el('button', 'linkbtn', t('Tabela'));
  bt.type = 'button';
  bt.setAttribute('aria-pressed', 'false');
  acc.append(bt);
  if (destino) {
    const a = el('a', 'linkbtn', t('Ver relatório') + ' →');
    a.href = '#' + destino;
    acc.append(a);
  }
  h.append(hd, acc);
  const corpo = el('div', 'viz-corpo'),
    tb = el('div', 'viz-tab tw');
  tb.hidden = true;
  const tab = el('table'),
    th = el('tr');
  colunas.forEach(([n, num]) => th.append(el('th', num ? 'n' : null, n)));
  const tbody = el('tbody');
  linhas.forEach((l) => {
    const tr = el('tr');
    l.forEach((v, i) => tr.append(el('td', colunas[i][1] ? 'n' : null, v)));
    tbody.append(tr);
  });
  const thead = el('thead');
  thead.append(th);
  tab.append(thead, tbody);
  tb.append(tab);
  bt.addEventListener('click', () => {
    const ver = tb.hidden;
    tb.hidden = !ver;
    corpo.hidden = ver;
    bt.setAttribute('aria-pressed', ver ? 'true' : 'false');
    bt.textContent = ver ? t('Gráfico') : t('Tabela');
  });
  c.append(h, corpo, tb);
  let ultima = 0;
  FITS.push(() => {
    if (!corpo.isConnected || corpo.hidden) return;
    const w = Math.floor(corpo.clientWidth);
    if (!w || w === ultima) return;
    ultima = w;
    corpo.replaceChildren(desenhar(w));
  });
  return c;
}
// Gráfico de barras agrupadas em SVG: categorias × séries, linha de meta opcional, valor por cima da última série
export function barras({ cats, series, meta, metaTxt, altura = 220, rot = 0 }) {
  const W = Math.max(320, cats.length * (series.length * 14 + 22)),
    H = altura,
    L = 34,
    B = rot ? 46 : 26,
    T = 18,
    R = 8;
  const max = Math.max(meta || 0, ...series.flatMap((s) => s.vals)) * 1.12 || 1;
  const passo = (W - L - R) / cats.length,
    bw = Math.min(18, (passo - 8) / series.length);
  const y = (v) => T + (H - T - B) * (1 - v / max),
    ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
  svg.setAttribute('class', 'gsvg');
  svg.setAttribute('role', 'img');
  const mk = (tag, at, txt) => {
    const e = document.createElementNS(ns, tag);
    Object.entries(at).forEach(([k, v]) => e.setAttribute(k, v));
    if (txt != null) e.textContent = txt;
    svg.append(e);
    return e;
  };
  const nice = max > 120 ? 50 : max > 40 ? 10 : 5;
  for (let g = 0; g <= max; g += nice) {
    mk('line', { x1: L, x2: W - R, y1: y(g), y2: y(g), class: 'g-grid' });
    mk('text', { x: L - 6, y: y(g) + 4, class: 'g-ax', 'text-anchor': 'end' }, n0(g));
  }
  cats.forEach((c, i) => {
    const x0 = L + passo * i + (passo - bw * series.length) / 2;
    series.forEach((s, j) => {
      const v = s.vals[i] || 0,
        h = Math.max(v ? 2 : 0, H - B - y(v)),
        cls = typeof s.cls === 'function' ? s.cls(v, i) : s.cls;
      const r = mk('rect', { x: x0 + j * bw + 1, y: H - B - h, width: Math.max(2, bw - 2), height: h, rx: 3, class: cls });
      const tt = document.createElementNS(ns, 'title');
      tt.textContent = `${c.titulo || c.txt} · ${s.nome}: ${n0(v)}`;
      r.append(tt);
      if (j === series.length - 1 && cats.length <= 31)
        mk('text', { x: x0 + j * bw + bw / 2, y: H - B - h - 4, class: 'g-val', 'text-anchor': 'middle' }, n0(v));
    });
    const tx = L + passo * i + passo / 2;
    if (!rot || i % Math.ceil(cats.length / 12) === 0 || i === cats.length - 1)
      mk(
        'text',
        rot
          ? { x: tx, y: H - B + 14, class: 'g-ax', 'text-anchor': 'end', transform: `rotate(-35 ${tx} ${H - B + 14})` }
          : { x: tx, y: H - 8, class: 'g-ax', 'text-anchor': 'middle' },
        c.txt,
      );
  });
  if (meta) {
    mk('line', { x1: L, x2: W - R, y1: y(meta), y2: y(meta), class: 'g-meta' });
    mk('text', { x: W - R, y: y(meta) - 5, class: 'g-ax', 'text-anchor': 'end' }, metaTxt || t('Meta {m}', { m: n0(meta) }));
  }
  const w = el('div', 'gwrap');
  w.append(svg);
  return w;
}
export const legenda = (itens) => {
  const l = el('div', 'glegenda');
  itens.forEach(([cls, txt]) => {
    const s = el('span');
    s.append(el('i', cls), document.createTextNode(txt));
    l.append(s);
  });
  return l;
};
