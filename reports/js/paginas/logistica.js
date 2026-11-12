// Logística: stock por carrinha e por armazém, calculado na base de dados do Pinguim BO (dbo.GET_STOCK).
//
// Filtros: só os gerais do portal, como em todas as páginas (sem filtros próprios, para não haver dois
// filtros de data ou de rota a dizer coisas diferentes):
//   - data do stock  = fim do período escolhido no topo (posição de stock nesse dia, às 23:59:59);
//   - rotas          = filtro «Rotas» da barra comum (stock por carrinha);
//   - armazéns       = filtro «Armazéns» da barra comum (stock por armazém);
//   - artigo         = pesquisa «Artigo» da barra comum (nome ou código).
// Há um único pedido ao servidor por data (todas as carrinhas e armazéns); mudar rotas, armazéns ou artigo
// filtra no portal, sem nova consulta à base de dados.
import { draw } from '../app.js';
import { promotorRota } from '../calculos.js';
import { pill, tabela, tiles } from '../componentes/blocos.js';
import { FV, filtroAtivo, normTxt } from '../filtros.js';
import { fetchStock } from '../fontes.js';
import { dl, n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { st } from '../periodo.js';
import { SESSAO } from '../perfil.js';
import { postJson } from '../servidor.js';
import { DB, el, sum, toast } from '../util.js';
import { R } from './registo.js';

// Estado do pedido de stock para a data do período: '' | 'a-carregar' | 'ok' | 'erro'
let pedido = { data: '', estado: '', erro: '', em: 0 };
const VALIDADE = 5 * 60000; // como o resto do portal: dados de stock renovados ao fim de 5 minutos

/** Pede o stock da data final do período (uma vez por data). Devolve o estado do pedido. */
function garantirStock() {
  const data = st.ate;
  const fresco = Date.now() - pedido.em < VALIDADE;
  if (pedido.data === data && pedido.estado && (pedido.estado === 'a-carregar' || fresco)) return pedido.estado;
  pedido = { data, estado: 'a-carregar', erro: '', em: Date.now() };
  fetchStock(data, 'todos')
    .then(() => {
      if (pedido.data === data) pedido.estado = 'ok';
      draw();
    })
    .catch((e) => {
      if (pedido.data === data) {
        pedido.estado = 'erro';
        pedido.erro = (e && e.message) || t('Erro na consulta de stock.');
      }
      draw();
    });
  return 'a-carregar';
}

/** Data da posição de stock mostrada: a da consulta (base de dados) ou, no ficheiro, a última até ao fim do período. */
function dataPosicao() {
  const S = DB.stock || {};
  if (S.data) return String(S.data).slice(0, 10);
  const ds = (S.datas_disponiveis || []).filter((d) => d <= st.ate).sort();
  return ds[ds.length - 1] || S.ultima_data || st.ate;
}

/** Enquanto o stock não está pronto, mostra o estado (a calcular ou erro com «Tentar de novo»). true = pronto. */
function stockPronto(pg) {
  const estado = garantirStock();
  if (estado === 'ok' && DB.stock) return true;
  const s = el('section', 'glass sec stock-estado');
  if (estado === 'erro') {
    s.append(el('h2', null, t('Não foi possível calcular o stock')));
    s.append(el('p', 'aviso', t(pedido.erro)));
    const b = el('button', 'btn', t('Tentar de novo'));
    b.type = 'button';
    b.addEventListener('click', () => {
      pedido = { data: '', estado: '', erro: '', em: 0 };
      draw();
    });
    s.append(b);
  } else {
    s.append(el('h2', null, t('A calcular o stock…')));
    s.append(el('p', 'note', t('Posição em {d}, calculada na base de dados do Pinguim BO. Pode demorar alguns segundos.', { d: dl(st.ate) })));
  }
  pg.append(s);
  return false;
}

const artigoOk = (pagina) => {
  const q = filtroAtivo(pagina, 'artigo') ? normTxt(FV.artigo) : '';
  return (it) => !q || normTxt((it.codigo || '') + ' ' + (it.produto || '')).includes(q);
};

const colsArtigos = () => [
  { key: 'codigo', label: t('Código do Produto'), p: 0 },
  { key: 'produto', label: t('Produto'), wrap: true, p: 0 },
  { key: 'armazem', label: t('Armazém'), p: 1 },
  { key: 'quantidade', label: t('Quantidade'), type: 'n', sum: true, p: 0 },
];

const podeConfirmar = () => !!SESSAO && ['SADM', 'TI'].includes(SESSAO.perfil);

/**
 * De onde vem o stock da carrinha: o(s) armazém(ns) do Pinguim BO que o portal juntou e se o armazém da carrinha já
 * foi confirmado. Sem confirmação conta o código 100010NN ou, em último caso, «10NN» no nome do armazém — e mais do
 * que um armazém na mesma carrinha é sinal de que algum não é dela. SADM e TI confirmam o certo com um clique.
 */
function origemCarrinha(car) {
  const box = el('div', 'stock-origem');
  const ori = car.origem || [];
  if (car.confirmado === undefined) return box; // ficheiro sem esta informação
  box.append(pill(car.confirmado ? t('Armazém confirmado') : t('Armazém por confirmar'), car.confirmado ? 'p-ok' : 'p-warn'));
  ori.forEach((a) => {
    const como = a.como === 'confirmado' ? t('confirmado') : a.como === 'codigo' ? t('pelo código') : t('pelo nome');
    const linha = el('span', 'stock-origem-arm', a.codigo + ' · ' + a.nome + ' (' + como + ')');
    if (podeConfirmar() && (!car.confirmado || ori.length > 1)) {
      const b = el('button', 'btn ghost sm', ori.length > 1 ? t('Só este é a carrinha') : t('Confirmar'));
      b.type = 'button';
      b.addEventListener('click', () => {
        b.disabled = true;
        postJson('stock', { accao: 'confirmar_carrinha', rota: car.rota, codigo: a.codigo, nome: a.nome }).then((d) => {
          b.disabled = false;
          if (!d || !d.ok) return toast(t((d && d.erro) || 'Sem resposta do servidor.'), 6000);
          toast(t('Armazém da carrinha {r} confirmado.', { r: car.rota }), 4000);
          pedido = { data: '', estado: '', erro: '', em: 0 };
          draw();
        });
      });
      linha.append(' ', b);
    }
    box.append(linha);
  });
  if (ori.length > 1) box.append(el('p', 'aviso', t('Esta carrinha junta {n} armazéns do Pinguim BO: confirme qual é o dela.', { n: ori.length })));
  return box;
}

const vazio = (txt) => {
  const v = el('div', 'glass empty');
  v.append(el('p', null, txt));
  return v;
};

R['stock-carrinha'] = (pg) => {
  if (!stockPronto(pg)) return;
  const dPos = dataPosicao();
  const rotasSel = filtroAtivo('stock-carrinha', 'rota') ? new Set(FV.rota.map(String)) : null;
  const okArtigo = artigoOk('stock-carrinha');
  const cargas = DB.cargas || [];

  const carrinhas = (DB.stock.carrinhas || [])
    .filter((c) => (!c.data || c.data === dPos) && (!rotasSel || rotasSel.has(String(c.rota))))
    .map((c) => {
      const cRota = cargas.filter((x) => x.rota === c.rota);
      const valor = (k) => (c[k] && c[k] !== '—' ? c[k] : (cRota[0] && cRota[0][k]) || '—');
      const viat = valor('viatura'),
        mat = valor('matricula'),
        mot = valor('motorista');
      const armazem = c.armazem || t('Armazém {r}', { r: c.rota }) + ' - FHR PIV';
      const itens = (c.itens || c.artigos || [])
        .map((it) => ({
          codigo: it.codigo || it.codigo_produto,
          produto: it.produto || it.artigo,
          armazem: it.armazem || armazem,
          quantidade: +it.quantidade || 0,
        }))
        .filter((it) => it.quantidade !== 0 && okArtigo(it));
      return {
        rota: c.rota,
        promotor: promotorRota(c.rota, c.promotor || (DB.mapRota[c.rota] || {}).promotor),
        carrinha: viat !== '—' ? viat + (mat !== '—' ? ' · ' + mat : '') : mat !== '—' ? mat : t('Carrinha {r}', { r: c.rota }),
        motorista: mot,
        armazem,
        itens,
        total_quantidade: sum(itens, 'quantidade'),
        confirmado: c.confirmado,
        origem: c.armazens_origem || [],
      };
    })
    .filter((c) => c.itens.length)
    .sort((a, b) => String(a.rota).localeCompare(String(b.rota), 'pt', { numeric: true }));

  pg.append(
    tiles([
      { l: t('Promotores / Carrinhas com stock'), v: n0(carrinhas.length) },
      { l: t('Stock total nas carrinhas'), v: n0(sum(carrinhas, 'total_quantidade')), s: t('Posição em {d}', { d: dl(dPos) }) },
      { l: t('Linhas de produtos'), v: n0(sum(carrinhas, (x) => x.itens.length)) },
      { l: t('Data do stock'), v: dl(dPos), s: dPos === st.ate ? t('fim do período escolhido') : t('último dia com dados até {d}', { d: dl(st.ate) }) },
    ]),
  );
  const secs = el('div', 'secs');
  if (!carrinhas.length) secs.append(vazio(t('Sem carrinhas com stock para os filtros escolhidos.')));
  carrinhas.forEach((car) => {
    const sec = tabela({
      id: 'sc-car-' + car.rota,
      titulo: t('Carrinha') + ': ' + car.carrinha,
      nota:
        t('Promotor') +
        ': ' +
        car.promotor +
        ' (' +
        t('Rota') +
        ' ' +
        car.rota +
        ')' +
        (car.motorista !== '—' ? ' · ' + t('Motorista') + ': ' + car.motorista : '') +
        ' · ' +
        car.armazem,
      rows: car.itens,
      sort: 'quantidade',
      dir: -1,
      total: true,
      cols: colsArtigos(),
    });
    const hl = sec.querySelector('.sec-h > div');
    if (hl) hl.append(origemCarrinha(car));
    secs.append(sec);
  });
  pg.append(secs);
};

R['stock-armazem'] = (pg) => {
  if (!stockPronto(pg)) return;
  const dPos = dataPosicao();
  const armSel = filtroAtivo('stock-armazem', 'armazem') ? new Set(FV.armazem.map(String)) : null;
  const okArtigo = artigoOk('stock-armazem');
  const chave = (x) => String(x.cod_armazem || x.armazem);

  const itens = (DB.stock.linhas_armazens || [])
    .filter((l) => (!l.data || l.data === dPos) && (!armSel || armSel.has(chave(l))))
    .map((l) => ({
      codigo: l.codigo || l.codigo_produto,
      produto: l.produto || l.artigo,
      armazem: l.armazem || (l.cod_armazem ? t('Armazém {r}', { r: l.cod_armazem }) : t('Armazém central')),
      cod_armazem: l.cod_armazem,
      centro: l.centro,
      quantidade: +l.quantidade || 0,
    }))
    .filter((it) => it.quantidade !== 0 && okArtigo(it));

  // Resumo por armazém, a partir das linhas filtradas (os totais batem sempre com o detalhe)
  const porArm = new Map();
  itens.forEach((it) => {
    const k = chave(it);
    if (!porArm.has(k)) porArm.set(k, { centro: it.centro, cod_armazem: it.cod_armazem, armazem: it.armazem, artigos_com_stock: 0, total_quantidade: 0 });
    const a = porArm.get(k);
    a.artigos_com_stock++;
    a.total_quantidade += it.quantidade;
  });
  const armRows = [...porArm.values()];
  const numCentros = new Set(armRows.map((x) => x.centro)).size;

  pg.append(
    tiles([
      { l: t('Armazéns com stock'), v: n0(armRows.length), s: t('{n} centros operacionais', { n: n0(numCentros) }) },
      { l: t('Centros'), v: n0(numCentros) },
      { l: t('Artigos com stock'), v: n0(new Set(itens.map((x) => x.codigo)).size), s: t('Posição em {d}', { d: dl(dPos) }) },
      { l: t('Stock final nas centrais'), v: n0(sum(itens, 'quantidade')), s: t('em {d}', { d: dl(dPos) }) },
    ]),
  );
  const secs = el('div', 'secs');
  if (!itens.length) secs.append(vazio(t('Sem armazéns com stock para os filtros escolhidos.')));
  else {
    secs.append(
      tabela({
        id: 'sa-resumo',
        titulo: t('Stock por centro e armazém'),
        nota: t('Posição de stock em cada armazém central em {d}.', { d: dl(dPos) }),
        rows: armRows,
        sort: 'total_quantidade',
        total: true,
        cols: [
          { key: 'centro', label: t('Centro'), p: 0 },
          { key: 'cod_armazem', label: t('Código') },
          { key: 'armazem', label: t('Armazém'), wrap: true },
          { key: 'artigos_com_stock', label: t('Artigos'), type: 'n', sum: true },
          { key: 'total_quantidade', label: t('Stock final'), type: 'n', sum: true },
        ],
      }),
      tabela({
        id: 'sa-detalhe',
        titulo: t('Stock final por artigo e armazém'),
        nota: t('Posição de stock por artigo e armazém central em {d}.', { d: dl(dPos) }),
        rows: itens,
        sort: 'quantidade',
        dir: -1,
        total: true,
        cols: colsArtigos(),
      }),
    );
  }
  pg.append(secs);
};
