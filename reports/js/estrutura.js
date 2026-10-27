// Excel «Dicionário técnico da base de dados» (Administração › Acessos): estrutura, sem dados de negócio.
// Boas práticas de documentação de dados (metadados técnicos e de negócio, ao estilo DAMA-DMBOK):
// capa com proveniência e classificação, índice com ligações, uma tabela do Excel com nome por folha,
// cabeçalho numa só linha, painéis fixos, filtros, sem células unidas nas tabelas e convenções explicadas.
import { download } from './componentes/blocos.js';
import { NAVY2, GREY, carregarExcelJS, conjuntosDados, nomeFolha } from './excel.js';
import { dl } from './formatos.js';
import { t } from './i18n/i18n.js';
import { nomeRecurso } from './paginas/catalogo.js';
import { SESSAO, podeExportar } from './perfil.js';
import { hoje } from './periodo.js';
import { toast } from './util.js';

export const NOME_ESTRUTURA = 'RTM - Dicionário Técnico da Base de Dados';
export const nomeEstrutura = () => NOME_ESTRUTURA + '-' + dl(hoje()) + '.xlsx';
const NIVEIS = { '': '—', Vr: 'V·r', V: 'V', Er: 'E·r', E: 'E', G: 'G', A: 'A' };
const TIPOS_PORTAL = { texto: 'Texto', data: 'Data', kz: 'Valor (Kz)', n: 'Número', q: 'Quantidade' };
const simNao = (v) => t(v ? 'Sim' : 'Não');
const dataHora = (s) => (s ? dl(String(s).slice(0, 10)) + ' ' + String(s).slice(11, 16) : '');

/** Pede a estrutura ao servidor; sem servidor PHP (testes locais) segue só com o modelo do portal. */
async function lerEstrutura() {
  try {
    const r = await fetch('data/estrutura-dados.php', { cache: 'no-store' });
    const d = await r.json().catch(() => null);
    if (!d) return { bd: { disponivel: false, erro: t('A estrutura da base de dados precisa do servidor da plataforma (PHP).') } };
    if (!r.ok) throw new Error(d.erro || t('O seu perfil não permite exportar a estrutura de dados.'));
    return d;
  } catch (e) {
    if (SESSAO) throw e;
    return { bd: { disponivel: false, erro: t('A estrutura da base de dados precisa do servidor da plataforma (PHP).') } };
  }
}

export async function exportarEstrutura(bt) {
  if (!podeExportar('estrutura-dados')) {
    toast(t('O seu perfil não permite exportar a estrutura de dados.'), 6000);
    return;
  }
  if (bt) bt.disabled = true;
  toast(t('A preparar a estrutura de dados…'));
  try {
    const [E] = await Promise.all([lerEstrutura(), carregarExcelJS()]);
    const wb = gerar(E);
    const buf = await wb.xlsx.writeBuffer();
    download(nomeEstrutura(), new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }));
    toast(t('Excel transferido.'), 2500);
  } catch (e) {
    console.error(e);
    toast(e && e.message ? t(e.message) : t('Não foi possível gerar o Excel.'), 6000);
  }
  if (bt) bt.disabled = false;
}

