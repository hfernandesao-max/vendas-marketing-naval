// Contagem de inventário da carrinha em PDF (A4, texto vectorial, gerado no navegador), segundo a prática dos sistemas
// de armazém de referência (documento de inventário físico com contagem às cegas):
//   - Folha de contagem às cegas: só o artigo, o código e a unidade, com espaço para escrever a quantidade contada;
//     nunca leva as quantidades do sistema, para a contagem não ser influenciada. Leva assinaturas de quem conta e
//     de quem acompanha (o promotor), e a hora de início e de fim.
//   - Resultado da contagem: depois de gravada, a mesma estrutura do ecrã (inicial, carregado, vendido, devolvido,
//     tem de voltar, contado, diferença, valor e motivo), o resumo das diferenças, quem contou e quem aprovou.
// Os dois documentos têm a mesma referência (CC-rota-data), número de página e a data e hora em que foram gerados.
import { dl, kz, n0 } from '../formatos.js';
import { LANG, t } from '../i18n/i18n.js';
import { carregarScript } from '../servidor.js';

// As letras-base do PDF (Helvetica) só têm o alfabeto latino: em tigrínia o documento sai em português
export const tp = (s, p) => {
  if (LANG !== 'ti') return t(s, p);
  let r = s;
  Object.keys(p || {}).forEach((k) => (r = r.split('{' + k + '}').join(p[k])));
  return r;
};
const AZUL = [39, 55, 119],
  CEU = [35, 151, 212],
  CINZA = [86, 101, 124],
  TINTA = [15, 26, 43],
  LINHA = [214, 221, 232];
// Cores dos grupos de colunas (as mesmas do ecrã): sistema neutro, contagem física azul, diferença âmbar
const COR = { sis: [226, 231, 240], cont: [205, 228, 246], dif: [252, 236, 206] };

export const refContagem = (rota, dia) => 'CC-' + rota + '-' + String(dia).replace(/-/g, '');
const agoraTxt = () => {
  const d = new Date(),
    p = (n) => String(n).padStart(2, '0');
  return p(d.getDate()) + '-' + p(d.getMonth() + 1) + '-' + d.getFullYear() + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
};
const q = (v) => (v == null ? '—' : n0(v));
const difTxt = (v) => (v == null ? '—' : (v > 0 ? '+' : '') + n0(v));
const horaTxt = (s) => (s ? dl(String(s).slice(0, 10)) + ' ' + String(s).slice(11, 16) : '');

let emblema;
/** Emblema da Naval como data URL (uma vez); sem ele, o documento sai só com o nome. */
export function lerEmblema() {
  if (emblema !== undefined) return Promise.resolve(emblema);
  return fetch('../assets/naval-emblema.png')
    .then((r) => (r.ok ? r.blob() : null))
    .then(
      (b) =>
        b &&
        new Promise((ok) => {
          const f = new FileReader();
          f.onload = () => ok(f.result);
          f.onerror = () => ok(null);
          f.readAsDataURL(b);
        }),
    )
    .catch(() => null)
    .then((u) => (emblema = u || null));
}

export async function novoDoc(orientacao) {
  await carregarScript('jspdf.umd.min.js', 'jspdf');
  await carregarScript('jspdf.plugin.autotable.min.js');
  const { jsPDF } = window.jspdf;
  return new jsPDF({ unit: 'mm', format: 'a4', orientation: orientacao });
}

