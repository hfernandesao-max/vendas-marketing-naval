// Exportação para Excel (página ou relatório completo), com registo da exportação no servidor.
import { FITS, REG, definirFits, definirReg, download, fname, numType, rawVal } from './componentes/blocos.js';
import { descreverFiltros, prepararDados } from './filtros.js';
import { dl } from './formatos.js';
import { t } from './i18n/i18n.js';
import { R } from './paginas/registo.js';
import { SESSAO, atual, podeExportar } from './perfil.js';
import { hoje, st } from './periodo.js';
import { carregarScript } from './servidor.js';
import { $, sum, toast } from './util.js';

export const carregarExcelJS = () => carregarScript('exceljs.min.js', 'ExcelJS');
const NAVY = 'FF1E3A8A',
  NAVY2 = 'FF283E81',
  SKY = 'FFDCE8FB',
  GREY = 'FF5F6573';
const FMT = { kz: '#,##0;-#,##0;"-"', mkz: '#,##0;-#,##0;"-"', bar: '#,##0;-#,##0;"-"', n: '#,##0;-#,##0;"-"', pct: '0.0%;-0.0%;"-"', date: 'dd-mm-yyyy' };
const toDate = (s) => {
  const [y, m, d] = s.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d));
};
const nomeFolha = (s, usados) => {
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
    const r = await fetch('data/registar.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      cache: 'no-store',
      body: JSON.stringify({ pagina: atual, completo, tabela, linhas, periodo: dl(st.de) + ' – ' + dl(st.ate) }),
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
    const r0 = 10;
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
