// Inteligência de negócio › Análises de gestão: as análises das melhores empresas de bens de consumo, calculadas no
// servidor (nucleo/servicos/analises-gestao.php) com as vendas do Pinguim BO até ontem e o stock de dbo.GET_STOCK:
// RFM, coortes de retenção, valor do cliente (CLV), ponte preço-volume-mix, matriz ABC-XYZ, cesto de compras,
// concentração (HHI), produtividade face ao quartil superior e cobertura de stock. Clientes, artigos e rotas ligam às
// fichas (drill-down).
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { secBox, tabela, tiles } from '../componentes/blocos.js';
import { COR, cartaoGrafico, grafBarras, kzc } from '../componentes/graficos.js';
import { celulaLigada } from '../componentes/ligacoes.js';
import { dl, kz, n0, n1, pct } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { atual } from '../perfil.js';
import { el } from '../util.js';
import { R } from './registo.js';

let A = null;
let aLer = false;
const ID = 'analises-gestao';

const SEG = {
  campeoes: [() => t('Campeões'), () => t('Proteger: prioridade na carga e nas visitas, lançamentos primeiro.'), 'p-ok'],
  leais: [() => t('Leais'), () => t('Aumentar o cesto: propor os artigos que ainda não compram.'), 'p-ok'],
  promissores: [() => t('Promissores'), () => t('Converter: visita de seguimento e encomenda sugerida.'), 'p-ok'],
  atencao: [() => t('Precisam de atenção'), () => t('Rever o dia de visita e a encomenda sugerida.'), 'p-warn'],
  nao_perder: [() => t('Não pode perder'), () => t('Eram dos melhores e deixaram de comprar: visita do coordenador esta semana.'), 'p-bad'],
  em_risco: [() => t('Em risco'), () => t('A afastar-se: contacto do vendedor e condições.'), 'p-bad'],
  hibernar: [() => t('A hibernar'), () => t('Visitar quando houver lugar livre na rota.'), 'p-warn'],
  perdidos: [() => t('Perdidos'), () => t('Confirmar se o ponto de venda ainda existe; senão, retirar da rota.'), 'p-bad'],
};
const POLITICA = {
  AX: () => t('Stock baixo e reposição automática frequente.'),
  AY: () => t('Stock de segurança moderado; rever a previsão todas as semanas.'),
  AZ: () => t('Reposição por encomenda e acompanhamento de perto.'),
  BX: () => t('Reposição automática com stock de segurança normal.'),
  BY: () => t('Stock de segurança normal; rever todos os meses.'),
  BZ: () => t('Reduzir o stock; carregar por encomenda.'),
  CX: () => t('Reposição automática com lotes maiores.'),
  CY: () => t('Lotes maiores, pouco stock de segurança.'),
  CZ: () => t('Candidato a sair do sortido ou a venda só por encomenda.'),
};
const ESTADO = {
  rotura: [() => t('Rotura'), 'p-bad'],
  critico: [() => t('Crítico'), 'p-warn'],
  normal: [() => t('Normal'), 'p-ok'],
  excesso: [() => t('Excesso'), 'p-warn'],
  parado: [() => t('Parado'), 'p-bad'],
};
const ALAVANCA = {
  referencia: () => t('referência'),
  valor: () => t('valor por compra (cesto)'),
  cobertura: () => t('mais compras por dia (cobertura)'),
};
const NIVEL = { baixa: () => t('baixa'), moderada: () => t('moderada'), alta: () => t('alta') };
const pillDe = (txt, cls) => el('span', 'pill ' + cls, txt);
// cada análise é um separador da página (#analises-gestao/<chave>): os mosaicos do topo abrem o separador
const irPara = (k) => {
  location.hash = '#' + ID + '/' + k;
};
const rotaCol = {
  key: 'rota',
  label: t('Rota'),
  p: 0,
  render: (v, r) => celulaLigada('rota', v, r, v + (r.promotor ? ' · ' + r.promotor : '')),
  xl: (r) => r.rota,
};

