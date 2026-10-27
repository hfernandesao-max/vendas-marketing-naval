// Comunicação dos smartphones RTM com o servidor: estado partilhado (página «Sincronização dos
// smartphones» e indicador discreto no menu lateral). Lido a cada minuto, só com o separador visível
// e só para quem tem acesso à página; o indicador mostra um ponto por smartphone, com a cor do estado
// (em dia, atenção, crítico, sem registo), o resumo em texto e o detalhe na dica de cada ponto.
import { promotorRota } from './calculos.js';
import { dl } from './formatos.js';
import { t } from './i18n/i18n.js';
import { podeVer } from './perfil.js';
import { $, el } from './util.js';

export const SY = { dados: null, lidoEm: 0, aLer: null };
const ouvintes = [];
/** Avisa quando chegam dados novos (a página de sincronização redesenha-se). */
export const aoActualizar = (f) => ouvintes.push(f);

export const ESTADO_SYNC = {
  em_dia: ['Em dia', 0],
  atencao: ['Atenção', 1],
  critico: ['Crítico', 2],
  sem_registo: ['Sem registo', 3],
};

/** «agora», «há 12 min», «há 3 h 12 min», «há 2 dias». */
export function haQuanto(min) {
  if (min == null) return '—';
  if (min < 1) return t('agora');
  if (min < 60) return t('há {n} min', { n: min });
  if (min < 48 * 60) {
    const h = Math.floor(min / 60),
      m = min % 60;
    return m ? t('há {h} h {m} min', { h, m }) : t('há {n} h', { n: h });
  }
  return t('há {n} dias', { n: Math.floor(min / 1440) });
}

export function lerSync() {
  if (SY.aLer) return SY.aLer;
  SY.aLer = fetch('data/sincronizacao.php', { cache: 'no-store' })
    .then((r) => (r.status === 403 || r.status === 401 ? { disponivel: false, semAcesso: true } : r.json()))
    .catch(() => ({ disponivel: false, erro: t('A sincronização precisa do servidor da plataforma (PHP) e da base de dados do Pinguim BO.') }))
    .then((d) => {
      SY.dados = d;
      SY.lidoEm = Date.now();
      mostrarIndicador();
      ouvintes.forEach((f) => f());
    })
    .finally(() => {
      SY.aLer = null;
    });
  return SY.aLer;
}

/** Indicador no menu lateral: um ponto por smartphone, por ordem de rota. */
export function mostrarIndicador() {
  const box = $('#sync-mini');
  if (!box) return;
  const d = SY.dados;
  if (!podeVer('sincronizacao') || !d || !d.disponivel || !(d.rotas || []).length) {
    box.hidden = true;
    return;
  }
  const rotas = [...d.rotas].sort((a, b) => String(a.rota).localeCompare(String(b.rota)));
  const n = (e) => rotas.filter((r) => r.estado === e).length;
  const pontos = el('span', 'sync-pontos');
  rotas.forEach((r) => {
    const quando = r.ultima ? dl(r.ultima) + ' ' + String(r.ultima).slice(11, 16) + ' · ' + haQuanto(r.minutos) : t('nunca sincronizou');
    const p = el('i', 'sync-p ' + r.estado);
    p.title = `${promotorRota(r.rota, r.promotor)} — ${t(ESTADO_SYNC[r.estado][0])} · ${quando}`;
    pontos.append(p);
  });
  const emDia = n('em_dia'),
    problema = n('critico') + n('sem_registo');
  const resumo =
    emDia === rotas.length
      ? t('Smartphones: todos em dia')
      : t('Smartphones: {a} de {b} em dia', { a: emDia, b: rotas.length }) + (problema ? ' · ' + t('{n} sem comunicar', { n: problema }) : '');
  // Uma linha curta (o resumo completo vai no rótulo acessível e na dica) e os pontos por baixo
  const cab = el('span', 'sync-t');
  cab.append(
    el('span', null, t('Smartphones')),
    el('b', problema ? 'mau' : emDia === rotas.length ? 'bom' : null, t('{a}/{b} em dia', { a: emDia, b: rotas.length })),
  );
  box.replaceChildren(cab, pontos);
  box.setAttribute(
    'aria-label',
    resumo + '. ' + t('Em dia: até {a} h; atenção: até {b} h; crítico: mais de {b} h.', { a: d.limites.alerta_horas, b: d.limites.critico_horas }),
  );
  box.title = t(
    'Comunicação dos smartphones com o servidor (verde: até {a} h · âmbar: até {b} h · vermelho: mais de {b} h ou nunca). Clique para ver o detalhe.',
    {
      a: d.limites.alerta_horas,
      b: d.limites.critico_horas,
    },
  );
  box.hidden = false;
}

// A cada minuto, com o separador visível; ao voltar ao separador, logo
const tick = () => {
  if (!document.hidden && podeVer('sincronizacao') && Date.now() - SY.lidoEm >= 55000) lerSync();
};
setInterval(tick, 60000);
document.addEventListener('visibilitychange', tick);
export const iniciarSync = () => {
  if (podeVer('sincronizacao')) lerSync();
};
