// Requisição de reposição da carrinha ao armazém em PDF (A4, texto vectorial, gerado no navegador). Documento de
// armazém com número sequencial, as quantidades requisitadas, coluna para o armazém escrever o que entregou e as
// assinaturas de quem requisita, de quem avia e de quem recebe. As reimpressões saem marcadas «2.ª via» (ou seguinte).
import { dl, n0 } from '../formatos.js';
import { assinaturas, cabecalho, entregar, estiloCabeca, estiloTabela, lerEmblema, novoDoc, rodapes, tp } from './contagem-pdf.js';

const CINZA = [86, 101, 124],
  AZUL = [39, 55, 119];
const quando = (s) => (s ? dl(String(s).slice(0, 10)) + ' ' + String(s).slice(11, 16) : '—');

/** Gera o PDF e abre-o para imprimir (ou descarrega-o, se o navegador não deixar abrir outro separador). */
export async function requisicaoPdf(r, imprimir = true) {
  const [doc, img] = await Promise.all([novoDoc('portrait'), lerEmblema()]);
  const via = (r.impressoes || []).length || 1;
  const viaTxt = via > 1 ? tp('{n}.ª via', { n: via }) : tp('Original');
  doc.setProperties({ title: tp('Requisição de reposição') + ' ' + r.numero, subject: tp('Requisição ao armazém'), creator: 'Portal RTM · Grupo Naval' });
  const origem = r.origem && r.origem.tipo === 'conciliacao' ? tp('Conciliação da carrinha de {d}', { d: dl(r.origem.data) }) : tp('Pedido do promotor');
  let y = cabecalho(doc, img, tp('Requisição de reposição'), r.numero + ' · ' + viaTxt, [
    [tp('Rota'), r.rota],
    [tp('Promotor'), r.promotor],
    [tp('Dia'), dl(r.data)],
    [tp('Origem'), origem],
    [tp('Emitida'), quando(r.emitida_em) + (r.emitida_por ? ' · ' + r.emitida_por : '')],
  ]);
  const corpo = r.linhas.map((l, i) => [String(i + 1), l.codigo, l.artigo, l.unidade || '', l.sugerida == null ? '—' : n0(l.sugerida), n0(l.quantidade), '']);
  const total = r.linhas.reduce((s, l) => s + (+l.quantidade || 0), 0);
  doc.autoTable({
    startY: y,
    margin: { left: 14, right: 14, bottom: 16 },
    head: [['#', tp('Código'), tp('Artigo'), tp('Un.'), tp('Vendido'), tp('Requisitado'), tp('Entregue')]],
    body: corpo,
    foot: [['', '', tp('Total ({n} artigos)', { n: n0(r.linhas.length) }), '', '', n0(total), '']],
    styles: { ...estiloTabela, minCellHeight: 8 },
    headStyles: estiloCabeca,
    footStyles: { fillColor: [234, 240, 249], textColor: AZUL, fontStyle: 'bold' },
    theme: 'grid',
    columnStyles: {
      0: { cellWidth: 8, halign: 'right', textColor: CINZA },
      1: { cellWidth: 22 },
      3: { cellWidth: 12 },
      4: { cellWidth: 20, halign: 'right', textColor: CINZA },
      5: { cellWidth: 26, halign: 'right', fontStyle: 'bold' },
      6: { cellWidth: 24 },
    },
    didParseCell: (d) => {
      if ((d.section === 'head' || d.section === 'foot') && (d.column.index === 4 || d.column.index === 5)) d.cell.styles.halign = 'right';
    },
  });
  y = doc.lastAutoTable.finalY + 7;
  doc
    .setFont('helvetica', 'normal')
    .setFontSize(8)
    .setTextColor(...CINZA);
  if (r.nota) {
    doc.text(doc.splitTextToSize(tp('Nota') + ': ' + r.nota, 182), 14, y);
    y += 8;
  }
  doc.text(
    doc.splitTextToSize(
      tp('Vendido: quantidade vendida no dia (reposição pelo consumo). Requisitado: o que o promotor pede ao armazém. Entregue: a preencher pelo armazém.'),
      182,
    ),
    14,
    y,
  );
  y = assinaturas(doc, y + 12, [
    [tp('Requisitado por (promotor)'), tp('Nome, assinatura e data')],
    [tp('Aviado por (armazém)'), tp('Nome, assinatura e data')],
    [tp('Recebido por'), tp('Nome, assinatura e data')],
  ]);
  // marca de água das reimpressões
  if (via > 1) {
    const n = doc.getNumberOfPages();
    for (let i = 1; i <= n; i++) {
      doc.setPage(i);
      doc.saveGraphicsState();
      doc.setGState(new doc.GState({ opacity: 0.12 }));
      doc
        .setFont('helvetica', 'bold')
        .setFontSize(70)
        .setTextColor(...AZUL);
      doc.text(viaTxt.toUpperCase(), 105, 170, { align: 'center', angle: 35 });
      doc.restoreGraphicsState();
    }
  }
  rodapes(doc, r.numero);
  entregar(doc, r.numero + (via > 1 ? '_' + via + 'via' : '') + '.pdf', imprimir);
}
