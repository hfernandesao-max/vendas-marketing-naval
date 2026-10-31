// Terreno: sincronização dos smartphones RTM com o Pinguim BO. Uma só consulta (última sincronização de cada
// rota); a página mostra, por esta ordem: rota, data da última sincronização e distribuição das horas desde
// essa sincronização (barra com a cor do estado). Actualiza sozinha a cada minuto.
import { draw } from '../app.js';
import { promotorRota } from '../calculos.js';
import { aguardar, tabela, tiles } from '../componentes/blocos.js';
import { FV, filtroAtivo } from '../filtros.js';
import { dl, n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { R } from './registo.js';
import { atual } from '../perfil.js';
import { el } from '../util.js';

import { ESTADO_SYNC, SY, aoActualizar, haQuanto, lerSync } from '../smartphones.js';

// Dados partilhados com o indicador do menu lateral (lidos a cada minuto em smartphones.js)
const ESTADOS = {
  em_dia: [ESTADO_SYNC.em_dia[0], 'p-ok', 0],
  atencao: [ESTADO_SYNC.atencao[0], 'p-warn', 1],
  critico: [ESTADO_SYNC.critico[0], 'p-bad', 2],
  sem_registo: [ESTADO_SYNC.sem_registo[0], 'p-bad', 3],
};
const redesenhar = () => {
  if (atual !== 'sincronizacao' || document.querySelector('dialog[open]')) return;
  const y = window.scrollY;
  draw();
  window.scrollTo(0, y);
};
aoActualizar(redesenhar);
export { haQuanto };

R.sincronizacao = (pg) => {
  const SYNC = SY.dados;
  if (!SYNC) {
    pg.append(el('div', 'glass empty', t('A carregar…')));
    lerSync();
    return;
  }
  if (Date.now() - SY.lidoEm > 60000) lerSync();
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
      t('Estado pelo tempo desde a última sincronização terminada de cada telemóvel (hora do servidor: {h}). Actualiza sozinho a cada minuto.', {
        h: dl(agora) + ' ' + agora.slice(11, 16),
      }),
    ),
  );
  // Distribuição das horas: barra proporcional às horas desde a última sincronização, na escala da rota mais
  // atrasada (no mínimo o limite crítico), com as marcas dos limites de atenção e crítico
  const escala = Math.max(lim.critico_horas, ...rotas.map((r) => r.horas || 0));
  const pct = (h) => Math.max(0, Math.min(100, (h / escala) * 100));
  const barra = (r) => {
    const w = el('div', 'sy-dist');
    w.title = t('{h} h desde a última sincronização', { h: r.horas == null ? '—' : String(r.horas).replace('.', ',') });
    const pista = el('div', 'sy-dist-pista');
    const b = el('div', 'sy-dist-b ' + r.estado);
    b.style.width = (r.horas == null ? 100 : Math.max(pct(r.horas), 1.5)) + '%';
    [lim.alerta_horas, lim.critico_horas].forEach((h) => {
      const m = el('i', 'sy-dist-m');
      m.style.left = pct(h) + '%';
      pista.append(m);
    });
    pista.prepend(b);
    w.append(pista, el('span', 'sy-dist-v', r.minutos == null ? t('sem registo') : haQuanto(r.minutos)));
    return w;
  };
  const secs = el('div', 'secs');
  secs.append(
    tabela({
      id: 'sy-estado',
      titulo: t('Última sincronização por rota'),
      nota: t('Da sincronização mais recente para a mais antiga. Barra: horas desde a última sincronização; marcas nos limites de {a} h e {b} h.', {
        a: lim.alerta_horas,
        b: lim.critico_horas,
      }),
      sort: 'ultima',
      dir: -1,
      total: false,
      rows: rotas.map((r) => ({
        ...r,
        chave: r.rota,
        rota_t: r.descricao || promotorRota(r.rota, r.promotor),
        promotor_t: r.promotor || '',
        estado_t: t(ESTADOS[r.estado][0]),
      })),
      cols: [
        {
          key: 'rota_t',
          label: t('Rota'),
          p: 0,
          render: (v, r) => {
            const c = el('span', 'sy-rota');
            c.append(el('b', null, v));
            if (r.promotor_t) c.append(el('small', null, r.promotor_t));
            return c;
          },
        },
        {
          key: 'ultima',
          label: t('Data última sincronização'),
          p: 0,
          render: (v) => document.createTextNode(v ? dl(v) + ' ' + String(v).slice(11, 16) : '—'),
          xl: (r) => r.ultima || '',
        },
        {
          key: 'minutos',
          label: t('Distribuição das horas'),
          type: 'n',
          p: 0,
          render: (v, r) => barra(r),
          xl: (r) => (r.horas == null ? '' : r.horas),
        },
      ],
    }),
  );
  pg.append(secs);
};
