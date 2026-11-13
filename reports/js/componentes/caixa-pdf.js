// Fecho de caixa em PDF (A4 ao alto, texto vectorial, gerado no navegador), como o relatório de caixa diário dos ERP de
// referência (SAP «Cash Journal», Microsoft Dynamics «Cash register statement»):
//   - reconciliação do dia: saldo inicial + dinheiro entregue − depósitos no banco = saldo final teórico, contagem
//     física por notas e diferença (com a explicação); o Multicaixa aparece à parte, porque não entra no saldo;
//   - detalhe das entregas por promotor, dos talões de fecho do TPA e dos depósitos no banco (lacre, requisitante e
//     aprovador: controlo duplo), e a conferência com o Pinguim BO por promotor;
//   - assinaturas registadas no portal (validação, 1.º nível, aprovação final) e o registo completo dos passos;
//   - autenticidade (ISO 15489): referência FC-aaaammdd, versão do fecho, código de verificação SHA-256 dos dados,
//     data e hora em que foi gerado e «Página x de y». Antes da aprovação final sai marcado «POR APROVAR».
import { dl, kz, n0 } from '../formatos.js';
import { quando, quem } from './assinantes.js';
import { assinaturas, cabecalho, entregar, estiloCabeca, estiloTabela, lerEmblema, novoDoc, rodapes, tp } from './contagem-pdf.js';

const AZUL = [39, 55, 119],
  CINZA = [86, 101, 124],
  VERMELHO = [180, 35, 24];
const PASSOS = {
  gravada: 'Lançada',
  fechada: 'Validada (contagem por notas)',
  verificada: 'Aprovada no 1.º nível',
  aprovada: 'Aprovação final',
  reaberta: 'Reaberta para corrigir',
  deposito_aprovado: 'Depósito aprovado',
  deposito_aprovacao_retirada: 'Aprovação do depósito retirada',
};
const soma = (l) => (l || []).reduce((a, x) => a + (+x.valor || 0), 0);

export const refFecho = (dia) => 'FC-' + String(dia).replace(/-/g, '');

/** Código de verificação: SHA-256 dos dados do fecho (o mesmo fecho dá sempre o mesmo código). */
export async function codigoVerificacao(c) {
  const dados = JSON.stringify([
    c.dia,
    c.versao,
    c.estado,
    c.saldo_inicial,
    c.contagem_fisica,
    (c.entregas || []).map((x) => [x.rota, x.valor]),
    (c.tpa || []).map((x) => [x.rota, x.banco, x.talao, x.valor]),
    (c.saidas || []).map((x) => [x.banco, x.lacre, x.valor, x.aprovado_por || '']),
    c.fechada_por || '',
    c.verificada_por || '',
    c.aprovada_por || '',
  ]);
  try {
    const h = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(dados));
    return [...new Uint8Array(h)].map((b) => b.toString(16).padStart(2, '0')).join('');
  } catch (e) {
    return '';
  }
}

/** Passos do fecho para o registo das assinaturas (gravações seguidas contam como um só «Lançada», como no ecrã). */
function passos(c) {
  const linhas = [];
  (c.historico || [])
    .filter((x) => PASSOS[x.accao])
    .forEach((x) => {
      const ult = linhas[linhas.length - 1];
      if (x.accao === 'gravada' && ult && ult.accao === 'gravada') {
        ult.n++;
        ult.por = x.por;
        ult.em = x.em;
      } else linhas.push({ ...x, n: 1, primeira: x.em });
    });
  return linhas.map((x) => [
    tp(PASSOS[x.accao]),
    x.por,
    x.em,
    x.accao === 'gravada' && x.n > 1
      ? tp('{n} gravações · primeira {h}', { n: x.n, h: quando(x.primeira) })
      : x.accao === 'reaberta'
        ? x.motivo || ''
        : x.accao === 'fechada' && x.diferenca
          ? tp('diferença {v}', { v: kz(x.diferenca) })
          : x.lacre
            ? [x.banco, tp('lacre {l}', { l: x.lacre }), x.valor != null ? kz(x.valor) : ''].filter(Boolean).join(' · ')
            : '',
  ]);
}

