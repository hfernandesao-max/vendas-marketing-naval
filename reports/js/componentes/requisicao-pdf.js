// Requisição Interna / PO da carrinha ao armazém em PDF (A4, texto vectorial, gerado no navegador), com o QR do número
// (o Fiel de Armazém lê-o em Logística › Cargas (PO) para conferir a carga no cais). Documento de
// armazém com número sequencial, as quantidades requisitadas, coluna para o armazém escrever o que entregou e as
// assinaturas de quem requisita, de quem avia e de quem recebe. Depois da conferência no cais, o mesmo documento mostra o
// que foi pedido e o que foi carregado, a diferença e o motivo de cada uma, quem conferiu, quem validou e a guia do
// Pinguim BO. As reimpressões saem marcadas «2.ª via» (ou seguinte).
import { dl, n0 } from '../formatos.js';
import { carregarScript } from '../servidor.js';
import { quem } from './assinantes.js';
import { MOTIVOS_CARGA } from './motivos-carga.js';
import { assinaturas, cabecalho, entregar, estiloCabeca, estiloTabela, lerEmblema, novoDoc, rodapes, tp } from './contagem-pdf.js';

const CINZA = [86, 101, 124],
  AZUL = [39, 55, 119];
const quando = (s) => (s ? dl(String(s).slice(0, 10)) + ' ' + String(s).slice(11, 16) : '—');

