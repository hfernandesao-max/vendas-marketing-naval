// Nomes dos artigos do Pinguim BO na língua escolhida (prática de localização dos catálogos de produto): os termos
// genéricos traduzem-se (fraldas de bebé, sabão, cores, tamanhos…); marcas, linhas de produto, medidas e embalagens
// (NAVAL, MIMO, CONFIANÇA, 20x850g, 4 x 48…) ficam iguais, como tudo o que não tem tradução. Também em português: os
// termos em inglês que vêm do Pinguim BO («SOAP BROWN») passam a português («SABÃO CASTANHO»).
// Só muda o que se mostra: o nome guardado, as ligações e a procura usam sempre o nome original.
import { LANG } from './i18n.js';

// termo (maiúsculas, sem acentos) → [português, inglês, tigrínia]; os de várias palavras primeiro
const G = {
  'FRALDAS DE BEBE': ['FRALDAS DE BEBÉ', 'BABY DIAPERS', 'ዳይፐር ህጻናት'],
  FRALDAS: ['FRALDAS', 'DIAPERS', 'ዳይፐር'],
  'EM BARRA': ['EM BARRA', 'BAR', 'ባር'],
  'COM AROMA': ['COM AROMA', 'SCENTED', 'ምስ መዓዛ'],
  'C/AROMA': ['COM AROMA', 'SCENTED', 'ምስ መዓዛ'],
  'C/ARO': ['COM AROMA', 'SCENTED', 'ምስ መዓዛ'],
  'W/PERFUME': ['COM PERFUME', 'W/PERFUME', 'ምስ ሽቶ'],
  'W/P': ['COM PERFUME', 'W/PERFUME', 'ምስ ሽቶ'],
  'EX-LONGO': ['EXTRA LONGO', 'EXTRA LONG', 'ኣዝዩ ነዊሕ'],
  'EX-LONG': ['EXTRA LONGO', 'EXTRA LONG', 'ኣዝዩ ነዊሕ'],
  'CUIDADO NATURAL': ['CUIDADO NATURAL', 'NATURAL CARE', 'ተፈጥሮኣዊ ክንክን'],
  'DOCE SEDUCAO': ['DOCE SEDUÇÃO', 'SWEET SEDUCTION', 'ጥዑም ምስሓብ'],
  'ENERGIA NATURAL': ['ENERGIA NATURAL', 'NATURAL ENERGY', 'ተፈጥሮኣዊ ጉልበት'],
  REVITALIZANTE: ['REVITALIZANTE', 'REVITALISING', 'ዘሐድስ'],
  SABAO: ['SABÃO', 'SOAP', 'ሳሙና'],
  SOAP: ['SABÃO', 'SOAP', 'ሳሙና'],
  SABONETE: ['SABONETE', 'TOILET SOAP', 'ሳሙና ሰብነት'],
  DETERGENTE: ['DETERGENTE', 'DETERGENT', 'ሳሙና ክዳን'],
  'EM PO': ['EM PÓ', 'POWDER', 'ደቂቕ'],
  LIXIVIA: ['LIXÍVIA', 'BLEACH', 'በረኪና'],
  ARROZ: ['ARROZ', 'RICE', 'ሩዝ'],
  VERDE: ['VERDE', 'GREEN', 'ቀጠልያ'],
  GREEN: ['VERDE', 'GREEN', 'ቀጠልያ'],
  CASTANHO: ['CASTANHO', 'BROWN', 'ቡናዊ'],
  CASTAN: ['CASTANHO', 'BROWN', 'ቡናዊ'],
  BROWN: ['CASTANHO', 'BROWN', 'ቡናዊ'],
  AMARELO: ['AMARELO', 'YELLOW', 'ብጫ'],
  AZUL: ['AZUL', 'BLUE', 'ሰማያዊ'],
  BRANCO: ['BRANCO', 'WHITE', 'ጻዕዳ'],
  VERMELHO: ['VERMELHO', 'RED', 'ቀይሕ'],
  BARRAS: ['BARRAS', 'BARS', 'ባራት'],
  BARRA: ['BARRA', 'BAR', 'ባር'],
  MEDIUM: ['MÉDIO', 'MEDIUM', 'ማእከላይ'],
  MEDIO: ['MÉDIO', 'MEDIUM', 'ማእከላይ'],
  SMALL: ['PEQUENO', 'SMALL', 'ንእሽቶ'],
  LARGE: ['GRANDE', 'LARGE', 'ዓቢ'],
  BIG: ['GRANDE', 'BIG', 'ዓቢ'],
  NEW: ['NOVO', 'NEW', 'ሓድሽ'],
  AROMA: ['AROMA', 'AROMA', 'መዓዛ'],
};
const IDX = { pt: 0, en: 1, ti: 2 };
const MAX_PALAVRAS = 3;
const norma = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase();
const cache = { pt: new Map(), en: new Map(), ti: new Map() };

/** Nome do artigo na língua do ecrã (o original quando nada tem tradução). */
export function tArtigo(nome) {
  if (nome == null || nome === '') return nome;
  const s = String(nome);
  const l = IDX[LANG] != null ? LANG : 'pt';
  const c = cache[l];
  if (c.has(s)) return c.get(s);
  // palavras com a pontuação à volta («(BIG)») separada; espaços (também os não separáveis) mantidos
  const partes = s.split(/(\s+)/);
  const pal = [];
  partes.forEach((p, i) => {
    if (i % 2 === 0 && p !== '') {
      const m = p.match(/^([(«"'[]*)(.*?)([)\]»"',.;:]*)$/);
      pal.push({ i, antes: m[1], nucleo: m[2], depois: m[3] });
    }
  });
  const out = partes.slice();
  for (let k = 0; k < pal.length;) {
    let feito = false;
    for (let n = Math.min(MAX_PALAVRAS, pal.length - k); n >= 1; n--) {
      const grupo = pal.slice(k, k + n);
      // frase: só entre palavras sem pontuação no meio
      if (grupo.slice(0, -1).some((g) => g.depois) || grupo.slice(1).some((g) => g.antes)) continue;
      const tr = G[norma(grupo.map((g) => g.nucleo).join(' '))];
      if (!tr) continue;
      const original = grupo.map((g) => g.nucleo).join(' ');
      let novo = tr[IDX[l]];
      // mantém a caixa do original (o Pinguim BO escreve em maiúsculas; o tigrínia não tem caixa)
      if (l !== 'ti' && original !== original.toUpperCase())
        novo = original === original.toLowerCase() ? novo.toLowerCase() : novo.charAt(0) + novo.slice(1).toLowerCase();
      out[grupo[0].i] = grupo[0].antes + novo + grupo[n - 1].depois;
      for (let j = 1; j < n; j++) {
        out[grupo[j].i] = '';
        out[grupo[j].i - 1] = ''; // o espaço antes da palavra juntada
      }
      k += n;
      feito = true;
      break;
    }
    if (!feito) k++;
  }
  const r = out.join('');
  c.set(s, r);
  return r;
}

/** Campos que trazem nomes de artigos (tabelas, Excel, gráficos). */
export const CAMPOS_ARTIGO = new Set(['artigo', 'produto', 'se_nome', 'entao_nome', 'artigo_nome', 'nome_artigo']);
