// Logística › Stock › Extracto de movimentos: todas as entradas e saídas de artigos, documento a documento, em todos
// os armazéns e carrinhas, no período escolhido (até 366 dias), lidas do Pinguim BO (servidor: nucleo/api/v1/movimentos.php).
//   - filtros: armazém ou carrinha, artigo (código ou nome) e tipo de documento (vendas, guias, devoluções, inventários…);
//   - com um armazém e um artigo, o extracto mostra o saldo, como um extracto bancário: o stock no fim do dia anterior
//     e o saldo depois de cada movimento;
//   - com um artigo (código), o balanço de cada armazém: stock inicial + entradas − saídas = stock final, ao lado do
//     stock do próprio Pinguim BO (confere ✓ ou mostra a diferença);
//   - resumo por artigo (entradas, saídas e líquido) e a lista de movimentos, com exportação para Excel;
//   - com um artigo escolhido, a rastreabilidade: o percurso em fluxo animado e o detalhe de cada documento
//     (componentes/rastreio.js).
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { secBox, tabela, tiles } from '../componentes/blocos.js';
import { botao } from '../componentes/janelas.js';
import { seccaoRastreio } from '../componentes/rastreio.js';
import { dl, n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { atual } from '../perfil.js';
import { st } from '../periodo.js';
import { el } from '../util.js';
import { R } from './registo.js';

const MAX = 366;
// natureza de cada movimento: vendas no Pinguim BO (FR, FA − NC) e vendas SAP (SA sem guia) são as saídas efectivas;
// a saída e a entrada gravadas ao mesmo tempo, com a mesma quantidade, são uma transferência entre armazéns
const NATUREZAS = {
  venda: 'Vendas no Pinguim BO (FR, FA)',
  devolucao: 'Notas de crédito (NC): abatem às vendas',
  venda_sap: 'Vendas SAP (saída de armazém sem guia)',
  transf_enviada: 'Transferência enviada para outro armazém',
  transf_recebida: 'Transferência recebida de outro armazém',
  guia_anulada: 'Saída com a guia anulada (não entrou noutro armazém)',
  entrada: 'Compras e outras entradas',
  outra_saida: 'Outra saída (quebras, consumos)',
  acerto: 'Acerto de inventário',
};
const CURTA = {
  venda: 'Venda',
  devolucao: 'Nota de crédito',
  venda_sap: 'Venda SAP',
  transf_enviada: 'Transferência enviada',
  transf_recebida: 'Transferência recebida',
  guia_anulada: 'Guia anulada',
  entrada: 'Compra',
  outra_saida: 'Outra saída',
  acerto: 'Acerto de inventário',
};
const dias = (a, b) => Math.round((Date.parse(b + 'T12:00:00Z') - Date.parse(a + 'T12:00:00Z')) / 864e5) + 1;
const F = { armazem: '', artigo: '', tipo: '' };
let D = null;
let chave = '';
let aLer = false;
const q3 = (v) => (v ? n0(Math.round(v * 1000) / 1000) : '');

function ler(de, ate) {
  if (aLer) return;
  aLer = true;
  chave = [de, ate, F.armazem, F.artigo, F.tipo].join('|');
  const qs = new URLSearchParams({ de, ate, armazem: F.armazem, artigo: F.artigo, tipo: F.tipo });
  apiJson('movimentos?' + qs.toString()).then((d) => {
    aLer = false;
    D = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os movimentos.') };
    if (atual === 'stock-movimentos') draw();
  });
}

function filtros(de, ate) {
  const f = el('form', 'mov-filtros');
  const arm = el('select');
  arm.setAttribute('aria-label', t('Armazém ou carrinha'));
  arm.append(new Option(t('Todos os armazéns e carrinhas'), ''));
  ((D && D.armazens) || []).forEach((a) => arm.append(new Option(a.nome ? a.codigo + ' · ' + a.nome : a.codigo, a.codigo)));
  if (F.armazem && ![...arm.options].some((o) => o.value === F.armazem)) arm.append(new Option(F.armazem, F.armazem));
  arm.value = F.armazem;
  const art = el('input');
  art.type = 'search';
  art.placeholder = t('Artigo (código ou nome)');
  art.setAttribute('aria-label', t('Artigo (código ou nome)'));
  art.value = F.artigo;
  const tip = el('select');
  tip.setAttribute('aria-label', t('Tipo de documento'));
  tip.append(new Option(t('Todos os documentos'), ''));
  const tipos = new Map();
  ((D && D.linhas) || []).forEach((l) => tipos.set(l.tipo, l.tipo_nome));
  if (F.tipo && !tipos.has(F.tipo)) tipos.set(F.tipo, '');
  [...tipos.entries()].sort().forEach(([k, n]) => tip.append(new Option(n ? k + ' · ' + n : k, k)));
  tip.value = F.tipo;
  const aplicar = () => {
    F.armazem = arm.value;
    F.artigo = art.value.trim();
    F.tipo = tip.value;
    D = null;
    ler(de, ate);
    draw();
  };
  f.addEventListener('submit', (e) => {
    e.preventDefault();
    aplicar();
  });
  arm.addEventListener('change', aplicar);
  tip.addEventListener('change', aplicar);
  const b = botao(t('Procurar'), 'sm');
  b.type = 'submit';
  f.append(arm, art, tip, b);
  return f;
}

R['stock-movimentos'] = (pg) => {
  // vindo da posição de um artigo nas páginas de stock («Ver movimentos»): abre já filtrado por esse artigo
  try {
    const pedido = JSON.parse(sessionStorage.getItem('vm-mov-pedido') || 'null');
    if (pedido) {
      sessionStorage.removeItem('vm-mov-pedido');
      F.artigo = String(pedido.artigo || '');
      F.armazem = String(pedido.armazem || '');
      F.tipo = '';
    }
  } catch (e) {
    /* sem armazenamento */
  }
  const de = st.de,
    ate = st.ate;
  if (!de || !ate) return;
  if (dias(de, ate) > MAX) {
    pg.append(el('p', 'aviso', t('Escolha um período até {n} dias para ver o extracto de movimentos.', { n: MAX })));
    return;
  }
  if (chave !== [de, ate, F.armazem, F.artigo, F.tipo].join('|')) {
    D = null;
    ler(de, ate);
  }
  pg.append(filtros(de, ate));
  if (!D) {
    pg.append(el('p', 'note', t('A ler os movimentos do período no Pinguim BO…')));
    return;
  }
  if (D.falhou) {
    pg.append(el('p', 'erro', D.falhou));
    return;
  }
  const r = D.resumo;
  const comSaldo = D.saldo_inicial != null;
  const B = D.balanco || null;
  const ts = [
    { l: t('Compras'), v: n0(r.entradas_externas || 0), s: t('entradas de fora (fornecedores)') },
    {
      l: t('Vendido'),
      v: n0(r.saidas_efectivas || 0),
      s: t('Pinguim BO {p} (FR, FA − NC) · SAP {s}', { p: n0(r.vendas_pinguim || 0), s: n0(r.vendas_sap || 0) }),
    },
    {
      l: t('Transferências entre armazéns'),
      v: n0(r.transf_enviadas || 0),
      s: t('só mudam de sítio: não mexem no stock total'),
    },
  ];
  if (B && B.length) {
    const fim = B.reduce((a, x) => a + x.final, 0);
    const pin = B.reduce((a, x) => a + x.pinguim, 0);
    const ok = B.every((x) => Math.abs(x.diferenca) < 0.0005);
    ts.unshift({
      l: t('Stock final'),
      v: n0(fim),
      s: ok ? t('igual ao Pinguim BO ✓') : t('Pinguim BO: {p} — ver a diferença por armazém', { p: n0(pin) }),
      tone: ok ? null : 'warn',
    });
  }
  if (r.guias_anuladas)
    ts.push({
      l: t('Saídas com a guia anulada'),
      v: n0(r.guias_anuladas),
      s: t('saíram do armazém sem entrar noutro: corrigir no Pinguim BO'),
      tone: 'warn',
    });
  if (r.outras_saidas) ts.push({ l: t('Outras saídas'), v: n0(r.outras_saidas), s: t('quebras, consumos e devoluções sem entrada') });
  if (r.acertos) ts.push({ l: t('Acertos de inventário'), v: n0(r.acertos), s: t('contado − teórico'), tone: r.acertos < 0 ? 'warn' : null });
  ts.push(
    {
      l: t('Variação do stock'),
      v: n0(r.liquido),
      s: t('entradas {a} − saídas {b}', { a: n0(r.entradas), b: n0(r.saidas) }),
      tone: r.liquido < 0 ? 'warn' : null,
    },
    { l: t('Movimentos'), v: n0(r.movimentos), s: t('{n} documentos', { n: n0(r.documentos) }) },
  );
  if (comSaldo) ts.push({ l: t('Saldo'), v: n0(D.saldo_final), s: t('início: {a} · fim: {b}', { a: n0(D.saldo_inicial), b: n0(D.saldo_final) }) });
  pg.append(tiles(ts));
  // um artigo escolhido: o percurso completo em fluxo animado, com os documentos de cada caixa e o detalhe de cada um
  const umArtigo = F.artigo && ((D.por_artigo.length === 1 && D.por_artigo[0].codigo) || (D.por_artigo.find((a) => a.codigo === F.artigo) || {}).codigo);
  if (umArtigo) pg.append(seccaoRastreio(umArtigo, de, ate, draw));
  if (D.limitado)
    pg.append(
      el(
        'p',
        'aviso',
        t(
          'Mostram-se os primeiros 20 000 movimentos (os totais incluem todos): escolha um armazém, um artigo ou um período mais curto para ver as outras linhas.',
        ),
      ),
    );
  if (!D.linhas.length) {
    pg.append(secBox(t('Movimentos'), '', el('p', 'note', t('Sem movimentos para os filtros escolhidos.'))));
    return;
  }
  const secs = el('div', 'secs');
  // um artigo: o balanço de cada armazém, conferido com o stock do Pinguim BO
  if (B && B.length) {
    const confere = (v) => (Math.abs(v) < 0.0005 ? el('span', 'mov-ok', '✓') : el('span', 'mov-dif', (v > 0 ? '+' : '') + n0(v)));
    secs.append(
      tabela({
        id: 'mov-balanco',
        titulo: t('Balanço por armazém'),
        nota: t('Stock em {a} + entradas − saídas = stock em {b}, ao lado do stock do Pinguim BO.', {
          a: dl(new Date(Date.parse(de + 'T12:00:00Z') - 864e5).toISOString().slice(0, 10)),
          b: dl(ate),
        }),
        rows: B,
        sort: 'armazem_cod',
        dir: 1,
        total: true,
        cols: [
          { key: 'armazem', label: t('Armazém'), p: 0, wrap: true, render: (v, x) => document.createTextNode(x.armazem_cod + (v ? ' · ' + v : '')) },
          { key: 'inicial', label: t('Stock inicial'), type: 'n', sum: true },
          { key: 'entradas', label: t('Entradas'), type: 'n', sum: true },
          { key: 'saidas', label: t('Saídas'), type: 'n', sum: true },
          { key: 'final', label: t('Stock final'), type: 'n', sum: true },
          { key: 'pinguim', label: t('Pinguim BO'), type: 'n', sum: true },
          { key: 'diferenca', label: t('Confere'), type: 'n', render: (v) => confere(v) },
        ],
      }),
      tabela({
        id: 'mov-balanco-detalhe',
        titulo: t('De onde vieram as entradas e para onde foram as saídas'),
        nota: t('Vendas = FR e FA − notas de crédito. Vendas SAP = saída de armazém sem guia. Transferências = SA ou DA com a guia ou entrada noutro armazém.'),
        rows: B,
        sort: 'armazem_cod',
        dir: 1,
        total: true,
        cols: [
          { key: 'armazem_cod', label: t('Armazém'), p: 0 },
          { key: 'compras', label: t('Compras'), type: 'n', sum: true },
          { key: 'recebido', label: t('Recebido de outro armazém'), type: 'n', sum: true },
          { key: 'acertos', label: t('Acertos de inventário'), type: 'n', sum: true },
          { key: 'vendas', label: t('Vendas Pinguim BO'), type: 'n', sum: true },
          { key: 'vendas_sap', label: t('Vendas SAP'), type: 'n', sum: true },
          { key: 'enviado', label: t('Enviado para outro armazém'), type: 'n', sum: true },
          { key: 'anuladas', label: t('Guia anulada'), type: 'n', sum: true },
          { key: 'outras', label: t('Outras saídas'), type: 'n', sum: true },
        ],
      }),
    );
  }
  if ((D.por_natureza || []).length)
    secs.append(
      tabela({
        id: 'mov-naturezas',
        titulo: t('Totais por natureza'),
        nota: t('Saídas efectivas: vendas no Pinguim BO (FR e FA − NC) e vendas SAP. A saída de armazém com a sua guia noutro armazém é uma transferência.'),
        rows: D.por_natureza.map((x) => ({ ...x, nome: t(NATUREZAS[x.natureza] || x.natureza) })),
        total: true,
        cols: [
          { key: 'nome', label: t('Natureza'), p: 0, wrap: true },
          { key: 'documentos', label: t('Documentos'), type: 'n', sum: true },
          { key: 'entradas', label: t('Entradas'), type: 'n', sum: true },
          { key: 'saidas', label: t('Saídas'), type: 'n', sum: true },
          { key: 'liquido', label: t('Líquido'), type: 'n', sum: true },
        ],
      }),
    );
  const efe = [
    { key: 'saidas_efectivas', label: t('Vendido'), type: 'n', sum: true },
    { key: 'vendas_sap', label: t('Vendas SAP'), type: 'n', sum: true },
    { key: 'transf_enviadas', label: t('Transf. enviadas'), type: 'n', sum: true },
    { key: 'transf_recebidas', label: t('Transf. recebidas'), type: 'n', sum: true },
  ];
  // totais por armazém ou carrinha e por tipo de documento (com o número de documentos de cada um)
  const totais = (id, titulo, rows, c1, c2) =>
    tabela({
      id,
      titulo,
      nota: t('Entradas, saídas e líquido de {a} a {b}.', { a: dl(de), b: dl(ate) }),
      rows,
      sort: 'saidas',
      total: true,
      cols: [
        c1,
        c2,
        { key: 'documentos', label: t('Documentos'), type: 'n', sum: true },
        ...(id === 'mov-armazens' ? efe : []),
        { key: 'entradas', label: t('Entradas'), type: 'n', sum: true },
        { key: 'saidas', label: t('Saídas'), type: 'n', sum: true },
        { key: 'liquido', label: t('Líquido'), type: 'n', sum: true },
      ],
    });
  if ((D.por_armazem || []).length > 1)
    secs.append(
      totais(
        'mov-armazens',
        t('Totais por armazém ou carrinha'),
        D.por_armazem,
        { key: 'armazem_cod', label: t('Código'), p: 0 },
        { key: 'armazem', label: t('Armazém'), wrap: true },
      ),
    );
  if ((D.por_tipo || []).length)
    secs.append(
      totais(
        'mov-tipos',
        t('Totais por tipo de documento'),
        D.por_tipo,
        { key: 'tipo', label: t('Tipo'), p: 0 },
        { key: 'tipo_nome', label: t('Documento'), wrap: true },
      ),
    );
  if (D.por_artigo.length > 1)
    secs.append(
      tabela({
        id: 'mov-artigos',
        titulo: t('Resumo por artigo'),
        nota: t('Entradas e saídas de {a} a {b}.', { a: dl(de), b: dl(ate) }),
        rows: D.por_artigo,
        sort: 'saidas',
        total: true,
        cols: [
          { key: 'codigo', label: t('Código'), p: 0 },
          { key: 'artigo', label: t('Artigo'), wrap: true },
          efe[0],
          { key: 'entradas', label: t('Entradas'), type: 'n', sum: true },
          { key: 'saidas', label: t('Saídas'), type: 'n', sum: true },
          { key: 'liquido', label: t('Líquido'), type: 'n', sum: true },
        ],
      }),
    );
  const cols = [
    { key: 'em', label: t('Data e hora'), p: 0, render: (v) => document.createTextNode(dl(v) + ' ' + String(v).slice(11, 16)) },
    { key: 'doc', label: t('Documento') },
    { key: 'tipo', label: t('Tipo'), render: (v, x) => document.createTextNode(x.tipo_nome ? v + ' · ' + x.tipo_nome : v), wrap: true },
    {
      key: 'natureza',
      label: t('Natureza'),
      // a transferência mostra o documento do outro lado (a guia da SA, a SA da guia)
      render: (v, x) => {
        const e = el('span', 'mov-nat mov-nat-' + v, t(CURTA[v] || v || '—'));
        if (!x.par || !x.par.length) return e;
        const w = el('span', 'mov-nat-par');
        w.append(e, document.createTextNode((v === 'transf_recebida' ? ' ← ' : ' → ') + x.par.join(', ')));
        return w;
      },
    },
    { key: 'armazem', label: t('Armazém'), render: (v, x) => document.createTextNode(x.armazem_cod + (v ? ' · ' + v : '')), wrap: true },
    { key: 'codigo', label: t('Código') },
    { key: 'artigo', label: t('Artigo'), wrap: true },
    { key: 'entrada', label: t('Entrada'), type: 'n', sum: true, render: (v) => document.createTextNode(q3(v)) },
    { key: 'saida', label: t('Saída'), type: 'n', sum: true, render: (v) => document.createTextNode(q3(v)) },
  ];
  if (comSaldo) cols.push({ key: 'saldo', label: t('Saldo'), type: 'n' });
  secs.append(
    tabela({
      id: 'mov-linhas',
      titulo: t('Movimentos'),
      nota: comSaldo
        ? t('Saldo inicial (stock no fim de {d}): {s}. Cada linha mostra o saldo depois do movimento.', {
            d: dl(new Date(Date.parse(de + 'T12:00:00Z') - 864e5).toISOString().slice(0, 10)),
            s: n0(D.saldo_inicial),
          })
        : t('Escolha um armazém e um artigo (código) para ver o saldo depois de cada movimento.'),
      rows: D.linhas,
      sort: 'em',
      dir: 1, // por ordem cronológica, como um extracto
      total: true,
      cols,
    }),
  );
  pg.append(secs);
};
