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
import { foraDoPrazo } from '../componentes/horario.js';
import { pill, tabela, tiles } from '../componentes/blocos.js';
import { FV, filtroAtivo, normTxt } from '../filtros.js';
import { fetchStock } from '../fontes.js';
import { dl, n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { st } from '../periodo.js';
import { SESSAO, podeVer } from '../perfil.js';
import { apiJson } from '../api.js';
import { botao, entrada, janela, msgErro } from '../componentes/janelas.js';
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

// Requisições ao armazém (reposição): o que vai ser carregado em cada carrinha (emitidas, por confirmar) e o que já
// foi carregado no dia (carga confirmada). Uma leitura por data; só para quem vê as requisições.
let REQ = { data: '', estado: '', rotas: {}, gere: false, eu: '', em: 0 };
function garantirRequisicoes(data) {
  if (!podeVer('requisicoes-carrinha')) return;
  if (REQ.data === data && (REQ.estado === 'a-ler' || Date.now() - REQ.em < VALIDADE)) return;
  REQ = { data, estado: 'a-ler', rotas: {}, gere: false, eu: '', em: Date.now() };
  apiJson('requisicoes?carga=1&data=' + data).then((d) => {
    if (REQ.data !== data) return;
    REQ = { data, estado: 'ok', rotas: (d && d.rotas) || {}, gere: !!(d && d.gere), eu: (d && d.eu) || '', horario: (d && d.horario) || null, em: Date.now() };
    draw();
  });
}
/** Por artigo: quantidade a carregar (emitidas) e carregada (confirmadas), e as requisições da rota. */
function requisicoesDaRota(rota) {
  const rs = REQ.rotas[rota] || [];
  const porArtigo = {};
  rs.forEach((r) =>
    r.linhas.forEach((l) => {
      const x = (porArtigo[l.codigo] = porArtigo[l.codigo] || { codigo: l.codigo, produto: l.artigo, a_carregar: 0, carregado: 0 });
      if (r.estado === 'emitida') x.a_carregar += +l.quantidade || 0;
      else x.carregado += +(l.entregue != null ? l.entregue : l.quantidade) || 0;
    }),
  );
  return { rs, porArtigo };
}
/** Coluna da requisição: âmbar enquanto está por carregar; azul depois de confirmada a carga. */
const colRequisicao = () => ({
  key: 'requisicao',
  label: t('Requisição ao armazém'),
  type: 'n',
  sum: true,
  p: 1,
  get: (r) => (r.a_carregar || 0) + (r.carregado || 0) || null,
  render: (v, r) => {
    if (!v) return document.createTextNode('—');
    const w = el('span', 'req-cel');
    if (r.a_carregar) {
      // depois do fim da janela de carregamento (17:00 de segunda a sexta) fica assinalado como fora do prazo
      const atraso = foraDoPrazo(REQ.horario, 'carga');
      const a = el('span', 'req-a-carregar' + (atraso ? ' req-atraso' : ''), n0(r.a_carregar));
      a.title = atraso
        ? t('Carga fora do prazo ({h}): requisição emitida, carga por confirmar', { h: REQ.horario.carga[1] })
        : t('A carregar: requisição emitida, carga por confirmar');
      w.append(a);
    }
    if (r.carregado) {
      const c = el('span', 'req-carregado', '✓ ' + n0(r.carregado));
      c.title = t('Carregado: carga confirmada');
      w.append(c);
    }
    return w;
  },
});

/** Janela para o armazém confirmar a carga de uma requisição, com a quantidade entregue de cada artigo. */
function confirmarCarga(r) {
  janela(
    t('Confirmar a carga da requisição {n}', { n: r.numero }),
    t('Indique o que foi realmente carregado na carrinha (por omissão, o requisitado). Depois de confirmada, a coluna passa a «carregado».'),
    (b, ft, fechar) => {
      const tab = el('table', 'cc-tab');
      const cab = el('tr');
      [t('Artigo'), t('Requisitado'), t('Entregue')].forEach((x, i) => cab.append(el('th', i ? 'n' : null, x)));
      tab.append(cab);
      const campos = {};
      r.linhas.forEach((l) => {
        const tr = el('tr');
        const i = entrada('number', l.quantidade, { min: 0, step: 'any', inputMode: 'decimal' });
        i.setAttribute('aria-label', t('Entregue') + ' · ' + l.artigo);
        campos[l.codigo] = i;
        const td = el('td', 'n');
        td.append(i);
        tr.append(el('td', null, l.artigo + ' · ' + l.codigo), el('td', 'n', n0(l.quantidade)), td);
        tab.append(tr);
      });
      const erro = msgErro();
      b.append(tab, erro);
      const ok = botao(t('Confirmar carga'), null, () => {
        const entregue = {};
        for (const [c, i] of Object.entries(campos)) {
          if (i.value === '' || Number(i.value) < 0) {
            erro.hidden = false;
            erro.textContent = t('Indique a quantidade entregue de cada artigo (0 se não foi carregado).');
            return i.focus();
          }
          entregue[c] = Number(i.value);
        }
        ok.disabled = true;
        postJson('requisicoes', { accao: 'confirmar_carga', numero: r.numero, entregue }).then((x) => {
          ok.disabled = false;
          if (!x.ok) {
            erro.hidden = false;
            erro.textContent = t(x.erro || 'Não foi possível gravar.');
            return;
          }
          fechar();
          toast(t('Carga da requisição {n} confirmada.', { n: r.numero }), 4000);
          REQ.em = 0;
          garantirRequisicoes(REQ.data);
        });
      });
      ft.append(botao(t('Cancelar'), 'ghost', fechar), ok);
    },
  );
}
/** Linha por baixo do título da carrinha: as requisições da rota e o botão de confirmar a carga. */
function linhaRequisicoes(rs) {
  const w = el('div', 'req-linha');
  rs.forEach((r) => {
    const pend = r.estado === 'emitida';
    w.append(
      pill((pend ? t('A carregar') : t('Carregado')) + ' · ' + r.numero, pend ? 'p-warn' : 'p-ok'),
      el(
        'span',
        'note',
        pend
          ? t('emitida em {d}', { d: dl(String(r.emitida_em).slice(0, 10)) + ' ' + String(r.emitida_em).slice(11, 16) })
          : t('carga confirmada por {p} em {d}', {
              p: r.confirmado_por,
              d: dl(String(r.confirmado_em).slice(0, 10)) + ' ' + String(r.confirmado_em).slice(11, 16),
            }),
      ),
    );
    if (pend && REQ.gere) {
      const b = botao(t('Confirmar carga'), 'sm', () => confirmarCarga(r));
      if (REQ.eu && String(r.emitida_por || '').toLowerCase() === REQ.eu.toLowerCase()) {
        b.disabled = true;
        b.title = t('Quem emitiu a requisição não confirma a carga (segregação de funções).');
      }
      w.append(b);
    }
  });
  return w;
}

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
  garantirRequisicoes(dPos);

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
      // requisição ao armazém: quantidades a carregar e carregadas; artigos requisitados que ainda não estão na carrinha
      const req = requisicoesDaRota(String(c.rota));
      itens.forEach((it) =>
        Object.assign(it, req.porArtigo[it.codigo] ? { a_carregar: req.porArtigo[it.codigo].a_carregar, carregado: req.porArtigo[it.codigo].carregado } : {}),
      );
      Object.values(req.porArtigo)
        .filter((x) => !itens.some((it) => it.codigo === x.codigo) && okArtigo(x))
        .forEach((x) => itens.push({ ...x, armazem, quantidade: 0 }));
      return {
        req: req.rs,
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
      cols: car.req.length ? [...colsArtigos(), colRequisicao()] : colsArtigos(),
    });
    const hl = sec.querySelector('.sec-h > div');
    if (hl) hl.append(origemCarrinha(car));
    if (hl && car.req.length) hl.append(linhaRequisicoes(car.req));
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