/** Gera o PDF e abre-o para imprimir (ou descarrega-o, se o navegador não deixar abrir outro separador). */
export async function requisicaoPdf(r, imprimir = true) {
  const [doc, img] = await Promise.all([novoDoc('portrait'), lerEmblema(), carregarScript('qrcode.js', 'qrcode').catch(() => null)]);
  const via = (r.impressoes || []).length || 1;
  // conferida no cais: as linhas já têm o que foi carregado
  const conferida = r.linhas.some((l) => l.entregue != null);
  const viaTxt = via > 1 ? tp('{n}.ª via', { n: via }) : tp('Original');
  doc.setProperties({ title: tp('Requisição Interna / PO') + ' ' + r.numero, subject: tp('Requisição ao armazém'), creator: 'Portal RTM · Grupo Naval' });
  const origem = r.origem && r.origem.tipo === 'conciliacao' ? tp('Conciliação da carrinha de {d}', { d: dl(r.origem.data) }) : tp('Pedido do promotor');
  let y = cabecalho(doc, img, tp('Requisição Interna / PO'), r.numero + ' · ' + viaTxt, [
    [tp('Rota'), r.rota],
    [tp('Promotor'), r.promotor],
    [tp('Dia'), dl(r.data)],
    [tp('Origem'), origem],
    [tp('Emitida'), quando(r.emitida_em) + (r.emitida_por ? ' · ' + quem(r.emitida_por, tp) : '')],
    ...(conferida
      ? [
          [tp('Conferida no cais'), quando(r.confirmado_em) + (r.confirmado_por ? ' · ' + quem(r.confirmado_por, tp) : '')],
          [tp('Validada'), r.validado_em ? quando(r.validado_em) + (r.validado_por ? ' · ' + quem(r.validado_por, tp) : '') : tp('por validar (Back-Office)')],
          [tp('Guia no Pinguim BO'), (r.guia && r.guia.numero) || tp('por gerar')],
        ]
      : []),
  ]);
  const dif = (l) => (+l.entregue || 0) - (+l.quantidade || 0);
  const motivo = (l) => (l.motivo ? tp(MOTIVOS_CARGA[l.motivo.codigo] || l.motivo.codigo) + (l.motivo.texto ? ': ' + l.motivo.texto : '') : '');
  const corpo = r.linhas.map((l, i) => {
    const d = conferida ? dif(l) : 0;
    const art =
      conferida && Math.abs(d) > 0.0001
        ? l.artigo + '\n' + tp('Diferença {d}', { d: (d > 0 ? '+' : '') + n0(d) }) + (motivo(l) ? ' · ' + motivo(l) : '')
        : l.artigo;
    return [String(i + 1), l.codigo, art, l.unidade || '', n0(l.quantidade), conferida ? n0(l.entregue) : ''];
  });
  const total = r.linhas.reduce((s, l) => s + (+l.quantidade || 0), 0);
  const totalEntregue = r.linhas.reduce((s, l) => s + (+l.entregue || 0), 0);
  doc.autoTable({
    startY: y,
    margin: { left: 14, right: 14, bottom: 16 },
    // sem «Vendido»: a PO é um pedido ao armazém, não uma venda
    head: [['#', tp('Código'), tp('Artigo'), tp('Un.'), tp('Requisitado'), conferida ? tp('Carregado') : tp('Entregue')]],
    body: corpo,
    foot: [['', '', tp('Total ({n} artigos)', { n: n0(r.linhas.length) }), '', n0(total), conferida ? n0(totalEntregue) : '']],
    styles: { ...estiloTabela, minCellHeight: 8 },
    headStyles: estiloCabeca,
    footStyles: { fillColor: [234, 240, 249], textColor: AZUL, fontStyle: 'bold' },
    theme: 'grid',
    columnStyles: {
      0: { cellWidth: 8, halign: 'right', textColor: CINZA },
      1: { cellWidth: 22 },
      3: { cellWidth: 12 },
      4: { cellWidth: 26, halign: 'right', fontStyle: 'bold' },
      5: { cellWidth: 24 },
    },
    didParseCell: (d) => {
      if ((d.section === 'head' || d.section === 'foot') && d.column.index >= 4) d.cell.styles.halign = 'right';
      if (!conferida || d.column.index !== 5) return;
      d.cell.styles.halign = 'right';
      d.cell.styles.fontStyle = 'bold';
      // linha com diferença: o carregado a vermelho (o motivo está por baixo do artigo)
      const l = d.section === 'body' ? r.linhas[d.row.index] : null;
      if (l && Math.abs(dif(l)) > 0.0001) d.cell.styles.textColor = [192, 38, 45];
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
      conferida
        ? tp('Requisitado: o que o promotor pediu. Carregado: o que o Fiel de Armazém confirmou no cais; cada diferença tem o motivo por baixo do artigo.')
        : tp('Requisitado: o que o promotor pede ao armazém. Entregue: a preencher pelo armazém.'),
      182,
    ),
    14,
    y,
  );
  // QR do número da PO (com a versão, para se ver se a impressão é a mais recente): leitura rápida no cais
  if (window.qrcode) {
    const q = window.qrcode(0, 'M');
    q.addData(r.numero + '|v' + (r.versao || 1));
    q.make();
    const n = q.getModuleCount();
    const lado = 26;
    const c = lado / n;
    const x0 = 196 - lado;
    const y0 = y + 9; // por baixo da nota das colunas
    doc.setFillColor(0, 0, 0);
    for (let i = 0; i < n; i++) for (let j = 0; j < n; j++) if (q.isDark(i, j)) doc.rect(x0 + j * c, y0 + i * c, c, c, 'F');
    doc
      .setFont('helvetica', 'normal')
      .setFontSize(7)
      .setTextColor(...CINZA);
    doc.text(tp('Ler no portal: Logística › Cargas (PO)'), x0 + lado / 2, y0 + lado + 3, { align: 'center' });
    y = y0 + lado - 2;
  }
  y = assinaturas(doc, y + 12, [
    // o promotor da rota já preenchido (também nas requisições automáticas da conciliação); quem emitiu fica no cabeçalho
    [tp('Requisitado por (promotor)'), tp('Assinatura e data'), { nome: r.rota + ' · ' + (r.promotor || r.rota) }],
    [tp('Aviado por (armazém)'), tp('Nome, assinatura e data')],
    [tp('Recebido por'), tp('Nome, assinatura e data'), { por: r.confirmado_por, em: r.confirmado_em }],
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
