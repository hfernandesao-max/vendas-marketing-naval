// Gráficos do painel de gestão RTM (SVG, uma só escala por gráfico, marcas finas, dica ao passar o rato e com o teclado;
// a vista em tabela vem do cartão). Formas de referência: tendência com média móvel, balas, ponte (cascata), curva ABC,
// dispersão (bolhas), mapa de calor e colunas agrupadas. Cores: azuis Naval; vermelho só para quedas.
import { n0, pct } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { COR, escala, interactivo, kzc, pathBarra, svgEl } from './graficos.js';

const AZUL = COR.s1,
  AZUL_CLARO = '#8fb4ff',
  QUEDA = COR.a3;
const eixoY = (sv, L, R, w, y, max, passo, fmt) => {
  for (let v = 0; v <= max + 1e-9; v += passo)
    sv.append(
      svgEl('line', { x1: L, x2: w - R, y1: y(v), y2: y(v), class: 'vx-grid' }),
      svgEl('text', { x: L - 6, y: y(v) + 4, class: 'vx-ax', 'text-anchor': 'end' }, fmt(v)),
    );
};

/** Tendência: colunas finas com as vendas do dia, linha da média móvel de 7 dias e previsão tracejada com o intervalo de 80%. */
export function grafTendencia(w, { dias, previsao, rot, altura }) {
  const H = Math.max(110, Math.round(altura || 260)),
    T = 16,
    B = 26,
    R = 8;
  const todos = [...dias.map((d) => d.vendas), ...previsao.map((p) => p.maximo)];
  const { max, passo } = escala(Math.max(...todos, 0));
  const L = Math.max(36, 8 + 7 * kzc(max).length);
  const n = dias.length + previsao.length;
  const banda = (w - L - R) / Math.max(1, n);
  const x = (i) => L + banda * i + banda / 2;
  const y = (v) => T + (H - T - B) * (1 - v / max);
  const sv = svgEl('svg', { viewBox: `0 0 ${w} ${H}`, width: w, height: H, class: 'vx' });
  eixoY(sv, L, R, w, y, max, passo, kzc);
  const bw = Math.max(1.5, Math.min(8, banda * 0.6));
  dias.forEach((d, i) => {
    const g = svgEl('g');
    g.append(svgEl('rect', { x: L + banda * i, y: T, width: banda, height: H - T - B, class: 'vx-hit' }));
    const h = Math.max(d.vendas > 0 ? 1 : 0, H - B - y(d.vendas));
    g.append(svgEl('path', { d: pathBarra(x(i) - bw / 2, H - B - h, bw, h, true), fill: AZUL_CLARO, opacity: 0.55, class: 'vx-bar' }));
    interactivo(g, rot(d.dia), [[kzc(d.vendas), t('vendas'), AZUL_CLARO], ...(d.mm7 != null ? [[kzc(d.mm7), t('média móvel de 7 dias'), AZUL]] : [])]);
    sv.append(g);
  });
  const pts = dias.map((d, i) => (d.mm7 == null ? null : `${x(i)},${y(d.mm7)}`)).filter(Boolean);
  if (pts.length) sv.append(svgEl('polyline', { points: pts.join(' '), fill: 'none', stroke: AZUL, 'stroke-width': 2, 'stroke-linejoin': 'round' }));
  if (previsao.length) {
    const o = dias.length;
    const banda80 = previsao
      .map((p, i) => `${x(o + i)},${y(p.maximo)}`)
      .concat([...previsao].reverse().map((p, k) => `${x(o + previsao.length - 1 - k)},${y(p.minimo)}`));
    sv.append(svgEl('polygon', { points: banda80.join(' '), fill: AZUL, opacity: 0.12 }));
    sv.append(
      svgEl('polyline', {
        points: previsao.map((p, i) => `${x(o + i)},${y(p.previsto)}`).join(' '),
        fill: 'none',
        stroke: AZUL,
        'stroke-width': 2,
        'stroke-dasharray': '5 4',
      }),
    );
    previsao.forEach((p, i) => {
      const g = svgEl('g');
      g.append(svgEl('rect', { x: L + banda * (o + i), y: T, width: banda, height: H - T - B, class: 'vx-hit' }));
      interactivo(g, rot(p.dia), [
        [kzc(p.previsto), t('previsto'), AZUL],
        [kzc(p.minimo) + ' – ' + kzc(p.maximo), t('intervalo de 80%')],
      ]);
      sv.append(g);
    });
  }
  const cada = Math.max(1, Math.ceil(n / Math.max(1, Math.floor((w - L - R) / 56))));
  [...dias.map((d) => d.dia), ...previsao.map((p) => p.dia)].forEach((d, i) => {
    if (i % cada === 0) sv.append(svgEl('text', { x: x(i), y: H - 8, class: 'vx-ax', 'text-anchor': 'middle' }, rot(d)));
  });
  return sv;
}

