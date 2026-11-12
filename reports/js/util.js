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
  mapa: 'M9 4 3 6v14l6-2 6 2 6-2V4l-6 2zM9 4v14M15 6v14',
  pin: 'M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21zM12 12a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
  radar: 'M12 12l6-6M19.1 4.9A10 10 0 1 0 22 12M16.2 7.8A6 6 0 1 0 18 12M12 12h.01',
  zona: 'M5 8l6-4 8 3 1 9-7 4-8-3zM5 8h.01M11 4h.01M19 7h.01M20 16h.01M13 20h.01M5 17h.01',
  menu: 'M4 4h16v16H4zM9 4v16',
  actualizar: 'M20 12a8 8 0 1 1-2.34-5.66M20 4v5h-5',
  // selo de certificação (conformidade)
  selo: 'M12 2l2.4 1.8 3-.2.9 2.9 2.5 1.7-1 2.8 1 2.8-2.5 1.7-.9 2.9-3-.2L12 22l-2.4-1.8-3 .2-.9-2.9L3.2 15.8l1-2.8-1-2.8 2.5-1.7.9-2.9 3 .2zM8.5 12l2.5 2.5 4.5-5',
  // moeda com seta para cima (valor do portal)
  valor:
    'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM9.5 14.5c0 1 1.1 1.8 2.5 1.8s2.5-.8 2.5-1.8-1.1-1.6-2.5-1.8-2.5-.8-2.5-1.8 1.1-1.8 2.5-1.8 2.5.8 2.5 1.8M12 6.5v1.2M12 16.3v1.2',
  // auscultadores (suporte)
  suporte: 'M4 14v-2a8 8 0 0 1 16 0v2M4 14h3v6H5a1 1 0 0 1-1-1zM20 14h-3v6h2a1 1 0 0 0 1-1zM17 20a4 4 0 0 1-4 2h-1',
  // peças de lego (módulos do portal)
  pecas: 'M4 10h6V4H4zM14 10h6V4h-6zM4 20h6v-6H4zM14 17h6M17 14v6',
  // carrinha com marca de verificação (painel da carrinha: fecho de rota)
  carrinhaok: 'M1 6h13v10H1zM14 10h4l3 3v3h-7M5.5 19a1.5 1.5 0 1 0 0-.01M17.5 19a1.5 1.5 0 1 0 0-.01M4.5 11l2 2 4-4',
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
  sino: 'M6 16V11a6 6 0 0 1 12 0v5l2 2H4zM10 21h4',
  ia: 'M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8zM19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9z',
  tv: 'M3 5h18v12H3zM8 21h8M12 17v4',
  user: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1',
  cal: 'M4 5h16v16H4zM4 10h16M8 3v4M16 3v4M8 14h2M14 14h2M8 17h2',
  loja: 'M3 9l1.5-5h15L21 9zM3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0M5 12v9h14v-9M10 21v-5h4v5',
  lista: 'M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01',
  kpi: 'M3 18a9 9 0 0 1 18 0M12 18l4-5M6.5 12.5l1 .6M12 9v1.2',
  pizza: 'M12 3v9h9a9 9 0 1 1-9-9zM15 3.5A9 9 0 0 1 20.5 9H15z',
  userplus: 'M10 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM3 21v-1a6 6 0 0 1 6-6h3M18 14v6M15 17h6',
  carrinho: 'M3 4h2l2.5 11h10L20 7H6.2M9 21a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM17 21a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z',
  qr: 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 14h2v2h-2zM14 18h2v2h-2zM18 18h2v2h-2z',
  check: 'M4 5h16v16H4zM4 10h16M8 3v4M16 3v4M9 15l2 2 4-4',
  agenda: 'M6 3h12a1 1 0 0 1 1 1v17H7a2 2 0 0 1-2-2V5a2 2 0 0 1 1-2zM5 18a2 2 0 0 1 2-2h12M9 7h6M9 11h4',
  rede: 'M12 7a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM5 21a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM19 21a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM12 7v5M12 12l-5.5 5.5M12 12l5.5 5.5',
  carga: 'M21 8l-9-5-9 5v8l9 5 9-5zM12 7v7M9 11.5l3 3 3-3',
  sync: 'M20 12a8 8 0 0 1-14.3 4.9M4 12a8 8 0 0 1 14.3-4.9M18.5 3v4h-4M5.5 21v-4h4',
  pasta: 'M3 6h6l2 2h10v12H3z',
  historico: 'M3 12a9 9 0 1 0 2.6-6.4M3 4v5h5M12 8v4l3 2',
  alvo: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM12 12h.01',
  slides: 'M3 4h18v12H3zM12 16v4M8 20h8M7 8h6M7 12h10',
  lupa: 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM21 21l-5-5',
  estrela: 'M12 3l2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3l-5.6 2.9 1.1-6.2L3 9.6l6.2-.9z',
  sair: 'M15 4h4v16h-4M10 16l4-4-4-4M14 12H3',
  negocio: 'M4 20V9l8-5 8 5v11M9 20v-6h6v6M4 20h16',
  grelha: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
  requisicao: 'M9 3h6v3H9zM7 4.5H5V21h14V4.5h-2M8 11h8M8 15h8M8 19h4',
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