/** Cabeçalho: emblema e empresa à esquerda; título, referência e estado à direita; dados da contagem por baixo. */
export function cabecalho(doc, img, titulo, ref, campos) {
  const W = doc.internal.pageSize.getWidth(),
    M = 14;
  if (img) doc.addImage(img, 'PNG', M, 10, 12, 12);
  const x0 = img ? M + 15 : M;
  doc
    .setFont('helvetica', 'bold')
    .setFontSize(12)
    .setTextColor(...AZUL)
    .text('Grupo Naval', x0, 15.5);
  doc
    .setFont('helvetica', 'normal')
    .setFontSize(8)
    .setTextColor(...CINZA)
    .text(tp('Portal RTM · Logística'), x0, 20);
  doc
    .setFont('helvetica', 'bold')
    .setFontSize(15)
    .setTextColor(...AZUL)
    .text(titulo, W - M, 15.5, { align: 'right' });
  doc
    .setFont('helvetica', 'normal')
    .setFontSize(9)
    .setTextColor(...TINTA)
    .text(tp('Referência') + ': ' + ref, W - M, 21, { align: 'right' });
  doc
    .setDrawColor(...CEU)
    .setLineWidth(0.6)
    .line(M, 25, W - M, 25);
  const larg = (W - 2 * M) / campos.length;
  let linhas = 1;
  campos.forEach(([k, v], i) => {
    const x = M + i * larg;
    doc
      .setFont('helvetica', 'normal')
      .setFontSize(7.5)
      .setTextColor(...CINZA)
      .text(k, x, 31);
    doc
      .setFont('helvetica', 'bold')
      .setFontSize(10)
      .setTextColor(...TINTA)
      .text(doc.splitTextToSize(String(v == null || v === '' ? '—' : v), larg - 3), x, 36);
    linhas = Math.max(linhas, doc.splitTextToSize(String(v == null || v === '' ? '—' : v), larg - 3).length);
  });
  // valores longos (várias linhas): a tabela começa por baixo deles
  return 42 + (linhas - 1) * 4.5;
}

/** Rodapé de todas as páginas: origem, data e hora em que foi gerado e «Página x de y». */
export function rodapes(doc, ref) {
  const n = doc.getNumberOfPages(),
    W = doc.internal.pageSize.getWidth(),
    H = doc.internal.pageSize.getHeight(),
    M = 14,
    quando = agoraTxt();
  for (let i = 1; i <= n; i++) {
    doc.setPage(i);
    doc
      .setDrawColor(...LINHA)
      .setLineWidth(0.2)
      .line(M, H - 11, W - M, H - 11);
    doc
      .setFont('helvetica', 'normal')
      .setFontSize(7)
      .setTextColor(...CINZA)
      .text(tp('Gerado no Portal RTM em {d}', { d: quando }) + ' · ' + ref, M, H - 7);
    doc.text(tp('Página {p} de {n}', { p: i, n }), W - M, H - 7, { align: 'right' });
  }
}

/** Assinaturas no fim (mudam de página se não couberem). */
export function assinaturas(doc, y, blocos) {
  const W = doc.internal.pageSize.getWidth(),
    H = doc.internal.pageSize.getHeight(),
    M = 14;
  if (y + 30 > H - 14) {
    doc.addPage();
    y = 20;
  }
  const larg = (W - 2 * M - (blocos.length - 1) * 8) / blocos.length;
  blocos.forEach(([titulo, sub], i) => {
    const x = M + i * (larg + 8);
    doc
      .setFont('helvetica', 'bold')
      .setFontSize(8.5)
      .setTextColor(...TINTA)
      .text(titulo, x, y);
    doc
      .setDrawColor(...CINZA)
      .setLineWidth(0.3)
      .line(x, y + 14, x + larg, y + 14);
    doc
      .setFont('helvetica', 'normal')
      .setFontSize(7.5)
      .setTextColor(...CINZA)
      .text(sub, x, y + 18);
  });
  return y + 22;
}

export const estiloTabela = {
  font: 'helvetica',
  fontSize: 9,
  cellPadding: 2.2,
  textColor: TINTA,
  lineColor: LINHA,
  lineWidth: 0.2,
  valign: 'middle',
};
export const estiloCabeca = { fillColor: [234, 240, 249], textColor: AZUL, fontStyle: 'bold' };

/** Entrega o PDF: para imprimir abre-o noutro separador com a impressão pedida; senão (ou se bloqueado) descarrega. */
export function entregar(doc, nome, imprimir) {
  if (imprimir) {
    doc.autoPrint();
    const w = window.open(doc.output('bloburl'), '_blank');
    if (w) return;
  }
  doc.save(nome);
}