function ler(fresco) {
  if (aLer) return;
  aLer = true;
  apiJson('analises' + (fresco ? '?fresco=1' : '')).then((d) => {
    aLer = false;
    A = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os dados.') };
    if (atual === ID) draw();
  });
}

R[ID] = (pg) => {
  if (!A) {
    ler();
    pg.append(el('p', 'note', t('A calcular as análises com as vendas do Pinguim BO…')));
    return;
  }
  if (A.falhou) {
    pg.append(el('p', 'erro', A.falhou));
    return;
  }
  pg.append(el('p', 'note', t('Dados até {d} (dias completos). Clientes, artigos e rotas abrem a ficha.', { d: dl(A.ate) })));
  const rfm = A.rfm,
    pvm = A.pvm.total,
    prod = A.produtividade,
    seg = Object.fromEntries(rfm.segmentos.map((s) => [s.segmento, s]));
  pg.append(
    tiles(
      [
        {
          l: t('Valor anual em risco'),
          v: kz(rfm.valor_anual_em_risco),
          s: t('{n} clientes «Não pode perder» e «Em risco»', { n: n0(seg.nao_perder.clientes + seg.em_risco.clientes) }),
          tone: rfm.valor_anual_em_risco > 0 ? 'bad' : null,
          abrir: () => irPara('rfm'),
        },
        {
          l: t('Variação do mês'),
          v: kz(pvm.actual - pvm.anterior),
          s: t('preço {p} · volume {v} · mix {m}', { p: kzc(pvm.preco), v: kzc(pvm.volume), m: kzc(pvm.mix) }),
          tone: pvm.actual >= pvm.anterior ? 'ok' : 'bad',
          abrir: () => irPara('pvm'),
        },
        {
          l: t('Potencial das rotas'),
          v: kz(prod.potencial_total),
          s: t('em 4 semanas, se cada rota chegar ao quartil superior'),
          abrir: () => irPara('produtividade'),
        },
        {
          l: t('Concentração nos clientes'),
          v: A.concentracao.clientes.hhi != null ? n0(A.concentracao.clientes.hhi) : '—',
          s: A.concentracao.clientes.nivel
            ? t('HHI {n} · 20% dos clientes = {p} das vendas', { n: NIVEL[A.concentracao.clientes.nivel](), p: pct(A.concentracao.clientes.top20pct) })
            : '—',
          abrir: () => irPara('concentracao'),
        },
        A.cobertura
          ? {
              l: t('Roturas de stock'),
              v: n0(A.cobertura.resumo.rotura),
              s: t('{c} críticos · stock parado {v}', { c: n0(A.cobertura.resumo.critico), v: kz(A.cobertura.capital.parado) }),
              tone: A.cobertura.resumo.rotura ? 'bad' : 'ok',
              abrir: () => irPara('stock'),
            }
          : null,
      ].filter(Boolean),
    ),
  );
  const acc = el('p', 'note');
  const b = el('button', 'linkbtn', t('Recalcular agora'));
  b.type = 'button';
  b.onclick = () => {
    A = null;
    ler(true);
    draw();
  };
  acc.append(b);
  pg.append(acc);

  // um grupo por análise (separadores da página), com as suas tabelas juntas
  let G = pg;
  const grupo = (chave, titulo) => {
    G = el('div', 'rel-grupo');
    G.dataset.chave = chave;
    G.dataset.titulo = titulo;
    G.dataset.junto = '1';
    pg.append(G);
  };
  grupo('rfm', t('Clientes (RFM)'));
  // 1. RFM
  G.append(
    tabela({
      id: 'ag-rfm',
      titulo: t('Segmentação RFM dos clientes'),
      nota: t('Recência, frequência e valor dos últimos {n} dias, em quintis. A acção recomendada é a prática das empresas de referência.', {
        n: rfm.dias,
      }),
      rows: rfm.segmentos.map((s) => ({ ...s, nome: SEG[s.segmento][0](), accao: SEG[s.segmento][1]() })),
      sort: 'valor',
      dir: -1,
      cols: [
        { key: 'nome', label: t('Segmento'), p: 0, render: (v, r) => pillDe(v, SEG[r.segmento][2]) },
        { key: 'clientes', label: t('Clientes'), type: 'n', sum: true },
        { key: 'valor', label: t('Valor'), type: 'kz', sum: true },
        { key: 'pct_valor', label: t('% do valor'), type: 'pct' },
        { key: 'recencia', label: t('Dias desde a última compra'), type: 'n' },
        { key: 'accao', label: t('Acção recomendada') },
      ],
    }),
  );
  G.append(
    tabela({
      id: 'ag-rfm-clientes',
      titulo: t('Clientes por segmento'),
      nota: t('Os {n} clientes de maior valor. Filtre pelo segmento para ter a lista de trabalho de cada vendedor.', { n: n0(rfm.lista.length) }),
      rows: rfm.lista.map((x) => ({ ...x, segmento_nome: SEG[x.segmento][0]() })),
      sort: 'valor',
      dir: -1,
      cols: [
        { key: 'cliente', label: t('Cliente'), p: 0 },
        rotaCol,
        { key: 'segmento_nome', label: t('Segmento'), render: (v, r) => pillDe(v, SEG[r.segmento][2]) },
        { key: 'rfm', label: 'RFM' },
        { key: 'recencia', label: t('Dias desde a última compra'), type: 'n' },
        { key: 'compras', label: t('Compras'), type: 'n' },
        { key: 'valor', label: t('Valor'), type: 'kz', sum: true },
      ],
    }),
  );

  grupo('retencao', t('Retenção e valor do cliente'));
  // 2. Coortes de retenção
  const co = A.coortes;
  if (co.coortes.length) {
    const tab = el('table', 'ag-coortes');
    const h = el('tr');
    h.append(el('th', null, t('Primeira compra')), el('th', 'n', t('Clientes')));
    const maxK = Math.max(...co.coortes.map((c) => c.retencao.length));
    for (let k = 0; k < maxK; k++) h.append(el('th', 'n', k ? t('Mês {k}', { k }) : t('Início')));
    tab.append(h);
    co.coortes.forEach((c) => {
      const tr = el('tr');
      tr.append(el('td', null, c.coorte), el('td', 'n', n0(c.clientes)));
      c.retencao.forEach((v, k) => {
        const td = el('td', 'n ag-calor', pct(v));
        td.style.setProperty('--a', String(Math.max(0.06, v)));
        if (co.ultimo_incompleto && k === c.retencao.length - 1) td.title = t('mês em curso');
        tr.append(td);
      });
      tab.append(tr);
    });
    const med = Object.entries(co.retencao_media);
    const w = el('div', 'tw');
    w.append(tab);
    const s = secBox(
      t('Coortes de retenção'),
      med.length
        ? t('De cada 100 clientes novos, {a} voltam a comprar no mês seguinte e {b} ao fim de {k} meses.', {
            a: n0(med[0][1] * 100),
            b: n0(med[med.length - 1][1] * 100),
            k: med[med.length - 1][0],
          })
        : '',
      w,
    );
    s.id = 's-ag-coortes';
    G.append(s);
  }

  // 3. CLV
  G.append(
    tabela({
      id: 'ag-clv',
      titulo: t('Valor do cliente (CLV) por rota'),
      nota: t('Valor mensal por cliente ÷ perda mensal de clientes (média dos últimos 3 meses completos), até 60 meses.'),
      rows: A.clv,
      sort: 'valor_carteira',
      dir: -1,
      cols: [
        rotaCol,
        { key: 'clientes', label: t('Clientes'), type: 'n', sum: true },
        { key: 'valor_mensal_cliente', label: t('Valor mensal por cliente'), type: 'kz' },
        { key: 'perda_mensal', label: t('Perda mensal de clientes'), type: 'pct' },
        { key: 'vida_meses', label: t('Vida esperada (meses)'), type: 'n' },
        { key: 'clv', label: t('Valor do cliente'), type: 'kz' },
        { key: 'valor_carteira', label: t('Valor da carteira'), type: 'kz', sum: true },
      ],
    }),
  );

  grupo('pvm', t('Preço-volume-mix'));
  // 4. Ponte preço-volume-mix
  const P = A.pvm;
  const passos = [
    { rot: t('Mês anterior'), valor: pvm.anterior, cor: COR.neutro },
    { rot: t('Preço'), valor: pvm.preco },
    { rot: t('Volume'), valor: pvm.volume },
    { rot: t('Mix'), valor: pvm.mix },
    { rot: t('Artigos novos'), valor: pvm.novos },
    { rot: t('Artigos perdidos'), valor: pvm.perdidos },
    { rot: t('Este mês'), valor: pvm.actual, cor: COR.s1 },
  ];
  const cg = cartaoGrafico({
    titulo: t('Ponte preço-volume-mix'),
    sub: t('{a} a {b} face a {c} a {d}', { a: dl(P.de), b: dl(P.ate), c: dl(P.anterior_de), d: dl(P.anterior_ate) }),
    desenhar: (w, hh) =>
      grafBarras(w, {
        altura: hh,
        itens: passos.map((x) => ({
          rot: x.rot,
          valor: Math.abs(x.valor),
          cor: x.cor || (x.valor >= 0 ? COR.s3 : COR.a4),
          linhas: [[kz(x.valor), '']],
        })),
      }),
    colunas: [[t('Componente')], [t('Valor'), 1]],
    linhas: passos.map((x) => [x.rot, kz(x.valor)]),
    // preço: os artigos com maior efeito do preço; o resto: a decomposição por rota
    abrirLinha: (i) => {
      const s = document.getElementById(i === 1 ? 's-ag-pvm-artigos' : 's-ag-pvm-rotas');
      if (s) s.scrollIntoView({ behavior: 'smooth', block: 'start' });
    },
  });
  cg.id = 's-ag-pvm';
  G.append(cg);
  G.append(
    tabela({
      id: 'ag-pvm-rotas',
      titulo: t('Preço, volume e mix por rota'),
      rows: P.rotas,
      sort: 'actual',
      dir: -1,
      cols: [
        rotaCol,
        { key: 'anterior', label: t('Mês anterior'), type: 'kz', sum: true },
        { key: 'actual', label: t('Este mês'), type: 'kz', sum: true },
        { key: 'preco', label: t('Preço'), type: 'kz', sum: true },
        { key: 'volume', label: t('Volume'), type: 'kz', sum: true },
        { key: 'mix', label: t('Mix'), type: 'kz', sum: true },
        { key: 'novos', label: t('Artigos novos'), type: 'kz', sum: true },
        { key: 'perdidos', label: t('Artigos perdidos'), type: 'kz', sum: true },
      ],
    }),
  );
  G.append(
    tabela({
      id: 'ag-pvm-artigos',
      titulo: t('Artigos com maior efeito do preço'),
      rows: P.artigos.map((x) => ({ ...x, artigo: x.nome })),
      cols: [
        { key: 'artigo', label: t('Artigo'), p: 0 },
        { key: 'preco_anterior', label: t('Preço anterior'), type: 'kz' },
        { key: 'preco_actual', label: t('Preço actual'), type: 'kz' },
        { key: 'variacao_preco', label: t('Variação do preço'), type: 'pct' },
        { key: 'efeito_preco', label: t('Efeito do preço'), type: 'kz', sum: true },
        { key: 'efeito_quantidade', label: t('Efeito da quantidade'), type: 'kz', sum: true },
      ],
    }),
  );

  grupo('abc-xyz', 'ABC-XYZ');
  // 5. ABC-XYZ
  const X = A.abc_xyz;
  const m = Object.fromEntries(X.matriz.map((c) => [c.celula, c]));
  const grelha = el('table', 'ag-matriz');
  const hx = el('tr');
  hx.append(el('th'), el('th', null, t('X · regular')), el('th', null, t('Y · variável')), el('th', null, t('Z · irregular')));
  grelha.append(hx);
  ['A', 'B', 'C'].forEach((a) => {
    const tr = el('tr');
    tr.append(el('th', null, a + ' · ' + (a === 'A' ? t('80% do valor') : a === 'B' ? t('15% seguintes') : t('5% finais'))));
    ['X', 'Y', 'Z'].forEach((z) => {
      const c = m[a + z];
      const td = el('td', 'ag-cel ag-' + a + z);
      td.append(
        el('strong', null, a + z),
        el('span', null, t('{n} artigos · {p}', { n: n0(c.artigos), p: pct(c.pct_valor) })),
        el('small', null, POLITICA[a + z]()),
      );
      tr.append(td);
    });
    grelha.append(tr);
  });
  const gw = el('div', 'tw');
  gw.append(grelha);
  const sx = secBox(
    t('Matriz ABC-XYZ dos artigos'),
    t('Valor de {n} semanas (ABC) × regularidade da procura semanal (XYZ), com a política de stock de cada célula.', { n: X.semanas }),
    gw,
  );
  sx.id = 's-ag-abcxyz';
  G.append(sx);
  G.append(
    tabela({
      id: 'ag-abcxyz-artigos',
      titulo: t('Artigos por classe'),
      rows: X.artigos.map((x) => ({ ...x, politica: POLITICA[x.celula]() })),
      sort: 'valor',
      dir: -1,
      cols: [
        { key: 'artigo', label: t('Artigo'), p: 0 },
        { key: 'celula', label: t('Classe') },
        { key: 'valor', label: t('Valor'), type: 'kz', sum: true },
        { key: 'pct_valor', label: t('% do valor'), type: 'pct' },
        { key: 'media_semanal', label: t('Média semanal'), type: 'n' },
        { key: 'cv', label: t('Variabilidade'), type: 'n', render: (v) => (v == null ? '—' : n1(v * 100) + '%') },
        { key: 'semanas_com_venda', label: t('Semanas com venda'), type: 'n' },
        { key: 'politica', label: t('Política de stock') },
      ],
    }),
  );

  grupo('cesto', t('Cesto de compras'));
  // 6. Cesto de compras
  const C = A.cesto;
  G.append(
    tabela({
      id: 'ag-cesto',
      titulo: t('Cesto de compras: artigos que se vendem juntos'),
      nota: C.artigos_por_cesto
        ? t('{n} compras em 90 dias, {a} artigos por compra. «Clientes a propor» compram o primeiro com regularidade e nunca compraram o segundo.', {
            n: n0(C.cestos),
            a: n1(C.artigos_por_cesto),
          })
        : t('Ainda não há compras suficientes para as regras.'),
      rows: C.regras,
      cols: [
        { key: 'se_nome', label: t('Quem compra'), p: 0, render: (v, r) => celulaLigada('artigo', v, { codigo: r.se, artigo: v }) },
        { key: 'entao_nome', label: t('Também compra'), render: (v, r) => celulaLigada('artigo', v, { codigo: r.entao, artigo: v }) },
        { key: 'confianca', label: t('Confiança'), type: 'pct' },
        { key: 'lift', label: t('Lift'), type: 'n', render: (v) => n1(v) + '×' },
        { key: 'suporte', label: t('Suporte'), type: 'pct' },
        { key: 'clientes_oportunidade', label: t('Clientes a propor'), type: 'n', sum: true },
      ],
    }),
  );

  grupo('concentracao', t('Concentração'));
  // 7. Concentração
  const K = A.concentracao;
  const linhaHHI = (nome, x) => ({
    dim: nome,
    n: x.n,
    hhi: x.hhi,
    nivel: x.nivel ? NIVEL[x.nivel]() : '—',
    maior: x.maior,
    top10: x.top10,
    top20pct: x.top20pct,
  });
  const sc = tabela({
    id: 'ag-conc',
    titulo: t('Concentração das vendas (90 dias)'),
    nota: t('Índice Herfindahl-Hirschman: até 1500 baixa, 1500 a 2500 moderada, acima de 2500 alta dependência.'),
    rows: [linhaHHI(t('Clientes'), K.clientes), linhaHHI(t('Artigos'), K.artigos), linhaHHI(t('Rotas'), K.rotas)],
    cols: [
      { key: 'dim', label: t('Dimensão'), p: 0 },
      { key: 'n', label: t('Quantos'), type: 'n' },
      { key: 'hhi', label: 'HHI', type: 'n' },
      { key: 'nivel', label: t('Concentração') },
      { key: 'maior', label: t('Maior'), type: 'pct' },
      { key: 'top10', label: t('10 maiores'), type: 'pct' },
      { key: 'top20pct', label: t('20% maiores'), type: 'pct' },
    ],
  });
  G.append(sc);
  G.append(
    tabela({
      id: 'ag-conc-rotas',
      titulo: t('Dependência dos maiores clientes, por rota'),
      rows: K.por_rota,
      sort: 'top5',
      dir: -1,
      cols: [
        rotaCol,
        { key: 'clientes', label: t('Clientes'), type: 'n' },
        { key: 'maior', label: t('Maior cliente'), type: 'pct' },
        { key: 'top5', label: t('5 maiores clientes'), type: 'pct' },
        { key: 'hhi', label: 'HHI', type: 'n' },
      ],
    }),
  );

  grupo('produtividade', t('Produtividade das rotas'));
  // 8. Produtividade
  G.append(
    tabela({
      id: 'ag-prod',
      titulo: t('Produtividade das rotas face à melhor prática'),
      nota: t('Últimas 4 semanas. Referência (quartil superior): {c} compras por dia e {v} por compra.', {
        c: n1(prod.referencia.compras_dia),
        v: kz(prod.referencia.valor_compra),
      }),
      rows: prod.rotas,
      sort: 'potencial',
      dir: -1,
      cols: [
        rotaCol,
        { key: 'dias', label: t('Dias de venda'), type: 'n' },
        { key: 'vendas', label: t('Vendas'), type: 'kz', sum: true },
        { key: 'compras_dia', label: t('Compras por dia'), type: 'n' },
        { key: 'valor_compra', label: t('Valor por compra'), type: 'kz' },
        { key: 'linhas_compra', label: t('Artigos por compra'), type: 'n' },
        { key: 'frequencia', label: t('Compras por cliente'), type: 'n' },
        { key: 'indice', label: t('Índice face à mediana'), type: 'pct' },
        { key: 'potencial', label: t('Potencial'), type: 'kz', sum: true },
        {
          key: 'alavanca',
          label: t('Alavanca'),
          render: (v) => (ALAVANCA[v] ? ALAVANCA[v]() : v),
          xl: (r) => (ALAVANCA[r.alavanca] ? ALAVANCA[r.alavanca]() : r.alavanca),
        },
      ],
    }),
  );

  grupo('stock', t('Cobertura de stock'));
  // 9. Cobertura de stock
  if (A.cobertura) {
    const S = A.cobertura;
    G.append(
      tabela({
        id: 'ag-stock',
        titulo: t('Cobertura de stock'),
        nota: t(
          'Dias de venda que o stock dos armazéns e das carrinhas cobre ({d} dias de venda nas últimas 4 semanas). Capital ao preço médio sem IVA: total {t}, excesso {e}, parado {p}.',
          {
            d: S.dias_venda,
            t: kz(S.capital.total),
            e: kz(S.capital.excesso),
            p: kz(S.capital.parado),
          },
        ),
        rows: S.artigos,
        cols: [
          { key: 'artigo', label: t('Artigo'), p: 0 },
          { key: 'abc', label: t('Classe') },
          { key: 'estado', label: t('Estado'), render: (v) => pillDe(ESTADO[v][0](), ESTADO[v][1]), xl: (r) => ESTADO[r.estado][0]() },
          { key: 'armazem', label: t('Armazéns'), type: 'n' },
          { key: 'carrinhas', label: t('Carrinhas'), type: 'n' },
          { key: 'venda_dia', label: t('Venda por dia'), type: 'n' },
          { key: 'cobertura_dias', label: t('Cobertura (dias)'), type: 'n' },
          { key: 'capital', label: t('Capital'), type: 'kz', sum: true },
        ],
      }),
    );
  } else if (A.com_stock) G.append(el('p', 'aviso', t('Sem ligação ao stock: falta a cobertura de stock.')));
};
