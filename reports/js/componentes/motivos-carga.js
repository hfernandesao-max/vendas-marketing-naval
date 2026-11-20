// Motivo de cada diferença entre o pedido e o carregado na conferência da carga (Stock por carrinha e Cargas (PO)).
import { entrada } from './janelas.js';
import { t } from '../i18n/i18n.js';
import { el } from '../util.js';

/** Motivos das diferenças entre o pedido e o carregado (a mesma lista fechada do servidor, NAVAL_REQ_MOTIVOS). */
export const MOTIVOS_CARGA = {
  falta_armazem: 'Falta no armazém',
  avaria: 'Avaria ou embalagem danificada',
  pedido_vendedor: 'A pedido do vendedor',
  arredondamento: 'Arredondamento à embalagem',
  outro: 'Outro',
};
/** Escolha do motivo de uma diferença (lista + texto para «Outro»); devolve {w, valor()}. */
export function campoMotivo(rotulo) {
  const w = el('div', 'cc-motivo');
  const sel = el('select', 'inp');
  sel.setAttribute('aria-label', rotulo);
  sel.append(new Option(t('Motivo da diferença…'), ''));
  Object.entries(MOTIVOS_CARGA).forEach(([k, v]) => sel.append(new Option(t(v), k)));
  const txt = entrada('text', '', { maxLength: 200, placeholder: t('Descreva o motivo') });
  txt.hidden = true;
  sel.addEventListener('change', () => (txt.hidden = sel.value !== 'outro'));
  w.append(sel, txt);
  return { w, sel, valor: () => (sel.value ? { codigo: sel.value, texto: txt.value.trim() } : null) };
}
