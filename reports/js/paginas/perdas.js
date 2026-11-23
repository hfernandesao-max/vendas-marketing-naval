// Inteligência de negócio › Perdas e tempo parado: por rota, no período escolhido (até 31 dias), tudo das bases de dados
// (servidor: nucleo/api/v1/perdas.php):
//   - perdas efectivas: faltas na carrinha (conciliação), falta de dinheiro na entrega (declarado na app × contado) e o
//     custo do tempo parado sem vender (OLAP × PERDAS_CUSTO_HORA);
//   - à parte, as decisões comerciais que tiram valor: notas de crédito, descontos e documentos anulados (Pinguim BO).
// As rotas, os documentos e as faltas ligam às fichas e às páginas respectivas (drill-down).
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { secBox, tabela, tiles } from '../componentes/blocos.js';
import { celulaLigada } from '../componentes/ligacoes.js';
import { dl, kz, n0, pct } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { atual } from '../perfil.js';
import { st } from '../periodo.js';
import { el } from '../util.js';
import { R } from './registo.js';

let P = null;
let chave = '';
let aLer = false;
const MAX = 31;
const dias = (a, b) => Math.round((Date.parse(b + 'T12:00:00Z') - Date.parse(a + 'T12:00:00Z')) / 864e5) + 1;
// indicadores da pontuação de risco (0 a 100; 60 ou mais avisa a Direcção todas as manhãs)
const FATOR = { anulados: 'anulados', faltas: 'faltas na carrinha', entrega: 'falta de dinheiro na entrega', descontos: 'descontos', nc: 'notas de crédito' };
const irPara = (id) => {
  const s = document.getElementById(id);
  if (s) s.scrollIntoView({ behavior: 'smooth', block: 'start' });
};
const horas = (m) => (m ? n0(Math.floor(m / 60)) + ' h ' + String(m % 60).padStart(2, '0') : '0 h');

function ler(de, ate, actualizar) {
  if (aLer) return;
  aLer = true;
  chave = de + '|' + ate;
  apiJson('perdas?de=' + de + '&ate=' + ate + (actualizar ? '&actualizar=1' : '')).then((d) => {
    aLer = false;
    P = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os dados.') };
    if (atual === 'perdas') draw();
  });
}

