// Motor do explorador de relatórios (funções puras, sem ecrã): filtra, agrupa e calcula as medidas de uma área.
// Uma definição de relatório é só dados — área, linhas (até 3 dimensões), coluna (1 dimensão), medidas, filtros,
// ordem e limite —, nunca código nem SQL. As medidas e as dimensões vêm do catálogo (catalogo.js), com as regras da
// Naval, por isso um relatório criado por alguém dá sempre o mesmo número que os ecrãs oficiais.
//
// Dimensões de data aceitam um grão: «data:dia», «data:semana» (ISO 8601), «data:mes», «data:trimestre», «data:ano»
// e «data:dia_semana».

export const GRAOS = ['dia', 'semana', 'mes', 'trimestre', 'ano', 'dia_semana'];
export const VAZIO = '(sem valor)';
export const OUTROS = '(outros)';
const pad = (n) => String(n).padStart(2, '0');

/** Semana ISO 8601 de uma data AAAA-MM-DD: «AAAA-Wss». */
export function semanaIso(iso) {
  const d = new Date(Date.UTC(+iso.slice(0, 4), +iso.slice(5, 7) - 1, +iso.slice(8, 10)));
  const dia = d.getUTCDay() || 7;
  d.setUTCDate(d.getUTCDate() + 4 - dia);
  const ano = d.getUTCFullYear();
  const sem = Math.ceil(((d - Date.UTC(ano, 0, 1)) / 864e5 + 1) / 7);
  return ano + '-W' + pad(sem);
}

/** Valor de uma data no grão pedido (texto ordenável). */
export function noGrao(v, grao) {
  const s = String(v == null ? '' : v).slice(0, 10);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return VAZIO;
  switch (grao) {
    case 'semana':
      return semanaIso(s);
    case 'mes':
      return s.slice(0, 7);
    case 'trimestre':
      return s.slice(0, 4) + '-T' + Math.ceil(+s.slice(5, 7) / 3);
    case 'ano':
      return s.slice(0, 4);
    case 'dia_semana': {
      const d = new Date(Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10))).getUTCDay() || 7;
      return String(d); // 1 = segunda … 7 = domingo (ISO 8601)
    }
    default:
      return s;
  }
}

/** Separa «campo:grão». */
export const partes = (dim) => {
  const [campo, grao] = String(dim).split(':');
  return { campo, grao: GRAOS.includes(grao) ? grao : null };
};

/** Valor de uma dimensão numa linha (texto; vazio → «(sem valor)»). */
export function valorDim(linha, dim, campos) {
  const { campo, grao } = partes(dim);
  const c = campos[campo];
  const v = c && c.valor ? c.valor(linha) : linha[campo];
  if (c && c.tipo === 'data') return noGrao(v, grao || 'dia');
  if (v == null || v === '') return VAZIO;
  if (Array.isArray(v)) return v.length ? v.join(', ') : VAZIO;
  return String(v);
}

/** Valor numérico de um campo numa linha. */
export function valorNum(linha, campo, campos) {
  const c = campos[campo];
  const v = c && c.valor ? c.valor(linha) : linha[campo];
  const n = typeof v === 'number' ? v : v == null || v === '' ? NaN : +v;
  return isFinite(n) ? n : null;
}

/** Aplica os filtros {campo|dim: [valores]} (inclusão; lista vazia = sem filtro). */
export function filtrar(linhas, filtros, campos) {
  const fs = Object.entries(filtros || {}).filter(([, vs]) => Array.isArray(vs) && vs.length);
  if (!fs.length) return linhas;
  const sets = fs.map(([d, vs]) => [d, new Set(vs.map(String))]);
  return linhas.filter((l) => sets.every(([d, s]) => s.has(valorDim(l, d, campos))));
}

/** Acumulador de uma medida. */
function acumulador(m) {
  return { m, soma: 0, n: 0, num: 0, den: 0, min: null, max: null, dist: m.agg === 'distintos' ? new Set() : null };
}
function somar(a, linha, campos) {
  const m = a.m;
  if (m.agg === 'contagem') {
    if (!m.quando || m.quando(linha)) a.n++;
    return;
  }
  if (m.agg === 'distintos') {
    const v = m.valor ? m.valor(linha) : linha[m.campo];
    if (v != null && v !== '') a.dist.add(String(v));
    return;
  }
  if (m.agg === 'razao') {
    const x = m.num(linha),
      y = m.den(linha);
    if (x != null && isFinite(x)) a.num += +x;
    if (y != null && isFinite(y)) a.den += +y;
    return;
  }
  const v = m.valor ? m.valor(linha) : valorNum(linha, m.campo, campos);
  if (v == null || !isFinite(v)) return;
  a.soma += v;
  a.n++;
  a.min = a.min == null ? v : Math.min(a.min, v);
  a.max = a.max == null ? v : Math.max(a.max, v);
}
function resultado(a) {
  switch (a.m.agg) {
    case 'contagem':
      return a.n;
    case 'distintos':
      return a.dist.size;
    case 'media':
      return a.n ? a.soma / a.n : null;
    case 'min':
      return a.min;
    case 'max':
      return a.max;
    case 'razao':
      return a.den ? (a.num / a.den) * (a.m.escala || 1) : null;
    default:
      return a.soma;
  }
}