function gerar(E) {
  const BD = E.bd || { disponivel: false };
  const wb = new ExcelJS.Workbook();
  wb.creator = 'Portal RTM · Grupo Naval';
  wb.created = new Date();
  wb.title = NOME_ESTRUTURA;
  wb.subject = t('Dicionário de dados');
  const F = (o) => Object.assign({ name: 'Arial', size: 10 }, o || {});
  const usados = new Set();
  const indice = [];

  // ---- Capa ----
  const capa = wb.addWorksheet(nomeFolha(t('Capa'), usados), { views: [{ showGridLines: false }] });
  capa.columns = [{ width: 3 }, { width: 26 }, { width: 44 }, { width: 44 }, { width: 12 }];
  capa.getCell('B2').value = 'Grupo Naval Angola';
  capa.getCell('B2').font = F({ size: 11, color: { argb: GREY } });
  capa.getCell('B3').value = NOME_ESTRUTURA;
  capa.getCell('B3').font = F({ size: 20, bold: true, color: { argb: NAVY2 } });
  const meta = [
    ['Conteúdo', t('Dicionário de dados: estrutura da base de dados do Pinguim BO, modelo de dados do portal, matriz de acessos e fontes de dados.')],
    ['Base de dados', BD.disponivel ? `${BD.base} · ${BD.motor}` : t(BD.erro || 'Base de dados indisponível.')],
    [
      'Objectos',
      BD.disponivel
        ? t('{t} tabelas, {v} vistas, {c} colunas, {r} relações, {i} índices', {
            t: BD.objectos.filter((o) => o.tipo === 'Tabela').length,
            v: BD.objectos.filter((o) => o.tipo === 'Vista').length,
            c: BD.colunas.length,
            r: new Set(BD.relacoes.map((r) => r.relacao)).size,
            i: BD.indices.length,
          })
        : '—',
    ],
    ['Gerado em', dl(hoje()) + ' ' + new Date().toTimeString().slice(0, 5)],
    ['Gerado por', SESSAO ? SESSAO.nome + ' (' + t(SESSAO.perfil_nome) + ')' : '—'],
    ['Classificação', t('Uso interno. Contém só metadados (estrutura); não contém dados de clientes nem de vendas.')],
    ['Método', t('Leitura do catálogo da base de dados só com SELECT (sys.* / INFORMATION_SCHEMA). Linhas: número aproximado.')],
  ];
  meta.forEach(([k, v], i) => {
    const r = 5 + i;
    capa.getCell('B' + r).value = t(k);
    capa.getCell('B' + r).font = F({ bold: true, color: { argb: GREY } });
    capa.getCell('B' + r).alignment = { vertical: 'top' };
    capa.getCell('C' + r).value = v;
    capa.getCell('C' + r).font = F();
    capa.getCell('C' + r).alignment = { wrapText: true, vertical: 'top' };
    capa.mergeCells(`C${r}:D${r}`);
  });
  let r0 = 5 + meta.length + 1;
  capa.getCell('B' + r0).value = t('Convenções');
  capa.getCell('B' + r0).font = F({ size: 13, bold: true, color: { argb: NAVY2 } });
  [
    ['PK', t('Chave primária: identifica cada linha da tabela.')],
    ['FK', t('Chave estrangeira: coluna que aponta para a chave de outra tabela (esquema.tabela.coluna).')],
    [
      t('Tipo de dados'),
      t('Tipos do SQL Server: nvarchar(n) texto Unicode, decimal(p,e) número com e casas decimais, datetime data e hora; max = sem limite.'),
    ],
    [t('Níveis de acesso'), t('V ver · V·r ver só as suas rotas · E exportar · E·r exportar só as suas rotas · G gerir · A administrar · — sem acesso.')],
  ].forEach(([k, v], i) => {
    const r = r0 + 1 + i;
    capa.getCell('B' + r).value = k;
    capa.getCell('B' + r).font = F({ bold: true, color: { argb: GREY } });
    capa.getCell('B' + r).alignment = { vertical: 'top' };
    capa.getCell('C' + r).value = v;
    capa.getCell('C' + r).font = F();
    capa.getCell('C' + r).alignment = { wrapText: true, vertical: 'top' };
    capa.mergeCells(`C${r}:D${r}`);
  });
  r0 += 6;
  capa.getCell('B' + r0).value = t('Índice');
  capa.getCell('B' + r0).font = F({ size: 13, bold: true, color: { argb: NAVY2 } });

  // ---- Folhas: uma tabela do Excel por folha ----
  const folha = (titulo, nota, tabela, colunas, linhas, larguras, estilo = 'TableStyleMedium2') => {
    const ws = wb.addWorksheet(nomeFolha(t(titulo), usados), { views: [{ state: 'frozen', ySplit: 4 }] });
    ws.getCell('A1').value = t(titulo);
    ws.getCell('A1').font = F({ size: 12, bold: true, color: { argb: NAVY2 } });
    ws.getCell('A2').value = t(nota);
    ws.getCell('A2').font = F({ size: 9, color: { argb: GREY } });
    const back = ws.getCell('A3');
    back.value = { text: '← ' + t('Voltar ao índice'), hyperlink: `#'${capa.name}'!A1` };
    back.font = F({ size: 9, color: { argb: 'FF2A6FE0' }, underline: true });
    const rows = linhas.length ? linhas : [colunas.map(() => null)];
    ws.addTable({
      name: 'tbl' + tabela,
      ref: 'A4',
      headerRow: true,
      style: { theme: estilo, showRowStripes: true },
      columns: colunas.map((c) => ({ name: t(c), filterButton: true })),
      rows,
    });
    larguras.forEach((w, i) => {
      ws.getColumn(i + 1).width = w;
    });
    ws.pageSetup = { orientation: 'landscape', fitToPage: true, fitToWidth: 1, fitToHeight: 0, printTitlesRow: '4:4' };
    indice.push([t(titulo), ws.name, linhas.length, t(nota)]);
    return ws;
  };

  if (BD.disponivel) {
    folha(
      'Tabelas e vistas',
      'Objectos da base de dados do Pinguim BO, com o número aproximado de linhas.',
      'Objectos',
      ['Esquema', 'Objecto', 'Tipo', 'Linhas (aprox.)', 'Colunas', 'Chave primária', 'Relações (FK)', 'Criado', 'Alterado', 'Descrição'],
      BD.objectos.map((o) => [
        o.esquema,
        o.objecto,
        t(o.tipo),
        o.linhas,
        o.colunas,
        o.chave_primaria,
        o.relacoes,
        dataHora(o.criado),
        dataHora(o.alterado),
        o.descricao,
      ]),
      [10, 34, 9, 15, 10, 28, 13, 17, 17, 50],
    ).getColumn(4).numFmt = '#,##0';
    folha(
      'Colunas',
      'Todas as colunas de cada tabela e vista, pela ordem da base de dados.',
      'Colunas',
      [
        'Esquema',
        'Objecto',
        'Ordem',
        'Coluna',
        'Tipo de dados',
        'Aceita nulos',
        'PK',
        'FK (referência)',
        'Identidade',
        'Calculada',
        'Valor por omissão',
        'Descrição',
      ],
      BD.colunas.map((c) => [
        c.esquema,
        c.objecto,
        c.ordem,
        c.coluna,
        c.tipo,
        simNao(c.nulo),
        c.chave_primaria ? 'PK' : '',
        c.chave_estrangeira,
        simNao(c.identidade),
        simNao(c.calculada),
        c.omissao,
        c.descricao,
      ]),
      [10, 30, 8, 30, 18, 12, 6, 40, 11, 11, 18, 44],
    );
    folha(
      'Relações',
      'Chaves estrangeiras: uma linha por coluna da relação, com as regras ao apagar e ao alterar.',
      'Relacoes',
      ['Relação', 'Esquema', 'Tabela', 'Coluna', 'Tabela referida', 'Coluna referida', 'Ao apagar', 'Ao alterar'],
      BD.relacoes.map((r) => [r.relacao, r.esquema, r.tabela, r.coluna, r.tabela_ref, r.coluna_ref, r.ao_apagar, r.ao_alterar]),
      [36, 10, 30, 26, 34, 26, 14, 14],
    );
    folha(
      'Índices',
      'Índices de cada tabela: colunas da chave por ordem e colunas incluídas.',
      'Indices',
      ['Esquema', 'Tabela', 'Índice', 'Tipo', 'Único', 'Chave primária', 'Colunas', 'Colunas incluídas'],
      BD.indices.map((i) => [i.esquema, i.tabela, i.indice, i.tipo, simNao(i.unico), simNao(i.chave_primaria), i.colunas, i.incluidas]),
      [10, 30, 36, 14, 8, 14, 44, 34],
    );
  }

  // Modelo de dados do portal (conjuntos que o portal usa e exporta), só a estrutura
  const modelo = [];
  conjuntosDados().forEach((ds) =>
    ds.cols.forEach((c, i) => modelo.push([t(ds.tabela), t(ds.desc), i + 1, c[0], t(c[1]), t(TIPOS_PORTAL[c[2]] || c[2]), t(c[3] || '')])),
  );
  folha(
    'Modelo do portal',
    'Conjuntos de dados do portal (os mesmos do Excel «Exportar dados»): campo técnico, nome, tipo e descrição.',
    'ModeloPortal',
    ['Conjunto', 'Descrição do conjunto', 'Ordem', 'Campo', 'Nome', 'Tipo', 'Descrição'],
    modelo,
    [16, 46, 8, 20, 26, 14, 60],
  );

  // Matriz de acessos (recurso × perfil)
  if (E.matriz && E.perfis) {
    const perfis = Object.keys(E.perfis);
    folha(
      'Matriz de acessos',
      'Nível de cada perfil em cada página ou função (matriz em vigor).',
      'Matriz',
      ['Recurso', 'Nome', ...perfis],
      Object.entries(E.matriz).map(([rec, col]) => [rec, nomeRecurso(rec), ...perfis.map((p) => NIVEIS[col[p] || ''])]),
      [24, 46, ...perfis.map(() => 8)],
      'TableStyleLight9',
    );
    folha(
      'Perfis',
      'Perfis de acesso: código e nome.',
      'Perfis',
      ['Código', 'Nome'],
      perfis.map((p) => [p, t(E.perfis[p])]),
      [12, 46],
      'TableStyleLight9',
    );
  }

  // Fontes de dados
  folha(
    'Fontes de dados',
    'De onde vem cada dado do portal e como é lido.',
    'Fontes',
    ['Fonte', 'Tipo', 'Acesso', 'Dados'],
    [
      [
        'Pinguim BO',
        'SQL Server' + (BD.disponivel ? ' (' + BD.base + ')' : ''),
        t('Só leitura (SELECT)'),
        t('Vendas, linhas de artigos, cobranças, clientes, stock, sincronização dos smartphones'),
      ],
      ['Pinguim BO', 'API REST (XML)', t('Só leitura'), t('Exportação de vendas (alternativa à ligação directa)')],
      [
        t('Portal RTM'),
        t('Ficheiros privados (JSON)'),
        t('Leitura e escrita pelo portal'),
        t('Contas, perfis, matriz de acessos, registos, plano de cargas, lotes do SAP'),
      ],
      ['SAP', t('Ficheiro de integração'), t('Gerado pelo portal'), t('Vendas para carregamento no SAP (numeração sequencial e controlo de emissão)')],
    ],
    [16, 30, 30, 70],
    'TableStyleLight9',
  );

  // Índice na capa, com ligações
  const hdr = capa.getRow(r0 + 1);
  ['', t('Folha'), t('Descrição'), '', t('Linhas')].forEach((v, i) => {
    if (!v) return;
    const c = hdr.getCell(i + 1);
    c.value = v;
    c.font = F({ bold: true, color: { argb: 'FFFFFFFF' } });
    c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: NAVY2 } };
  });
  hdr.getCell(4).fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: NAVY2 } };
  indice.forEach(([titulo, nome, n, nota], i) => {
    const row = capa.getRow(r0 + 2 + i);
    row.getCell(2).value = { text: titulo, hyperlink: `#'${nome}'!A1` };
    row.getCell(2).font = F({ color: { argb: 'FF2A6FE0' }, underline: true });
    row.getCell(3).value = nota;
    row.getCell(3).font = F({ size: 9, color: { argb: GREY } });
    capa.mergeCells(`C${r0 + 2 + i}:D${r0 + 2 + i}`);
    row.getCell(5).value = n;
    row.getCell(5).numFmt = '#,##0';
    row.getCell(5).font = F();
  });
  if (!BD.disponivel) {
    const r = r0 + 3 + indice.length;
    capa.getCell('B' + r).value = t('Nota');
    capa.getCell('B' + r).font = F({ bold: true, color: { argb: GREY } });
    capa.getCell('C' + r).value = t('Sem as folhas da base de dados: {e}', { e: t(BD.erro || 'Base de dados indisponível.') });
    capa.getCell('C' + r).font = F({ color: { argb: 'FFB45309' } });
    capa.mergeCells(`C${r}:D${r}`);
  }
  capa.pageSetup = { orientation: 'portrait', fitToPage: true, fitToWidth: 1, fitToHeight: 0 };
  return wb;
}
