// Ficheiro de vendas para o SAP, gerado a partir do modelo original.
import { draw } from '../app.js';
import { catMeio, eNC, nomeRota, pagosDe, tipoDoc } from '../calculos.js';
import { pill, secBox, tabela, tiles } from '../componentes/blocos.js';
import { botao, campo, entrada, janela, msgErro } from '../componentes/janelas.js';
import { FV, filtroAtivo } from '../filtros.js';
import { dl, kz, kz2, n0 } from '../formatos.js';
import { t, tv } from '../i18n/i18n.js';
import { R } from './registo.js';
import { SESSAO, atual } from '../perfil.js';
import { inR, st } from '../periodo.js';
import { carregarScript, postJson } from '../servidor.js';
import { DB, b64, el, sum, toast } from '../util.js';
import { apiDescarregar, apiFetch } from '../api.js';
import { fetchVendas, fonteServe } from '../fontes.js';

let SAPD = null;
function carregarSap() {
  return apiFetch('sap')
    .then((r) => r.json())
    .then((d) => {
      SAPD = d && d.config ? d : { erro: (d && d.erro) || t('Sem dados') };
    })
    .catch(() => {
      SAPD = { erro: t('O ficheiro para o SAP precisa do servidor da plataforma (PHP).') };
    });
}
const postSap = (corpo) => postJson('sap', corpo);
const r2 = (v) => Math.round((+v || 0) * 100) / 100;
const rotasPortal = () => [...new Set([...Object.keys(DB.mapRota || {}), ...(DB.vendas || []).map((v) => v.rota)])].filter(Boolean).sort();
// Loja, SAP location e empregado de cada rota: o que estiver configurado ou, por omissão, o padrão do modelo
const mapaSap = (rota, cfg) => {
  const c = cfg || SAPD.config,
    m = c.rotas[rota] || {};
  return { loja: m.loja || `${rota} - ${c.prefixo}`, local: m.local || '', empregado: m.empregado || `${c.prefixo} - ROTA ${rota} - ${nomeRota(rota)}` };
};
// SAP storage location de cada linha (determinação automática pela origem do stock, como no SAP):
//   1. a indicada na rota (substituição manual); 2. a do armazém principal de onde o artigo veio para
//   a viatura dessa rota; 3. a predominante da rota; 4. a do armazém principal de onde o artigo sai;
//   5. a SAP location por omissão (viatura sem abastecimento registado: avariada, em manutenção, …).
// Nunca «N/A»: o SAP valida a storage location e recusaria a linha; usa-se um valor válido e a
// excepção fica assinalada para revisão (gestão por excepção).
const origens = () => SAPD.origens || { rotas: {}, artigos: {} };
const localDeterminado = (rota, codigo) => {
  const o = origens(),
    r = o.rotas[rota];
  return (r && ((codigo && r.artigos[codigo]) || r.local)) || (codigo && o.artigos[codigo]) || '';
};
const localOmissao = () => (SAPD.config && SAPD.config.local_omissao) || '';
/** {local, como}: como = rota (manual) | origem (stock) | omissao (sem abastecimento registado) | '' */
const localLinha = (rota, codigo) => {
  const manual = mapaSap(rota).local;
  if (manual) return { local: manual, como: 'rota' };
  const auto = localDeterminado(rota, codigo);
  if (auto) return { local: auto, como: 'origem' };
  return localOmissao() ? { local: localOmissao(), como: 'omissao' } : { local: '', como: '' };
};
const ROTULO_CAMPO = { loja: 'Loja SAP da rota {r}', local: 'Depósito SAP da rota {r}', empregado: 'Empregado de vendas SAP da rota {r}' };
const COMO = { rota: 'Indicada na rota', origem: 'Origem do stock', omissao: 'Por omissão (sem abastecimento)' };
/**
 * CASH AMOUNT e CARD AMOUNT de cada documento com a mesma regra do Início (calculos.js: catMeio): numerário e
 * Multicaixa; nas notas de crédito, a devolução ao cliente a negativo. Assim o ficheiro leva exactamente os valores do
 * Início. A estrutura do ficheiro (folha, colunas, formatos) não muda.
 */
// substituições de facturas (vendas.php): os pagamentos do documento como estão no Pinguim BO, que corrigem no SAP o
// recebido da factura original (ex.: Multicaixa −20 000 quando o cliente só pagou o valor com desconto)
const pagosSap = (v) => v.pagamentos_origem || pagosDe(v);
const acertoTxt = (s, fmt) =>
  (s.acerto || []).length
    ? ' · ' + t('acerto do recebido na factura original: {l}', { l: s.acerto.map((a) => t(a.meio) + ' ' + fmt(a.valor)).join(', ') })
    : '';

const meioDoc = (v, cat) =>
  sum(
    pagosSap(v).filter((p) => catMeio(p) === cat),
    'valor',
  );
