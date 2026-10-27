// Logística › Registo de cargas: o que saiu em cada viatura, segundo os planos de cargas publicados.
// Separa o fluxo logístico como nas boas práticas (planear → expedir → stock): o plano de cargas é o
// planeamento do dia; este registo é a execução (cargas expedidas); o stock fica nas páginas de stock.
import { promotorRota } from '../calculos.js';
import { secBox, tabela, tiles } from '../componentes/blocos.js';
import { n0, n1 } from '../formatos.js';
import { FV, filtroAtivo } from '../filtros.js';
import { t } from '../i18n/i18n.js';
import { R } from './registo.js';
import { inR } from '../periodo.js';
import { DB, el, sum } from '../util.js';

const NOME_ARTIGO = { fraldas: 'Fraldas', sabao: 'Sabão', sabonete: 'Sabonete' };
const nomeArtigo = (id) => t(NOME_ARTIGO[id] || id.charAt(0).toUpperCase() + id.slice(1));
// Zonas: só as primeiras na tabela (linhas de altura igual); a lista completa na dica e no Excel
const zonasCurtas = (txt, max = 3) => {
  const z = String(txt || '')
    .split(',')
    .map((x) => x.trim())
    .filter(Boolean);
  const s = el('span', null, z.slice(0, max).join(', ') + (z.length > max ? ' ' + t('+{n}', { n: z.length - max }) : ''));
  if (z.length > max) s.title = z.join(', ');
  return s;
};

R['registo-cargas'] = (pg) => {
  let C = (DB.cargas || []).filter((x) => inR(x.data));
  if (filtroAtivo('registo-cargas', 'rota')) C = C.filter((x) => FV.rota.includes(String(x.rota)));
  C = C.map((x) => ({ ...x, promotor_t: promotorRota(x.rota, x.promotor) }));
  // artigos presentes nos planos (carga_<artigo>), pela ordem habitual
  const ids = [
    ...new Set(
      C.flatMap((x) =>
        Object.keys(x)
          .filter((k) => k.startsWith('carga_'))
          .map((k) => k.slice(6)),
      ),
    ),
  ].sort((a, b) => (Object.keys(NOME_ARTIGO).indexOf(a) + 1 || 99) - (Object.keys(NOME_ARTIGO).indexOf(b) + 1 || 99));
  const colsArt = ids.map((id) => ({ key: 'carga_' + id, label: nomeArtigo(id) + ' (cx)', type: 'n', sum: true }));
  const dias = new Set(C.map((x) => x.data)).size;
  pg.append(
    tiles([
      { l: t('Saídas de viatura'), v: n0(C.length), s: t('{n} dias com plano publicado', { n: dias }) },
      { l: t('Viaturas'), v: n0(new Set(C.map((x) => x.matricula).filter(Boolean)).size) },
      { l: t('Caixas carregadas'), v: n0(sum(C, 'carga')) },
      ...ids.map((id) => ({ l: nomeArtigo(id) + ' (cx)', v: n0(sum(C, 'carga_' + id)) })),
      { l: t('Média por saída'), v: C.length ? n1(sum(C, 'carga') / C.length) : '—', s: t('caixas') },
    ]),
  );
  if (!C.length) {
    pg.append(
      secBox(
        t('Registo de cargas'),
        '',
        el('p', 'note', t('Sem cargas no período escolhido. O registo é feito a partir dos planos de cargas publicados (Logística › Plano de cargas).')),
      ),
    );
    return;
  }
  const secs = el('div', 'secs');
  // 1. Detalhe: uma linha por saída (dia × rota × viatura)
  secs.append(
    tabela({
      id: 'rc-saidas',
      titulo: t('Cargas por rota e viatura'),
      nota: t('Uma linha por saída: rota, viatura e caixas carregadas, segundo a versão publicada do plano de cargas de cada dia.'),
      rows: C,
      sort: 'data',
      total: true,
      cols: [
        { key: 'data', label: t('Dia'), type: 'date', p: 0 },
        { key: 'promotor_t', label: t('Rota / promotor'), p: 0 },
        { key: 'matricula', label: t('Matrícula'), p: 1, nowrap: true },
        { key: 'viatura', label: t('Tipo de viatura'), p: 3 },
        { key: 'motorista', label: t('Motorista'), p: 2 },
        { key: 'transportadora', label: t('Transportadora'), p: 4 },
        ...colsArt,
        { key: 'carga', label: t('Total (cx)'), type: 'n', sum: true, p: 0 },
        { key: 'n_zonas', label: t('N.º de zonas'), type: 'n', p: 3 },
        { key: 'zonas', label: t('Zonas de descarga'), p: 4, render: (v) => zonasCurtas(v), xl: (r) => r.zonas },
      ],
    }),
  );
  // 2. Resumo diário
  const porDia = Object.values(
    C.reduce((m, x) => {
      const d = (m[x.data] = m[x.data] || { chave: x.data, data: x.data, saidas: 0, viaturas: new Set(), carga: 0 });
      d.saidas++;
      if (x.matricula) d.viaturas.add(x.matricula);
      d.carga += +x.carga || 0;
      ids.forEach((id) => (d['carga_' + id] = (d['carga_' + id] || 0) + (+x['carga_' + id] || 0)));
      return m;
    }, {}),
  ).map((d) => ({ ...d, viaturas: d.viaturas.size }));
  secs.append(
    tabela({
      id: 'rc-dias',
      titulo: t('Cargas por dia'),
      nota: t('Resumo diário das saídas e das caixas carregadas.'),
      rows: porDia,
      sort: 'data',
      total: true,
      cols: [
        { key: 'data', label: t('Dia'), type: 'date', p: 0 },
        { key: 'saidas', label: t('Saídas'), type: 'n', sum: true },
        { key: 'viaturas', label: t('Viaturas'), type: 'n' },
        ...colsArt,
        { key: 'carga', label: t('Total (cx)'), type: 'n', sum: true, p: 0 },
      ],
    }),
  );
  // 3. Por viatura (utilização da frota)
  const porViatura = Object.values(
    C.reduce((m, x) => {
      const k = x.matricula || '—';
      const v = (m[k] = m[k] || { chave: k, matricula: k, viatura: x.viatura, transportadora: x.transportadora, dias: new Set(), rotas: new Set(), carga: 0 });
      v.dias.add(x.data);
      v.rotas.add(x.rota);
      v.carga += +x.carga || 0;
      return m;
    }, {}),
  ).map((v) => ({ ...v, dias: v.dias.size, rotas: [...v.rotas].sort().join(', '), media: v.dias.size ? v.carga / v.dias.size : 0 }));
  secs.append(
    tabela({
      id: 'rc-viaturas',
      titulo: t('Cargas por viatura'),
      nota: t('Utilização de cada viatura no período: dias de saída, rotas servidas e caixas transportadas.'),
      rows: porViatura,
      sort: 'carga',
      dir: -1,
      total: true,
      cols: [
        { key: 'matricula', label: t('Matrícula'), p: 0, nowrap: true },
        { key: 'viatura', label: t('Tipo de viatura') },
        { key: 'transportadora', label: t('Transportadora') },
        { key: 'dias', label: t('Dias de saída'), type: 'n' },
        { key: 'rotas', label: t('Rotas') },
        { key: 'carga', label: t('Total (cx)'), type: 'n', sum: true, p: 0 },
        { key: 'media', label: t('Média por dia (cx)'), type: 'n' },
      ],
    }),
  );
  pg.append(secs);
};