/** Balas (bullet chart): barra do período actual e traço do período comparável, por linha, numa escala comum. */
export function grafBalas(w, { itens, altura }) {
  // quadro baixo: só as linhas que cabem com 12 px cada (as maiores; o resto fica na tabela)
  if (altura) itens = itens.slice(0, Math.max(1, Math.floor((altura - 26) / 12)));
  const T = 4,
    linha = altura ? Math.max(6, Math.min(30, Math.floor((altura - T - 22) / Math.max(1, itens.length)))) : 30,
    H = T + itens.length * linha + 22,
    Lr = Math.min(170, Math.max(90, w * 0.28)),
    R = 70;
  const { max, passo } = escala(Math.max(...itens.map((x) => Math.max(x.actual, x.anterior)), 0));
  const sx = (v) => Lr + (w - Lr - R) * (v / max);
  const sv = svgEl('svg', { viewBox: `0 0 ${w} ${H}`, width: w, height: H, class: 'vx' });
  for (let v = 0; v <= max + 1e-9; v += passo)
    sv.append(
      svgEl('line', { x1: sx(v), x2: sx(v), y1: T, y2: H - 18, class: 'vx-grid' }),
      svgEl('text', { x: sx(v), y: H - 4, class: 'vx-ax', 'text-anchor': 'middle' }, kzc(v)),
    );
  itens.forEach((x, i) => {
    const y0 = T + i * linha;
    const g = svgEl('g');
    g.append(svgEl('rect', { x: 0, y: y0, width: w, height: linha, class: 'vx-hit' }));
    g.append(svgEl('text', { x: Lr - 8, y: y0 + linha / 2 + 4, class: 'vx-rot', 'text-anchor': 'end' }, x.rotulo));
    const cor = x.anterior > 0 && x.actual < x.anterior ? QUEDA : AZUL;
    const fol = Math.max(2, Math.round(linha * 0.27));
    g.append(svgEl('path', { d: pathBarra(Lr, y0 + fol, Math.max(1, sx(x.actual) - Lr), linha - 2 * fol, false), fill: cor, class: 'vx-bar' }));
    if (x.anterior > 0)
      g.append(
        svgEl('line', {
          x1: sx(x.anterior),
          x2: sx(x.anterior),
          y1: y0 + 4,
          y2: y0 + linha - 4,
          stroke: 'currentColor',
          'stroke-width': 2.5,
          class: 'vx-marca',
        }),
      );
    g.append(svgEl('text', { x: w - R + 6, y: y0 + linha / 2 + 4, class: 'vx-val' }, x.variacao == null ? '—' : (x.variacao > 0 ? '+' : '') + pct(x.variacao)));
    interactivo(g, x.rotulo, [
      [kzc(x.actual), t('mês até hoje'), cor],
      [kzc(x.anterior), t('mesmos dias do mês anterior')],
    ]);
    sv.append(g);
  });
  return sv;
}