/** Folha de contagem às cegas (A4 ao alto), para imprimir e contar à mão no regresso da carrinha. */
export async function folhaContagemCega(r, dia, imprimir = true) {
  const [doc, img] = await Promise.all([novoDoc('portrait'), lerEmblema()]);
  const ref = refContagem(r.rota, dia);
  doc.setProperties({
    title: tp('Folha de contagem às cegas') + ' ' + ref,
    subject: tp('Contagem de inventário da carrinha'),
    creator: 'Portal RTM · Grupo Naval',
  });
  let y = cabecalho(doc, img, tp('Folha de contagem às cegas'), ref, [
    [tp('Rota'), r.rota],
    [tp('Promotor'), r.promotor],
    [tp('Dia'), dl(dia)],
    [tp('Artigos a contar'), n0(r.linhas.length)],
  ]);
  doc
    .setFont('helvetica', 'normal')
    .setFontSize(8.5)
    .setTextColor(...CINZA)
    .text(
      doc.splitTextToSize(
        tp(
          'Conte tudo o que voltou na carrinha (artigos por vender e devoluções) e escreva a quantidade na unidade indicada; 0 se não voltou nada. Esta folha não mostra as quantidades do sistema. Não rasure: se se enganar, risque, escreva ao lado e rubrique.',
        ),
        182,
      ),
      14,
      y,
    );
  y += 11;
  doc.autoTable({
    startY: y,
    margin: { left: 14, right: 14, bottom: 16 },
    head: [['#', tp('Código'), tp('Artigo'), tp('Un.'), tp('Quantidade contada'), tp('Observações')]],
    body: r.linhas.map((l, i) => [String(i + 1), l.codigo || '—', l.artigo || '—', l.unidade || '', '', '']),
    styles: { ...estiloTabela, minCellHeight: 9 },
    headStyles: estiloCabeca,
    theme: 'grid',
    columnStyles: {
      0: { cellWidth: 8, halign: 'right', textColor: CINZA },
      1: { cellWidth: 22 },
      3: { cellWidth: 12 },
      4: { cellWidth: 38 },
      5: { cellWidth: 36 },
    },
  });
  y = doc.lastAutoTable.finalY + 8;
  doc
    .setFont('helvetica', 'normal')
    .setFontSize(8.5)
    .setTextColor(...TINTA);
  doc.text(tp('Início da contagem') + ':  ____ : ____', 14, y);
  doc.text(tp('Fim da contagem') + ':  ____ : ____', 80, y);
  y = assinaturas(doc, y + 10, [
    [tp('Contado por (técnico do armazém)'), tp('Nome, assinatura e data')],
    [tp('Acompanhado por (promotor)'), tp('Nome, assinatura e data')],
  ]);
  rodapes(doc, ref);
  entregar(doc, ref + '_' + tp('folha-de-contagem') + '.pdf', imprimir);
}

