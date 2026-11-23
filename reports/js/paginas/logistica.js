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
import { SESSAO, atual, podeExportar, podeVer } from '../perfil.js';
import { apiJson } from '../api.js';
import { botao, campo, entrada, janela, msgErro } from '../componentes/janelas.js';
import { diferencaViva, lancamento, quantidadeAtipica } from '../componentes/lancamento.js';
import { marcarPorGravar } from '../componentes/rascunho.js';
import { postJson } from '../servidor.js';
import { DB, el, sum, toast } from '../util.js';
import { exportarExcel } from '../excel.js';
import { R } from './registo.js';
import { campoMotivo } from '../componentes/motivos-carga.js';

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
  { key: 'codigo', label: t('Código'), p: 0 },
  { key: 'produto', label: t('Artigo'), wrap: true, p: 0 },
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
    REQ = {
      data,
      estado: 'ok',
      rotas: (d && d.rotas) || {},
      fechos: (d && d.fechos) || {},
      autoriza: !!(d && d.autoriza),
      gere: !!(d && d.gere),
      eu: (d && d.eu) || '',
      horario: (d && d.horario) || null,
      em: Date.now(),
    };
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
    (b, ft, fechar, d) => {
      const tab = el('table', 'cc-tab');
      const cab = el('tr');
      [t('Código'), t('Artigo'), t('Requisitado'), t('Entregue')].forEach((x, i) => cab.append(el('th', i > 1 ? 'n' : null, x)));
      tab.append(cab);
      const campos = {};
      const motivos = {};
      const linhasLc = [];
      r.linhas.forEach((l) => {
        const tr = el('tr');
        const i = entrada('number', l.quantidade, { min: 0, step: 'any', inputMode: 'decimal' });
        i.setAttribute('aria-label', t('Entregue') + ' · ' + l.artigo);
        campos[l.codigo] = i;
        const td = el('td', 'n');
        // diferença: o motivo é obrigatório (aparece só quando a quantidade muda)
        const m = campoMotivo(t('Motivo') + ' · ' + l.artigo);
        m.w.hidden = true;
        motivos[l.codigo] = m;
        i.addEventListener('input', () => (m.w.hidden = i.value === '' || Number(i.value) === Number(l.quantidade)));
        // diferença viva: ✓ verde se bate com o requisitado, senão a diferença a vermelho
        td.append(i, diferencaViva(i, l.quantidade), m.w);
        tr.append(el('td', 'cc-cod', l.codigo), el('td', null, l.artigo), el('td', 'n', n0(l.quantidade)), td);
        tab.append(tr);
        linhasLc.push({
          tr,
          campos: [i],
          codigo: l.codigo,
          texto: l.artigo,
          atipico: () => quantidadeAtipica(i.value, { unidade: l.unidade, esperado: Number(l.quantidade) }),
        });
      });
      const erro = msgErro();
      const ok = botao(t('Confirmar carga'), null, () => (Object.values(campos).some((i) => i.value === '') ? confirmar() : lc.confirmar(confirmar)));
      // linha activa, Enter para a seguinte, Ctrl+Enter confirma, procurar, rascunho e valores fora do normal
      const lc = lancamento({ tabela: tab, linhas: linhasLc, gravar: ok, progresso: false, chave: 'req-carga-' + r.numero });
      b.append(lc.barra, tab, erro);
      // fechar sem confirmar: o rascunho fica guardado para a próxima vez, mas a página deixa de estar «por gravar»
      d.addEventListener('close', () => marcarPorGravar('req-carga-' + r.numero, false));
      function confirmar() {
        const entregue = {};
        const porque = {};
        for (const [c, i] of Object.entries(campos)) {
          if (i.value === '' || Number(i.value) < 0) {
            erro.hidden = false;
            erro.textContent = t('Indique a quantidade entregue de cada artigo (0 se não foi carregado).');
            return i.focus();
          }
          entregue[c] = Number(i.value);
          if (!motivos[c].w.hidden) {
            const v = motivos[c].valor();
            if (!v || (v.codigo === 'outro' && v.texto.length < 3)) {
              erro.hidden = false;
              erro.textContent = t('Indique o motivo de cada diferença entre o pedido e o carregado.');
              return motivos[c].sel.focus();
            }
            porque[c] = v;
          }
        }
        ok.disabled = true;
        postJson('requisicoes', { accao: 'confirmar_carga', numero: r.numero, entregue, motivos: porque }).then((x) => {
          ok.disabled = false;
          if (!x.ok) {
            erro.hidden = false;
            erro.textContent = t(x.erro || 'Não foi possível gravar.');
            return;
          }
          lc.limparRascunho();
          fechar();
          toast(t('Carga da requisição {n} confirmada.', { n: r.numero }), 4000);
          REQ.em = 0;
          garantirRequisicoes(REQ.data);
        });
      }
      ft.append(botao(t('Cancelar'), 'ghost', fechar), ok);
    },
  );
}
const ESTADO_FECHO = {
  por_contar: 'por contar',
  por_validar: 'por validar',
  por_aprovar: 'por aprovar',
  por_fechar: 'por fechar',
  por_verificar: 'sem dados do Pinguim BO',
};
const diasTxt = (ps) => ps.map((p) => dl(p.data) + ' (' + t(ESTADO_FECHO[p.estado] || p.estado) + ')').join(', ');