/** Ponte (cascata): do total anterior ao actual, com o contributo de cada linha (subidas a azul, quedas a vermelho). */
export function grafCascata(w, { inicio, fim, passos, rotInicio, rotFim, altura }) {
  const H = Math.max(110, Math.round(altura || 260)),
    T = 18,
    B = 24,
    R = 8;
  let acc = inicio;
  const barras = [{ rot: rotInicio, de: 0, ate: inicio, tipo: 'total' }];
  passos.forEach((p) => {
    barras.push({ rot: p.rot, de: acc, ate: acc + p.valor, tipo: p.valor >= 0 ? 'sobe' : 'desce', valor: p.valor });
    acc += p.valor;
  });
  barras.push({ rot: rotFim, de: 0, ate: fim, tipo: 'total' });
  const { max, passo } = escala(Math.max(...barras.map((b) => Math.max(b.de, b.ate)), 0));
  const L = Math.max(36, 8 + 7 * kzc(max).length);
  const banda = (w - L - R) / barras.length,
    bw = Math.min(34, banda * 0.62);
  const y = (v) => T + (H - T - B) * (1 - Math.max(0, v) / max);
  const sv = svgEl('svg', { viewBox: `0 0 ${w} ${H}`, width: w, height: H, class: 'vx' });
  eixoY(sv, L, R, w, y, max, passo, kzc);
  barras.forEach((b, i) => {
    const cx = L + banda * i + banda / 2;
    const top = y(Math.max(b.de, b.ate)),
      h = Math.max(1, Math.abs(y(b.de) - y(b.ate)));
    const g = svgEl('g');
    g.append(svgEl('rect', { x: L + banda * i, y: T, width: banda, height: H - T - B, class: 'vx-hit' }));
    g.append(
      svgEl('rect', {
        x: cx - bw / 2,
        y: top,
        width: bw,
        height: h,
        rx: 3,
        fill: b.tipo === 'desce' ? QUEDA : b.tipo === 'sobe' ? AZUL : '#1e3a8a',
        class: 'vx-bar',
      }),
    );
    if (i < barras.length - 1)
      sv.append(svgEl('line', { x1: cx + bw / 2, x2: cx + banda - bw / 2, y1: y(b.ate), y2: y(b.ate), class: 'vx-grid', 'stroke-dasharray': '3 3' }));
    g.append(svgEl('text', { x: cx, y: H - 8, class: 'vx-ax', 'text-anchor': 'middle' }, b.rot));
    interactivo(g, b.rot, [[b.tipo === 'total' ? kzc(b.ate) : (b.valor > 0 ? '+' : '') + kzc(b.valor), b.tipo === 'total' ? t('total') : t('contributo')]]);
    sv.append(g);
  });
  return sv;
}

/** Curva ABC (Pareto): % acumulada do valor face à % de itens, com a referência dos 80%. */
export function grafAbc(w, { curva, ate80, n, nome, altura }) {
  const H = Math.max(110, Math.round(altura || 240)),
    T = 12,
    B = 28,
    L = 40,
    R = 12;
  const sx = (v) => L + (w - L - R) * v,
    sy = (v) => T + (H - T - B) * (1 - v);
  const sv = svgEl('svg', { viewBox: `0 0 ${w} ${H}`, width: w, height: H, class: 'vx' });
  [0, 0.25, 0.5, 0.75, 1].forEach((v) =>
    sv.append(
      svgEl('line', { x1: L, x2: w - R, y1: sy(v), y2: sy(v), class: 'vx-grid' }),
      svgEl('text', { x: L - 6, y: sy(v) + 4, class: 'vx-ax', 'text-anchor': 'end' }, n0(v * 100) + '%'),
      svgEl('text', { x: sx(v), y: H - 8, class: 'vx-ax', 'text-anchor': 'middle' }, n0(v * 100) + '%'),
    ),
  );
  if (!curva.length) return sv;
  sv.append(svgEl('polyline', { points: curva.map(([a, b]) => `${sx(a)},${sy(b)}`).join(' '), fill: 'none', stroke: AZUL, 'stroke-width': 2.2 }));
  const x80 = n ? ate80 / n : 0;
  sv.append(
    svgEl('line', { x1: L, x2: sx(x80), y1: sy(0.8), y2: sy(0.8), class: 'vx-ref', 'stroke-dasharray': '4 3' }),
    svgEl('line', { x1: sx(x80), x2: sx(x80), y1: sy(0.8), y2: sy(0), class: 'vx-ref', 'stroke-dasharray': '4 3' }),
  );
  const g = svgEl('g');
  g.append(svgEl('circle', { cx: sx(x80), cy: sy(0.8), r: 5, fill: AZUL, class: 'vx-bar' }));
  g.append(
    svgEl(
      'text',
      { x: sx(x80) > w / 2 ? sx(x80) - 8 : sx(x80) + 8, y: sy(0.8) + 18, class: 'vx-val', 'text-anchor': sx(x80) > w / 2 ? 'end' : 'start' },
      w < 380 ? pct(x80) + ' → 80%' : t('{n} {o} ({p}) fazem 80%', { n: n0(ate80), o: nome, p: pct(x80) }),
    ),
  );
  interactivo(g, t('80% do valor'), [
    [n0(ate80), nome],
    [pct(x80), t('do total')],
  ]);
  sv.append(g);
  return sv;
}

