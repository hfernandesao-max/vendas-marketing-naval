// Formatação de números, valores em kwanzas, percentagens e datas (dd-mm-aaaa).
import { LANG } from './i18n/i18n.js';

export let nf, nf1, nf2;
export const setFmt = () => {
  const loc = LANG === 'en' ? 'en-GB' : 'pt-PT';
  nf = new Intl.NumberFormat(loc, { maximumFractionDigits: 0 });
  nf1 = new Intl.NumberFormat(loc, { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  nf2 = new Intl.NumberFormat(loc, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};
setFmt();
export const kz = (v) => (v == null || isNaN(v) ? '—' : nf2.format(v) + ' Kz');
// Variantes mantidas por compatibilidade das colunas: hoje todas mostram Kz com 2 casas decimais
export const kz2 = kz;
export const mkz = kz;
export const n0 = (v) => (v == null || isNaN(v) ? '—' : nf.format(v));
export const n1 = (v) => (v == null || isNaN(v) ? '—' : nf1.format(v));
export const pct = (v) => (v == null || isNaN(v) || !isFinite(v) ? '—' : nf1.format(v * 100) + '%');
const MES = {
  pt: ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'],
  en: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
};
const SEM = { pt: ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'], en: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] };
// Datas sempre no formato dia-mês-ano (dd-mm-aaaa)
export const dl = (s) => {
  if (!s) return '—';
  const [y, m, d] = String(s).slice(0, 10).split('-');
  return `${d}-${m}-${y}`;
};
export const ds = (s) => {
  const [, m, d] = s.split('-');
  return `${SEM[LANG][new Date(s + 'T12:00:00Z').getUTCDay()]} ${+d} ${MES[LANG][m - 1]}`;
};
export const diaCurto = (d) => d.slice(8, 10) + '/' + d.slice(5, 7);
