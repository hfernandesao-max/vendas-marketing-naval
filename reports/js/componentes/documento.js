// Detalhe de um documento (factura, factura-recibo, nota de crédito): clicar no número do documento em qualquer
// tabela abre um painel lateral (no telemóvel, de baixo para cima) com a representação do documento, as linhas de
// artigos, os meios de cobrança e o estado. Tudo vem dos dados já lidos do Pinguim BO (as linhas pedem-se se ainda
// não chegaram); a representação não substitui o original fiscal, que está no Pinguim BO.
import { artigosProntos, garantirArtigos } from '../fontes.js';
import { eNC, pagosDe, pendenteDe, promotorRota, recebidoDe } from '../calculos.js';
import { dl, kz, n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { DB, el, sum, toast } from '../util.js';
import { carregarScript } from '../servidor.js';
import { botao } from './janelas.js';

const estado = (v) => (eNC(v) ? 'Nota de crédito' : v.liquidado && pendenteDe(v) <= 0.005 ? 'Pago' : 'Pendente');
const qtd = (q) => (Number.isInteger(+q) ? n0(q) : (+q).toLocaleString(undefined, { maximumFractionDigits: 3 }));

/** Coluna «Documento» que abre o painel do documento (o Excel continua a levar só o número). */
export const colDocumento = (extra = {}) => ({
  key: 'documento',
  label: t('Documento'),
  nowrap: true,
  render: (v, r) => {
    if (!v) return document.createTextNode('—');
    const b = el('button', 'doc-link', String(v));
    b.type = 'button';
    b.setAttribute('aria-label', t('Abrir o documento {d}', { d: v }));
    b.addEventListener('click', (e) => {
      e.stopPropagation();
      abrirDocumento((r && r.documento) || v);
    });
    return b;
  },
  xl: (r) => r.documento,
  ...extra,
});

const pctIva = (a) => {
  const base = Math.abs(+a.valor_sem_iva || 0);
  return base > 0.005 ? Math.round((Math.abs(+a.iva || 0) / base) * 100) : 0;
};

/**
 * Representação do documento como foi emitido (estrutura de uma factura: tipo e número, datas, cliente e NIF, linhas
 * com quantidade, unidade, preço, desconto, taxa e valor do IVA, resumo do IVA por taxa, totais e meios de pagamento),
 * em página branca e tipo de letra legível. Dados do Pinguim BO; o original fiscal está no Pinguim BO.
 */
function folha(v, linhas, pags) {
  const f = el('article', 'doc-folha');
  f.setAttribute('aria-label', t('Representação do documento'));
  // cabeçalho: tipo e número à direita, dados do documento por baixo
  const cab = el('header', 'df-cab');
  const esq = el('div', 'df-orig');
  esq.append(el('span', 'df-rot', t('Rota')), el('b', null, promotorRota(v.rota)));
  const dir = el('div', 'df-id');
  dir.append(el('span', 'df-tipo', t(v.tipo || (eNC(v) ? 'Nota de crédito' : 'Factura'))), el('span', 'df-num', v.documento));
  cab.append(esq, dir);
  f.append(cab);
  const meta = el('dl', 'df-meta');
  const par = (k, val) => {
    const d = el('div');
    d.append(el('dt', null, k), el('dd', null, val));
    meta.append(d);
  };
  par(t('Data'), dl(v.data));
  if (v.hora) par(t('Hora'), v.hora);
  par(t('Cliente'), v.cliente || t('Consumidor final'));
  par(t('NIF'), v.nif || '—');
  if (v.cliente_codigo) par(t('Código do cliente'), v.cliente_codigo);
  f.append(meta);
  // linhas
  const w = el('div', 'df-tabw');
  const tb = el('table', 'df-linhas');
  const cols = el('colgroup');
  ['c-cod', 'c-desc', 'c-qtd', 'c-un', 'c-preco', 'c-desco', 'c-iva', 'c-tot'].forEach((c) => cols.append(el('col', c)));
  tb.append(cols);
  const hr = el('tr');
  [[t('Código')], [t('Descrição')], [t('Qtd.'), 'n'], [t('Un.')], [t('Preço unit.'), 'n'], [t('Desc.'), 'n'], [t('IVA'), 'n'], [t('Total'), 'n']].forEach(
    ([h, c]) => {
      const th = el('th', c || null, h);
      th.scope = 'col';
      hr.append(th);
    },
  );
  const th = el('thead');
  th.append(hr);
  tb.append(th);
  const tbody = el('tbody');
  if (!linhas || !linhas.length) {
    const tr = el('tr');
    const td = el('td', 'df-espera', linhas === null ? t('A carregar os artigos…') : t('Sem linhas de artigos no Pinguim BO.'));
    td.colSpan = 8;
    tr.append(td);
    tbody.append(tr);
  }
  (linhas || []).forEach((a) => {
    const tr = el('tr');
    tr.append(
      el('td', null, a.codigo || '—'),
      el('td', null, a.artigo || '—'),
      el('td', 'n', qtd(Math.abs(+a.quantidade || 0))),
      el('td', null, a.unidade || ''),
      el('td', 'n', kz(Math.abs(+a.preco_unitario || 0))),
      el('td', 'n', kz(Math.abs(+a.desconto || 0))),
      el('td', 'n', pctIva(a) + ' %'),
      el('td', 'n', kz(Math.abs(+a.total || 0))),
    );
    tbody.append(tr);
  });
  tb.append(tbody);
  w.append(tb);
  f.append(w);
  // rodapé: resumo do IVA e pagamentos à esquerda, totais à direita
  const pe = el('div', 'df-pe');
  const esqPe = el('div');
  if (linhas && linhas.length) {
    const taxas = new Map();
    linhas.forEach((a) => {
      const k = pctIva(a);
      const o = taxas.get(k) || { base: 0, iva: 0 };
      o.base += Math.abs(+a.valor_sem_iva || 0);
      o.iva += Math.abs(+a.iva || 0);
      taxas.set(k, o);
    });
    const ti = el('table', 'df-iva');
    const cab2 = el('tr');
    [t('Taxa de IVA'), t('Incidência'), t('Valor do IVA')].forEach((h, i) => cab2.append(el('th', i ? 'n' : null, h)));
    ti.append(cab2);
    [...taxas.entries()]
      .sort((a, b) => b[0] - a[0])
      .forEach(([k, o]) => {
        const tr = el('tr');
        tr.append(el('td', null, k + ' %'), el('td', 'n', kz(o.base)), el('td', 'n', kz(o.iva)));
        ti.append(tr);
      });
    esqPe.append(el('h4', null, t('Resumo do IVA')), ti);
  }
  esqPe.append(el('h4', null, eNC(v) ? t('Devolução') : t('Meios de pagamento')));
  const ul = el('ul', 'df-pags');
  if (!pags.length) ul.append(el('li', null, '—'));
  // devoluções de notas de crédito sem meio no Pinguim BO: no meio da factura corrigida (ou numerário presumido)
  pags.forEach((p) => {
    const li = el('li');
    li.append(
      el(
        'span',
        null,
        t(p.meio || p.codigo || 'Sem meio indicado') + (p.atribuido ? ' · ' + t('meio da {d}', { d: p.atribuido }) : p.presumido ? ' · ' + t('presumido') : ''),
      ),
      el('b', null, kz(p.valor)),
    );
    ul.append(li);
  });
  esqPe.append(ul);
  const tot = el('dl', 'df-tot');
  const linha = (k, val, cls) => {
    const d = el('div', cls || null);
    d.append(el('dt', null, k), el('dd', null, val));
    tot.append(d);
  };
  if (linhas && linhas.length) {
    const base = sum(linhas, (a) => Math.abs(+a.valor_sem_iva || 0)),
      desc = sum(linhas, (a) => Math.abs(+a.desconto || 0)),
      iva = sum(linhas, (a) => Math.abs(+a.iva || 0));
    linha(t('Total ilíquido'), kz(base + desc));
    linha(t('Descontos'), kz(desc));
    linha(t('Total do IVA'), kz(iva));
  }
  linha(t('Total do documento'), kz(v.total), 'df-grande');
  if (!eNC(v)) {
    linha(t('Recebido'), kz(recebidoDe(v)));
    if (pendenteDe(v) > 0.005) linha(t('Em falta'), kz(pendenteDe(v)), 'df-falta');
  }
  pe.append(esqPe, tot);
  f.append(pe);
  f.append(el('p', 'df-nota', t('Representação a partir dos dados do Pinguim BO; o documento fiscal original está no Pinguim BO.')));
  return f;
}

// Largura da folha (A4 a 96 ppp): a folha desenha-se sempre a esta largura e ajusta-se à janela por escala,
// como um leitor de PDF em «ajustar à largura» — sem barras de deslocação horizontais, em qualquer ecrã.
const LARGURA_FOLHA = 794;
function ajustarFolha(palco) {
  const f = palco.firstElementChild;
  if (!f) return;
  const k = Math.min(1.25, palco.clientWidth / LARGURA_FOLHA);
  f.style.transform = 'scale(' + k + ')';
  palco.style.height = Math.ceil(f.offsetHeight * k) + 'px';
}

/**
 * PDF do documento (A4, texto vectorial, pesquisável), com a mesma estrutura da folha: tipo e número, dados do
 * documento, linhas, resumo do IVA, meios de pagamento, totais e a nota de origem. Gerado no navegador.
 */
async function exportarPdf(v, linhas, pags) {
  await carregarScript('jspdf.umd.min.js', 'jspdf');
  await carregarScript('jspdf.plugin.autotable.min.js');
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({ unit: 'mm', format: 'a4' });
  const AZUL = [10, 42, 92],
    CINZA = [86, 101, 124],
    M = 16,
    L = 210 - 2 * M;
  const tipo = t(v.tipo || (eNC(v) ? 'Nota de crédito' : 'Factura'));
  doc.setProperties({ title: tipo + ' ' + v.documento, subject: t('Representação do documento'), creator: 'Portal RTM · Grupo Naval' });
  doc.setFont('helvetica', 'normal');
  doc
    .setFontSize(8)
    .setTextColor(...CINZA)
    .text(t('Rota'), M, 20);
  doc
    .setFontSize(11)
    .setTextColor(15, 26, 43)
    .setFont('helvetica', 'bold')
    .text(String(promotorRota(v.rota)), M, 25);
  doc
    .setFontSize(16)
    .setTextColor(...AZUL)
    .text(tipo, 210 - M, 21, { align: 'right' });
  doc
    .setFontSize(11)
    .setTextColor(15, 26, 43)
    .text(String(v.documento), 210 - M, 27, { align: 'right' });
  doc
    .setDrawColor(...AZUL)
    .setLineWidth(0.6)
    .line(M, 31, 210 - M, 31);
  const campos = [
    [t('Data'), dl(v.data)],
    ...(v.hora ? [[t('Hora'), v.hora]] : []),
    [t('Cliente'), v.cliente || t('Consumidor final')],
    [t('NIF'), v.nif || '—'],
    ...(v.cliente_codigo ? [[t('Código do cliente'), String(v.cliente_codigo)]] : []),
  ];
  let y = 38;
  const larg = L / 3;
  campos.forEach(([k, val], i) => {
    const x = M + (i % 3) * larg,
      yy = y + Math.floor(i / 3) * 12;
    doc
      .setFont('helvetica', 'normal')
      .setFontSize(8)
      .setTextColor(...CINZA)
      .text(k, x, yy);
    doc
      .setFont('helvetica', 'bold')
      .setFontSize(10)
      .setTextColor(15, 26, 43)
      .text(doc.splitTextToSize(String(val), larg - 4), x, yy + 5);
  });
  y += Math.ceil(campos.length / 3) * 12 + 4;
  const corpo = (linhas || []).map((a) => [
    a.codigo || '—',
    a.artigo || '—',
    qtd(Math.abs(+a.quantidade || 0)),
    a.unidade || '',
    kz(Math.abs(+a.preco_unitario || 0)),
    kz(Math.abs(+a.desconto || 0)),
    pctIva(a) + ' %',
    kz(Math.abs(+a.total || 0)),
  ]);
  // cabeçalhos alinhados como as colunas (números à direita)
  const alinhar = (cs) => (d) => {
    if (d.section === 'head' && cs[d.column.index]) d.cell.styles.halign = cs[d.column.index].halign;
  };
  const estilo = { font: 'helvetica', fontSize: 8.5, cellPadding: 1.8, textColor: [15, 26, 43], lineColor: [225, 230, 238] };
  const cabeca = { fillColor: [238, 242, 248], textColor: [58, 74, 99], fontStyle: 'bold' };
  doc.autoTable({
    startY: y,
    margin: { left: M, right: M },
    head: [[t('Código'), t('Descrição'), t('Qtd.'), t('Un.'), t('Preço unit.'), t('Desc.'), t('IVA'), t('Total')]],
    body: corpo.length ? corpo : [[{ content: t('Sem linhas de artigos no Pinguim BO.'), colSpan: 8 }]],
    styles: estilo,
    headStyles: cabeca,
    theme: 'grid',
    columnStyles: { 2: { halign: 'right' }, 4: { halign: 'right' }, 5: { halign: 'right' }, 6: { halign: 'right' }, 7: { halign: 'right' } },
    didParseCell: alinhar({ 2: { halign: 'right' }, 4: { halign: 'right' }, 5: { halign: 'right' }, 6: { halign: 'right' }, 7: { halign: 'right' } }),
  });
  y = doc.lastAutoTable.finalY + 8;
  const meia = (L - 8) / 2;
  // à esquerda: resumo do IVA e meios de pagamento
  if (linhas && linhas.length) {
    const taxas = new Map();
    linhas.forEach((a) => {
      const k = pctIva(a);
      const o = taxas.get(k) || { base: 0, iva: 0 };
      o.base += Math.abs(+a.valor_sem_iva || 0);
      o.iva += Math.abs(+a.iva || 0);
      taxas.set(k, o);
    });
    doc.autoTable({
      startY: y,
      margin: { left: M, right: 210 - M - meia },
      head: [[t('Taxa de IVA'), t('Incidência'), t('Valor do IVA')]],
      body: [...taxas.entries()].sort((a, b) => b[0] - a[0]).map(([k, o]) => [k + ' %', kz(o.base), kz(o.iva)]),
      styles: estilo,
      headStyles: cabeca,
      theme: 'grid',
      columnStyles: { 1: { halign: 'right' }, 2: { halign: 'right' } },
      didParseCell: alinhar({ 1: { halign: 'right' }, 2: { halign: 'right' } }),
    });
  }
  doc.autoTable({
    startY: linhas && linhas.length ? doc.lastAutoTable.finalY + 4 : y,
    margin: { left: M, right: 210 - M - meia },
    head: [[eNC(v) ? t('Devolução') : t('Meios de pagamento'), '']],
    body: pags.length
      ? pags.map((p) => [
          t(p.meio || p.codigo || 'Sem meio indicado') +
            (p.atribuido ? ' · ' + t('meio da {d}', { d: p.atribuido }) : p.presumido ? ' · ' + t('presumido') : ''),
          kz(p.valor),
        ])
      : [['—', '']],
    styles: estilo,
    headStyles: cabeca,
    theme: 'grid',
    columnStyles: { 1: { halign: 'right' } },
  });
  const fimEsq = doc.lastAutoTable.finalY;
  // à direita: totais
  const tot = [];
  if (linhas && linhas.length) {
    const base = sum(linhas, (a) => Math.abs(+a.valor_sem_iva || 0)),
      desc = sum(linhas, (a) => Math.abs(+a.desconto || 0)),
      iva = sum(linhas, (a) => Math.abs(+a.iva || 0));
    tot.push([t('Total ilíquido'), kz(base + desc)], [t('Descontos'), kz(desc)], [t('Total do IVA'), kz(iva)]);
  }
  tot.push([t('Total do documento'), kz(v.total)]);
  if (!eNC(v)) {
    tot.push([t('Recebido'), kz(recebidoDe(v))]);
    if (pendenteDe(v) > 0.005) tot.push([t('Em falta'), kz(pendenteDe(v))]);
  }
  const ig = tot.findIndex((r) => r[0] === t('Total do documento'));
  doc.autoTable({
    startY: y,
    margin: { left: M + meia + 8, right: M },
    body: tot,
    styles: { ...estilo, fontSize: 9.5, lineWidth: 0 },
    theme: 'plain',
    columnStyles: { 1: { halign: 'right', fontStyle: 'bold' } },
    didParseCell: (d) => {
      if (d.row.index === ig) {
        d.cell.styles.fontSize = 12;
        d.cell.styles.fontStyle = 'bold';
        d.cell.styles.textColor = AZUL;
      }
    },
  });
  y = Math.max(fimEsq, doc.lastAutoTable.finalY) + 10;
  doc
    .setFont('helvetica', 'normal')
    .setFontSize(8)
    .setTextColor(...CINZA);
  doc.text(doc.splitTextToSize(t('Representação a partir dos dados do Pinguim BO; o documento fiscal original está no Pinguim BO.'), L), M, Math.min(y, 285));
  doc.save(String(v.documento).replace(/[^A-Za-z0-9._-]+/g, '_') + '.pdf');
}

/** Abre o painel do documento (pelo número). */
export function abrirDocumento(numero) {
  const v = (DB.vendas || []).find((x) => x.documento === numero);
  if (!v) {
    toast(t('O documento {d} não está no período carregado.', { d: numero }), 5000);
    return;
  }
  document.querySelectorAll('dialog.doc-painel').forEach((d) => d.close());
  const dlg = el('dialog', 'doc-painel');
  dlg.setAttribute('aria-labelledby', 'doc-titulo');
  const corpo = el('div', 'doc-corpo');
  const desenhar = () => {
    const linhas = artigosProntos() ? (DB.artigos || []).filter((a) => a.documento === v.documento) : null;
    const pags = pagosDe(v);
    corpo.replaceChildren();
    const cab = el('header', 'doc-cab');
    const tit = el('h2', null, v.documento);
    tit.id = 'doc-titulo';
    const e = estado(v);
    const st = el('span', 'pill ' + (e === 'Pago' ? 'p-ok' : e === 'Pendente' ? 'p-warn' : 'p-mut'), t(e));
    // barra fixa no topo: número e estado à esquerda, Imprimir e Fechar à direita (sempre à mão)
    const imprimir = botao(t('Imprimir'), 'ghost sm', () => {
      document.body.classList.add('a-imprimir-doc');
      window.print();
      setTimeout(() => document.body.classList.remove('a-imprimir-doc'), 500);
    });
    const pdf = botao(t('Exportar PDF'), 'ghost sm', () =>
      exportarPdf(v, linhas, pags).catch((e) => {
        console.error(e);
        toast(t('Não foi possível criar o PDF.'), 5000);
      }),
    );
    const fechar = botao(t('Fechar'), 'sm doc-fechar', () => dlg.close());
    const acc = el('div', 'doc-acc');
    acc.append(pdf, imprimir, fechar);
    const id = el('div', 'doc-idt');
    id.append(tit, st);
    cab.append(id, acc);
    corpo.append(cab);
    // a folha desenha-se à largura de uma página A4 e ajusta-se à janela (sem deslocação horizontal)
    const palco = el('div', 'doc-palco');
    palco.append(folha(v, linhas, pags));
    corpo.append(palco);
    requestAnimationFrame(() => ajustarFolha(palco));
  };
  // a janela muda de tamanho (ou o painel abre): a folha volta a ajustar-se
  const obs = window.ResizeObserver
    ? new ResizeObserver(() => {
        const palco = corpo.querySelector('.doc-palco');
        if (palco) ajustarFolha(palco);
      })
    : null;
  if (obs) obs.observe(corpo);
  dlg.append(corpo);
  // clicar fora do painel fecha-o; Esc fecha (nativo); o foco volta ao botão que o abriu
  const origem = document.activeElement;
  dlg.addEventListener('click', (e) => {
    if (e.target === dlg) dlg.close();
  });
  dlg.addEventListener('close', () => {
    if (obs) obs.disconnect();
    setTimeout(() => dlg.remove(), 0);
    if (origem && origem.focus) origem.focus();
  });
  document.body.append(dlg);
  desenhar();
  dlg.showModal();
  if (!artigosProntos())
    garantirArtigos().then(() => {
      if (dlg.open) desenhar();
    });
}