/** Dispersão (bolhas): x e y por linha, área proporcional ao tamanho; linhas da média para os quatro quadrantes. */
export function grafDispersao(w, { itens, fx, fy, rx, ry, altura }) {
  const H = Math.max(120, Math.round(altura || 280)),
    T = 14,
    B = 34,
    R = 16;
  // eixos ajustados aos dados (com margem): numa dispersão interessa a posição relativa, não o zero
  const dominio = (vals) => {
    const mn = Math.min(...vals),
      mx = Math.max(...vals),
      folga = (mx - mn || mx || 1) * 0.15;
    const e = escala(mx + folga - Math.max(0, mn - folga));
    const ini = Math.max(0, Math.floor((mn - folga) / e.passo) * e.passo);
    return { ini, max: ini + Math.ceil((mx + folga - ini) / e.passo) * e.passo, passo: e.passo };
  };
  const ex = dominio(itens.map((p) => p.x)),
    ey = dominio(itens.map((p) => p.y));
  const L = Math.max(36, 8 + 7 * fy(ey.max).length);
  const sx = (v) => L + (w - L - R) * ((v - ex.ini) / (ex.max - ex.ini)),
    sy = (v) => T + (H - T - B) * (1 - (v - ey.ini) / (ey.max - ey.ini));
  const maxT = Math.max(...itens.map((p) => p.tamanho), 1);
  const sv = svgEl('svg', { viewBox: `0 0 ${w} ${H}`, width: w, height: H, class: 'vx' });
  for (let v = ey.ini; v <= ey.max + 1e-9; v += ey.passo)
    sv.append(
      svgEl('line', { x1: L, x2: w - R, y1: sy(v), y2: sy(v), class: 'vx-grid' }),
      svgEl('text', { x: L - 6, y: sy(v) + 4, class: 'vx-ax', 'text-anchor': 'end' }, fy(v)),
    );
  for (let v = ex.ini; v <= ex.max + 1e-9; v += ex.passo) sv.append(svgEl('text', { x: sx(v), y: H - 16, class: 'vx-ax', 'text-anchor': 'middle' }, fx(v)));
  sv.append(svgEl('text', { x: (L + w - R) / 2, y: H - 2, class: 'vx-ax', 'text-anchor': 'middle' }, rx));
  const mx = itens.reduce((s, p) => s + p.x, 0) / Math.max(1, itens.length),
    my = itens.reduce((s, p) => s + p.y, 0) / Math.max(1, itens.length);
  sv.append(
    svgEl('line', { x1: sx(mx), x2: sx(mx), y1: T, y2: H - B, class: 'vx-ref', 'stroke-dasharray': '4 3' }),
    svgEl('line', { x1: L, x2: w - R, y1: sy(my), y2: sy(my), class: 'vx-ref', 'stroke-dasharray': '4 3' }),
  );
  itens.forEach((p) => {
    const g = svgEl('g');
    const r = (H < 200 ? 3 : 4) + (H < 200 ? 9 : 14) * Math.sqrt(p.tamanho / maxT);
    g.append(svgEl('circle', { cx: sx(p.x), cy: sy(p.y), r, fill: AZUL, opacity: 0.55, stroke: 'var(--bg)', 'stroke-width': 2, class: 'vx-bar' }));
    g.append(svgEl('text', { x: sx(p.x), y: sy(p.y) + 4, class: 'vx-ax', 'text-anchor': 'middle' }, p.rot));
    interactivo(g, p.titulo, [
      [fx(p.x), rx, AZUL],
      [fy(p.y), ry],
      [kzc(p.tamanho), t('vendas')],
    ]);
    sv.append(g);
  });
  return sv;
}