/** Calcula as medidas de um conjunto de linhas: {id: valor}. */
export function medir(linhas, medidas, campos) {
  const acc = medidas.map(acumulador);
  linhas.forEach((l) => acc.forEach((a) => somar(a, l, campos)));
  return Object.fromEntries(acc.map((a) => [a.m.id, resultado(a)]));
}

/**
 * Executa um relatório. dominio: {campos: {id: {nome, tipo, valor?}}, medidas: [{id, agg, campo|valor|num/den}]};
 * def: {linhas, coluna, medidas, filtros, ordem, limite}. Devolve {grupos, colunas, total, linhas (filtradas)}:
 *   grupos:  [{chaves: [..], valores: {medida: v}, porColuna: {valorColuna: {medida: v}}, n}]
 *   colunas: valores da dimensão de coluna (os 12 com maior primeira medida; o resto em «(outros)»)
 */
export function executar(linhasBase, dominio, def) {
  const campos = dominio.campos;
  const medidas = (def.medidas || []).map((id) => dominio.medidas.find((m) => m.id === id)).filter(Boolean);
  if (!medidas.length) return { grupos: [], colunas: [], total: {}, linhas: [], medidas: [] };
  const dims = (def.linhas || []).filter((d) => campos[partes(d).campo]);
  const linhas = filtrar(linhasBase, def.filtros, campos);
  const col = def.coluna && campos[partes(def.coluna).campo] ? def.coluna : null;
  // valores da coluna: os 12 mais significativos pela primeira medida (boa prática de legibilidade das tabelas cruzadas)
  let colunas = [];
  let colDe = null;
  if (col) {
    const porCol = new Map();
    linhas.forEach((l) => {
      const k = valorDim(l, col, campos);
      if (!porCol.has(k)) porCol.set(k, []);
      porCol.get(k).push(l);
    });
    const ord = [...porCol.entries()].map(([k, ls]) => [k, medir(ls, [medidas[0]], campos)[medidas[0].id] || 0]);
    const temporal = campos[partes(col).campo].tipo === 'data';
    ord.sort((a, b) => (temporal ? a[0].localeCompare(b[0]) : b[1] - a[1]));
    const fica = new Set(ord.slice(0, 12).map((x) => x[0]));
    colunas = ord.slice(0, 12).map((x) => x[0]);
    if (ord.length > 12) colunas.push(OUTROS);
    colDe = (l) => {
      const k = valorDim(l, col, campos);
      return fica.has(k) ? k : OUTROS;
    };
  }
  const mapa = new Map();
  linhas.forEach((l) => {
    const chaves = dims.map((d) => valorDim(l, d, campos));
    const k = JSON.stringify(chaves);
    if (!mapa.has(k)) mapa.set(k, { chaves, linhas: [] });
    mapa.get(k).linhas.push(l);
  });
  let grupos = [...mapa.values()].map((g) => {
    const porColuna = {};
    if (colDe) {
      const pc = new Map();
      g.linhas.forEach((l) => {
        const k = colDe(l);
        if (!pc.has(k)) pc.set(k, []);
        pc.get(k).push(l);
      });
      pc.forEach((ls, k) => {
        porColuna[k] = medir(ls, medidas, campos);
      });
    }
    return { chaves: g.chaves, valores: medir(g.linhas, medidas, campos), porColuna, n: g.linhas.length, linhas: g.linhas };
  });
  const m0 = medidas[0].id;
  const temporal = dims.length && campos[partes(dims[0]).campo].tipo === 'data';
  const ordem = def.ordem || (temporal ? 'nome' : 'valor_desc');
  grupos.sort((a, b) => {
    if (ordem === 'nome' || temporal) return a.chaves.join('\u0001').localeCompare(b.chaves.join('\u0001'), 'pt');
    const x = a.valores[m0] ?? -Infinity,
      y = b.valores[m0] ?? -Infinity;
    return ordem === 'valor_asc' ? x - y : y - x;
  });
  if (def.limite > 0 && !temporal) grupos = grupos.slice(0, def.limite);
  // total de cada coluna (medido sobre todas as linhas dessa coluna, não somado das linhas: médias e distintos certos)
  const totalColunas = {};
  if (colDe) {
    const pc = new Map();
    linhas.forEach((l) => {
      const k = colDe(l);
      if (!pc.has(k)) pc.set(k, []);
      pc.get(k).push(l);
    });
    pc.forEach((ls, k) => {
      totalColunas[k] = medir(ls, medidas, campos);
    });
  }
  return { grupos, colunas, total: medir(linhas, medidas, campos), totalColunas, linhas, medidas, dims, coluna: col };
}

/** Valores distintos de uma dimensão (para os filtros), por ordem. */
export function valoresDim(linhas, dim, campos, max = 500) {
  const s = new Set();
  for (const l of linhas) {
    s.add(valorDim(l, dim, campos));
    if (s.size > max) break;
  }
  return [...s].sort((a, b) => a.localeCompare(b, 'pt', { numeric: true }));
}
