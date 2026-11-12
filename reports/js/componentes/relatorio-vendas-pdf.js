// Relatório de Vendas diário em PDF (A4 ao baixo, texto vectorial), sempre a partir da versão congelada no servidor:
// resumo, vendas e cobranças por promotor, caixa, Multicaixa por banco, registo de vendas, stock das carrinhas e
// facturação SAP por empresa. Cada página tem a referência, a impressão digital (SHA-256) e «Página x de y».
import { dl, kz, n0 } from '../formatos.js';
import { assinaturas, cabecalho, entregar, estiloCabeca, estiloTabela, lerEmblema, novoDoc, rodapes, tp } from './contagem-pdf.js';

const AZUL = [39, 55, 119],
  CINZA = [86, 101, 124],
  VERMELHO = [178, 34, 34];
const SAIDA = {
  deposito_banco: 'Depósito no banco',
  fundo_maneio: 'Fundo de maneio',
  transferencia_gestao: 'Transferência para a gestão',
  outro: 'Outra saída',
};

export async function relatorioVendasPdf(R, imprimir = false) {
  const [doc, img] = await Promise.all([novoDoc('landscape'), lerEmblema()]);
  const d = R.dados,
    r = d.resumo,
    M = 14;
  doc.setProperties({ title: tp('Relatório de Vendas') + ' ' + R.numero, subject: tp('Relatório de Vendas diário'), creator: 'Portal RTM · Grupo Naval' });
  let y = cabecalho(doc, img, tp('Relatório de Vendas'), R.numero, [
    [tp('Dia'), dl(R.dia)],
    [tp('Vendas RTM (líquidas)'), kz(r.vendas)],
    [tp('Dinheiro'), kz(r.dinheiro)],
    [tp('Multicaixa'), kz(r.multicaixa)],
    [tp('Por receber'), kz(r.por_receber)],
    [tp('Gerado'), dl(R.gerado_em.slice(0, 10)) + ' ' + R.gerado_em.slice(11, 16) + ' · ' + R.gerado_por],
  ]);
  y += 4;
  const titulo = (txt) => {
    if (y > doc.internal.pageSize.getHeight() - 40) {
      doc.addPage();
      y = 18;
    }
    doc
      .setFont('helvetica', 'bold')
      .setFontSize(11)
      .setTextColor(...AZUL)
      .text(txt, M, y);
    y += 3;
  };
  const tabelaPdf = (head, body, opc = {}) => {
    doc.autoTable({
      startY: y,
      margin: { left: M, right: M, bottom: 16 },
      head: [head],
      body,
      styles: { ...estiloTabela, fontSize: 8 },
      headStyles: estiloCabeca,
      theme: 'grid',
      ...opc,
    });
    y = doc.lastAutoTable.finalY + 8;
  };
  const direita = (cols) => {
    const o = {};
    cols.forEach((c) => (o[c] = { halign: 'right' }));
    return o;
  };
  const vermelhoSe = (cols) => (h) => {
    if (h.section === 'head' && cols.includes(h.column.index)) h.cell.styles.halign = 'right';
    if (h.section === 'body' && h.cell.raw && String(h.cell.raw).startsWith('!')) {
      h.cell.text = [String(h.cell.raw).slice(1)];
      h.cell.styles.textColor = VERMELHO;
      h.cell.styles.fontStyle = 'bold';
    }
  };
  const dif = (v) => (Math.abs(v) >= 0.01 ? '!' + kz(v) : kz(v));

  // 1. Controlos (prova de que as fontes estavam completas)
  titulo(tp('Fecho do dia: fontes completas'));
  tabelaPdf(
    [tp('Fonte'), tp('Estado'), tp('Detalhe')],
    Object.entries(R.controlos).map(([k, x]) => [
      {
        pinguim: tp('Pinguim BO'),
        conciliacao: tp('Conciliação das carrinhas'),
        sap_ficheiro: tp('Vendas carregadas no SAP'),
        caixa: tp('Folha de caixa'),
        sap_odata: tp('Facturação SAP (conector)'),
      }[k] || k,
      { ok: tp('Completo'), por_ligar: tp('Por ligar'), pendente: tp('Em falta'), erro: tp('Sem ligação') }[x.estado] || x.estado,
      x.detalhe,
    ]),
    { columnStyles: { 0: { cellWidth: 60 }, 1: { cellWidth: 26 } } },
  );

  // 2. Vendas e cobranças por promotor
  titulo(tp('Vendas e cobranças por promotor'));
  const P = d.promotores;
  const soma = (k) => P.reduce((s, p) => s + (+p[k] || 0), 0);
  tabelaPdf(
    [
      tp('Promotor'),
      tp('Docs'),
      tp('Vendas'),
      tp('Dinheiro'),
      tp('Entregue'),
      tp('Dif. dinheiro'),
      tp('Multicaixa'),
      tp('TPA'),
      tp('Dif. Multicaixa'),
      tp('Por receber'),
    ],
    [
      ...P.map((p) => [
        p.rota + ' · ' + p.promotor,
        n0(p.documentos),
        kz(p.vendas),
        kz(p.dinheiro),
        kz(p.entregue),
        dif(p.dif_dinheiro),
        kz(p.multicaixa),
        kz(p.tpa),
        dif(p.dif_tpa),
        kz(p.por_receber),
      ]),
      [
        tp('Total'),
        n0(soma('documentos')),
        kz(soma('vendas')),
        kz(soma('dinheiro')),
        kz(soma('entregue')),
        dif(soma('dif_dinheiro')),
        kz(soma('multicaixa')),
        kz(soma('tpa')),
        dif(soma('dif_tpa')),
        kz(soma('por_receber')),
      ],
    ],
    {
      columnStyles: direita([1, 2, 3, 4, 5, 6, 7, 8, 9]),
      didParseCell: (h) => {
        vermelhoSe([1, 2, 3, 4, 5, 6, 7, 8, 9])(h);
        if (h.section === 'body' && h.row.index === P.length) h.cell.styles.fontStyle = 'bold';
      },
    },
  );

  // 3. Caixa e Multicaixa por banco
  const cx = d.caixa;
  titulo(tp('Caixa do dia'));
  tabelaPdf(
    [tp('Movimento'), tp('Valor')],
    [
      [tp('Saldo inicial'), kz(cx.saldo_inicial)],
      [tp('Dinheiro entregue pelos promotores'), kz(cx.entregas)],
      ...cx.saidas.map((s) => [
        '- ' + tp(SAIDA[s.tipo] || s.tipo) + (s.empresa ? ' · ' + s.empresa : '') + (s.descricao ? ' · ' + s.descricao : ''),
        kz(-s.valor),
      ]),
      [tp('Saldo final teórico'), kz(cx.saldo_teorico)],
      [tp('Contagem física'), kz(cx.contagem_fisica)],
      [tp('Diferença') + (cx.explicacao ? ' · ' + cx.explicacao : ''), cx.diferenca == null ? '—' : dif(cx.diferenca)],
      [tp('Fechada por / aprovada por'), (cx.fechada_por || '—') + ' / ' + (cx.aprovada_por || '—')],
    ],
    { tableWidth: 150, columnStyles: { 1: { halign: 'right', cellWidth: 40 } }, didParseCell: vermelhoSe([1]) },
  );
  if (cx.tpa.length) {
    titulo(tp('Multicaixa por banco (talões do TPA)'));
    const nome = (rota) => rota + ' · ' + ((P.find((p) => p.rota === rota) || {}).promotor || '');
    tabelaPdf(
      [tp('Banco'), tp('Empresa'), tp('Promotor'), tp('Valor')],
      [...cx.tpa.map((x) => [x.banco, x.empresa || '—', nome(x.rota), kz(x.valor)]), [tp('Total'), '', '', kz(cx.total_tpa)]],
      { tableWidth: 180, columnStyles: { 3: { halign: 'right' } }, didParseCell: vermelhoSe([3]) },
    );
  }

  // 4. Registo de vendas (com subtotal por promotor)
  titulo(tp('Registo de vendas'));
  const corpo = [];
  let atual = null,
    sub = 0;
  const fecharSub = () => {
    if (atual !== null)
      corpo.push([
        { content: tp('Total') + ' ' + atual, colSpan: 6, styles: { fontStyle: 'bold' } },
        { content: kz(sub), styles: { fontStyle: 'bold', halign: 'right' } },
      ]);
  };
  d.registo.forEach((l) => {
    const nome = l.rota + ' · ' + l.promotor;
    if (nome !== atual) {
      fecharSub();
      atual = nome;
      sub = 0;
    }
    sub += +l.valor || 0;
    corpo.push([nome, l.documento, l.codigo, l.artigo, n0(l.quantidade), kz(l.preco), kz(l.valor)]);
  });
  fecharSub();
  corpo.push([
    { content: tp('Total geral'), colSpan: 6, styles: { fontStyle: 'bold' } },
    { content: kz(d.registo.reduce((s, l) => s + (+l.valor || 0), 0)), styles: { fontStyle: 'bold', halign: 'right' } },
  ]);
  tabelaPdf([tp('Promotor'), tp('Documento'), tp('Código'), tp('Artigo'), tp('Qtd.'), tp('Preço unit.'), tp('Valor')], corpo, {
    columnStyles: direita([4, 5, 6]),
    didParseCell: vermelhoSe([4, 5, 6]),
  });

  // 5. Stock das carrinhas
  titulo(tp('Stock das carrinhas'));
  tabelaPdf(
    [tp('Promotor'), tp('Artigo'), tp('Inicial'), tp('Carregado'), tp('Vendido'), tp('Devolvido'), tp('Final'), tp('Contado'), tp('Diferença')],
    d.stock.map((l) => [
      l.rota + ' · ' + l.promotor,
      l.artigo,
      n0(l.inicial),
      n0(l.carregado),
      n0(l.vendido),
      n0(l.devolvido),
      n0(l.final),
      l.contado == null ? '—' : n0(l.contado),
      l.diferenca ? '!' + n0(l.diferenca) : n0(l.diferenca || 0),
    ]),
    { columnStyles: direita([2, 3, 4, 5, 6, 7, 8]), didParseCell: vermelhoSe([2, 3, 4, 5, 6, 7, 8]) },
  );

  // 6. SAP
  titulo(tp('Facturação SAP por empresa'));
  if (d.sap)
    tabelaPdf(
      [tp('Empresa'), tp('Dinheiro'), tp('Depósito'), tp('Crédito'), tp('Outros'), tp('Total')],
      Object.entries(d.sap.resumo).map(([e, x]) => [e, kz(x.dinheiro), kz(x.deposito), kz(x.credito), kz(x.outro), kz(x.total)]),
      { columnStyles: direita([1, 2, 3, 4, 5]), didParseCell: vermelhoSe([1, 2, 3, 4, 5]) },
    );
  else {
    doc
      .setFont('helvetica', 'normal')
      .setFontSize(8.5)
      .setTextColor(...CINZA)
      .text(tp('Conector SAP por ligar: esta parte do relatório não tem dados.'), M, y + 2);
    y += 10;
  }
  if (R.motivo) {
    doc
      .setFont('helvetica', 'normal')
      .setFontSize(8)
      .setTextColor(...CINZA)
      .text(tp('Motivo desta versão') + ': ' + R.motivo, M, y);
    y += 6;
  }
  doc
    .setFontSize(7)
    .setTextColor(...CINZA)
    .text('SHA-256 ' + R.sha256, M, y);
  assinaturas(doc, y + 10, [
    [tp('Preparado por (tesouraria)'), tp('Nome, assinatura e data')],
    [tp('Revisto por (controlo de gestão)'), tp('Nome, assinatura e data')],
    [tp('Aprovado por (direcção)'), tp('Nome, assinatura e data')],
  ]);
  rodapes(doc, R.numero);
  entregar(doc, R.numero + '.pdf', imprimir);
}