const cashSap = (v) => meioDoc(v, 'dinheiro'),
  cardSap = (v) => meioDoc(v, 'multicaixa');
function linhasSap() {
  const c = SAPD.config;
  // Rota e tipo de documento vêm da barra de filtros. Os mesmos documentos do Início: facturas e notas de crédito
  // (estas com os valores a negativo, como documentos próprios), para o ficheiro levar exactamente os valores do Início
  const V = (DB.vendas || []).filter((v) => inR(v.data));
  const porDoc = {};
  (DB.artigos || []).forEach((a) => {
    (porDoc[a.documento] = porDoc[a.documento] || []).push(a);
  });
  const docs = [],
    linhas = [],
    erros = [],
    avisos = [],
    semLocal = new Set(),
    porOmissao = new Set();
  let repetidos = 0;
  V.slice()
    .sort((a, b) => (a.data + a.hora + a.documento < b.data + b.hora + b.documento ? -1 : 1))
    .forEach((v) => {
      // Documentos já emitidos num lote válido nunca entram noutro ficheiro (o servidor também o garante)
      if (SAPD.exportados[v.documento]) {
        repetidos++;
        return;
      }
      const ls = porDoc[v.documento] || [],
        m = mapaSap(v.rota),
        docTxt = tipoDoc(v) + ' ' + v.documento;
      const pg = pagosSap(v); // nas notas de crédito, a devolução ao cliente (a negativo)
      // CASH AMOUNT e CARD AMOUNT: os valores do Início (a estrutura do ficheiro não muda)
      const cash = r2(cashSap(v)),
        card = r2(cardSap(v));
      const outros = pg.filter((p) => !['dinheiro', 'multicaixa', 'compensacao'].includes(catMeio(p)));

      if (!ls.length) {
        erros.push(t('{d}: o documento não tem linhas de artigo.', { d: docTxt }));
        return;
      }
      // GROSSSALES = DOCMOVDETAIL.GROSS_TOTAL (exportação REST sem esta coluna: NETSALES + TAXAMOUNT)
      const bruto = (a) => (a.gross_total != null ? r2(a.gross_total) : r2(r2(a.valor_sem_iva) + r2(a.iva)));
      const invoice = r2(sum(ls, bruto)); // Invoice Total = soma do GROSSSALES das linhas do documento
      ls.forEach((a) => {
        const dif = r2(bruto(a) - r2(a.valor_sem_iva) - r2(a.iva));
        if (Math.abs(dif) > 1)
          avisos.push(
            t('{d}, artigo {a}: NETSALES + TAXAMOUNT ({x}) difere do GROSS_TOTAL ({g}).', {
              d: docTxt,
              a: a.codigo,
              x: kz(r2(a.valor_sem_iva) + r2(a.iva)),
              g: kz(bruto(a)),
            }),
          );
      });
      const soma = invoice;
      if (Math.abs(soma - r2(v.total)) > 1)
        avisos.push(t('{d}: a soma das linhas ({a}) não bate com o total do documento ({b}).', { d: docTxt, a: kz(soma), b: kz(v.total) }));
      if (outros.length)
        avisos.push(
          t('{d}: {v} pagos por {m}; o modelo só tem numerário e Multicaixa.', {
            d: docTxt,
            v: kz(sum(outros, 'valor')),
            m: outros.map((p) => tv(p.meio)).join(', '),
          }),
        );
      ls.forEach((a) => {
        const lc = localLinha(v.rota, a.codigo);
        if (!lc.local) semLocal.add(v.rota);
        if (lc.como === 'omissao') porOmissao.add(v.rota);
        if (!a.codigo) erros.push(t('{d}: linha «{a}» sem código de artigo.', { d: docTxt, a: a.artigo }));
        const net = r2(a.valor_sem_iva),
          tax = r2(a.iva),
          gross = bruto(a);
        linhas.push({
          chave: a.chave,
          doc: docTxt,
          documento: v.documento,
          data: v.data,
          loja: m.loja,
          codigo: a.codigo,
          artigo: a.artigo,
          quantidade: +a.quantidade || 0,
          uom: a.unidade || c.unidade,
          tipo: v.tipo,
          local: lc.local,
          local_como: t(COMO[lc.como] || '—'),
          net,
          tax,
          gross,
          moeda: c.moeda,
          inv: invoice,
          cash: cash || null,
          card: card || null,
          cliente: v.cliente || '',
          empregado: m.empregado,
          rota: v.rota,
        });
      });
      docs.push(v);
    });
  if (semLocal.size)
    erros.unshift(
      t('Sem depósito SAP para as rotas {r}: indique o depósito SAP por omissão em Correspondência com o SAP.', { r: [...semLocal].sort().join(', ') }),
    );
  // Excepção que não bloqueia: vendas de viaturas sem abastecimento registado seguem com a SAP location por omissão
  if (porOmissao.size)
    avisos.unshift(
      t('Rotas {r} sem abastecimento registado no período: as linhas seguem com o depósito SAP por omissão ({l}). Confirme no SAP.', {
        r: [...porOmissao].sort().join(', '),
        l: localOmissao(),
      }),
    );
  return { docs, linhas, erros, avisos, repetidos };
}
// Ficheiro para o SAP (formato de carregamento): um livro simples com UMA folha («Upload Format»), UMA linha de
// cabeçalho com os nomes exactos das colunas e as linhas de dados. Sem estilos (sem cores, negrito, contornos, larguras
// nem títulos); só o formato de apresentação dos valores, como no ficheiro de referência: DOCUMENT_DATE em data
// dd/mm/aaaa e os valores em número com separador de milhares e 2 casas decimais (ex.: 394 736,84).
export const CAB_SAP = [
  'INVOICENO',
  'DOCUMENT_DATE',
  'LOJA',
  'PRODUCT',
  'QUANTITY',
  'UNIT',
  'DOCUMENT_TYPE',
  'STORAGELOCATION',
  'NETSALES',
  'TAXAMOUNT',
  'GROSSSALES',
  'CURRENCY',
  'Invoice Total',
  'CASH AMOUNT',
  'CARD AMOUNT',
  'CUSTOMER',
  'SALES EMPLOYEE',
];
const carregarJSZip = () =>
  carregarScript('jszip.min.js', 'JSZip').catch(() => {
    throw new Error(t('Não foi possível gerar o ficheiro para o SAP.'));
  });
