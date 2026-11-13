// Selo «Novo» durante 24 horas no que mudou numa actualização (padrão dos produtos de referência: GitHub, Atlassian,
// Microsoft 365, Linear, Salesforce). A origem é o registo de alterações (CHANGELOG.md: «<!-- paginas: a, b -->» em
// cada secção) e a hora em que a actualização foi instalada no servidor (novidades.php).
//   - fase 1 (primeiras 6 h): selo cheio azul Naval, com um brilho suave;
//   - fase 2 (6 a 12 h): contorno azul;
//   - fase 3 (12 a 24 h): discreto; às 24 h desaparece;
//   - depois de a pessoa abrir a página, passa logo a discreto (já viu).
// Sempre com texto «Novo» (não só cor, WCAG 1.4.1) e a descrição da novidade no título; sem animação para quem pede
// movimento reduzido. A marca de «visto» é só deste navegador (conveniência; não é registo).
import { t } from '../i18n/i18n.js';
import { SESSAO } from '../perfil.js';
import { el } from '../util.js';

export const HORAS_NOVO = 24;
const CHAVE = 'vm-novo-vistos';
const lerVistos = () => {
  try {
    return JSON.parse(localStorage.getItem(CHAVE) || '{}') || {};
  } catch (e) {
    return {};
  }
};

/** Novidade de uma página nas últimas 24 h: {titulo, desde, horas, fase, visto} ou null. */
export function novoDe(id, agora = Date.now()) {
  const n = ((SESSAO && SESSAO.novo) || []).find((x) => x.pagina === id);
  if (!n || !n.desde_ts) return null;
  const horas = (agora - n.desde_ts * 1000) / 36e5;
  if (horas < 0 || horas >= HORAS_NOVO) return null;
  return { ...n, horas, fase: horas < 6 ? 1 : horas < 12 ? 2 : 3, visto: lerVistos()[id] === n.desde_ts };
}

const haQuanto = (h) => (h < 1 ? t('há menos de 1 hora') : t('há {n} h', { n: Math.floor(h) }));

/** Selo «Novo» (ou null) para o menu e para os títulos. */
export function seloNovo(id) {
  const n = novoDe(id);
  if (!n) return null;
  const s = el('span', 'novo-selo f' + (n.visto ? 3 : n.fase) + (n.visto ? ' visto' : ''), t('Novo'));
  const txt = t('Novidade: {t} · {h}', { t: t(n.titulo), h: haQuanto(n.horas) });
  s.title = txt;
  s.setAttribute('aria-label', txt);
  return s;
}

/** Faixa no topo da página com o que mudou (e a página fica vista). */
export function faixaNovo(id) {
  const n = novoDe(id);
  if (!n) return null;
  const f = el('div', 'novo-faixa f' + (n.visto ? 3 : n.fase));
  f.setAttribute('role', 'note');
  f.append(
    el('span', 'novo-selo f' + (n.visto ? 3 : n.fase), t('Novo')),
    el('span', null, t(n.titulo) + ' · ' + t('actualizado {h}', { h: haQuanto(n.horas) })),
  );
  try {
    const v = lerVistos();
    v[id] = n.desde_ts;
    localStorage.setItem(CHAVE, JSON.stringify(v));
  } catch (e) {
    /* sem armazenamento: o selo continua até às 24 h */
  }
  return f;
}
