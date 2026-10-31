// Ligação às fontes de dados externas (Pinguim BO: vendas e stock) e indicador de estado.
import { marcarActualizado } from './actualizacao.js';
import { dl } from './formatos.js';
import { t } from './i18n/i18n.js';
import { definirDiasSap, definirDiasTodos, diasSap, diasTodos, hoje } from './periodo.js';
import { $, DB, el, toast } from './util.js';
import { apiDescarregar, apiFetch } from './api.js';
import { SESSAO } from './perfil.js';

// Pinguim BO: resultado da última consulta de vendas e verificação curta (ping) a cada 5 minutos.
// Stock de artigos (Pinguim BO): data da posição carregada.
// Os cadastros e o plano de cargas não contam como fontes externas: o plano é produzido na plataforma.
export const LIGACOES = {
  pinguim: { estado: '?', txt: 'A verificar…' },
  stock: { estado: '?', txt: '' },
};
const NOMES_FONTE = { pinguim: 'Pinguim BO (vendas)', stock: 'Stock de artigos (BO)' };
const desde = (ms) => {
  const m = Math.round((Date.now() - ms) / 60000);
  return m < 1 ? t('agora') : m < 60 ? t('há {n} min', { n: m }) : t('há {n} h', { n: Math.round(m / 60) });
};
const diasAtras = (d) => Math.round((new Date(hoje() + 'T12:00:00Z') - new Date(String(d).slice(0, 10) + 'T12:00:00Z')) / 864e5);
function marcarPinguim(ok, erro) {
  LIGACOES.pinguim = ok
    ? { estado: 'ok', txt: t('consulta directa'), hora: Date.now() }
    : { estado: 'erro', txt: erro ? t(erro) : t('sem ligação'), hora: Date.now() };
  mostrarFontes();
}
export function avaliarFontes() {
  if (DB.stock && DB.stock.dados_ate) {
    const ns = diasAtras(DB.stock.dados_ate);
    LIGACOES.stock = { estado: ns <= 2 ? 'ok' : 'aviso', txt: t('dados até {d}', { d: dl(DB.stock.dados_ate) }) };
  } else LIGACOES.stock = { estado: 'erro', txt: t('sem dados de stock') };
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
  // Super administrador: diagnóstico do servidor (PHP, .env, ligações) pela API, com o token
  if (SESSAO && (SESSAO.permissoes || {})['admin-config']) {
    const b = el('button', 'linkbtn', t('Diagnóstico do servidor'));
    b.type = 'button';
    b.addEventListener('click', () => apiDescarregar('vendas/diagnostico', 'diagnostico-servidor.json').catch((e) => toast(e.message, 6000)));
    det.append(b);
  }
  $('#fontes').title = ks.map((k) => t(NOMES_FONTE[k]) + ': ' + LIGACOES[k].txt).join('\n');
}
function pingPinguim() {
  return apiFetch('vendas/estado')
    .then((r) => (r.status === 401 ? null : r.json()))
    .then((d) => {
      if (d) marcarPinguim(!!d.ok, d.erro);
    })
    .catch(() => marcarPinguim(false, t('sem ligação ao servidor')));
}
// Detalhe das fontes: abre ao clicar e fecha sozinho ao fim de 8 s (ou ao clicar fora, com Esc ou ao
// clicar de novo); com o rato ou o foco em cima, espera que saia para voltar a contar.
export const FECHO_FONTES = 8000;
let fechoFontes = null;
function contarFecho() {
  clearTimeout(fechoFontes);
  fechoFontes = setTimeout(() => detalheFontes(false), FECHO_FONTES);
}
export function detalheFontes(abrir) {
  const d = $('#fontes-det');
  d.hidden = !abrir;
  $('#fontes').setAttribute('aria-expanded', String(abrir));
  clearTimeout(fechoFontes);
  if (abrir) contarFecho();
}
$('#fontes').addEventListener('click', () => detalheFontes($('#fontes-det').hidden));
$('#fontes-mini').addEventListener('click', (ev) => {
  ev.stopPropagation();
  $('#app').classList.add('open');
  detalheFontes(true);
});
['#fontes', '#fontes-det'].forEach((q) => {
  $(q).addEventListener('mouseenter', () => clearTimeout(fechoFontes));
  $(q).addEventListener('mouseleave', () => {
    if (!$('#fontes-det').hidden) contarFecho();
  });
});
document.addEventListener('mousedown', (ev) => {
  if (!$('#fontes-det').hidden && !ev.target.closest('#fontes, #fontes-det, #fontes-mini')) detalheFontes(false);
});
document.addEventListener('keydown', (ev) => {
  if (ev.key === 'Escape' && !$('#fontes-det').hidden) {
    detalheFontes(false);
    $('#fontes').focus();
  }
});
setInterval(pingPinguim, 5 * 60000);
setInterval(mostrarFontes, 30000);

