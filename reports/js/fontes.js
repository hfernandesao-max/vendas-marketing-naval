// Ligação às fontes de dados externas (Pinguim BO) e indicador de estado.
import { marcarActualizado } from './actualizacao.js';
import { t } from './i18n/i18n.js';
import { definirDiasSap, definirDiasTodos, diasSap } from './periodo.js';
import { $, DB, el, toast } from './util.js';

// Pinguim BO: resultado da última consulta de vendas e verificação curta (ping) a cada 5 minutos.
// Os cadastros e o plano de cargas não contam como fontes externas: o plano é produzido na plataforma.
export const LIGACOES = { pinguim: { estado: '?', txt: 'A verificar…' } };
const NOMES_FONTE = { pinguim: 'Pinguim BO (vendas)' };
const desde = (ms) => {
  const m = Math.round((Date.now() - ms) / 60000);
  return m < 1 ? t('agora') : m < 60 ? t('há {n} min', { n: m }) : t('há {n} h', { n: Math.round(m / 60) });
};
function marcarPinguim(ok, erro) {
  LIGACOES.pinguim = ok ? { estado: 'ok', txt: t('consulta directa'), hora: Date.now() } : { estado: 'erro', txt: erro || t('sem ligação'), hora: Date.now() };
  mostrarFontes();
}
export function avaliarFontes() {
  mostrarFontes();
}
export function mostrarFontes() {
  const ks = Object.keys(LIGACOES),
    est = ks.map((k) => LIGACOES[k].estado);
  const geral =
    est.includes('erro') && LIGACOES.pinguim.estado === 'erro'
      ? 'erro'
      : est.some((e) => e === 'erro' || e === 'aviso')
        ? 'aviso'
        : est.includes('?')
          ? ''
          : 'ok';
  const lig = est.filter((e) => e === 'ok' || e === 'aviso').length;
  ['#fontes-dot', '#fontes-mini-dot'].forEach((q) => {
    $(q).className = 'dot ' + geral;
  });
  $('#fontes-l1').textContent = t('{a} de {b} fontes ligadas', { a: lig, b: ks.length });
  $('#fontes-l2').textContent = LIGACOES.pinguim.hora
    ? (LIGACOES.pinguim.estado === 'ok' ? t('consulta directa') : t('Pinguim BO sem ligação')) + ' · ' + desde(LIGACOES.pinguim.hora)
    : t('A verificar…');
  $('#fontes-mini-t').textContent = geral === 'erro' ? t('Sem ligação') : geral === 'aviso' ? t('Ligação parcial') : geral === 'ok' ? t('Ligado') : '…';
  const det = $('#fontes-det');
  det.replaceChildren();
  ks.forEach((k) => {
    const d = el('div');
    d.append(
      el('i', 'dot ' + (LIGACOES[k].estado === '?' ? '' : LIGACOES[k].estado)),
      el('b', null, t(NOMES_FONTE[k])),
      el('span', null, LIGACOES[k].txt + (k === 'pinguim' && LIGACOES[k].hora ? ' · ' + desde(LIGACOES[k].hora) : '')),
    );
    det.append(d);
  });
  $('#fontes').title = ks.map((k) => t(NOMES_FONTE[k]) + ': ' + LIGACOES[k].txt).join('\n');
}
function pingPinguim() {
  return fetch('data/api.php?ping=1', { cache: 'no-store' })
    .then((r) => (r.status === 401 ? null : r.json()))
    .then((d) => {
      if (d) marcarPinguim(!!d.ok, d.erro);
    })
    .catch(() => marcarPinguim(false, t('sem ligação ao servidor')));
}
$('#fontes').addEventListener('click', () => {
  const d = $('#fontes-det');
  d.hidden = !d.hidden;
  $('#fontes').setAttribute('aria-expanded', String(!d.hidden));
});
$('#fontes-mini').addEventListener('click', (ev) => {
  ev.stopPropagation();
  $('#app').classList.add('open');
  $('#fontes-det').hidden = false;
  $('#fontes').setAttribute('aria-expanded', 'true');
});
setInterval(pingPinguim, 5 * 60000);
setInterval(mostrarFontes, 30000);

export function fetchVendas(de, ate) {
  document.body.classList.add('a-carregar');
  const sd = de.replace(/-/g, ''),
    ed = ate.replace(/-/g, '');
  return fetch('data/api.php?start_date=' + sd + '&end_date=' + ed + '&include_items=1', { cache: 'no-store' })
    .then((r) =>
      r
        .json()
        .catch(() => ({ erro: 'Resposta inválida do servidor (HTTP ' + r.status + ').' }))
        .then((d) => {
          if (r.status === 401) {
            location.href = '../geo/login.php?redirect=' + encodeURIComponent('../reports/');
            return [];
          }
          if (!r.ok || (d && d.erro)) {
            marcarPinguim(false, d && d.erro);
            toast((d && d.erro ? d.erro : 'Erro do servidor (HTTP ' + r.status + ').') + (d && d.detalhe ? ' ' + d.detalhe : ''), 12000);
            return [];
          }
          marcarPinguim(true);
          marcarActualizado();
          return d;
        }),
    )
    .catch(() => {
      marcarPinguim(false, t('sem ligação ao servidor'));
      toast('Sem ligação ao servidor dos relatórios.', 8000);
      return [];
    })
    .then((d) => {
      DB.vendas = Array.isArray(d) ? d : Array.isArray(d.vendas) ? d.vendas : [];
      DB.artigos = Array.isArray(d.artigos) ? d.artigos : [];
      definirDiasSap([...new Set(DB.vendas.map((x) => x.data))].sort());
      definirDiasTodos([...new Set([...diasSap, ...DB.cargas.map((x) => x.data)])].sort());
      document.body.classList.remove('a-carregar');
    });
}