const xmlEsc = (v) =>
  String(v)
    // eslint-disable-next-line no-control-regex -- remove caracteres de controlo inválidos em XML
    .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
const COL_SAP = 'ABCDEFGHIJKLMNOPQ'.split('');
// AAAA-MM-DD → número de série de data do Excel (dias desde 30-12-1899)
const dataSap = (d) => Math.round((Date.UTC(+d.slice(0, 4), +d.slice(5, 7) - 1, +d.slice(8, 10)) - Date.UTC(1899, 11, 30)) / 86400000);
// formato de apresentação por coluna: 1 = data dd/mm/aaaa; 2 = #,##0.00 (NETSALES, TAXAMOUNT, GROSSSALES, Invoice Total, CASH, CARD)
const FMT_SAP = { B: 1, I: 2, J: 2, K: 2, M: 2, N: 2, O: 2 };
// só formatos de número: fonte, preenchimento e contorno são os de omissão do Excel
const ESTILOS_SAP =
  '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
  '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' +
  '<numFmts count="1"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/></numFmts>' +
  '<fonts count="1"><font><sz val="11"/><name val="Calibri"/><family val="2"/></font></fonts>' +
  '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>' +
  '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>' +
  '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' +
  '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' +
  '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>' +
  '<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>' +
  '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>' +
  '</styleSheet>';
