// «Exportar dados»: todos os dados de gestão do negócio num só Excel (extracção de dados), desde o
// arranque do Pinguim BO até hoje, sem os filtros das páginas e com o âmbito do perfil (rotas, NIF).
// Boas práticas de extracção de dados: uma tabela do Excel por conjunto, tipos reais, cabeçalho numa só
// linha, painéis fixos, dicionário de dados, capa com proveniência, âmbito e classificação, e registo.
import { download } from './componentes/blocos.js';
import { GREY, NAVY2, acrescentarDados, carregarExcelJS, conjuntosDados, nomeFolha } from './excel.js';
import { dadosCompletos } from './filtros.js';
import { dl } from './formatos.js';
import { t } from './i18n/i18n.js';
import { SESSAO, podeExportar } from './perfil.js';
import { INICIO_PINGUIM, hoje } from './periodo.js';
import { $, toast } from './util.js';
import { apiFetch } from './api.js';

export const NOME_DADOS = 'RTM - Dados de Gestão';
export const nomeDados = () => NOME_DADOS + '-' + dl(hoje()) + '.xlsx';

/** Confirma no servidor que o perfil pode exportar os dados e regista a exportação; sem PHP (testes locais) segue. */
async function autorizar(linhas, periodo) {
  try {
    const r = await apiFetch('exportacoes', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      cache: 'no-store',
      body: JSON.stringify({ pagina: 'exportar-dados', completo: false, tabela: NOME_DADOS, linhas, periodo, csrf: SESSAO && SESSAO.csrf }),
    });
    const d = await r.json().catch(() => null);
    if (!d) return !SESSAO;
    if (!d.ok) {
      toast(t(d.erro || 'O seu perfil não permite exportar esta informação.'), 7000);
      return false;
    }
    return true;
  } catch (e) {
    return !SESSAO;
  }
}

/** Vendas e linhas de artigos de todo o histórico (sem mexer nos dados do período aberto no portal). */
async function lerHistorico(de, ate) {
  const r = await apiFetch(`vendas?start_date=${de.replace(/-/g, '')}&end_date=${ate.replace(/-/g, '')}&include_items=1`);
  const d = await r.json().catch(() => null);
  if (!r.ok || !d || d.erro) throw new Error((d && d.erro) || t('Não foi possível ler os dados do Pinguim BO.'));
  return { vendas: Array.isArray(d) ? d : d.vendas || [], artigos: Array.isArray(d.artigos) ? d.artigos : [] };
}

export async function exportarDados() {
  if (!podeExportar('exportar-dados')) {
    toast(t('O seu perfil não permite exportar esta informação.'), 6000);
    return;
  }
  const bt = $('#xl-dados');
  bt.disabled = true;
  toast(t('A preparar os dados de gestão…'));
  try {
    const de = INICIO_PINGUIM,
      ate = hoje();
    const [H] = await Promise.all([lerHistorico(de, ate), carregarExcelJS()]);
    const conjuntos = conjuntosDados({ ...dadosCompletos(), ...H }, () => true).filter((c) => c.rows.length);
    const linhas = conjuntos.reduce((s, c) => s + c.rows.length, 0);
    if (!(await autorizar(linhas, dl(de) + ' – ' + dl(ate)))) return;
    const wb = gerar(conjuntos, de, ate);
    const buf = await wb.xlsx.writeBuffer();
    download(nomeDados(), new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }));
    toast(t('Excel transferido.'), 2500);
  } catch (e) {
    console.error(e);
    toast(e && e.message ? t(e.message) : t('Não foi possível gerar o Excel.'), 6000);
  } finally {
    bt.disabled = false;
  }
}

function gerar(conjuntos, de, ate) {
  const wb = new ExcelJS.Workbook();
  wb.creator = 'Portal RTM · Grupo Naval';
  wb.created = new Date();
  wb.title = NOME_DADOS;
  const F = (o) => Object.assign({ name: 'Arial', size: 10 }, o || {});
  const usados = new Set();
  const periodo = dl(de) + ' – ' + dl(ate);
  const capa = wb.addWorksheet(nomeFolha(t('Capa'), usados), { views: [{ showGridLines: false }] });
  capa.columns = [{ width: 3 }, { width: 26 }, { width: 44 }, { width: 34 }, { width: 12 }];
  capa.getCell('B2').value = 'Grupo Naval Angola';
  capa.getCell('B2').font = F({ size: 11, color: { argb: GREY } });
  capa.getCell('B3').value = NOME_DADOS;
  capa.getCell('B3').font = F({ size: 20, bold: true, color: { argb: NAVY2 } });
  const rotas = SESSAO && Array.isArray(SESSAO.rotas) && SESSAO.rotas.length ? SESSAO.rotas.join(', ') : t('Todas as rotas do perfil');
  [
    ['Conteúdo', t('Todos os dados de gestão do negócio, em tabelas, com dicionário de dados.')],
    ['Período', periodo + ' · ' + t('desde o arranque do Pinguim BO até hoje')],
    ['Âmbito', t('Sem os filtros das páginas. Rotas: {r}.', { r: rotas })],
    ['Gerado em', dl(hoje()) + ' ' + new Date().toTimeString().slice(0, 5)],
    ['Gerado por', SESSAO ? SESSAO.nome + ' (' + t(SESSAO.perfil_nome) + ')' : '—'],
    ['Classificação', t('Confidencial. Contém dados de clientes e de vendas: não partilhar fora do Grupo Naval.')],
    ['Nota', t('Valores em kwanzas (Kz). Fontes: Pinguim BO (vendas, cobranças, artigos, clientes, stock), plano de cargas e cadastros RTM.')],
  ].forEach(([k, v], i) => {
    const r = 5 + i;
    capa.getCell('B' + r).value = t(k);
    capa.getCell('B' + r).font = F({ bold: true, color: { argb: GREY } });
    capa.getCell('B' + r).alignment = { vertical: 'top' };
    capa.getCell('C' + r).value = v;
    capa.getCell('C' + r).font = F();
    capa.getCell('C' + r).alignment = { wrapText: true, vertical: 'top' };
    capa.mergeCells(`C${r}:D${r}`);
  });
  const r0 = 13;
  capa.getCell('B' + r0).value = t('Índice');
  capa.getCell('B' + r0).font = F({ size: 13, bold: true, color: { argb: NAVY2 } });
  const hdr = capa.getRow(r0 + 1);
  ['', t('Folha'), t('Conteúdo'), '', t('Linhas')].forEach((v, i) => {
    if (i === 0) return;
    const c = hdr.getCell(i + 1);
    c.value = v;
    c.font = F({ bold: true, color: { argb: 'FFFFFFFF' } });
    c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: NAVY2 } };
  });
  let n = 0;
  const linhaIndice = (_grupo, desc, folha, linhas) => {
    const row = capa.getRow(r0 + 2 + n++);
    row.getCell(2).value = { text: folha, hyperlink: `#'${folha}'!A1` };
    row.getCell(2).font = F({ color: { argb: 'FF2A6FE0' }, underline: true });
    row.getCell(3).value = desc;
    row.getCell(3).font = F({ size: 9, color: { argb: GREY } });
    row.getCell(5).value = linhas;
    row.getCell(5).numFmt = '#,##0';
    row.getCell(5).font = F();
  };
  acrescentarDados(wb, F, usados, capa, linhaIndice, conjuntos, periodo);
  capa.pageSetup = { orientation: 'portrait', fitToPage: true, fitToWidth: 1, fitToHeight: 0 };
  return wb;
}
