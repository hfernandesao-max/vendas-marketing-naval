// Integrações: Comunicação AGT — estado da comunicação de cada documento de vendas do período à AGT, lido do
// Pinguim BO (DOCHEADEREXTRA ligado ao DOCHEADER; ver nucleo/servicos/agt.php): o estado devolvido pela AGT
// (Enviado / Erro / Pendente) e a mensagem de erro mandam; depois o número do pedido; sem nada registado, enviado
// (como o Pinguim BO mostra).
// Um pedido ao servidor por período; o filtro de rotas da barra comum filtra no portal, sem nova consulta.
import { apiFetch } from '../api.js';
import { draw } from '../app.js';
import { promotorRota } from '../calculos.js';
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

// Estado da comunicação: texto e cor (com rótulo, nunca só a cor)
const ESTADOS = {
  comunicado: ['Comunicado', 'p-ok'],
  pendente: ['Pendente', 'p-warn'],
  erro: ['Com erro', 'p-bad'],
  por_comunicar: ['Por comunicar', 'p-warn'],
};

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

// Estado AGT: o que a AGT respondeu e o comprovativo (estado registado, número do pedido ou sem registo de erro)
const textoAgt = (r) => {
  if (r.estado === 'erro') return t('Erro na comunicação');
  if (r.estado === 'pendente') return t('Pendente na AGT');
  if (r.estado === 'por_comunicar') return t('Sem pedido de comunicação');
  if (r.pedido_agt) return t('Enviado') + ' · ' + t('Pedido n.º {n}', { n: r.pedido_agt });
  if (r.evidencia === 'sem_registo') return t('Enviado (sem registo de erro)');
  return t('Enviado');
};
// Estado do documento: activo ou anulado (com o motivo de cancelamento, quando é mais do que «Anulado»)
const textoDoc = (r) =>
  !r.anulado ? t('Activo') : r.motivo_anulacao && r.motivo_anulacao !== 'Anulado' ? t('Anulado') + ' · ' + r.motivo_anulacao : t('Anulado');

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
  const conta = (e) => D.filter((x) => x.estado === e).length;
  const nPor = conta('por_comunicar') + conta('pendente'),
    nErro = conta('erro'),
    nCom = conta('comunicado');
  pg.append(
    tiles([
      { l: t('Documentos'), v: n0(D.length) },
      { l: t('Comunicados'), v: n0(nCom), s: t('{p} dos documentos', { p: pct(D.length ? nCom / D.length : null) }) },
      { l: t('Com erro'), v: n0(nErro), tone: nErro ? 'bad' : null },
      { l: t('Por comunicar'), v: n0(nPor), s: t('pendentes ou sem pedido'), tone: nPor ? 'warn' : null },
      { l: t('Anulados'), v: n0(D.filter((x) => x.anulado).length) },
    ]),
  );
  const nomeEstado = (e) => t((ESTADOS[e] || [e])[0]);
  pg.append(
    tabela({
      id: 'agt',
      titulo: t('Comunicação AGT'),
      nota: t(
        'Documentos de vendas do período e o estado da comunicação à AGT registado no Pinguim BO: estado devolvido pela AGT, mensagem de erro e número do pedido.',
      ),
      rows: D.map((x) => ({
        ...x,
        rota_txt: promotorRota(x.rota, x.promotor),
      })),
      sort: 'data',
      total: true,
      cols: [
        { key: 'rota_txt', label: t('Rota'), p: 0 },
        { key: 'tipo', label: t('Tipo de documento'), get: (r) => t(r.tipo), p: 3 },
        colDocumento({ p: 1 }),
        { key: 'total', label: t('Total'), type: 'kz', sum: true },
        { key: 'data', label: t('Data do documento'), type: 'date' },
        { key: 'data_fiscal', label: t('Data fiscal'), type: 'date', p: 4 },
        { key: 'estado_agt', label: t('Estado AGT'), wrap: true, get: textoAgt, p: 1 },
        { key: 'mensagem_agt', label: t('Mensagem da AGT'), wrap: true, get: (r) => r.mensagem_agt || '', p: 2 },
        {
          key: 'anulado',
          label: t('Estado do documento'),
          p: 2,
          get: textoDoc,
          render: (v, r) => pill(r ? textoDoc(r) : String(v), r && r.anulado ? 'p-mut' : 'p-ok'),
          xl: textoDoc,
        },
        { key: 'entidade', label: t('Nome da entidade'), wrap: true, p: 3 },
        {
          key: 'estado',
          label: t('Estado'),
          p: 0,
          get: (r) => nomeEstado(r.estado),
          render: (v, r) => pill(nomeEstado(r ? r.estado : v), (ESTADOS[r ? r.estado : v] || [])[1] || 'p-mut'),
          xl: (r) => nomeEstado(r.estado),
        },
      ],
    }),
  );
  if (!D.length) pg.append(el('div', 'glass empty', t('Sem documentos no período.')));
};