/** Resultado da contagem (A4 ao baixo), igual à tabela do ecrã, com o resumo, os motivos e a auditoria. */
export async function resultadoContagem(r, dia, motivos) {
  const [doc, img] = await Promise.all([novoDoc('landscape'), lerEmblema()]);
  const ref = refContagem(r.rota, dia);
  const ESTADO = {
    por_contar: 'Por contar',
    por_validar: 'Por validar',
    por_aprovar: 'Por aprovar (coordenador)',
    por_fechar: 'Por aprovar (responsável)',
    aprovada: 'Fechada',
  };
  doc.setProperties({ title: tp('Resultado da contagem') + ' ' + ref, subject: tp('Contagem de inventário da carrinha'), creator: 'Portal RTM · Grupo Naval' });
  const res = r.resumo || {};
  let y = cabecalho(doc, img, tp('Resultado da contagem'), ref, [
    [tp('Rota'), r.rota],
    [tp('Promotor'), r.promotor],
    [tp('Dia'), dl(dia)],
    [tp('Estado'), tp(ESTADO[res.estado] || res.estado || '')],
    [tp('Artigos'), n0(r.linhas.length)],
    [tp('Diferenças'), n0(res.diferencas || 0)],
    [tp('Faltas'), kz(res.falta_kz || 0)],
    [tp('Sobras'), kz(res.sobra_kz || 0)],
  ]);
  const NUM = { halign: 'right' };
  const cols = { 1: NUM, 2: NUM, 3: NUM, 4: NUM, 5: NUM, 6: NUM, 7: NUM, 8: NUM };
  const motivo = (l) => {
    if (!l.diferenca) return '';
    const m = (r.motivos || {})[l.codigo];
    return m ? tp((motivos || {})[m.motivo] || m.motivo) + (m.nota ? ' · ' + m.nota : '') : '—';
  };
  doc.autoTable({
    startY: y,
    margin: { left: 14, right: 14, bottom: 16 },
    head: [
      [
        { content: '', styles: { fillColor: [255, 255, 255] } },
        { content: tp('Sistema · Pinguim BO'), colSpan: 5, styles: { halign: 'center', fillColor: COR.sis } },
        { content: tp('Contagem física'), styles: { halign: 'center', fillColor: COR.cont } },
        { content: tp('Diferença'), colSpan: 2, styles: { halign: 'center', fillColor: COR.dif } },
        { content: tp('Justificação'), styles: { fillColor: [255, 255, 255] } },
      ],
      [
        tp('Artigo'),
        tp('Inicial'),
        tp('Carregado'),
        tp('Vendido'),
        tp('Devolvido'),
        tp('Tem de voltar'),
        tp('Contado'),
        tp('Diferença'),
        tp('Valor'),
        tp('Motivo'),
      ],
    ],
    body: r.linhas.map((l) => [
      l.artigo + (l.codigo ? ' · ' + l.codigo : ''),
      q(l.inicial),
      q(l.carregado),
      q(l.vendido),
      q(l.devolvido),
      q(l.esperado),
      q(l.contado),
      difTxt(l.diferenca),
      l.valor == null ? '—' : kz(l.valor),
      motivo(l),
    ]),
    styles: { ...estiloTabela, fontSize: 8.5 },
    headStyles: estiloCabeca,
    theme: 'grid',
    columnStyles: { 0: { cellWidth: 72 }, ...cols, 8: { halign: 'right', cellWidth: 30 }, 9: { cellWidth: 44 } },
    didParseCell: (d) => {
      const i = d.column.index,
        grupo = i >= 1 && i <= 5 ? COR.sis : i === 6 ? COR.cont : i === 7 || i === 8 ? COR.dif : null;
      if (d.section === 'head' && d.row.index === 1) {
        if (cols[i]) d.cell.styles.halign = 'right';
        if (grupo) d.cell.styles.fillColor = grupo;
      }
      if (d.section === 'body' && grupo) d.cell.styles.fillColor = grupo.map((c) => Math.round(c + (255 - c) * 0.45));
      if (d.section === 'body' && i === 6) d.cell.styles.fontStyle = 'bold';
      if (d.section === 'body' && (d.column.index === 7 || d.column.index === 8)) {
        const dif = r.linhas[d.row.index].diferenca;
        if (dif < 0) d.cell.styles.textColor = [178, 34, 34];
        else if (dif > 0) d.cell.styles.textColor = AZUL;
        if (dif) d.cell.styles.fontStyle = 'bold';
      }
    },
  });
  y = doc.lastAutoTable.finalY + 6;
  // Circuito de aprovação (quem e quando, em cada passo)
  const auto = r.aprovado_por === 'automatico';
  const passos = [
    [tp('Contada'), r.contado_por, r.contado_em],
    [tp('Validada por quem contou'), r.validado_por, r.validado_em],
    ...(auto ? [] : [[tp('Aprovada pelo coordenador'), r.coordenado_por, r.coordenado_em]]),
    [auto ? tp('Fechada sozinha (sem diferenças)') : tp('Aprovada pelo responsável · fechada'), auto ? tp('automático') : r.aprovado_por, r.aprovado_em],
  ];
  doc.autoTable({
    startY: y,
    margin: { left: 14, right: 14, bottom: 16 },
    head: [[tp('Circuito de aprovação'), tp('Por'), tp('Data e hora')]],
    body: passos.map(([p, quem, em]) => [p, em ? quem || '—' : tp('pendente'), em ? horaTxt(em) : '—']),
    styles: { ...estiloTabela, fontSize: 8 },
    headStyles: estiloCabeca,
    theme: 'grid',
    tableWidth: 150,
  });
  y = doc.lastAutoTable.finalY + 6;
  doc
    .setFont('helvetica', 'normal')
    .setFontSize(7.5)
    .setTextColor(...CINZA)
    .text(tp('Diferença = contado - tem de voltar (negativo: falta; positivo: sobra). Valor ao preço médio de venda do dia. Origem: Pinguim BO.'), 14, y);
  assinaturas(doc, y + 10, [
    [tp('Técnico do armazém (contou e validou)'), tp('Nome, assinatura e data')],
    [tp('Promotor'), tp('Nome, assinatura e data')],
    [tp('Coordenador (1.º nível)'), tp('Nome, assinatura e data')],
    [tp('Responsável (2.º nível)'), tp('Nome, assinatura e data')],
  ]);
  rodapes(doc, ref);
  entregar(doc, ref + '_' + tp('resultado-da-contagem') + '.pdf', false);
}