R.perdas = (pg) => {
  const de = st.de,
    ate = st.ate;
  if (!de || !ate) return;
  if (dias(de, ate) > MAX) {
    pg.append(el('p', 'aviso', t('Escolha um período até {n} dias para ver as perdas e o tempo parado.', { n: MAX })));
    return;
  }
  if (chave !== de + '|' + ate) {
    P = null;
    ler(de, ate);
  }
  if (!P) {
    pg.append(el('p', 'note', t('A ler as perdas do período no Pinguim BO, no OLAP e nas conciliações…')));
    return;
  }
  if (P.falhou) {
    pg.append(el('p', 'erro', P.falhou));
    return;
  }
  const T = P.total;
  const semCusto = P.custo_hora == null;
  if (!P.fontes.pinguim) pg.append(el('p', 'aviso', t('Sem ligação ao Pinguim BO: faltam as vendas, as notas de crédito, os descontos e os anulados.')));
  if (!P.fontes.olap) pg.append(el('p', 'aviso', t('Sem ligação ao OLAP: falta o tempo parado.')));
  pg.append(
    tiles([
      {
        abrir: () => irPara('s-perdas-rotas'),
        l: t('Perdas efectivas'),
        v: kz(T.perdas_kz),
        s: T.perdas_pct != null ? t('{p} das vendas', { p: pct(T.perdas_pct) }) : '—',
        tone: T.perdas_kz > 0 ? 'warn' : null,
      },
      { l: t('Faltas na carrinha'), v: kz(T.falta_kz), s: t('sobras: {v}', { v: kz(T.sobra_kz) }), abrir: () => irPara('s-perdas-faltas') },
      { l: t('Diferença na entrega do dinheiro'), v: kz(T.entrega_dif_kz), s: t('contado − declarado pelo vendedor') },
      {
        l: t('Tempo parado sem vender'),
        v: horas(T.parado_min),
        s: semCusto ? t('custo por definir (PERDAS_CUSTO_HORA)') : t('custo: {v}', { v: kz(T.parado_kz) }),
      },
      { l: t('Notas de crédito'), v: kz(T.nc_kz), s: t('decisão comercial') },
      { l: t('Descontos'), v: kz(T.desconto_kz), s: t('nas linhas das facturas') },
      { l: t('Documentos anulados'), v: kz(T.anulados_kz), s: t('{n} documento(s)', { n: n0(T.anulados_n) }), abrir: () => irPara('s-perdas-anulados') },
    ]),
  );
  pg.append(
    tabela({
      id: 'perdas-rotas',
      titulo: t('Perdas por rota'),
      nota: t('Ordenadas pelas perdas efectivas. A rota abre a ficha da rota.'),
      rows: P.rotas,
      sort: 'perdas_kz',
      dir: -1,
      cols: [
        {
          key: 'rota',
          label: t('Rota'),
          p: 0,
          render: (v, r) => celulaLigada('rota', v, r, v + (r.promotor ? ' · ' + r.promotor : '')),
          xl: (r) => r.rota + ' · ' + r.promotor,
        },
        {
          key: 'risco',
          label: t('Risco'),
          get: (r) => ((P.risco || {})[r.rota] || { pontos: 0 }).pontos,
          render: (v, r) => {
            const x = (P.risco || {})[r.rota] || { pontos: 0, fatores: [] };
            const s = el('span', 'pill ' + (x.pontos >= 60 ? 'p-bad' : x.pontos >= 30 ? 'p-warn' : 'p-ok'), String(x.pontos));
            s.title = x.fatores
              .map((f) => t(FATOR[f.indicador] || f.indicador) + ': ' + pct(f.rota) + ' (' + t('mediana') + ' ' + pct(f.mediana) + ')')
              .join('\n');
            return s;
          },
        },
        { key: 'vendas', label: t('Vendas'), type: 'kz', sum: true },
        { key: 'perdas_kz', label: t('Perdas efectivas'), type: 'kz', sum: true },
        { key: 'perdas_pct', label: t('% das vendas'), type: 'pct' },
        { key: 'falta_kz', label: t('Faltas na carrinha'), type: 'kz', sum: true },
        { key: 'entrega_dif_kz', label: t('Entrega do dinheiro'), type: 'kz', sum: true },
        { key: 'parado_min', label: t('Parado (min)'), type: 'n', sum: true },
        { key: 'parado_kz', label: t('Custo do tempo parado'), type: 'kz', sum: true },
        { key: 'km', label: t('Km'), type: 'n' },
        { key: 'nc_kz', label: t('Notas de crédito'), type: 'kz', sum: true },
        { key: 'desconto_kz', label: t('Descontos'), type: 'kz', sum: true },
        { key: 'anulados_kz', label: t('Anulados'), type: 'kz', sum: true },
      ],
    }),
  );
  const ligConc = (dia) => {
    const a = el('a', 'lig', dl(dia));
    a.href = '#conciliacao-carrinha';
    a.title = t('Abrir a conciliação da carrinha');
    return a;
  };
  pg.append(
    tabela({
      id: 'perdas-faltas',
      titulo: t('Faltas e sobras nas carrinhas'),
      nota: t('Cada contagem com diferenças, com os motivos aprovados.'),
      rows: P.faltas.map((x) => ({
        ...x,
        motivos_txt: Object.entries(x.motivos || {})
          .map(([m, n]) => t(m) + (n > 1 ? ' ×' + n : ''))
          .join(', '),
      })),
      sort: 'falta_kz',
      dir: -1,
      cols: [
        { key: 'dia', label: t('Dia'), p: 0, render: (v) => ligConc(v), xl: (r) => r.dia },
        { key: 'rota', label: t('Rota'), render: (v, r) => celulaLigada('rota', v, r), xl: (r) => r.rota },
        { key: 'falta_kz', label: t('Falta'), type: 'kz', sum: true },
        { key: 'sobra_kz', label: t('Sobra'), type: 'kz', sum: true },
        { key: 'motivos_txt', label: t('Motivos'), wrap: true },
      ],
    }),
  );
  if (P.anulados.length)
    pg.append(
      tabela({
        id: 'perdas-anulados',
        titulo: t('Documentos anulados no Pinguim BO'),
        rows: P.anulados,
        sort: 'data',
        dir: -1,
        cols: [
          { key: 'documento', label: t('Documento'), p: 0, ligar: false },
          { key: 'data', label: t('Data'), type: 'date' },
          { key: 'rota', label: t('Rota'), render: (v, r) => celulaLigada('rota', v, r), xl: (r) => r.rota },
          { key: 'total', label: t('Valor'), type: 'kz', sum: true },
        ],
      }),
    );
  pg.append(
    secBox(
      t('Como se calcula'),
      t('Período de {a} a {b}', { a: dl(P.de), b: dl(P.ate) }),
      (() => {
        const o = el('ol', 'valor-formula');
        [
          'Perdas efectivas = faltas na carrinha + falta de dinheiro na entrega + custo do tempo parado.',
          'Tempo parado = minutos parados sem vender nas paragens registadas no OLAP (dias de trabalho); custo = minutos ÷ 60 × custo por hora da rota.',
          'Notas de crédito, descontos e anulados mostram-se à parte: são decisões comerciais, não perdas por si.',
        ].forEach((x) => o.append(el('li', null, t(x))));
        return o;
      })(),
    ),
  );
};
