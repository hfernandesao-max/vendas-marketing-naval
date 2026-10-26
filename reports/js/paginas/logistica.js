// Logística: stock por carrinha e por armazém.
import { promotorRota } from '../calculos.js';
import { aguardar, tabela, tiles } from '../componentes/blocos.js';
import { n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { R } from './registo.js';
import { inR } from '../periodo.js';
import { DB, el, sum } from '../util.js';

R['stock-carrinha'] = (pg) => {
  const C = DB.cargas.filter((x) => inR(x.data)).map((x) => ({ ...x, promotor: promotorRota(x.rota, x.promotor) }));
  pg.append(
    tiles([
      { l: t('Carrinhas'), v: n0(new Set(C.map((x) => x.matricula)).size) },
      { l: t('Caixas carregadas'), v: n0(sum(C, 'carga')) },
      { l: t('Fraldas'), v: n0(sum(C, 'carga_fraldas')) },
      { l: t('Sabão'), v: n0(sum(C, 'carga_sabao')) },
      { l: t('Sabonetes'), v: n0(sum(C, 'carga_sabonete')) },
    ]),
  );
  const secs = el('div', 'secs');
  secs.append(
    tabela({
      id: 'sc',
      titulo: t('Carga por promotor e carrinha'),
      nota: t(
        'Caixas carregadas em cada carrinha segundo o plano de cargas diário. O stock por artigo de cada carrinha fica disponível com a exportação de stock do Pinguim BO.',
      ),
      rows: C,
      sort: 'data',
      total: true,
      cols: [
        { key: 'data', label: t('Dia'), type: 'date' },
        { key: 'rota', label: t('Rota'), p: 4 },
        { key: 'promotor', label: t('Promotor'), p: 0 },
        { key: 'matricula', label: t('Carrinha') },
        { key: 'viatura', label: t('Viatura') },
        { key: 'motorista', label: t('Motorista') },
        { key: 'n_zonas', label: t('Zonas'), type: 'n' },
        { key: 'carga_fraldas', label: t('Fraldas'), type: 'n', sum: true },
        { key: 'carga_sabao', label: t('Sabão'), type: 'n', sum: true },
        { key: 'carga_sabonete', label: t('Sabonetes'), type: 'n', sum: true },
        { key: 'carga', label: t('Total'), type: 'n', sum: true },
        { key: 'zonas', label: t('Zonas de descarga'), wrap: true },
      ],
    }),
  );
  pg.append(secs);
};
R['stock-armazem'] = (pg) =>
  aguardar(pg, t('Para mostrar o stock por armazém e por carrinha, a exportação de stock do Pinguim BO deve trazer:'), [
    'Armazém ou carrinha',
    'Artigo (código e descrição)',
    'Stock inicial, entradas, saídas e stock final',
    'Data',
  ]);
