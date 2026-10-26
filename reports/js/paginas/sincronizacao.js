// Terreno: sincronização dos smartphones RTM com o Pinguim BO («último contacto» de cada dispositivo),
// com estado por limites de tempo, histórico de 14 dias e actualização automática a cada minuto.
import { draw } from '../app.js';
import { promotorRota } from '../calculos.js';
import { aguardar, pill, secBox, tabela, tiles } from '../componentes/blocos.js';
import { legenda } from '../componentes/graficos.js';
import { FV, filtroAtivo } from '../filtros.js';
import { diaCurto, dl, ds, n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { R } from './registo.js';
import { atual } from '../perfil.js';
import { addD } from '../periodo.js';
import { el } from '../util.js';

let SYNC = null,
  lidoEm = 0,
  aLer = false;
const ESTADOS = {
  em_dia: ['Em dia', 'p-ok', 0],
  atencao: ['Atenção', 'p-warn', 1],
  critico: ['Crítico', 'p-bad', 2],
  sem_registo: ['Sem registo', 'p-bad', 3],
};
function ler() {
  if (aLer) return Promise.resolve();
  aLer = true;
  return fetch('data/sincronizacao.php', { cache: 'no-store' })
    .then((r) => r.json())
    .catch(() => ({ disponivel: false, erro: t('A sincronização precisa do servidor da plataforma (PHP) e da base de dados do Pinguim BO.') }))
    .then((d) => {
      SYNC = d;
      lidoEm = Date.now();
    })
    .finally(() => {
      aLer = false;
    });
}
const redesenhar = () => {
  if (atual !== 'sincronizacao' || document.querySelector('dialog[open]')) return;
  const y = window.scrollY;
  draw();
  window.scrollTo(0, y);
};
// Estado em tempo quase real: com a página aberta e visível, volta a ler a cada minuto
setInterval(() => {
  if (atual === 'sincronizacao' && !document.hidden) ler().then(redesenhar);
}, 60000);

/** «há 3 h 12 min», «há 2 dias» */
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

R.sincronizacao = (pg) => {
  if (!SYNC) {
    pg.append(el('div', 'glass empty', t('A carregar…')));
    ler().then(redesenhar);
    return;
  }
  if (Date.now() - lidoEm > 60000) ler().then(redesenhar);
  if (!SYNC.disponivel) {
    aguardar(pg, t(SYNC.erro || 'Sem dados de sincronização.'), [
      t('Ligação à base de dados do Pinguim BO (SQL Server) no .env do servidor'),
      t('Tabela SYNC_PHSE_HISTORY (fim de cada sincronização por loja/rota)'),
    ]);
    return;
  }
  const lim = SYNC.limites,
    agora = SYNC.agora;
  let rotas = SYNC.rotas;
  if (filtroAtivo('sincronizacao', 'rota')) rotas = rotas.filter((r) => FV.rota.includes(String(r.rota)));
  const conta = (e) => rotas.filter((r) => r.estado === e).length;
  const maisRecente = rotas.filter((r) => r.minutos != null).sort((a, b) => a.minutos - b.minutos)[0];
  pg.append(
    tiles([
      { l: t('Em dia'), v: n0(conta('em_dia')) + ' / ' + n0(rotas.length), s: t('última sincronização há menos de {h} h', { h: lim.alerta_horas }) },
      {
        l: t('Atenção'),
        v: n0(conta('atencao')),
        s: t('entre {a} e {b} h', { a: lim.alerta_horas, b: lim.critico_horas }),
        tone: conta('atencao') ? 'warn' : null,
      },
      {
        l: t('Crítico'),
        v: n0(conta('critico') + conta('sem_registo')),
        s: t('mais de {h} h ou sem registo', { h: lim.critico_horas }),
        tone: conta('critico') + conta('sem_registo') ? 'bad' : null,
      },
      {
        l: t('Última sincronização da rede'),
        v: maisRecente ? haQuanto(maisRecente.minutos) : '—',
        s: maisRecente ? promotorRota(maisRecente.rota, maisRecente.promotor) : '',
      },
    ]),
  );
  pg.append(
    el(
      'p',
      'note',
      t('Estado pelo tempo desde a última sincronização terminada de cada smartphone (hora do servidor: {h}). Actualiza sozinho a cada minuto.', {
        h: dl(agora) + ' ' + agora.slice(11, 16),
      }),
    ),
  );
  const secs = el('div', 'secs');
  secs.append(
    tabela({
      id: 'sy-estado',
      titulo: t('Estado por smartphone'),
      nota: t('Do mais grave para o mais recente. Sem registo: rota do portal sem nenhuma sincronização na base de dados.'),
      sort: 'gravidade',
      dir: -1,
      total: false,
      rows: rotas.map((r) => ({
        ...r,
        chave: r.rota,
        promotor_t: promotorRota(r.rota, r.promotor),
        gravidade: ESTADOS[r.estado][2] * 1e7 + (r.minutos == null ? 9e6 : r.minutos),
        estado_t: t(ESTADOS[r.estado][0]),
        ha: haQuanto(r.minutos),
      })),
      cols: [
        { key: 'promotor_t', label: t('Rota / promotor'), p: 0 },
        { key: 'gravidade', label: t('Estado'), p: 0, render: (v, r) => pill(r.estado_t, ESTADOS[r.estado][1]), xl: (r) => r.estado_t },
        {
          key: 'ultima',
          label: t('Última sincronização'),
          render: (v) => document.createTextNode(v ? dl(v) + ' ' + String(v).slice(11, 16) : '—'),
          xl: (r) => r.ultima || '',
        },
        { key: 'minutos', label: t('Há quanto tempo'), type: 'n', render: (v, r) => document.createTextNode(r.ha), xl: (r) => r.ha },
        { key: 'hoje', label: t('Sincronizações hoje'), type: 'n', sum: true },
        { key: 'sete_dias', label: t('Últimos 7 dias'), type: 'n', sum: true },
      ],
    }),
  );
  // Histórico: sincronizações por dia (14 dias), escala de uma só cor (mais escuro = mais sincronizações)
  const dias = [];
  for (let i = 13; i >= 0; i--) dias.push(addD(agora.slice(0, 10), -i));
  const mat = el('div', 'hm-wrap'),
    tb = el('table', 'hm'),
    hr = el('tr'),
    th = el('thead'),
    tbd = el('tbody');
  hr.append(el('th', 'hm-r', t('Rota')));
  dias.forEach((d) => {
    const h = el('th', null, diaCurto(d));
    h.title = ds(d);
    hr.append(h);
  });
  th.append(hr);
  tb.append(th);
  const nivel = (n) => (!n ? 'hs-0' : n <= 2 ? 'hs-1' : n <= 5 ? 'hs-2' : 'hs-3');
  [...rotas]
    .sort((a, b) => String(a.rota).localeCompare(String(b.rota)))
    .forEach((r) => {
      const tr = el('tr');
      tr.append(el('td', 'hm-r', promotorRota(r.rota, r.promotor)));
      dias.forEach((d) => {
        const n = (r.dias || {})[d] || 0,
          td = el('td'),
          c = el('span', 'hm-c ' + nivel(n), n ? String(n) : '');
        c.title = `${promotorRota(r.rota, r.promotor)} · ${dl(d)}: ${t('{n} sincronizações', { n })}`;
        td.append(c);
        tr.append(td);
      });
      tbd.append(tr);
    });
  tb.append(tbd);
  mat.append(tb);
  requestAnimationFrame(() => {
    mat.scrollLeft = mat.scrollWidth;
  });
  const secH = secBox(t('Sincronizações por dia'), t('Últimos 14 dias. Dias sem nenhuma sincronização ficam vazios.'), mat);
  secH.querySelector('.sec-h').append(
    legenda([
      ['hs-0', t('Nenhuma')],
      ['hs-1', '1–2'],
      ['hs-2', '3–5'],
      ['hs-3', '6+'],
    ]),
  );
  secs.append(secH);
  pg.append(secs);
};
