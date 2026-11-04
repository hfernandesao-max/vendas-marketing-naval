// Integrações: Comunicação AGT — os documentos que comunicaram com a AGT, com as colunas e os estados da consulta validada
// no Pinguim BO (ver nucleo/servicos/agt.php): Loja, Tipo de documento, Documento, Total do documento, Mensagem (estado
// interno), Data documento, Data fiscal, Nome entidade, Estado AGT (Enviado, Erro - Comunicado, Pendente, Erro - Não
// comunicado) e Mensagem de erro.
// Um pedido ao servidor por período; o filtro de rotas da barra comum filtra no portal, sem nova consulta.
import { apiFetch } from '../api.js';
import { draw } from '../app.js';
import { pill, tabela, tiles } from '../componentes/blocos.js';
import { FV, filtroAtivo } from '../filtros.js';
import { dl, n0, pct } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { st } from '../periodo.js';
import { el } from '../util.js';
import { R } from './registo.js';
import { colDocumento } from '../componentes/documento.js';

const VALIDADE = 5 * 60000; // como o resto do portal: renovado ao fim de 5 minutos
let pedido = { chave: '', estado: '', dados: null, erro: '', em: 0 };

// Estado AGT: o texto da consulta, com a cor (com rótulo, nunca só a cor)
const ESTADOS = {
  Enviado: 'p-ok',
  Pendente: 'p-warn',
  'Erro - Comunicado': 'p-bad',
  'Erro - Não comunicado': 'p-bad',
};
const estadoAgt = (r) => r.estado_agt || 'Erro - Não comunicado';

/** Pede os documentos do período (uma vez por período). Devolve o estado do pedido. */
function garantir() {
  const chave = st.de + '|' + st.ate;
  const fresco = Date.now() - pedido.em < VALIDADE;
  if (pedido.chave === chave && pedido.estado && (pedido.estado === 'a-carregar' || fresco)) return pedido.estado;
  pedido = { chave, estado: 'a-carregar', dados: null, erro: '', em: Date.now() };
  const q = 'start_date=' + st.de.replace(/-/g, '') + '&end_date=' + st.ate.replace(/-/g, '');
  apiFetch('agt?' + q)
    .then((r) => (r.status === 401 || r.status === 403 ? { disponivel: false, erro: 'Sem acesso a esta página.' } : r.json()))
    .catch(() => ({ disponivel: false, erro: 'A Comunicação AGT precisa do servidor da plataforma (PHP) e da base de dados do Pinguim BO.' }))
    .then((d) => {
      if (pedido.chave !== chave) return;
      pedido.estado = d && d.disponivel ? 'ok' : 'erro';
      pedido.dados = d;
      pedido.erro = (d && d.erro) || '';
      draw();
    });
  return 'a-carregar';
}

R.agt = (pg) => {
  const estado = garantir();
  if (estado !== 'ok') {
    const s = el('section', 'glass sec');
    if (estado === 'erro') {
      s.append(el('h2', null, t('Não foi possível ler a comunicação à AGT')));
      s.append(el('p', 'aviso', t(pedido.erro || 'Sem ligação à base de dados do Pinguim BO.')));
      const b = el('button', 'btn', t('Tentar de novo'));
      b.type = 'button';
      b.addEventListener('click', () => {
        pedido = { chave: '', estado: '', dados: null, erro: '', em: 0 };
        draw();
      });
      s.append(b);
    } else {
      s.append(el('h2', null, t('A carregar…')));
      s.append(el('p', 'note', t('Documentos de {a} a {b}, lidos na base de dados do Pinguim BO.', { a: dl(st.de), b: dl(st.ate) })));
    }
    pg.append(s);
    return;
  }
  let D = pedido.dados.documentos || [];
  if (filtroAtivo('agt', 'rota')) D = D.filter((x) => FV.rota.includes(String(x.rota)));
  const conta = (e) => D.filter((x) => estadoAgt(x) === e).length;
  const nEnv = conta('Enviado'),
    nErroC = conta('Erro - Comunicado'),
    nErroN = conta('Erro - Não comunicado'),
    nPend = conta('Pendente');
  pg.append(
    tiles([
      { l: t('Documentos'), v: n0(D.length) },
      { l: t('Enviado'), v: n0(nEnv), s: t('{p} dos documentos', { p: pct(D.length ? nEnv / D.length : null) }) },
      { l: t('Erro - Comunicado'), v: n0(nErroC), tone: nErroC ? 'bad' : null },
      { l: t('Erro - Não comunicado'), v: n0(nErroN), tone: nErroN ? 'bad' : null },
      { l: t('Pendente'), v: n0(nPend), tone: nPend ? 'warn' : null },
    ]),
  );
  pg.append(
    tabela({
      id: 'agt',
      titulo: t('Comunicação AGT'),
      nota: t('Documentos que comunicaram com a AGT no período, com o estado e a mensagem de erro registados no Pinguim BO.'),
      rows: D,
      sort: 'loja',
      dir: 1,
      total: true,
      cols: [
        { key: 'loja', label: t('Loja'), wrap: true, p: 0 },
        { key: 'tipo', label: t('Tipo de documento'), get: (r) => t(r.tipo), p: 3 },
        colDocumento({ key: 'documento_agt', p: 1, xl: (r) => r.documento_agt }),
        { key: 'total', label: t('Total do documento'), type: 'kz', sum: true },
        { key: 'estado_documento', label: t('Mensagem'), p: 4 },
        { key: 'data', label: t('Data documento'), type: 'date' },
        { key: 'data_fiscal', label: t('Data fiscal'), type: 'date', p: 4 },
        { key: 'entidade', label: t('Nome entidade'), wrap: true, p: 3 },
        {
          key: 'estado_agt',
          label: t('Estado AGT'),
          p: 0,
          get: (r) => t(estadoAgt(r)),
          render: (v, r) => pill(r ? t(estadoAgt(r)) : String(v), ESTADOS[r ? estadoAgt(r) : v] || 'p-mut'),
          xl: (r) => t(estadoAgt(r)),
        },
        { key: 'mensagem_erro', label: t('Mensagem de erro'), wrap: true, get: (r) => r.mensagem_erro || '', p: 2 },
      ],
    }),
  );
  if (!D.length) pg.append(el('div', 'glass empty', t('Sem documentos no período.')));
};
