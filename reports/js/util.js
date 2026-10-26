// Utilitários comuns: dados carregados, DOM, somas, agrupamentos, ícones e avisos.

export const DB = {};
export const $ = (s) => document.querySelector(s);
export const el = (tg, c, txt) => {
  const e = document.createElement(tg);
  if (c) e.className = c;
  if (txt != null) e.textContent = txt;
  return e;
};
export const sum = (a, f) => a.reduce((s, x) => s + (+(typeof f === 'function' ? f(x) : x[f]) || 0), 0);
export const group = (a, k) =>
  a.reduce((m, x) => {
    const g = typeof k === 'function' ? k(x) : x[k];
    (m[g] || (m[g] = [])).push(x);
    return m;
  }, {});
const ICON = {
  lock: 'M6 11h12v10H6zM8.5 11V8a3.5 3.5 0 0 1 7 0v3',
  actualizar: 'M20 12a8 8 0 1 1-2.34-5.66M20 4v5h-5',
  clip: 'M9 3h6v3H9zM9 4.5H6v16.5h12V4.5h-3M9 11h6M9 15h6M9 19h3',
  upload: 'M12 15V3M7 8l5-5 5 5M4 15v5h16v-5',
  clock: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 7v5l3 2',
  filtro: 'M3 5h18l-7 8v6l-4-2v-4z',
  users: 'M16 19v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1M9 10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM22 19v-1a4 4 0 0 0-3-3.9M16 3.1a3.5 3.5 0 0 1 0 6.8',
  grow: 'M3 17l6-6 4 4 8-8M15 7h6v6',
  shield: 'M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6zM9 12l2 2 4-4',
  trophy: 'M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0zM7 6H4v2a3 3 0 0 0 3 3M17 6h3v2a3 3 0 0 1-3 3',
  home: 'M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z',
  truck: 'M3 6h11v10H3zM14 9h4l3 3v4h-7M7.5 19a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM17.5 19a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z',
  ware: 'M3 9l9-5 9 5v11H3zM7 20v-7h10v7M7 16h10',
  box: 'M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8',
  card: 'M3 6h18v12H3zM3 10h18M7 15h4',
  chart: 'M4 20V10M10 20V4M16 20v-7M22 20H2',
  tag: 'M3 12V4h8l10 10-8 8zM7.5 8.5h.01',
  scale: 'M12 3v18M5 7h14M5 7l-3 7a3 3 0 0 0 6 0zM19 7l-3 7a3 3 0 0 0 6 0zM8 21h8',
  route: 'M6 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM18 9a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM8 17h7a3 3 0 0 0 0-6H9a3 3 0 0 1 0-6h7',
  phone: 'M7 2h10v20H7zM11 18h2',
  file: 'M6 2h9l5 5v15H6zM14 2v6h6M9 13h7M9 17h7',
};
export const svg = (k) => `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${ICON[k]}"/></svg>`;
export const toast = (m, ms) => {
  const x = $('#toast');
  x.textContent = m;
  x.hidden = false;
  clearTimeout(toast.h);
  if (ms)
    toast.h = setTimeout(() => {
      x.hidden = true;
    }, ms);
};
export const chaveTxt = (s) =>
  String(s || '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toUpperCase()
    .replace(/[^A-Z0-9]+/g, ' ')
    .trim();
const isoDia = (dt) => dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0') + '-' + String(dt.getDate()).padStart(2, '0');
export const somaDias = (iso, n) => {
  const [y, m, d] = iso.split('-').map(Number);
  return isoDia(new Date(y, m - 1, d + n));
};

// ---- Excel no formato habitual (GENERAL SALES PLAN) ----
export const b64 = (buf) => {
  const u = new Uint8Array(buf);
  let s = '';
  for (let i = 0; i < u.length; i += 0x8000) s += String.fromCharCode.apply(null, u.subarray(i, i + 0x8000));
  return btoa(s);
};