function celula(ref, v, fmt) {
  if (v == null || v === '') return '';
  if (typeof v === 'number' && Number.isFinite(v)) return `<c r="${ref}"${fmt ? ` s="${fmt}"` : ''}><v>${v}</v></c>`;
  const s = String(v);
  return `<c r="${ref}" t="inlineStr"><is><t${/^\s|\s$/.test(s) ? ' xml:space="preserve"' : ''}>${xmlEsc(s)}</t></is></c>`;
}
function gerarExcelSap(linhas) {
  return carregarJSZip().then(() => {
    const valores = (l) => [
      l.doc,
      dataSap(l.data),
      l.loja,
      /^\d+$/.test(l.codigo) ? +l.codigo : l.codigo,
      l.quantidade,
      l.uom,
      l.tipo,
      l.local,
      l.net,
      l.tax,
      l.gross,
      l.moeda,
      l.inv,
      l.cash,
      l.card,
      l.cliente,
      l.empregado,
    ];
    const linha = (n, vals, fmt) => `<row r="${n}">${vals.map((v, k) => celula(COL_SAP[k] + n, v, fmt && FMT_SAP[COL_SAP[k]])).join('')}</row>`;
    const dados = [linha(1, CAB_SAP, false), ...linhas.map((l, i) => linha(i + 2, valores(l), true))].join('');
    const folha =
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
      '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' +
      `<dimension ref="A1:Q${linhas.length + 1}"/><sheetData>${dados}</sheetData></worksheet>`;
    const zip = new JSZip();
    zip.file(
      '[Content_Types].xml',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' +
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' +
        '<Default Extension="xml" ContentType="application/xml"/>' +
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' +
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' +
        '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' +
        '</Types>',
    );
    zip.file(
      '_rels/.rels',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' +
        '</Relationships>',
    );
    zip.file(
      'xl/workbook.xml',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' +
        '<sheets><sheet name="Upload Format" sheetId="1" r:id="rId1"/></sheets></workbook>',
    );
    zip.file(
      'xl/_rels/workbook.xml.rels',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' +
        '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' +
        '</Relationships>',
    );
    zip.file('xl/worksheets/sheet1.xml', folha);
    zip.file('xl/styles.xml', ESTILOS_SAP);
    return zip.generateAsync({ type: 'arraybuffer', compression: 'DEFLATE', mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
  });
}
function gerarFicheiroSap() {
  const L = linhasSap();
  if (!L.linhas.length) {
    toast(t('Não há documentos para incluir com estes filtros.'), 6000);
    return;
  }
  if (L.erros.length) {
    janela(t('Ainda não pode gerar o ficheiro'), t('Corrija estes pontos:'), (b, ft, fechar) => {
      const ul = el('ul', 'pc-lista');
      L.erros.slice(0, 30).forEach((x) => ul.append(el('li', null, x)));
      if (L.erros.length > 30) ul.append(el('li', null, t('… e mais {n}.', { n: L.erros.length - 30 })));
      b.append(ul);
      ft.append(botao(t('Fechar'), '', fechar));
    });
    return;
  }
  const total = r2(sum(L.docs, 'total'));
  janela(
    t('Gerar ficheiro para o SAP'),
    t(
      '{n} documentos, {l} linhas, {v} facturados, de {a} a {b}. O ficheiro é emitido uma única vez: guarde-o e carregue-o no SAP; estes documentos não poderão entrar noutro ficheiro.',
      {
        n: n0(L.docs.length),
        l: n0(L.linhas.length),
        v: kz(total),
        a: dl(st.de),
        b: dl(st.ate),
      },
    ),
    (b, ft, fechar) => {
      if (L.avisos.length) {
        b.append(el('p', 'aviso', t('{n} avisos (ver a lista na página). Pode gerar na mesma.', { n: L.avisos.length })));
      }
      const erro = msgErro();
      b.append(erro);
      const go = botao(t('Gerar e descarregar'), '', () => {
        go.disabled = true;
        go.textContent = t('A gerar…');
        gerarExcelSap(L.linhas)
          .then((buf) =>
            postSap({
              accao: 'lote',
              de: st.de,
              ate: st.ate,
              rotas: filtroAtivo('sap-exportar', 'rota') ? FV.rota : [],
              documentos: L.docs.map((v) => v.documento),
              linhas: L.linhas.length,
              total,
              // excepções registadas no lote (trilho de auditoria): linhas com a SAP location por omissão
              omissao: {
                linhas: L.linhas.filter((l) => l.local_como === t(COMO.omissao)).length,
                rotas: [...new Set(L.linhas.filter((l) => l.local_como === t(COMO.omissao)).map((l) => l.rota))].sort(),
                local: localOmissao(),
              },
              xlsx: b64(buf),
            }).then((r) => {
              go.disabled = false;
              go.textContent = t('Gerar e descarregar');
              if (!r.ok) {
                erro.textContent = t(r.erro || 'Não foi possível gerar.');
                erro.hidden = false;
                // documentos emitidos entretanto por outra pessoa: actualiza a lista
                if (r.duplicados) carregarSap();
                return;
              }
              const a = document.createElement('a');
              a.href = URL.createObjectURL(new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }));
              a.download = r.lote.ficheiro;
              document.body.append(a);
              a.click();
              setTimeout(() => {
                URL.revokeObjectURL(a.href);
                a.remove();
              }, 2000);
              fechar();
              toast(t('Ficheiro do lote {n} gerado: {f}', { n: r.lote.numero, f: r.lote.ficheiro }), 8000);
              carregarSap().then(() => {
                if (atual === 'sap-exportar') draw();
              });
            }),
          )
          .catch(() => {
            go.disabled = false;
            go.textContent = t('Gerar e descarregar');
            erro.textContent = t('Não foi possível gerar.');
            erro.hidden = false;
          });
      });
      ft.append(botao(t('Cancelar'), 'ghost', fechar), go);
    },
  );
}
R['sap-exportar'] = (pg) => {
  // o ficheiro para o SAP sai sempre da fonte fiscal (Pinguim BO): com o histórico do OLAP carregado, lê-se de novo
  if (!fonteServe()) {
    pg.append(el('div', 'glass empty', t('A ler as vendas do Pinguim BO (fonte fiscal)…')));
    fetchVendas(st.de, st.ate).then(() => {
      if (atual === 'sap-exportar') draw();
    });
    return;
  }
  if (!SAPD) {
    pg.append(el('div', 'glass empty', t('A carregar…')));
    carregarSap().then(() => {
      if (atual === 'sap-exportar') draw();
    });
    return;
  }
  if (SAPD.erro) {
    pg.append(secBox(t('Vendas para o SAP'), '', el('p', null, t(SAPD.erro))));
    return;
  }
  const gera = SAPD.pode_gerar,
    L = linhasSap(),
    rotas = rotasPortal();
  // filtros próprios: rotas, tipos de documento e documentos já enviados
  const barra = el('section', 'glass sec sap-barra fixo');
  const acc = el('div', 'pc-acc');
  if (gera) acc.append(botao(t('Gerar ficheiro para o SAP'), 'sm', gerarFicheiroSap));
  barra.append(
    el('p', 'sap-per', t('Período, rotas e tipo de documento escolhem-se na barra de filtros, em cima: {a} a {b}.', { a: dl(st.de), b: dl(st.ate) })),
    acc,
  );
  pg.append(barra);
  // os mesmos documentos e valores do Início, que são os que seguem no ficheiro
  const cash = sum(L.docs, cashSap),
    card = sum(L.docs, cardSap);
  // substituições de facturas de dias anteriores (vendas.php): os dois documentos seguem no ficheiro como estão no
  // Pinguim BO (documentos fiscais): o total tem o desconto e os meios têm o acerto do recebido da factura original,
  // que as Cobranças mostram à parte (não é dinheiro de hoje)
  const subst = L.docs.filter((v) => v.substituicao && eNC(v)),
    descSubst = subst.reduce((a, v) => a + (+v.substituicao.desconto || 0), 0);
  pg.append(
    tiles([
      {
        // como no Início: o número de facturas e, à parte, o valor das notas de crédito (também seguem no ficheiro)
        l: t('Documentos a incluir'),
        v: n0(L.docs.filter((v) => !eNC(v)).length),
        s:
          (L.docs.some(eNC) ? t('notas de crédito: −{v}', { v: kz2(-sum(L.docs.filter(eNC), 'total')) }) + ' · ' : '') +
          t('{n} linhas de artigo', { n: n0(L.linhas.length) }),
      },
      {
        l: t('Total facturado'),
        v: kz2(sum(L.docs, 'total')),
        cls: 'full-value',
        s: descSubst ? t('inclui descontos em substituições de facturas: −{v}', { v: kz2(descSubst) }) : '',
      },
      { l: t('Numerário'), v: kz2(cash), cls: 'full-value' },
      { l: t('Multicaixa'), v: kz2(card), cls: 'full-value' },
      {
        l: t('Já enviados ao SAP'),
        v: n0(L.repetidos),
        s: t('excluídos: só podem ser emitidos uma vez'),
      },
      {
        l: t('Por corrigir'),
        v: n0(L.erros.length),
        s: t('{n} avisos', { n: L.avisos.length }),
        tone: L.erros.length ? 'bad' : L.avisos.length ? 'warn' : null,
      },
    ]),
  );
  // conciliação com as vendas do portal (o desconto é real: a nota de crédito e a factura nova vão para o SAP)
  if (subst.length) {
    const tf = sum(L.docs, 'total');
    const p = el('p', 'note aviso-rect');
    p.setAttribute('role', 'note');
    p.append(
      el('b', null, t('Conciliação com as vendas') + ': '),
      document.createTextNode(
        t('vendas do portal {a} − descontos em substituições de facturas {d} = total do ficheiro {b}.', {
          a: kz2(tf + descSubst),
          d: kz2(descSubst),
          b: kz2(tf),
        }) +
          ' ' +
          subst
            .map(
              (v) =>
                t('rota {r} · {nc} e {f}: desconto {v}', { r: v.rota, nc: v.substituicao.nota, f: v.substituicao.factura, v: kz2(v.substituicao.desconto) }) +
                acertoTxt(v.substituicao, kz2),
            )
            .join('; ') +
          '. ' +
          t(
            'No SAP o desconto fica registado pela nota de crédito e pela factura nova, e o acerto corrige o recebido da factura original (o cliente pagou o valor com desconto), sem saldo a crédito.',
          ),
      ),
    );
    pg.append(p);
  }
  if (L.erros.length || L.avisos.length) {
    const ul = el('ul', 'pc-lista sap-avisos');
    L.erros.slice(0, 20).forEach((x) => ul.append(el('li', 'pc-err-t', x)));
    L.avisos.slice(0, 20).forEach((x) => ul.append(el('li', 'pc-av-t', x)));
    if (L.erros.length + L.avisos.length > 40) ul.append(el('li', null, t('… e mais {n}.', { n: L.erros.length + L.avisos.length - 40 })));
    {
      const v = secBox(t('Verificação'), t('Os erros impedem a geração do ficheiro; os avisos não.'), ul);
      v.classList.add('fixo');
      pg.append(v);
    }
  }
  pg.append(
    tabela({
      id: 'sap-l',
      titulo: t('Pré-visualização do ficheiro'),
      nota: t(
        'Uma linha por artigo, como no modelo de carregamento de vendas do SAP. O total, o numerário e o Multicaixa são do documento e repetem-se em cada linha.',
      ),
      sort: 'doc',
      dir: 1,
      total: true,
      rows: L.linhas,
      // Nomes das colunas fixos em todas as línguas (iguais aos do modelo do SAP): são os que a integração entre os
      // dois sistemas reconhece. Não traduzir.
      cols: [
        { key: 'doc', label: 'INVOICENO', p: 0 },
        { key: 'data', label: 'DOCUMENT_DATE', type: 'date' },
        { key: 'loja', label: 'LOJA' },
        { key: 'codigo', label: 'PRODUCT' },
        { key: 'artigo', label: 'Artigo', wrap: true },
        { key: 'quantidade', label: 'QUANTITY', type: 'n', sum: true },
        { key: 'uom', label: 'UNIT' },
        { key: 'local', label: 'STORAGELOCATION' },
        { key: 'local_como', label: 'Determinação da location', p: 4 },
        { key: 'net', label: 'NETSALES', type: 'kz', sum: true },
        { key: 'tax', label: 'TAXAMOUNT', type: 'kz', sum: true },
        { key: 'gross', label: 'GROSSSALES', type: 'kz', sum: true },
        { key: 'inv', label: 'Invoice Total', type: 'kz' },
        { key: 'cash', label: 'CASH AMOUNT', type: 'kz' },
        { key: 'card', label: 'CARD AMOUNT', type: 'kz' },
        { key: 'cliente', label: 'CUSTOMER', wrap: true },
        { key: 'empregado', label: 'SALES EMPLOYEE', wrap: true },
      ],
    }),
  );
  // correspondência das rotas com o SAP
  const cfg = JSON.parse(JSON.stringify(SAPD.config));
  const sec = el('section', 'glass sec'),
    h = el('div', 'sec-h'),
    hd = el('div');
  hd.append(
    el('h2', null, t('Correspondência com o SAP')),
    el(
      'p',
      null,
      t(
        'Para cada rota: loja, depósito e empregado de vendas como existem no SAP. Em branco fica o padrão mostrado. O depósito SAP é determinado pela origem do stock de cada viatura; só se indica na rota para o substituir.',
      ),
    ),
  );
  h.append(hd);
  sec.append(h);
  const geral = el('div', 'campos3');
  const ci = (k, rot) => {
    const i = entrada('text', cfg[k]);
    i.disabled = !gera;
    i.addEventListener('input', () => {
      cfg[k] = i.value.trim();
    });
    return campo(rot, i);
  };
  geral.append(ci('prefixo', t('Prefixo da loja e do empregado')), ci('moeda', t('Moeda')), ci('unidade', t('Unidade por omissão')));
  sec.append(geral);
  // Armazéns principais (origem do stock) → SAP location
  if (!cfg.armazens) cfg.armazens = {};
  const ap = el('div', 'sap-arm');
  ap.append(el('h3', null, t('Armazéns principais e depósito SAP')));
  const O = origens();
  ap.append(
    el(
      'p',
      'note',
      O.fonte
        ? t('Determinação automática por {f} ({p}).', { f: t(O.fonte), p: String(O.periodo || '').replace(' a ', ' – ') })
        : t('Ainda sem transferências de stock dos armazéns principais para as viaturas: indique o depósito SAP em cada rota.'),
    ),
  );
  const ta = el('div', 'sap-map sap-map-arm');
  const cabA = el('div', 'sap-map-l sap-map-h');
  [t('Código'), t('Armazém principal (Pinguim BO)'), t('Depósito SAP'), t('Rotas abastecidas')].forEach((x) => cabA.append(el('span', null, x)));
  ta.append(cabA);
  Object.entries(cfg.armazens).forEach(([cod, a]) => {
    const l = el('div', 'sap-map-l');
    const nome = entrada('text', a.nome || '');
    nome.disabled = !gera;
    nome.setAttribute('aria-label', t('Nome do armazém {c}', { c: cod }));
    nome.addEventListener('input', () => (a.nome = nome.value.trim()));
    const loc = entrada('text', a.local || '');
    loc.disabled = !gera;
    loc.setAttribute('aria-label', t('Depósito SAP do armazém {c}', { c: cod }));
    loc.addEventListener('input', () => (a.local = loc.value.trim().toUpperCase()));
    const abastecidas = Object.entries(O.rotas || {})
      .filter(([, x]) => x.armazens && x.armazens[cod])
      .map(([r]) => r);
    l.append(el('b', 'sap-map-r', cod), nome, loc, el('span', 'sap-arm-rotas', abastecidas.length ? abastecidas.join(', ') : '—'));
    ta.append(l);
  });
  ap.append(ta);
  // SAP location por omissão: para viaturas sem abastecimento registado (nunca bloqueia o envio)
  const om = entrada('text', cfg.local_omissao || '');
  om.disabled = !gera;
  om.setAttribute('aria-label', t('Depósito SAP por omissão'));
  om.addEventListener('input', () => (cfg.local_omissao = om.value.trim().toUpperCase()));
  const cOm = campo(t('Depósito SAP por omissão'), om);
  cOm.classList.add('sap-omissao');
  ap.append(
    cOm,
    el(
      'p',
      'note',
      t(
        'Usado quando a viatura não teve abastecimento registado (avariada, em manutenção, sem carga). As vendas seguem para o SAP com este depósito e ficam assinaladas na pré-visualização, nos avisos e no registo do lote, para confirmação.',
      ),
    ),
  );
  sec.append(ap);
  const tb = el('div', 'sap-map');
  const cab = el('div', 'sap-map-l sap-map-h');
  [t('Rota'), t('Loja SAP'), t('Depósito SAP'), t('Empregado de vendas SAP')].forEach((x) => cab.append(el('span', null, x)));
  tb.append(cab);
  rotas.forEach((r) => {
    const l = el('div', 'sap-map-l'),
      m = (cfg.rotas[r] = { ...(cfg.rotas[r] || {}) }),
      pad = mapaSap(r, { ...cfg, rotas: {} });
    const c = (k, ph) => {
      const i = entrada('text', m[k] || '', { placeholder: ph });
      i.disabled = !gera;
      i.setAttribute('aria-label', t(ROTULO_CAMPO[k], { r }));
      i.addEventListener('input', () => {
        m[k] = i.value.trim();
      });
      return i;
    };
    const rt = el('span', 'sap-map-r');
    rt.append(el('b', null, r), el('small', null, nomeRota(r)));
    // SAP location: automática pela origem do stock; o campo só serve para a substituir
    const det = O.rotas && O.rotas[r];
    const auto = localDeterminado(r, '');
    const loc = c(
      'local',
      auto ? auto + ' · ' + t('automático') : localOmissao() ? localOmissao() + ' · ' + t('por omissão (sem abastecimento)') : t('obrigatório'),
    );
    if (det)
      loc.title = t('Origem do stock desta viatura: {o}', {
        o: Object.entries(det.armazens)
          .map(([cod, q]) => ((cfg.armazens[cod] && cfg.armazens[cod].nome) || cod) + ' ' + n0(q))
          .join(' · '),
      });
    if (!m.local && !auto && !localOmissao()) loc.classList.add('falta');
    if (!m.local && auto) loc.classList.add('auto');
    if (!m.local && !auto && localOmissao()) {
      loc.classList.add('omissao');
      loc.title = t('Sem abastecimento registado desta viatura no período: usa o depósito SAP por omissão.');
    }
    l.append(rt, c('loja', pad.loja), loc, c('empregado', pad.empregado));
    tb.append(l);
  });
  sec.append(tb);
  if (gera) {
    const ft = el('div', 'pc-acc'),
      erro = msgErro();
    const gd = botao(t('Guardar correspondência'), 'sm', () => {
      gd.disabled = true;
      postSap({ accao: 'config', config: cfg }).then((r) => {
        gd.disabled = false;
        if (!r.ok) {
          erro.textContent = t(r.erro || 'Não foi possível guardar.');
          erro.hidden = false;
          return;
        }
        SAPD.config = r.config;
        toast(t('Correspondência guardada.'), 4000);
        draw();
      });
    });
    ft.append(gd);
    sec.append(erro, ft);
  }
  pg.append(sec);
  // Registo dos ficheiros emitidos (controlo de interface): estado, impressão digital e acções
  if (SAPD.lotes.length) pg.append(registoLotes(gera));
};

