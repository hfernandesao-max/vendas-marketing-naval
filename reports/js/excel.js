// Exportação para Excel (página ou relatório completo), com registo da exportação no servidor.
import { FITS, REG, definirFits, definirReg, download, fname, numType, rawVal } from './componentes/blocos.js';
import { dadosCompletos, descreverFiltros, prepararDados } from './filtros.js';
import { dl } from './formatos.js';
import { t } from './i18n/i18n.js';
import { R } from './paginas/registo.js';
import { SESSAO, atual, podeExportar } from './perfil.js';
import { hoje, inR, st } from './periodo.js';
import { carregarScript } from './servidor.js';
import { $, DB, sum, toast } from './util.js';
import { apiFetch } from './api.js';

export const carregarExcelJS = () => carregarScript('exceljs.min.js', 'ExcelJS');
export const NAVY = 'FF1E3A8A',
  NAVY2 = 'FF283E81',
  SKY = 'FFDCE8FB',
  GREY = 'FF5F6573';
const FMT = { kz: '#,##0;-#,##0;"-"', mkz: '#,##0;-#,##0;"-"', bar: '#,##0;-#,##0;"-"', n: '#,##0;-#,##0;"-"', pct: '0.0%;-0.0%;"-"', date: 'dd-mm-yyyy' };
const toDate = (s) => {
  const [y, m, d] = s.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d));
};
export const nomeFolha = (s, usados) => {
  const b =
    s
      .replace(/[[\]:*?/\\]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim()
      .slice(0, 28) || 'Folha';
  let n = b,
    i = 2;
  while (usados.has(n.toLowerCase())) n = b.slice(0, 26) + ' ' + i++;
  usados.add(n.toLowerCase());
  return n;
};
const colLetter = (n) => {
  let s = '';
  while (n > 0) {
    const m = (n - 1) % 26;
    s = String.fromCharCode(65 + m) + s;
    n = Math.floor((n - 1) / 26);
  }
  return s;
};

// Confirma no servidor que o perfil pode exportar e regista a exportação; sem servidor PHP segue
async function autorizarExportacao(completo, tabela, linhas) {
  try {
    const r = await apiFetch('exportacoes', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      cache: 'no-store',
      body: JSON.stringify({ pagina: atual, completo, tabela, linhas, periodo: dl(st.de) + ' – ' + dl(st.ate), csrf: SESSAO && SESSAO.csrf }),
    });
    const d = await r.json().catch(() => null);
    if (!d) return !SESSAO; // resposta que não é JSON: teste local sem PHP
    if (!d.ok) {
      toast(d.erro || t('O seu perfil não permite exportar esta informação.'), 7000);
      return false;
    }
    return true;
  } catch (e) {
    return !SESSAO;
  }
}
// Nome do Excel completo: «RTM - Controlo de Gestão Consolidado-DD-MM-AAAA», com a data do dia em que é retirado
export const NOME_CONSOLIDADO = 'RTM - Controlo de Gestão Consolidado';
export const nomeConsolidado = () => NOME_CONSOLIDADO + '-' + dl(hoje()) + '.xlsx';
export async function exportarExcel(paginas, nomeFicheiro) {
  const completo = paginas.length > 1,
    linhas = paginas.reduce((s, p) => s + (p.tabelas || []).reduce((a, tb) => a + (tb.rows || []).length, 0), 0);
  if (!(completo ? podeExportar('excel-completo') : podeExportar(atual))) {
    toast(t('O seu perfil não permite exportar esta informação.'), 6000);
    return;
  }
  if (!(await autorizarExportacao(completo, nomeFicheiro, linhas))) return;
  return gerarExcel(paginas, nomeFicheiro);
}
async function gerarExcel(paginas, nomeFicheiro) {
  const bt = [$('#xl-page'), $('#xl-all')];
  bt.forEach((b) => {
    b.disabled = true;
  });
  toast(t('A preparar o Excel…'));
  try {
    await carregarExcelJS();
    const wb = new ExcelJS.Workbook();
    wb.creator = 'Portal RTM · Grupo Naval';
    wb.created = new Date();
    const F = (o) => Object.assign({ name: 'Arial', size: 10 }, o || {});
    const periodo = `${dl(st.de)} – ${dl(st.ate)}`;
    const usados = new Set();
    // Capa e índice
    const capa = wb.addWorksheet(nomeFolha(t('Capa'), usados), { views: [{ showGridLines: false }] });
    capa.columns = [{ width: 3 }, { width: 26 }, { width: 44 }, { width: 34 }, { width: 10 }];
    capa.getCell('B2').value = 'Grupo Naval Angola';
    capa.getCell('B2').font = F({ size: 11, color: { argb: GREY } });
    capa.getCell('B3').value = paginas.length === 1 ? t('Portal RTM') + ' · ' + t(paginas[0].pagina) : NOME_CONSOLIDADO;
    capa.getCell('B3').font = F({ size: 20, bold: true, color: { argb: NAVY2 } });
    [
      ['Período', periodo],
      ['Filtros', descreverFiltros(paginas.length === 1 ? atual : '*') || t('Sem filtros')],
      ['Gerado em', dl(hoje()) + ' ' + new Date().toTimeString().slice(0, 5)],
      ['Gerado por', SESSAO ? SESSAO.nome + ' (' + t(SESSAO.perfil_nome) + ')' : '—'],
      ...(paginas.length > 1
        ? [
            [
              'Conteúdo',
              t('Resumo dos indicadores, relatórios de todas as páginas e dados de base do período (sem os filtros das páginas), com dicionário de dados.'),
            ],
          ]
        : []),
      ['Nota', t('Valores em kwanzas (Kz). Os totais usam SUBTOTAL e acompanham os filtros.')],
      ['', t('Fontes: Pinguim BO (vendas e carteira de clientes) e plano de cargas.')],
    ].forEach(([k, v], i) => {
      const r = 5 + i;
      capa.getCell('B' + r).value = k ? t(k) : '';
      capa.getCell('B' + r).font = F({ bold: true, color: { argb: GREY } });
      capa.getCell('C' + r).value = v;
      capa.getCell('C' + r).font = F();
      capa.mergeCells(`C${r}:D${r}`);
    });
    const r0 = paginas.length > 1 ? 13 : 11;
    capa.getCell('B' + r0).value = t('Índice');
    capa.getCell('B' + r0).font = F({ size: 13, bold: true, color: { argb: NAVY2 } });
    const hdr = capa.getRow(r0 + 1);
    ['', t('Secção'), t('Página'), t('Folha'), t('Linhas')].forEach((v, i) => {
      if (i) {
        const c = hdr.getCell(i + 1);
        c.value = v;
        c.font = F({ bold: true, color: { argb: 'FFFFFFFF' } });
        c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: NAVY } };
      }
    });
    let ri = r0 + 2;
    // Resumo de indicadores
    const res = wb.addWorksheet(nomeFolha(t('Resumo'), usados), { views: [{ showGridLines: false, state: 'frozen', ySplit: 4 }] });
    res.columns = [{ width: 3 }, { width: 34 }, { width: 34 }, { width: 22 }, { width: 40 }];
    res.getCell('B2').value = t('Resumo') + ' · ' + periodo;
    res.getCell('B2').font = F({ size: 14, bold: true, color: { argb: NAVY2 } });
    const rh = res.getRow(4);
    [t('Página'), t('Indicador'), t('Valor'), t('Nota')].forEach((v, i) => {
      const c = rh.getCell(i + 2);
      c.value = v;
      c.font = F({ bold: true, color: { argb: 'FFFFFFFF' } });
      c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: NAVY } };
    });
    let rr = 5;
    paginas.forEach((pg) =>
      pg.kpis.forEach((k) => {
        const row = res.getRow(rr++);
        row.getCell(2).value = t(pg.pagina);
        row.getCell(3).value = k.l;
        row.getCell(4).value = k.v;
        row.getCell(4).alignment = { horizontal: 'right' };
        row.getCell(5).value = k.s || '';
        row.eachCell((c) => {
          c.font = F();
          c.border = { bottom: { style: 'thin', color: { argb: 'FFE3E6ED' } } };
        });
      }),
    );
    const linhaIndice = (sec, pag, folha, n) => {
      const row = capa.getRow(ri++);
      row.getCell(2).value = sec;
      row.getCell(3).value = pag;
      row.getCell(4).value = { text: folha, hyperlink: `#'${folha}'!A1` };
      row.getCell(4).font = F({ color: { argb: 'FF2A6FE0' }, underline: true });
      row.getCell(5).value = n;
      row.getCell(5).numFmt = '#,##0';
      [2, 3, 5].forEach((i) => {
        row.getCell(i).font = F();
      });
      row.eachCell((c) => {
        c.border = { bottom: { style: 'thin', color: { argb: 'FFE3E6ED' } } };
      });
    };
    linhaIndice('', t('Resumo'), res.name, rr - 5);
    // Uma folha por tabela
    paginas.forEach((pg) =>
      pg.tabelas.forEach((tb) => {
        const rows = tb.sorted || tb.rows;
        const ws = wb.addWorksheet(nomeFolha(tb.titulo, usados), { views: [{ showGridLines: false, state: 'frozen', ySplit: 4 }] });
        const cols = tb.cols;
        const nC = cols.length;
        ws.getCell('A1').value = tb.titulo;
        ws.getCell('A1').font = F({ size: 14, bold: true, color: { argb: NAVY2 } });
        ws.getCell('A2').value = `${pg.grupo ? t(pg.grupo) + ' · ' : ''}${t(pg.pagina)} · ${t('Período')}: ${periodo}${tb.nota ? ' · ' + tb.nota : ''}`;
        ws.getCell('A2').font = F({ size: 9, italic: true, color: { argb: GREY } });
        const back = ws.getCell(colLetter(Math.max(nC, 2)) + '1');
        back.value = { text: '← ' + t('Voltar ao índice'), hyperlink: `#'${capa.name}'!A1` };
        back.font = F({ size: 9, color: { argb: 'FF2A6FE0' }, underline: true });
        back.alignment = { horizontal: 'right' };
        const head = ws.getRow(4);
        head.height = 22;
        cols.forEach((c, i) => {
          const cell = head.getCell(i + 1);
          cell.value = c.label + (['kz', 'mkz', 'bar'].includes(c.type) ? ' (Kz)' : '');
          cell.font = F({ bold: true, color: { argb: 'FFFFFFFF' } });
          cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: NAVY } };
          cell.alignment = { vertical: 'middle', horizontal: numType(c) ? 'right' : 'left', wrapText: true };
        });
        rows.forEach((r, j) => {
          const row = ws.getRow(5 + j);
          cols.forEach((c, i) => {
            const v = rawVal(c, r);
            const cell = row.getCell(i + 1);
            if (c.type === 'date' && v) {
              cell.value = toDate(v);
              cell.numFmt = FMT.date;
            } else if (numType(c) && v != null && v !== '' && !isNaN(+v)) {
              cell.value = +v;
              cell.numFmt = c.xlfmt || FMT[c.type] || FMT.n;
            } else cell.value = v == null ? '' : typeof v === 'boolean' ? t(v ? 'Sim' : 'Não') : v;
            cell.font = F();
            if (c.wrap) cell.alignment = { wrapText: true, vertical: 'top' };
            if (j % 2) cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFF4F7FD' } };
          });
        });
        const last = 4 + rows.length;
        if (rows.length) ws.autoFilter = { from: { row: 4, column: 1 }, to: { row: last, column: nC } };
        if (tb.total && rows.length) {
          const tr = ws.getRow(last + 1);
          cols.forEach((c, i) => {
            const cell = tr.getCell(i + 1);
            const L = colLetter(i + 1);
            if (i === 0) cell.value = t('Total');
            else if (c.sum === true && numType(c)) {
              cell.value = { formula: `SUBTOTAL(109,${L}5:${L}${last})`, result: sum(rows, (r) => +rawVal(c, r) || 0) };
              cell.numFmt = c.xlfmt || FMT[c.type] || FMT.n;
            } else if (typeof c.sum === 'function') {
              cell.value = c.sum(rows);
              cell.numFmt = c.xlfmt || FMT[c.type] || FMT.n;
            }
            cell.font = F({ bold: true });
            cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: SKY } };
            cell.border = { top: { style: 'thin', color: { argb: NAVY } } };
          });
        }
        cols.forEach((c, i) => {
          const lens = [
            String(c.label).length + 4,
            ...rows.slice(0, 300).map((r) => {
              const v = rawVal(c, r);
              return typeof v === 'number' ? 14 : String(v ?? '').length;
            }),
          ];
          ws.getColumn(i + 1).width = Math.min(c.wrap ? 48 : 34, Math.max(9, Math.max(...lens) + 2));
        });
        ws.pageSetup = { orientation: 'landscape', fitToPage: true, fitToWidth: 1, fitToHeight: 0, printTitlesRow: '4:4' };
        linhaIndice(t(pg.grupo || 'Início'), t(pg.pagina), ws.name, rows.length);
      }),
    );
    // Exportação completa: dados de base em tabelas do Excel e dicionário de dados
    if (paginas.length > 1) acrescentarDados(wb, F, usados, capa, linhaIndice);
    const buf = await wb.xlsx.writeBuffer();
    download(
      paginas.length > 1 ? nomeConsolidado() : fname(nomeFicheiro, 'xlsx'),
      new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }),
    );
    toast(t('Excel transferido.'), 2500);
  } catch (e) {
    console.error(e);
    toast(t('Não foi possível gerar o Excel.'), 4000);
  }
  bt.forEach((b) => {
    b.disabled = false;
  });
}
// Desenha uma página fora do ecrã só para recolher as suas tabelas e indicadores
export function recolher(id) {
  const guarda = REG,
    gf = FITS;
  definirReg({ tabelas: [], kpis: [] });
  definirFits([]);
  const tmp = document.createElement('div');
  try {
    prepararDados(id);
    R[id](tmp);
  } catch (e) {
    console.error(id, e);
  } finally {
    prepararDados(atual);
  }
  tmp.querySelectorAll('section[id^="s-"]').forEach(() => {});
  const out = REG;
  definirReg(guarda);
  definirFits(gf);
  return out;
}