/**
 * Autorização de carga antes do fecho (excepção): quem fecha as conciliações indica o motivo. A carga fica fora da
 * contagem dos dias por fechar e é confirmada por outra pessoa.
 */
function autorizarCarga(r, pendentes) {
  janela(
    t('Autorizar a carga antes do fecho · {n}', { n: r.numero }),
    t(
      'A carrinha ainda não tem o fecho de {d}. Com a autorização, a carga pode ser confirmada por outra pessoa e não entra na contagem desses dias (soma-se ao que se conta). É uma excepção registada.',
      { d: diasTxt(pendentes) },
    ),
    (b, ft, fechar) => {
      const motivo = el('textarea', 'inp');
      motivo.rows = 3;
      motivo.maxLength = 300;
      const erro = msgErro();
      b.append(campo(t('Motivo (obrigatório)'), motivo), erro);
      const ok = botao(t('Autorizar'), null, () => {
        ok.disabled = true;
        postJson('requisicoes', { accao: 'autorizar_carga', numero: r.numero, motivo: motivo.value.trim() }).then((x) => {
          ok.disabled = false;
          if (!x.ok) {
            erro.hidden = false;
            erro.textContent = t(x.erro || 'Não foi possível gravar.');
            return;
          }
          fechar();
          toast(t('Carga da requisição {n} autorizada antes do fecho.', { n: r.numero }), 4000);
          REQ.em = 0;
          garantirRequisicoes(REQ.data);
        });
      });
      ft.append(botao(t('Cancelar'), 'ghost', fechar), ok);
      setTimeout(() => motivo.focus(), 50);
    },
  );
}