/** Título de secção com a tabela a seguir. */
function tabela(doc, y, titulo, head, body, opcoes = {}) {
  const H = doc.internal.pageSize.getHeight();
  if (y + 24 > H - 16) {
    doc.addPage();
    y = 20;
  }
  doc
    .setFont('helvetica', 'bold')
    .setFontSize(10)
    .setTextColor(...AZUL)
    .text(titulo, 14, y);
  doc.autoTable({
    startY: y + 2.5,
    margin: { left: 14, right: 14, bottom: 16 },
    head: [head],
    body: body.length ? body : [[{ content: opcoes.vazio || tp('Sem registos neste dia.'), colSpan: head.length, styles: { textColor: CINZA } }]],
    foot: opcoes.foot && body.length ? [opcoes.foot] : undefined,
    styles: { ...estiloTabela, fontSize: 8.5 },
    headStyles: estiloCabeca,
    footStyles: { fillColor: [234, 240, 249], textColor: AZUL, fontStyle: 'bold' },
    theme: 'grid',
    columnStyles: opcoes.colunas || {},
    didParseCell: (d) => {
      if ((opcoes.direita || []).includes(d.column.index)) d.cell.styles.halign = 'right';
      if (opcoes.marcar && d.section === 'body') opcoes.marcar(d);
    },
  });
  return doc.lastAutoTable.finalY + 8;
}

/**
 * Gera o PDF do fecho de caixa. c: o fecho (resposta de v1/caixa); extra: {promotores, esperado, dois_niveis}.
 * imprimir: abre para imprimir (senão descarrega).
 */
