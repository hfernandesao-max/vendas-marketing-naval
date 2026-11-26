// Logística › Stock › Extracto de movimentos: todas as entradas e saídas de artigos, documento a documento, em todos
// os armazéns e carrinhas, no período escolhido (até 31 dias), lidas do Pinguim BO (servidor: nucleo/api/v1/movimentos.php).
//   - filtros: armazém ou carrinha, artigo (código ou nome) e tipo de documento (vendas, guias, devoluções, inventários…);
//   - com um armazém e um artigo, o extracto mostra o saldo, como um extracto bancário: o stock no fim do dia anterior
//     e o saldo depois de cada movimento;
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

const MAX = 31;
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
  const ts = [
    { l: t('Entradas'), v: n0(r.entradas), s: t('quantidade que entrou') },
    { l: t('Saídas'), v: n0(r.saidas), s: t('quantidade que saiu') },
    { l: t('Líquido'), v: n0(r.liquido), tone: r.liquido < 0 ? 'warn' : null },
    { l: t('Movimentos'), v: n0(r.movimentos), s: t('{n} documentos', { n: n0(r.documentos) }) },
  ];
  if (comSaldo) ts.push({ l: t('Saldo'), v: n0(D.saldo_final), s: t('início: {a} · fim: {b}', { a: n0(D.saldo_inicial), b: n0(D.saldo_final) }) });
  pg.append(tiles(ts));
  // um artigo escolhido: o percurso completo em fluxo animado, com os documentos de cada caixa e o detalhe de cada um
  const umArtigo = F.artigo && ((D.por_artigo.length === 1 && D.por_artigo[0].codigo) || (D.por_artigo.find((a) => a.codigo === F.artigo) || {}).codigo);
  if (umArtigo) pg.append(seccaoRastreio(umArtigo, de, ate, draw));
  if (D.limitado) pg.append(el('p', 'aviso', t('Mostram-se os primeiros 20 000 movimentos: escolha um armazém, um artigo ou um período mais curto.')));
  if (!D.linhas.length) {
    pg.append(secBox(t('Movimentos'), '', el('p', 'note', t('Sem movimentos para os filtros escolhidos.'))));
    return;
  }
  const secs = el('div', 'secs');
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