/** Mapa de calor: linhas × colunas, uma só cor (azul) do claro ao escuro; valor no quadrado quando cabe. */
export function grafCalor(w, { linhas, colunas, fmt, altura }) {
  const Lr = Math.min(150, Math.max(46, w * 0.12)),
    T = 18,
    alt = altura ? Math.max(10, Math.min(26, Math.floor((altura - T - 4) / Math.max(1, linhas.length)))) : 26;
  const H = T + linhas.length * alt + 4;
  const cw = (w - Lr - 4) / colunas.length;
  const max = Math.max(...linhas.flatMap((l) => l.valores), 1);
  const sv = svgEl('svg', { viewBox: `0 0 ${w} ${H}`, width: w, height: H, class: 'vx' });
  colunas.forEach((c, j) => sv.append(svgEl('text', { x: Lr + cw * j + cw / 2, y: 14, class: 'vx-ax', 'text-anchor': 'middle' }, c)));
  linhas.forEach((l, i) => {
    const y0 = T + i * alt;
    sv.append(svgEl('text', { x: Lr - 8, y: y0 + alt / 2 + 4, class: 'vx-rot', 'text-anchor': 'end' }, l.rot));
    l.valores.forEach((v, j) => {
      const g = svgEl('g');
      const f = v / max;
      g.append(
        svgEl('rect', {
          x: Lr + cw * j + 1,
          y: y0 + 1,
          width: cw - 2,
          height: alt - 2,
          rx: 3,
          fill: AZUL,
          opacity: v > 0 ? 0.08 + 0.85 * f : 0.03,
          class: 'vx-bar',
        }),
      );
      if (cw > 54 && alt >= 18 && v > 0)
        g.append(
          svgEl('text', { x: Lr + cw * j + cw / 2, y: y0 + alt / 2 + 4, class: f > 0.55 ? 'vx-val vx-claro' : 'vx-ax', 'text-anchor': 'middle' }, fmt(v)),
        );
      interactivo(g, l.rot + ' · ' + colunas[j], [[fmt(v), t('média por dia')]]);
      sv.append(g);
    });
  });
  return sv;
}

/** Colunas agrupadas (poucas séries, mesma unidade), com legenda à parte. */
export function grafAgrupadas(w, { cats, series, fmt = n0, altura }) {
  const H = Math.max(110, Math.round(altura || 230)),
    T = 16,
    B = 26,
    R = 8;
  const { max, passo } = escala(Math.max(...series.flatMap((s) => s.valores), 0));
  const L = Math.max(30, 8 + 7 * fmt(max).length);
  const y = (v) => T + (H - T - B) * (1 - v / max);
  const banda = (w - L - R) / Math.max(1, cats.length),
    bw = Math.min(22, (banda * 0.7) / series.length);
  const sv = svgEl('svg', { viewBox: `0 0 ${w} ${H}`, width: w, height: H, class: 'vx' });
  eixoY(sv, L, R, w, y, max, passo, fmt);
  cats.forEach((c, i) => {
    const g = svgEl('g');
    g.append(svgEl('rect', { x: L + banda * i, y: T, width: banda, height: H - T - B, class: 'vx-hit' }));
    const x0 = L + banda * i + banda / 2 - (bw * series.length + 2 * (series.length - 1)) / 2;
    series.forEach((s, k) => {
      const v = s.valores[i],
        h = Math.max(v > 0 ? 1 : 0, H - B - y(v));
      g.append(svgEl('path', { d: pathBarra(x0 + k * (bw + 2), H - B - h, bw, h, true), fill: s.cor, class: 'vx-bar' }));
    });
    g.append(svgEl('text', { x: L + banda * i + banda / 2, y: H - 8, class: 'vx-ax', 'text-anchor': 'middle' }, c));
    interactivo(
      g,
      c,
      series.map((s) => [fmt(s.valores[i]), s.nome, s.cor]),
    );
    sv.append(g);
  });
  return sv;
}

/** Legenda (duas ou mais séries): amostra da cor e nome; o texto fica na cor do texto, nunca na da série. */
export function comLegenda(svg, itens) {
  const w = document.createElement('div');
  const l = document.createElement('div');
  l.className = 'glegenda';
  itens.forEach(([cor, txt, tracejado]) => {
    const s = document.createElement('span'),
      i = document.createElement('i');
    i.style.background = tracejado ? 'none' : cor;
    if (tracejado) {
      i.style.borderTop = '2px dashed ' + cor;
      i.style.height = '0';
      i.style.width = '16px';
      i.style.borderRadius = '0';
    }
    s.append(i, document.createTextNode(txt));
    l.append(s);
  });
  w.append(svg, l);
  return w;
}