/** Linha por baixo do título da carrinha: as requisições da rota, o fecho por fazer e o botão de confirmar a carga. */
function linhaRequisicoes(rs, rota) {
  const w = el('div', 'req-linha');
  const fecho = (REQ.fechos || {})[rota];
  rs.forEach((r) => {
    const pend = r.estado === 'emitida';
    const exc = r.excepcao_fecho;
    const cobertos = new Set((exc && exc.dias) || []);
    const falta = pend && fecho ? fecho.pendentes.filter((p) => !cobertos.has(p.data)) : [];
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
    if (falta.length) w.append(pill(t('Fecho por fazer: {d}', { d: diasTxt(falta) }), 'p-bad'));
    else if (pend && exc) w.append(pill(t('Carga autorizada antes do fecho por {p}', { p: exc.por }), 'p-warn'));
    if (pend && REQ.gere) {
      const b = botao(t('Confirmar carga'), 'sm', () => confirmarCarga(r));
      const eu = String(REQ.eu || '').toLowerCase();
      if (eu && String(r.emitida_por || '').toLowerCase() === eu) {
        b.disabled = true;
        b.title = t('Quem emitiu a requisição não confirma a carga (segregação de funções).');
      } else if (falta.length) {
        b.disabled = true;
        b.title = t('Só se carrega depois de fechada a conciliação da carrinha dos dias anteriores.');
      } else if (exc && eu && String(exc.por || '').toLowerCase() === eu) {
        b.disabled = true;
        b.title = t('Quem autorizou a carga antes do fecho não a confirma (segregação de funções).');
      }
      w.append(b);
    }
    if (falta.length && REQ.autoriza) w.append(botao(t('Autorizar a carga antes do fecho'), 'ghost sm', () => autorizarCarga(r, falta)));
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
    const como =
      a.como === 'confirmado'
        ? t('confirmado')
        : a.como === 'automatico'
          ? t('confirmado automaticamente: código da rota')
          : a.como === 'codigo'
            ? t('pelo código')
            : t('pelo nome');
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
  if (ori.length > 1) box.append(el('p', 'aviso', t('Esta carrinha tem {n} armazéns no Pinguim BO: confirme qual é o dela.', { n: ori.length })));
  return box;
}

/**
 * Exportar o stock de TODOS os armazéns de uma só vez (centrais e carrinhas), sem os filtros do ecrã, num só Excel:
 * resumo por armazém, detalhe por artigo e armazém e matriz artigo × armazém (uma coluna por armazém).
 */
function exportarStockTodos(dPos) {
  const S = DB.stock || {};
  const linhas = [
    ...(S.linhas_armazens || []).map((l) => ({
      tipo: t('Central'),
      cod_armazem: String(l.cod_armazem || ''),
      armazem: l.armazem || '',
      rota: '',
      codigo: l.codigo || l.codigo_produto || '',
      produto: l.produto || l.artigo || '',
      quantidade: +l.quantidade || 0,
      data: l.data,
    })),
    ...(S.linhas_carrinhas || []).map((l) => ({
      tipo: t('Carrinha'),
      cod_armazem: String(l.cod_armazem || ''),
      armazem: l.armazem || t('Carrinha {r}', { r: l.rota }),
      rota: String(l.rota || ''),
      codigo: l.codigo || l.codigo_produto || '',
      produto: l.produto || l.artigo || '',
      quantidade: +l.quantidade || 0,
      data: l.data,
    })),
  ].filter((l) => (!l.data || l.data === dPos) && l.quantidade !== 0);
  if (!linhas.length) return toast(t('Sem stock para exportar.'), 5000);
  const nomeArm = (l) => (l.rota ? l.rota + ' · ' : '') + l.armazem;
  const resumo = new Map();
  linhas.forEach((l) => {
    const k = l.tipo + '|' + nomeArm(l);
    if (!resumo.has(k)) resumo.set(k, { tipo: l.tipo, cod_armazem: l.cod_armazem, rota: l.rota, armazem: l.armazem, artigos: 0, quantidade: 0 });
    const x = resumo.get(k);
    x.artigos++;
    x.quantidade += l.quantidade;
  });
  // matriz: uma linha por artigo, uma coluna por armazém, e o total
  const arms = [...new Set(linhas.map(nomeArm))].sort((a, b) => a.localeCompare(b, 'pt', { numeric: true }));
  const porArt = new Map();
  linhas.forEach((l) => {
    if (!porArt.has(l.codigo)) porArt.set(l.codigo, { codigo: l.codigo, produto: l.produto, total: 0 });
    const x = porArt.get(l.codigo);
    x['a' + arms.indexOf(nomeArm(l))] = (x['a' + arms.indexOf(nomeArm(l))] || 0) + l.quantidade;
    x.total += l.quantidade;
  });
  const nota = t('Posição de stock em {d}, todos os armazéns (centrais e carrinhas), sem filtros.', { d: dl(dPos) });
  exportarExcel(
    [
      {
        pagina: 'Stock de todos os armazéns',
        grupo: 'Logística',
        kpis: [],
        tabelas: [
          {
            id: 'stock-todos-resumo',
            titulo: t('Resumo por armazém'),
            nota,
            total: true,
            rows: [...resumo.values()],
            cols: [
              { key: 'tipo', label: t('Tipo') },
              { key: 'cod_armazem', label: t('Código') },
              { key: 'rota', label: t('Rota') },
              { key: 'armazem', label: t('Armazém') },
              { key: 'artigos', label: t('Artigos'), type: 'n', sum: true },
              { key: 'quantidade', label: t('Stock final'), type: 'n', sum: true },
            ],
          },
          {
            id: 'stock-todos-detalhe',
            titulo: t('Stock por artigo e armazém'),
            nota,
            total: true,
            rows: linhas,
            cols: [
              { key: 'tipo', label: t('Tipo') },
              { key: 'cod_armazem', label: t('Código do armazém') },
              { key: 'rota', label: t('Rota') },
              { key: 'armazem', label: t('Armazém') },
              { key: 'codigo', label: t('Código') },
              { key: 'produto', label: t('Artigo') },
              { key: 'quantidade', label: t('Quantidade'), type: 'n', sum: true },
            ],
          },
          {
            id: 'stock-todos-matriz',
            titulo: t('Matriz artigo × armazém'),
            nota,
            total: true,
            rows: [...porArt.values()].sort((a, b) => b.total - a.total),
            cols: [
              { key: 'codigo', label: t('Código') },
              { key: 'produto', label: t('Artigo') },
              ...arms.map((a, i) => ({ key: 'a' + i, label: a, type: 'n', sum: true })),
              { key: 'total', label: t('Total'), type: 'n', sum: true },
            ],
          },
        ],
      },
    ],
    t('Stock de todos os armazéns') + ' ' + dl(dPos),
  );
}
// botão no topo das páginas de stock (quem pode exportar a página)
function botaoStockTodos(pg, dPos) {
  if (!podeExportar(atual)) return;
  const b = el('button', 'btn');
  b.type = 'button';
  b.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>'; // nosemgrep: insecure-innerhtml -- SVG estático
  b.append(t('Exportar o stock de todos os armazéns'));
  b.title = t('Um só Excel com o stock de todos os armazéns centrais e carrinhas: resumo, detalhe e matriz artigo × armazém');
  b.addEventListener('click', () => exportarStockTodos(dPos));
  const w = el('div', 'stock-exportar');
  w.append(b);
  pg.append(w);
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
  botaoStockTodos(pg, dPos);
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
    if (hl && car.req.length) hl.append(linhaRequisicoes(car.req, car.rota));
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
  botaoStockTodos(pg, dPos);
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
