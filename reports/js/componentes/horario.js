// Calendário de trabalho das vendas (do servidor, calendario.php): horário do dia e prazos do fecho no ecrã.
import { t } from '../i18n/i18n.js';

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