// ---- Dados de base (exportação completa) ----
// Boas práticas de exportação de dados: uma tabela por folha, cabeçalho numa só linha, sem células unidas,
// tipos reais (datas como datas, valores como números), tabelas do Excel com nome e filtros, painéis
// fixos e um dicionário de dados com a descrição de cada coluna.
const TIPO_FMT = { data: FMT.date, kz: '#,##0.00', n: '#,##0', q: '#,##0.##' };
/** Conjuntos de dados de base. D: dados (por omissão os do período, sem filtros); dentro: datas incluídas. */
export function conjuntosDados(D = dadosCompletos(), dentro = inR) {
  const vendas = D.vendas || [],
    artigos = D.artigos || [];
  const verNif = vendas.some((v) => v.nif) || (D.clientes || []).some((c) => c.nif);
  const nif = verNif ? [['nif', 'NIF', 'texto', 'Número de identificação fiscal do cliente']] : [];
  const sets = [
    {
      folha: 'Dados · Documentos',
      tabela: 'Documentos',
      desc: 'Documentos de venda do período (um por linha)',
      cols: [
        ['data', 'Data', 'data', 'Data do documento'],
        ['hora', 'Hora', 'texto', 'Hora do documento'],
        ['rota', 'Rota', 'texto', 'Código da rota (loja no Pinguim BO)'],
        ['promotor', 'Promotor', 'texto', 'Promotor da rota'],
        ['tipo', 'Tipo de documento', 'texto', 'Factura-recibo ou factura'],
        ['documento', 'Documento', 'texto', 'Número do documento'],
        ['cliente_codigo', 'Código do cliente', 'texto', 'Código do cliente no Pinguim BO'],
        ['cliente', 'Cliente', 'texto', 'Nome do cliente'],
        ...nif,
        ['total', 'Total (Kz)', 'kz', 'Valor do documento com IVA'],
        ['valor_pago', 'Valor cobrado (Kz)', 'kz', 'Valor recebido no documento, já sem o troco'],
        ['por_receber', 'Por receber (Kz)', 'kz', 'Total menos o valor cobrado'],
        ['liquidado', 'Liquidado', 'texto', 'Sim se o documento está totalmente pago'],
        ['meio_pagamento', 'Meios de cobrança', 'texto', 'Meios usados no documento'],
      ],
      rows: vendas.map((v) => ({ ...v, por_receber: Math.max(0, (+v.total || 0) - (+v.valor_pago || 0)), liquidado: t(v.liquidado ? 'Sim' : 'Não') })),
    },
    {
      folha: 'Dados · Cobranças',
      tabela: 'Cobrancas',
      desc: 'Valores cobrados por documento e meio de cobrança',
      cols: [
        ['data', 'Data', 'data', 'Data do documento'],
        ['rota', 'Rota', 'texto', 'Código da rota'],
        ['promotor', 'Promotor', 'texto', 'Promotor da rota'],
        ['documento', 'Documento', 'texto', 'Número do documento'],
        ['codigo', 'Código do meio', 'texto', 'Código do meio de cobrança no Pinguim BO'],
        ['meio', 'Meio de cobrança', 'texto', 'Numerário, Multicaixa, transferência, …'],
        ['valor', 'Valor (Kz)', 'kz', 'Valor cobrado por este meio'],
      ],
      rows: vendas.flatMap((v) => (v.pagamentos || []).map((p) => ({ data: v.data, rota: v.rota, promotor: v.promotor, documento: v.documento, ...p }))),
    },
    {
      folha: 'Dados · Linhas de artigos',
      tabela: 'LinhasArtigos',
      desc: 'Linhas de artigo de cada documento de venda',
      cols: [
        ['data', 'Data', 'data', 'Data do documento'],
        ['rota', 'Rota', 'texto', 'Código da rota'],
        ['promotor', 'Promotor', 'texto', 'Promotor da rota'],
        ['documento', 'Documento', 'texto', 'Número do documento'],
        ['codigo', 'Código do artigo', 'texto', 'Código do artigo'],
        ['artigo', 'Artigo', 'texto', 'Descrição do artigo'],
        ['unidade', 'Unidade', 'texto', 'Unidade de venda'],
        ['quantidade', 'Quantidade', 'q', 'Quantidade vendida'],
        ['preco_unitario', 'Preço unitário (Kz)', 'kz', 'Preço unitário sem IVA'],
        ['valor_sem_iva', 'Valor sem IVA (Kz)', 'kz', 'Valor líquido da linha'],
        ['desconto', 'Desconto (Kz)', 'kz', 'Desconto da linha'],
        ['iva', 'IVA (Kz)', 'kz', 'Imposto da linha'],
        ['total', 'Total (Kz)', 'kz', 'Valor da linha com IVA'],
      ],
      rows: artigos,
    },
    {
      folha: 'Dados · Carteira',
      tabela: 'Carteira',
      desc: 'Carteira de clientes por rota e dia de visita',
      cols: [
        ['rota', 'Rota', 'texto', 'Código da rota'],
        ['promotor', 'Promotor', 'texto', 'Promotor da rota'],
        ['codigo', 'Código do cliente', 'texto', 'Código do cliente'],
        ['nome', 'Cliente', 'texto', 'Nome do cliente'],
        ...nif,
        ['tipo', 'Tipo de cliente', 'texto', 'Tipo de cliente'],
        ['dia', 'Dia de visita', 'texto', 'Dia da semana da visita'],
        ['estado', 'Estado', 'texto', 'Estado do cliente'],
        ['gps', 'Com GPS', 'texto', 'Sim se o cliente tem coordenadas'],
      ],
      rows: (D.clientes || []).map((c) => ({ ...c, gps: c.tem_gps == null ? '' : t(c.tem_gps ? 'Sim' : 'Não') })),
    },
    {
      folha: 'Dados · Plano de cargas',
      tabela: 'PlanoCargas',
      desc: 'Carga de cada carrinha por dia (plano de cargas da plataforma)',
      cols: [
        ['data', 'Data', 'data', 'Dia do plano'],
        ['rota', 'Rota', 'texto', 'Código da rota'],
        ['promotor', 'Promotor', 'texto', 'Promotor / vendedor'],
        ['motorista', 'Motorista', 'texto', 'Motorista'],
        ['matricula', 'Matrícula', 'texto', 'Matrícula da viatura'],
        ['viatura', 'Viatura', 'texto', 'Modelo da viatura'],
        ['transportadora', 'Transportadora', 'texto', 'Transportadora'],
        ['zonas', 'Zonas', 'texto', 'Zonas de descarga'],
        ['carga_fraldas', 'Fraldas (cx)', 'n', 'Caixas de fraldas'],
        ['carga_sabao', 'Sabão (cx)', 'n', 'Caixas de sabão'],
        ['carga_sabonete', 'Sabonete (cx)', 'n', 'Caixas de sabonete'],
        ['carga', 'Total (cx)', 'n', 'Total de caixas carregadas'],
      ],
      rows: (D.cargas || []).filter((c) => dentro(c.data)),
    },
  ];
  // Cadastros: colunas como vêm do repositório rtm-clientes
  const cad = (D.cad && D.cad.cadastros) || [];
  if (cad.length) {
    const ks = Object.keys(cad[0]).filter((k) => typeof cad[0][k] !== 'object' && k !== 'chave');
    sets.push({
      folha: 'Dados · Cadastros',
      tabela: 'Cadastros',
      desc: 'Cadastros de clientes RTM',
      cols: ks.map((k) => [
        k,
        k.charAt(0).toUpperCase() + k.slice(1).replace(/_/g, ' '),
        /^data/.test(k) ? 'data' : typeof cad[0][k] === 'number' ? 'n' : 'texto',
        '',
      ]),
      rows: cad,
    });
  }
  // Stock (quando o portal o tem): linhas de carrinhas e de armazéns
  const S = DB.stock;
  if (S && (Array.isArray(S.linhas_carrinhas) || Array.isArray(S.linhas_armazens))) {
    sets.push({
      folha: 'Dados · Stock',
      tabela: 'Stock',
      desc: 'Posição de stock por carrinha e armazém (só quantidades diferentes de zero)',
      cols: [
        ['data', 'Data', 'data', 'Data da posição de stock'],
        ['local', 'Carrinha / armazém', 'texto', 'Carrinha (rota) ou armazém central'],
        ['rota', 'Rota', 'texto', 'Rota da carrinha'],
        ['codigo', 'Código do artigo', 'texto', 'Código do artigo'],
        ['artigo', 'Artigo', 'texto', 'Descrição do artigo'],
        ['quantidade', 'Quantidade', 'q', 'Quantidade em stock'],
      ],
      rows: [
        ...(S.linhas_carrinhas || []).map((l) => ({ ...l, local: l.carrinha || l.armazem, artigo: l.artigo || l.produto })),
        ...(S.linhas_armazens || []).map((l) => ({ ...l, local: l.armazem, artigo: l.artigo || l.produto })),
      ].filter((l) => +l.quantidade),
    });
  }
  // Rotas
  sets.push({
    folha: 'Dados · Rotas',
    tabela: 'Rotas',
    desc: 'Rotas e promotores',
    cols: [
      ['rota', 'Rota', 'texto', 'Código da rota'],
      ['promotor', 'Promotor', 'texto', 'Promotor da rota'],
    ],
    rows: DB.rotas || [],
  });
  return sets;
}
export function acrescentarDados(wb, F, usados, capa, linhaIndice, conjuntos = conjuntosDados(), periodo = dl(st.de) + ' – ' + dl(st.ate)) {
  const dic = [];
  conjuntos.forEach((ds) => {
    if (!ds.rows.length) return;
    const ws = wb.addWorksheet(nomeFolha(t(ds.folha), usados), { views: [{ state: 'frozen', ySplit: 3, showGridLines: true }] });
    ws.getCell('A1').value = t(ds.desc) + ' · ' + t('Período') + ': ' + periodo;
    ws.getCell('A1').font = F({ size: 12, bold: true, color: { argb: NAVY2 } });
    const back = ws.getCell('A2');
    back.value = { text: '← ' + t('Voltar ao índice'), hyperlink: `#'${capa.name}'!A1` };
    back.font = F({ size: 9, color: { argb: 'FF2A6FE0' }, underline: true });
    const valor = (c, r) => {
      const v = r[c[0]];
      if (v == null || v === '') return null;
      if (c[2] === 'data') return toDate(String(v).slice(0, 10));
      if (c[2] !== 'texto') return isNaN(+v) ? null : +v;
      return String(v);
    };
    ws.addTable({
      name: 'tbl' + ds.tabela,
      ref: 'A3',
      headerRow: true,
      style: { theme: 'TableStyleMedium2', showRowStripes: true },
      columns: ds.cols.map((c) => ({ name: t(c[1]), filterButton: true })),
      rows: ds.rows.map((r) => ds.cols.map((c) => valor(c, r))),
    });
    ds.cols.forEach((c, i) => {
      const col = ws.getColumn(i + 1);
      if (TIPO_FMT[c[2]]) col.numFmt = TIPO_FMT[c[2]];
      const lens = [String(t(c[1])).length + 4, ...ds.rows.slice(0, 300).map((r) => (c[2] === 'texto' ? String(r[c[0]] ?? '').length : 14))];
      col.width = Math.min(48, Math.max(10, Math.max(...lens) + 2));
      dic.push([ws.name, t(c[1]), { texto: 'Texto', data: 'Data', kz: 'Valor (Kz)', n: 'Número', q: 'Quantidade' }[c[2]], t(c[3] || '')]);
    });
    ws.pageSetup = { orientation: 'landscape', fitToPage: true, fitToWidth: 1, fitToHeight: 0, printTitlesRow: '3:3' };
    linhaIndice(t('Dados de base'), t(ds.desc), ws.name, ds.rows.length);
  });
  // Dicionário de dados
  const wd = wb.addWorksheet(nomeFolha(t('Dicionário de dados'), usados), { views: [{ state: 'frozen', ySplit: 3 }] });
  wd.getCell('A1').value = t('Dicionário de dados');
  wd.getCell('A1').font = F({ size: 12, bold: true, color: { argb: NAVY2 } });
  wd.addTable({
    name: 'tblDicionario',
    ref: 'A3',
    headerRow: true,
    style: { theme: 'TableStyleLight9', showRowStripes: true },
    columns: [t('Folha'), t('Coluna'), t('Tipo'), t('Descrição')].map((n) => ({ name: n, filterButton: true })),
    rows: dic.map((d) => [d[0], d[1], t(d[2]), d[3]]),
  });
  [30, 28, 14, 70].forEach((w, i) => {
    wd.getColumn(i + 1).width = w;
  });
  linhaIndice(t('Dados de base'), t('Dicionário de dados'), wd.name, dic.length);
}
