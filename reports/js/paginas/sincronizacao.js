// Terreno: sincronização dos smartphones RTM com o Pinguim BO. Uma só consulta (última sincronização de cada
// rota); a página mostra, por esta ordem: rota, data da última sincronização e distribuição das horas desde
// essa sincronização (barra com a cor do estado). Actualiza sozinha a cada minuto.
// Por baixo, a abertura e o fecho do dia de cada rota no Pinguim BO nos últimos 7 dias (v1/pinguim-dia): quadro
// rota × dia e, primeiro, o que precisa de acção (dia por fechar, fechado tarde, aberto na véspera, vendas com a data
// de outro dia).
import { apiJson } from '../api.js';
import { botaoPorque } from '../componentes/ajuda.js';
import { draw } from '../app.js';
import { promotorRota } from '../calculos.js';
import { aguardar, pill, secBox, tabela, tiles } from '../componentes/blocos.js';
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

// Abertura e fecho do dia no Pinguim BO (lido a cada 5 minutos)
const PD = { dados: null, lidoEm: 0, aLer: false };
function lerDia() {
  if (PD.aLer) return;
  PD.aLer = true;
  apiJson('pinguim-dia').then((d) => {
    PD.aLer = false;
    PD.dados = d || { disponivel: false, erro: 'Não foi possível ler os dados.' };
    PD.lidoEm = Date.now();
    redesenhar();
  });
}
const FECHO = {
  a_tempo: ['Fechado', 'p-ok'],
  atrasado: ['Fechado tarde', 'p-warn'],
  por_fechar: ['Por fechar', 'p-bad'],
  aberto: ['Aberto', 'p-mut'],
};
const hm = (v) => (v ? String(v).slice(11, 16) : '');
const dh = (v) => (v ? dl(v) + ' ' + hm(v) : '—');
function oQue(x) {
  const m = [];
  if (x.fecho === 'por_fechar') m.push(t('Dia por fechar há {h} h', { h: x.atraso_horas }));
  if (x.fecho === 'atrasado') m.push(t('Fechado {h} h depois do limite', { h: x.atraso_horas }));
  if (x.abertura === 'antecipada') m.push(t('Aberto na véspera'));
  if (x.noutro_dia) m.push(t('{n} venda(s) emitida(s) noutro dia com esta data', { n: x.noutro_dia }));
  return m.join(' · ');
}
function seccaoDia(rotasVisiveis) {
  const w = el('div', 'secs pd-secs');
  const D = PD.dados;
  if (!D || Date.now() - PD.lidoEm > 300000) lerDia();
  if (!D) {
    w.append(el('div', 'glass empty', t('A carregar…')));
    return w;
  }
  if (!D.disponivel) {
    w.append(el('div', 'glass empty', t(D.erro || 'Não foi possível ler os dados.')));
    return w;
  }
  let linhas = D.linhas || [];
  if (rotasVisiveis) linhas = linhas.filter((x) => rotasVisiveis.has(String(x.rota)));
  const exc = linhas.filter((x) => oQue(x) !== '');
  const tituloExc = t('Abertura e fecho do dia: o que precisa de acção');
  const notaExc = t(
    'Do Pinguim BO (abertura e fecho do dia feitos no telemóvel, na tabela SESSION; o documento FCL só quando não há fecho no telemóvel). O dia deve ser aberto no próprio dia e fechado até às {h} do dia seguinte; o fecho prova que as vendas do dia estão completas.',
    { h: D.limite },
  );
  if (!exc.length) w.append(secBox(tituloExc, notaExc, el('p', 'note ok', t('Tudo em dia: todos os dias abertos no próprio dia e fechados a tempo.'))));
  else
    w.append(
      tabela({
        id: 'pd-excepcoes',
        titulo: tituloExc,
        nota: notaExc,
        sort: 'dia',
        dir: -1,
        total: false,
        rows: exc.map((x) => ({ ...x, chave: x.rota + '|' + x.dia, oque: oQue(x) })),
        cols: [
          { key: 'rota', label: t('Rota'), p: 0 },
          { key: 'dia', label: t('Dia'), type: 'date', p: 0, render: (v) => document.createTextNode(dl(v)) },
          {
            key: 'oque',
            label: t('O que se passou'),
            p: 0,
            render: (v, r) => {
              const c = el('span', 'aj-celula');
              c.append(
                document.createTextNode(v + ' '),
                botaoPorque({
                  pagina: 'sincronizacao',
                  titulo: t('Rota {r}, dia {d}', { r: r.rota, d: dl(r.dia) }),
                  estado: v,
                  detalhe: [t('Aberto em') + ': ' + dh(r.aberto_em), t('Fechado em') + ': ' + dh(r.fechado_em), r.documento || ''].filter(Boolean).join(' · '),
                  tema: 'Pinguim BO',
                  pergunta: t('Rota {r}, dia {d}: {o}. Porquê, que impacto tem nas vendas e no SAP, e o que se deve fazer?', { r: r.rota, d: dl(r.dia), o: v }),
                }),
              );
              return c;
            },
            xl: (r) => r.oque,
          },
          { key: 'aberto_em', label: t('Aberto em'), p: 1, render: (v) => document.createTextNode(dh(v)), xl: (r) => r.aberto_em || '' },
          { key: 'fechado_em', label: t('Fechado em'), p: 1, render: (v) => document.createTextNode(dh(v)), xl: (r) => r.fechado_em || '' },
          { key: 'documento', label: t('Documento'), p: 2, render: (v) => document.createTextNode(v || '—') },
        ],
      }),
    );
  // quadro rota × dia
  const dias = [];
  for (let d = new Date(D.ate + 'T12:00:00'); dias.length < 7; d.setDate(d.getDate() - 1)) dias.unshift(d.toISOString().slice(0, 10));
  const porRota = {};
  linhas.forEach((x) => {
    (porRota[x.rota] = porRota[x.rota] || { rota: x.rota, chave: x.rota })['d' + x.dia] = x;
  });
  const celula = (x) => {
    if (!x) return el('span', 'pd-vazio', '·');
    const [txt, cls] = FECHO[x.fecho] || ['—', 'p-mut'];
    const c = el('span', 'pd-cel');
    c.append(pill(t(txt) + (x.fechado_em ? ' ' + hm(x.fechado_em) : ''), cls));
    if (x.abertura === 'antecipada' || x.noutro_dia) c.append(el('b', 'pd-marca', '!'));
    c.title = [t('Aberto em') + ': ' + dh(x.aberto_em), t('Fechado em') + ': ' + dh(x.fechado_em), oQue(x)].filter(Boolean).join(' · ');
    return c;
  };
  w.append(
    tabela({
      id: 'pd-quadro',
      titulo: t('Fecho do dia por rota (últimos 7 dias)'),
      nota: t('Hora do fecho em cada dia; «!» quando o dia foi aberto na véspera ou tem vendas emitidas noutro dia. Passe o rato para ver a abertura.'),
      sort: 'rota',
      dir: 1,
      total: false,
      rows: Object.values(porRota),
      cols: [
        { key: 'rota', label: t('Rota'), p: 0 },
        ...dias.map((d, i) => ({
          key: 'd' + d,
          label: dl(d).slice(0, 5),
          p: i < 4 ? 1 : 0,
          render: (x) => celula(x),
          xl: (r) => (r['d' + d] ? t((FECHO[r['d' + d].fecho] || ['—'])[0]) + (r['d' + d].fechado_em ? ' ' + r['d' + d].fechado_em : '') : ''),
        })),
      ],
    }),
  );
  return w;
}
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
  pg.append(seccaoDia(filtroAtivo('sincronizacao', 'rota') ? new Set(FV.rota.map(String)) : null));
};