const ESTADOS = { emitido: ['Emitido', 'p-warn'], carregado: ['Carregado no SAP', 'p-ok'], anulado: ['Anulado', 'p-bad'] };
function registoLotes(gera) {
  const eu = SESSAO && SESSAO.login;
  return tabela({
    id: 'sap-h',
    titulo: t('Registo de ficheiros emitidos'),
    nota: t(
      'Cada ficheiro é emitido uma única vez, com número sequencial e impressão digital SHA-256. Depois de carregar no SAP, confirme o carregamento. Um lote ainda não carregado só pode ser anulado, com motivo, por outra pessoa que não quem o emitiu; a anulação liberta os documentos para um novo ficheiro.',
    ),
    sort: 'numero',
    total: false,
    rows: SAPD.lotes.map((x) => ({
      ...x,
      chave: x.numero,
      periodo: dl(x.de) + (x.ate !== x.de ? ' – ' + dl(x.ate) : ''),
      rotas_t: x.rotas.length ? x.rotas.join(', ') : t('Todas'),
      estado_t: t(ESTADOS[x.estado || 'emitido'][0]),
      detalhe:
        (x.estado === 'carregado'
          ? t('por {p} em {d}', { p: x.carregado.por_nome, d: dl(x.carregado.em) }) + (x.carregado.referencia ? ' · ' + x.carregado.referencia : '')
          : x.estado === 'anulado'
            ? t('por {p} em {d}', { p: x.anulado.por_nome, d: dl(x.anulado.em) }) + ' · ' + x.anulado.motivo
            : '') +
        (x.omissao
          ? (x.estado === 'emitido' ? '' : ' · ') +
            t('{n} linhas com o depósito por omissão {l} (rotas {r})', { n: x.omissao.linhas, l: x.omissao.local, r: x.omissao.rotas.join(', ') })
          : ''),
      hash: x.sha256 ? x.sha256.slice(0, 12) + '…' : '—',
    })),
    cols: [
      { key: 'numero', label: t('Lote'), type: 'n', p: 0 },
      { key: 'em', label: t('Emitido em'), render: (v) => document.createTextNode(dl(v) + ' ' + v.slice(11, 16)) },
      { key: 'por_nome', label: t('Por') },
      { key: 'periodo', label: t('Período') },
      { key: 'rotas_t', label: t('Rotas') },
      { key: 'n_documentos', label: t('Documentos'), type: 'n' },
      { key: 'total', label: t('Total'), type: 'kz' },
      { key: 'estado_t', label: t('Estado'), render: (v, r) => pill(v, ESTADOS[r.estado || 'emitido'][1]), xl: (r) => r.estado_t },
      { key: 'detalhe', label: t('Detalhe'), wrap: true },
      { key: 'hash', label: 'SHA-256', p: 0, xl: (r) => r.sha256 || '' },
      {
        key: 'ficheiro',
        label: t('Acções'),
        render: (v, r) => {
          const box = el('span', 'sap-acoes');
          if (gera && r.estado === 'emitido') {
            box.append(botao(t('Confirmar carregamento'), 'sm', () => confirmarCarregamento(r)));
            if (r.por !== eu || (SESSAO && SESSAO.perfil === 'SADM')) box.append(botao(t('Anular'), 'sm ghost', () => anularLote(r)));
          }
          if (SAPD.segunda_via) {
            const a = el('a', 'linkbtn', t('Segunda via'));
            a.href = '#';
            a.addEventListener('click', (ev) => {
              ev.preventDefault();
              apiDescarregar('sap/excel?numero=' + encodeURIComponent(r.numero), r.ficheiro || 'SAP.xlsx').catch((e) => toast(e.message, 6000));
            });
            a.title = t('Cópia do ficheiro emitido, para auditoria (fica registada)');
            box.append(a);
          }
          return box;
        },
        xl: (r) => r.ficheiro,
      },
    ],
  });
}
function accaoLote(corpo, msg) {
  return postSap(corpo).then((r) => {
    if (r.ok) {
      toast(msg, 6000);
      carregarSap().then(() => {
        if (atual === 'sap-exportar') draw();
      });
    }
    return r;
  });
}
function confirmarCarregamento(r) {
  janela(t('Confirmar carregamento no SAP'), t('Lote {n} · {f}', { n: r.numero, f: r.ficheiro }), (b, ft, fechar) => {
    const ref = entrada('text', '', { maxLength: 60, placeholder: t('opcional') });
    const erro = msgErro();
    b.append(campo(t('Referência no SAP (n.º do lote ou documento de carregamento)'), ref), erro);
    const go = botao(t('Confirmar'), '', () => {
      go.disabled = true;
      accaoLote({ accao: 'confirmar', lote: r.numero, referencia: ref.value }, t('Lote {n} confirmado como carregado no SAP.', { n: r.numero })).then((d) => {
        go.disabled = false;
        if (!d.ok) {
          erro.textContent = t(d.erro || 'Não foi possível concluir.');
          erro.hidden = false;
          return;
        }
        fechar();
      });
    });
    ft.append(botao(t('Cancelar'), 'ghost', fechar), go);
  });
}
function anularLote(r) {
  janela(
    t('Anular lote {n}', { n: r.numero }),
    t('Só se o ficheiro ainda não foi carregado no SAP. Os {n} documentos voltam a poder ser incluídos num novo ficheiro, com outro número.', {
      n: r.n_documentos,
    }),
    (b, ft, fechar) => {
      const mot = entrada('text', '', { maxLength: 300, placeholder: t('ex.: ficheiro perdido antes do carregamento') });
      const erro = msgErro();
      b.append(campo(t('Motivo da anulação'), mot), erro);
      const go = botao(t('Anular lote'), '', () => {
        go.disabled = true;
        accaoLote({ accao: 'anular', lote: r.numero, motivo: mot.value }, t('Lote {n} anulado.', { n: r.numero })).then((d) => {
          go.disabled = false;
          if (!d.ok) {
            erro.textContent = t(d.erro || 'Não foi possível concluir.');
            erro.hidden = false;
            return;
          }
          fechar();
        });
      });
      ft.append(botao(t('Cancelar'), 'ghost', fechar), go);
    },
  );
}
