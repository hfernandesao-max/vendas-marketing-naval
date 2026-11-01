// Idioma do portal (PT/EN/TI) e tradução dos textos. Os textos estão escritos em português no código; cada
// dicionário (en.js, ti.js) é indexado pelo texto em português. Sem tradução, mostra-se o texto em português.
import { EN } from './en.js';
import { TI } from './ti.js';

/** Idiomas do portal e respectivas etiquetas BCP 47 (atributo lang, formatação de números e datas). */
export const IDIOMAS = { pt: 'pt-PT', en: 'en', ti: 'ti' };
const DIC = { en: EN, ti: TI };
/** Nome de cada idioma na própria língua (norma dos selectores de idioma). */
export const NOMES_IDIOMA = { pt: 'Português', en: 'English', ti: 'ትግርኛ' };

export let LANG = 'pt';
try {
  const v = localStorage.getItem('vm-lang');
  LANG = v in IDIOMAS ? v : 'pt';
} catch (e) {}
// Mensagens com partes variáveis já montadas (ex.: do servidor, «Lote 12 não encontrado.»): procuram-se pelos modelos
// do dicionário com marcadores («Lote {n} não encontrado.») e as partes variáveis passam para a tradução.
const MODELOS = {};
const esc = (x) => x.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
function porModelo(d, s) {
  if (typeof s !== 'string' || !s) return null;
  if (!MODELOS[LANG]) {
    MODELOS[LANG] = { cache: new Map(), lista: [] };
    Object.keys(d).forEach((k) => {
      const nomes = [];
      const partes = k.split(/\{([a-z0-9_]+)\}/i);
      // só modelos com texto fixo suficiente (evita que «{a} · {b}» apanhe dados quaisquer)
      if (
        partes.length < 3 ||
        partes
          .filter((x, i) => !(i % 2))
          .join('')
          .replace(/[^A-Za-zÀ-ÿ]/g, '').length < 8
      )
        return;
      let re = '^';
      partes.forEach((x, i) => {
        if (i % 2) {
          nomes.push(x);
          re += '(.+?)';
        } else re += esc(x);
      });
      MODELOS[LANG].lista.push({
        re: new RegExp(re + '$', 's'),
        nomes,
        trad: d[k],
        letras: partes
          .filter((x, i) => !(i % 2))
          .join('')
          .replace(/[^A-Za-zÀ-ÿ]/g, '').length,
      });
    });
  }
  const M = MODELOS[LANG];
  if (M.cache.has(s)) return M.cache.get(s);
  let r = null;
  for (const m of M.lista) {
    const x = s.match(m.re);
    if (!x) continue;
    // modelos curtos («Carrinha {r}») só para partes variáveis curtas (evita traduzir frases inteiras por engano)
    if (m.letras < 15 && x.slice(1).join('').length > 15) continue;
    r = m.trad;
    m.nomes.forEach((n, i) => {
      const v = x[i + 1];
      r = r.split('{' + n + '}').join(d[v] != null ? d[v] : v);
    });
    break;
  }
  M.cache.set(s, r);
  return r;
}

export const t = (s, p) => {
  const d = DIC[LANG];
  let r = d && d[s] != null ? d[s] : s;
  if (d && d[s] == null && !p) {
    const m = porModelo(d, s);
    if (m != null) return m;
  }
  // Controlo de qualidade (testes): regista os textos pedidos sem tradução na língua escolhida
  if (d && d[s] == null && s && typeof globalThis.__vmSemTraducao === 'object') globalThis.__vmSemTraducao[s] = 1;
  if (p)
    Object.keys(p).forEach((k) => {
      r = r.split('{' + k + '}').join(p[k]);
    });
  return r;
};

// Valores de categorias vindos dos dados (tipo de cliente, estado, …): traduzidos quando há entrada no dicionário,
// sem distinguir maiúsculas («SUPERMERCADO» = «Supermercado»). Os restantes dados (nomes de clientes, artigos,
// armazéns, pessoas e locais) ficam como estão registados: os dados mestre não se traduzem.
const IDX = {};
export const tv = (v) => {
  if (v == null || v === '') return v;
  const d = DIC[LANG];
  if (!d) return v;
  if (d[v] != null) return d[v];
  if (!IDX[LANG]) {
    IDX[LANG] = {};
    Object.keys(d).forEach((k) => (IDX[LANG][k.toLowerCase()] = d[k]));
  }
  const r = IDX[LANG][String(v).toLowerCase()];
  return r != null ? r : v;
};

// Símbolos dos níveis da matriz de acessos, pela inicial do verbo em cada língua
// (pt: Ver, Exportar, Gerir, Administrar · en: View, Export, Manage, Administer · ti: ርኣይ, ኣውጽእ, ምሕደራ, ምምሕዳር).
const SIGLAS_NIVEL = {
  pt: { '': '—', V: 'V', Vr: 'V·r', E: 'E', Er: 'E·r', G: 'G', A: 'A' },
  en: { '': '—', V: 'V', Vr: 'V·o', E: 'E', Er: 'E·o', G: 'M', A: 'A' },
  ti: { '': '—', V: 'ር', Vr: 'ር·መ', E: 'ው', Er: 'ው·መ', G: 'ሕ', A: 'ም' },
};
export const siglaNivel = (n) => (SIGLAS_NIVEL[LANG] || SIGLAS_NIVEL.pt)[n || ''];

// Alteração do estado partilhado: só este módulo altera estas variáveis; os outros lêem-nas.
export function definirIdioma(valor) {
  LANG = valor in IDIOMAS ? valor : 'pt';
}
