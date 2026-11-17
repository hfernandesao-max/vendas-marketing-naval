// Consolidado diário de vendas RTM da Naval em PDF (A4 ao baixo, texto vectorial), sempre a partir da versão congelada
// no servidor: resumo no formato do SALES REPORT, conferências, excepções, vendas e cobranças por promotor, caixa,
// bancos, registo de vendas e stock das carrinhas. Cada página tem a referência, a impressão digital (SHA-256) e
// «Página x de y»; no fim, as assinaturas (preparado, revisto e aprovado).
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
  const NOME = d.consolidado ? tp('Consolidado diário de vendas · Naval · RTM') : tp('Relatório de Vendas');
  doc.setProperties({ title: NOME + ' ' + R.numero, subject: NOME, creator: 'Portal RTM · Grupo Naval' });
  let y = cabecalho(
    doc,
    img,
    NOME,
    R.numero,
    [
      [tp('Dia'), dl(R.dia)],
      [tp('Vendas RTM (líquidas)'), kz(r.vendas)],
      [tp('Dinheiro'), kz(r.dinheiro)],
      [tp('Multicaixa'), kz(r.multicaixa)],
      [tp('Por receber'), kz(r.por_receber)],
      [tp('Gerado'), dl(R.gerado_em.slice(0, 10)) + ' ' + R.gerado_em.slice(11, 16) + ' · ' + R.gerado_por],
    ],
    'Portal RTM · Comercial · Vendas',
  );
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
        caixa: tp('Fecho de caixa'),
      }[k] || k,
      { ok: tp('Completo'), por_ligar: tp('Por ligar'), pendente: tp('Em falta'), erro: tp('Sem ligação') }[x.estado] || x.estado,
      x.detalhe,
    ]),
    { columnStyles: { 0: { cellWidth: 60 }, 1: { cellWidth: 26 } } },
  );

  // 1.1 Consolidado: resumo (SALES REPORT), conferências e excepções
  const c = d.consolidado;
  if (c) {
    const v = c.vendas,
      m = c.meios,
      k = c.caixa;
    titulo(tp('Resumo do dia') + ' · ' + (c.diferencas ? tp('{n} conferência(s) com diferença', { n: c.diferencas }) : tp('Tudo confere')));
    const forte = (a, b) => [
      { content: a, styles: { fontStyle: 'bold' } },
      { content: b, styles: { fontStyle: 'bold', halign: 'right' } },
    ];
    tabelaPdf(
      [tp('Linha'), tp('Valor')],
      [
        [tp('Vendas brutas ({n} documentos)', { n: n0(v.documentos) }), kz(v.brutas)],
        [tp('Notas de crédito ({n})', { n: n0(v.notas_credito_documentos) }), kz(-v.notas_credito)],
        forte(tp('Vendas líquidas'), kz(v.liquidas)),
        [tp('Dinheiro'), kz(m.dinheiro)],
        [tp('Multicaixa'), kz(m.multicaixa)],
        ...(m.outros ? [[tp('Outros meios'), kz(m.outros)]] : []),
        [tp('Por receber (crédito)'), kz(m.por_receber)],
        ...(m.compensacao ? [[tp('das quais compensadas com notas de crédito'), kz(m.compensacao)]] : []),
        ...(m.excesso ? [[tp('Cobrado a mais'), kz(m.excesso)]] : []),
        [tp('Caixa: saldo inicial'), kz(k.saldo_inicial)],
        [tp('Caixa: dinheiro entregue pelos promotores'), kz(k.entregue)],
        [tp('Caixa: depósitos no banco'), kz(-k.depositos)],
        forte(tp('Caixa: saldo final (contagem física)'), kz(k.contagem_fisica)),
      ],
      { tableWidth: 150, columnStyles: { 1: { halign: 'right', cellWidth: 40 } } },
    );
    titulo(tp('Conferências'));
    tabelaPdf(
      [tp('Conferência'), tp('Fonte 1'), tp('Fonte 2'), tp('Diferença'), tp('Resultado')],
      c.conferencias.map((x) => [
        tp(x.nome),
        x.unidade ? '—' : kz(x.valor_origem),
        x.unidade ? '—' : kz(x.valor_destino),
        (x.estado === 'diferenca' ? '!' : '') + (x.unidade ? tp('{n} linha(s)', { n: n0(x.diferenca) }) : kz(x.diferenca)),
        (x.estado === 'diferenca' ? '!' : '') + tp({ confere: 'Confere', diferenca: 'Diferença', sem_dados: 'Sem dados' }[x.estado] || x.estado),
      ]),
      { columnStyles: { 0: { cellWidth: 95 }, ...direita([1, 2, 3]) }, didParseCell: vermelhoSe([1, 2, 3]) },
    );
    if (c.excepcoes.length) {
      titulo(tp('Excepções a explicar ({n})', { n: n0(c.excepcoes.length) }));
      const TIPO = { caixa: 'Caixa', dinheiro: 'Dinheiro', multicaixa: 'Multicaixa', stock: 'Stock', por_receber: 'Por receber' };
      tabelaPdf(
        [tp('Tipo'), tp('Promotor'), tp('Detalhe'), tp('Valor'), tp('Explicação')],
        c.excepcoes.map((x) => [
          tp(TIPO[x.tipo] || x.tipo),
          x.promotor,
          x.detalhe,
          (x.tipo === 'por_receber' ? '' : '!') + (x.tipo === 'stock' ? n0(x.valor) + (x.unidade ? ' ' + x.unidade : '') : kz(x.valor)),
          '',
        ]),
        {
          columnStyles: { 0: { cellWidth: 24 }, 1: { cellWidth: 50 }, 3: { halign: 'right', cellWidth: 32 }, 4: { cellWidth: 60 } },
          didParseCell: vermelhoSe([3]),
        },
      );
    }
  }

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
        '- ' +
          tp(SAIDA[s.tipo] || s.tipo) +
          (s.banco ? ' · ' + s.banco : '') +
          (s.lacre ? ' · ' + tp('lacre {l}', { l: s.lacre }) : '') +
          (s.empresa ? ' · ' + s.empresa : '') +
          (s.descricao ? ' · ' + s.descricao : ''),
        kz(-s.valor),
      ]),
      [tp('Saldo final teórico'), kz(cx.saldo_teorico)],
      [tp('Contagem física'), kz(cx.contagem_fisica)],
      [tp('Diferença') + (cx.explicacao ? ' · ' + cx.explicacao : ''), cx.diferenca == null ? '—' : dif(cx.diferenca)],
      [tp('Fechada por / aprovada por'), (cx.fechada_por || '—') + ' / ' + (cx.aprovada_por || '—')],
    ],
    { tableWidth: 150, columnStyles: { 1: { halign: 'right', cellWidth: 40 } }, didParseCell: vermelhoSe([1]) },
  );
  if (c && c.bancos.length) {
    titulo(tp('Bancos: Multicaixa e depósitos'));
    const B = c.bancos;
    const sb = (k) => B.reduce((s, b) => s + (+b[k] || 0), 0);
    tabelaPdf(
      [tp('Banco'), tp('Conta'), tp('Talões'), tp('Multicaixa'), tp('Lacres'), tp('Depósito'), tp('Total')],
      [
        ...B.map((b) => [b.banco, b.conta || '—', n0(b.talaos), kz(b.multicaixa), n0(b.lacres), kz(b.deposito), kz(b.total)]),
        [tp('Total'), '', n0(sb('talaos')), kz(sb('multicaixa')), n0(sb('lacres')), kz(sb('deposito')), kz(sb('total'))],
      ],
      { columnStyles: direita([2, 3, 4, 5, 6]), didParseCell: vermelhoSe([2, 3, 4, 5, 6]) },
    );
  } else if (cx.tpa.length) {
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