export async function fechoCaixaPdf(c, extra = {}, imprimir = false) {
  const [doc, img, codigo] = await Promise.all([novoDoc('portrait'), lerEmblema(), codigoVerificacao(c)]);
  const ref = refFecho(c.dia);
  const prom = (r) => (r ? r + (extra.promotores && extra.promotores[r] ? ' · ' + extra.promotores[r] : '') : '—');
  const dois = extra.dois_niveis !== false;
  const ESTADO = {
    aberta: 'Aberto',
    fechada: dois ? 'Validado, por aprovar (1.º nível)' : 'Validado, por aprovar',
    verificada: 'Aprovado no 1.º nível, falta a aprovação final',
    aprovada: 'Aprovado',
  };
  const final = c.estado === 'aprovada';
  doc.setProperties({
    title: tp('Fecho de caixa de {d}', { d: dl(c.dia) }),
    subject: tp('Fecho de caixa'),
    creator: 'Portal RTM · Grupo Naval',
    keywords: ref + (codigo ? ' · SHA-256 ' + codigo : ''),
  });
  const ent = soma(c.entregas),
    sai = soma(c.saidas),
    tpa = soma(c.tpa),
    si = c.saldo_inicial == null ? null : +c.saldo_inicial,
    teo = si == null ? null : si + ent - sai;
  let y = cabecalho(doc, img, tp('Fecho de caixa'), ref + ' · v' + (c.versao || 1), [
    [tp('Dia'), dl(c.dia)],
    [tp('Estado'), tp(ESTADO[c.estado] || c.estado)],
    [tp('Saldo final teórico'), kz(teo)],
    [tp('Contagem física'), c.contagem_fisica == null ? '—' : kz(c.contagem_fisica)],
  ]);

  // reconciliação do dia
  const dif = c.diferenca != null ? +c.diferenca : c.contagem_fisica != null && teo != null ? +c.contagem_fisica - teo : null;
  const rec = [
    [tp('Saldo inicial'), c.saldo_inicial_origem === 'manual' ? tp('primeiro dia: escrito à mão') : tp('saldo aprovado do dia anterior'), kz(si)],
    ['+ ' + tp('Dinheiro entregue'), tp('{n} entrega(s)', { n: (c.entregas || []).length }), kz(ent)],
    ['- ' + tp('Depósitos no banco'), tp('{n} depósito(s)', { n: (c.saidas || []).length }), kz(sai)],
    ['= ' + tp('Saldo final teórico'), '', kz(teo)],
    [tp('Contagem física'), tp('contagem por notas e moedas'), c.contagem_fisica == null ? '—' : kz(c.contagem_fisica)],
    [tp('Diferença'), c.explicacao || '', dif == null ? '—' : kz(dif)],
    [tp('Multicaixa (talões do TPA)'), tp('não entra no saldo da caixa'), kz(tpa)],
  ];
  y = tabela(doc, y, tp('Reconciliação do dia'), [tp('Rubrica'), tp('Notas'), tp('Valor')], rec, {
    colunas: { 0: { cellWidth: 62, fontStyle: 'bold' }, 2: { cellWidth: 38 } },
    direita: [2],
    marcar: (d) => {
      if (d.row.index === 3) d.cell.styles.fillColor = [234, 240, 249];
      if (d.row.index === 5 && dif != null && Math.abs(dif) >= 0.01) d.cell.styles.textColor = VERMELHO;
      if (d.row.index === 6) d.cell.styles.textColor = CINZA;
    },
  });

  y = tabela(
    doc,
    y,
    tp('Dinheiro entregue por promotor'),
    [tp('Promotor'), tp('Empresa'), tp('Notas e moedas'), tp('Valor')],
    (c.entregas || []).map((l) => [
      prom(l.rota),
      l.empresa || '',
      l.notas
        ? Object.entries(l.notas)
            .filter(([k]) => k !== 'moedas')
            .sort((a, b) => b[0] - a[0])
            .map(([k, q]) => q + ' × ' + n0(+k))
            .concat(l.notas.moedas ? [tp('moedas {v}', { v: kz(l.notas.moedas) })] : [])
            .join(' · ')
        : l.nota || '',
      kz(l.valor),
    ]),
    { foot: [tp('Total'), '', '', kz(ent)], direita: [3], colunas: { 0: { cellWidth: 55 }, 1: { cellWidth: 26 }, 3: { cellWidth: 32 } } },
  );
  y = tabela(
    doc,
    y,
    tp('Talões de fecho do TPA'),
    [tp('Promotor'), tp('Banco'), tp('Talão'), tp('Valor')],
    (c.tpa || []).map((l) => [prom(l.rota), l.banco || '', l.talao || '', kz(l.valor)]),
    { foot: [tp('Total'), '', '', kz(tpa)], direita: [3], colunas: { 0: { cellWidth: 70 }, 3: { cellWidth: 32 } } },
  );
  y = tabela(
    doc,
    y,
    tp('Depósitos no banco'),
    [tp('Banco'), tp('Código do lacre'), tp('Valor'), tp('Requisitante'), tp('Aprovador')],
    (c.saidas || []).map((l) => [
      l.banco || '',
      l.lacre || '',
      kz(l.valor),
      l.requisitante ? quem(l.requisitante, tp) + '\n' + quando(l.requisitado_em) : '—',
      l.aprovado_por ? quem(l.aprovado_por, tp) + '\n' + quando(l.aprovado_em) : tp('por aprovar'),
    ]),
    { foot: [tp('Total'), '', kz(sai), '', ''], direita: [2], vazio: tp('Sem depósitos neste dia.'), colunas: { 2: { cellWidth: 30 } } },
  );
  if (extra.esperado) {
    const rotas = [...new Set([...Object.keys(extra.esperado), ...(c.entregas || []).map((x) => x.rota), ...(c.tpa || []).map((x) => x.rota)])]
      .filter(Boolean)
      .sort();
    const linhas = rotas.map((r) => {
      const e = extra.esperado[r] || { dinheiro: 0, multicaixa: 0 };
      const d1 = soma((c.entregas || []).filter((x) => x.rota === r)),
        t1 = soma((c.tpa || []).filter((x) => x.rota === r));
      return [prom(r), kz(d1), kz(e.dinheiro), kz(d1 - e.dinheiro), kz(t1), kz(e.multicaixa), kz(t1 - e.multicaixa)];
    });
    y = tabela(
      doc,
      y,
      tp('Conferência por promotor (portal × Pinguim BO)'),
      [tp('Promotor'), tp('Entregue'), 'Pinguim BO', tp('Diferença'), tp('TPA'), 'Pinguim BO', tp('Diferença')],
      linhas,
      {
        direita: [1, 2, 3, 4, 5, 6],
        colunas: { 0: { cellWidth: 44 } },
        marcar: (d) => {
          if ((d.column.index === 3 || d.column.index === 6) && d.cell.raw !== kz(0)) d.cell.styles.textColor = VERMELHO;
        },
      },
    );
  }

  // assinaturas do circuito (registadas no portal) e registo completo dos passos
  const blocos = [[tp('Validado por (quem lança)'), tp('Nome, assinatura e data'), { por: c.fechada_por, em: c.fechada_em }]];
  if (dois) blocos.push([tp('Aprovado no 1.º nível'), tp('Nome, assinatura e data'), { por: c.verificada_por, em: c.verificada_em }]);
  blocos.push([tp('Aprovação final'), tp('Nome, assinatura e data'), { por: c.aprovada_por, em: c.aprovada_em }]);
  y = assinaturas(doc, y + 2, blocos);
  const reg = passos(c);
  if (reg.length) {
    doc.autoTable({
      startY: y + 4,
      margin: { left: 14, right: 14, bottom: 16 },
      head: [[tp('Registo das assinaturas'), tp('Assinado por'), tp('Data e hora'), tp('Notas')]],
      body: reg.map(([passo, por, em, nota]) => [passo, em ? quem(por, tp) || '—' : tp('pendente'), em ? quando(em) : '—', nota || '']),
      styles: { ...estiloTabela, fontSize: 8 },
      headStyles: estiloCabeca,
      theme: 'grid',
      columnStyles: { 0: { cellWidth: 48 }, 1: { cellWidth: 48 }, 2: { cellWidth: 30 } },
    });
    y = doc.lastAutoTable.finalY + 6;
  }
  const H = doc.internal.pageSize.getHeight();
  if (y + 10 > H - 14) {
    doc.addPage();
    y = 20;
  }
  doc
    .setFont('helvetica', 'normal')
    .setFontSize(7.5)
    .setTextColor(...CINZA)
    .text(
      doc.splitTextToSize(
        tp('Código de verificação (SHA-256 dos dados do fecho, versão {v}): {c}', { v: c.versao || 1, c: codigo || '—' }) +
          '. ' +
          tp('Quem lança valida; aprova outra pessoa (segregação de funções). Documento gerado a partir do registo do portal; nada se apaga.'),
        182,
      ),
      14,
      y,
    );

  // antes da aprovação final: marca de água «POR APROVAR» em todas as páginas
  if (!final) {
    const n = doc.getNumberOfPages();
    for (let i = 1; i <= n; i++) {
      doc.setPage(i);
      doc.saveGraphicsState();
      doc.setGState(new doc.GState({ opacity: 0.1 }));
      doc
        .setFont('helvetica', 'bold')
        .setFontSize(64)
        .setTextColor(...AZUL);
      doc.text(tp('POR APROVAR'), 105, 175, { align: 'center', angle: 35 });
      doc.restoreGraphicsState();
    }
  }
  rodapes(doc, ref + ' · v' + (c.versao || 1));
  entregar(doc, ref + '_fecho-de-caixa' + (final ? '' : '_por-aprovar') + '.pdf', imprimir);
}
