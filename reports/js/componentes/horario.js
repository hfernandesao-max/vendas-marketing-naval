// Calendário de trabalho das vendas (do servidor, calendario.php): horário do dia e prazos do fecho no ecrã.
import { t } from '../i18n/i18n.js';
import { el } from '../util.js';

/**
 * Calendário de trabalho (do servidor): texto do horário do dia e prazos do fecho. h = {tipo, motivo, feriado,
 * vendas, fecho, carga, agora}. foraDoPrazo(h, 'fecho' | 'carga'): o prazo desse dia já passou à hora do servidor.
 */
export function horarioTxt(h, tr = t) {
  if (!h) return '';
  if (h.tipo === 'folga')
    return h.feriado ? tr('Folga: feriado ({f}).', { f: h.feriado }) : h.motivo === 'domingo' ? tr('Folga: domingo.') : tr('Folga da empresa.');
  return tr('Horário do dia: vendas {v} · fecho de contas com a tesouraria, contagem das carrinhas e requisição ao armazém {f} · carregamento {c}.', {
    v: h.vendas.join('–'),
    f: h.fecho.join('–'),
    c: h.carga.join('–'),
  });
}
export function foraDoPrazo(h, qual) {
  if (!h || !h.agora || !h[qual]) return false;
  const hoje = h.agora.slice(0, 10);
  return h.dia < hoje || (h.dia === hoje && h.agora.slice(11, 16) >= h[qual][1]);
}

/**
 * O dia de trabalho numa linha: Vendas · Contagem e PO · Carga, com a fase em curso destacada e as que já passaram a
 * esbatido (à hora do servidor). Num dia de descanso, o texto do horário.
 */
export function linhaHorario(h) {
  if (!h) return el('span');
  if (h.tipo === 'folga') return el('p', 'note cc-horario', horarioTxt(h));
  const hoje = (h.agora || '').slice(0, 10);
  const hm = (h.agora || '').slice(11, 16);
  const d = el('div', 'hor-linha');
  d.setAttribute('role', 'list');
  d.setAttribute('aria-label', t('Horário do dia'));
  [
    [t('Vendas'), h.vendas],
    [t('Contagem e PO'), h.fecho],
    [t('Carga'), h.carga],
  ].forEach(([nome, j]) => {
    const passou = h.dia < hoje || (h.dia === hoje && hm >= j[1]);
    const agora = h.dia === hoje && hm >= j[0] && hm < j[1];
    const s = el('span', 'hor-fase' + (agora ? ' agora' : passou ? ' passou' : ''));
    s.setAttribute('role', 'listitem');
    s.append(el('b', null, nome), el('span', null, j.join('–')));
    if (agora) s.append(el('i', null, t('agora')));
    d.append(s);
  });
  return d;
}