// «Actualizar»: os pedidos dos próximos segundos pedem dados frescos ao servidor (sem a cache de 2 min)
let frescoAte = 0;
export const pedirFresco = () => {
  frescoAte = Date.now() + 5000;
};
const frescoPedido = () => Date.now() < frescoAte;

// Stock de artigos na data indicada e armazém/rota: API v1/stock (cálculo no SQL Server do Pinguim BO)
export function fetchStock(dataOuDe, armazemOuAte = 'todos') {
  let data = dataOuDe || '';
  let arm = armazemOuAte || 'todos';
  if (typeof arm === 'string' && (/^\d{8}$/.test(arm) || /^\d{4}-\d{2}-\d{2}$/.test(arm))) {
    data = arm;
    arm = 'todos';
  }
  const dStr = data.replace(/-/g, '');
  const armStr = encodeURIComponent(arm);
  // O stock vem só do serviço (base de dados do Pinguim BO). Se o cálculo falhar, mostra-se o motivo e não
  // dados antigos de um ficheiro, que pareceriam ser da data pedida.
  return apiFetch('stock?data=' + dStr + '&armazem=' + armStr, { cache: 'no-store' })
    .catch(() => {
      throw new Error(t('Sem ligação ao servidor do portal.'));
    })
    .then((r) =>
      r
        .json()
        .catch(() => null)
        .then((d) => {
          if (!r.ok || !d || !(Array.isArray(d.carrinhas) || Array.isArray(d.armazens))) {
            const erro = d && d.erro ? t(d.erro) : t('Não foi possível obter dados da base de dados.');
            throw new Error(d && d.detalhe ? erro + ' (' + d.detalhe + ')' : erro);
          }
          return d;
        }),
    )
    .then((d) => {
      if (d && (Array.isArray(d.carrinhas) || Array.isArray(d.armazens))) DB.stock = d;
      if (DB.stock && Array.isArray(DB.stock.datas_disponiveis)) definirDiasTodos([...new Set([...diasTodos, ...DB.stock.datas_disponiveis])].sort());
      avaliarFontes();
      return DB.stock;
    })
    .catch((e) => {
      DB.stock = null; // não deixar à vista o stock de uma consulta anterior
      avaliarFontes();
      throw e;
    });
}

export function fetchVendas(de, ate) {
  return fetchVendasPinguim(de, ate);
}
function fetchVendasPinguim(de, ate) {
  document.body.classList.add('a-carregar');
  const sd = de.replace(/-/g, ''),
    ed = ate.replace(/-/g, '');
  return apiFetch('vendas?start_date=' + sd + '&end_date=' + ed + '&include_items=1' + (frescoPedido() ? '&fresco=1' : ''))
    .then((r) =>
      r
        .json()
        .catch(() => ({ erro: t('Resposta inválida do servidor (HTTP {s}).', { s: r.status }) }))
        .then((d) => {
          if (r.status === 401) {
            try {
              sessionStorage.setItem('vm-voltar', '1'); // depois de entrar de novo, volta à página onde estava
            } catch (e) {
              /* sem armazenamento */
            }
            location.href = '../geo/login.php?redirect=' + encodeURIComponent('../reports/');
            return [];
          }
          if (!r.ok || (d && d.erro)) {
            marcarPinguim(false, d && d.erro);
            toast((d && d.erro ? t(d.erro) : t('Erro do servidor (HTTP {s}).', { s: r.status })) + (d && d.detalhe ? ' ' + t(d.detalhe) : ''), 12000);
            return [];
          }
          marcarPinguim(true);
          marcarActualizado();
          return d;
        }),
    )
    .catch(() => {
      marcarPinguim(false, t('sem ligação ao servidor'));
      toast(t('Sem ligação ao servidor dos relatórios.'), 8000);
      return [];
    })
    .then((d) => {
      DB.vendas = Array.isArray(d) ? d : Array.isArray(d.vendas) ? d.vendas : [];
      DB.artigos = Array.isArray(d.artigos) ? d.artigos : [];
      definirDiasSap([...new Set(DB.vendas.map((x) => x.data))].sort());
      definirDiasTodos([...new Set([...diasSap, ...DB.cargas.map((x) => x.data), ...((DB.stock && DB.stock.datas_disponiveis) || [])])].sort());
      document.body.classList.remove('a-carregar');
    });
}
