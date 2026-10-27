// Logística: stock por carrinha e por armazém (as cargas expedidas estão no Registo de cargas).
import { aguardar } from '../componentes/blocos.js';
import { t } from '../i18n/i18n.js';
import { R } from './registo.js';

// O que foi carregado em cada carrinha está em Logística › Registo de cargas; aqui fica só o stock
R['stock-carrinha'] = (pg) =>
  aguardar(pg, t('Para mostrar o stock de cada carrinha (por promotor), a exportação de stock do Pinguim BO deve trazer:'), [
    'Armazém ou carrinha',
    'Artigo (código e descrição)',
    'Stock inicial, entradas, saídas e stock final',
    'Data',
  ]);
R['stock-armazem'] = (pg) =>
  aguardar(pg, t('Para mostrar o stock por armazém e por carrinha, a exportação de stock do Pinguim BO deve trazer:'), [
    'Armazém ou carrinha',
    'Artigo (código e descrição)',
    'Stock inicial, entradas, saídas e stock final',
    'Data',
  ]);
