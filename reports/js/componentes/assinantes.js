// Rastreabilidade das assinaturas: mostra quem assinou cada passo com o nome completo e o utilizador do portal
// (ex.: «Manuel Queia (manuel.queia)»), a partir dos nomes que o servidor junta às respostas.
import { NOMES } from '../api.js';
import { dl } from '../formatos.js';
import { t } from '../i18n/i18n.js';

/** «Nome completo (utilizador)»; o fecho automático aparece como «automático»; sem nome conhecido, o utilizador. */
export function quem(login, tr = t) {
  if (!login) return '';
  if (login === 'automatico') return tr('automático');
  const n = NOMES[login];
  return n && n !== login ? n + ' (' + login + ')' : String(login);
}

/** Data e hora (dd-mm-aaaa hh:mm) de um carimbo «AAAA-MM-DD HH:MM:SS». */
export const quando = (s) => (s ? dl(String(s).slice(0, 10)) + ' ' + String(s).slice(11, 16) : '');
