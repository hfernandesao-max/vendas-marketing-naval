// Ficheiro de vendas para o SAP, gerado a partir do modelo original.
import { draw } from '../app.js';
import { nomeRota } from '../calculos.js';
import { secBox, tabela, tiles } from '../componentes/blocos.js';
import { botao, campo, entrada, janela, msgErro } from '../componentes/janelas.js';
import { FV, filtroAtivo } from '../filtros.js';
import { dl, kz, kz2, n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { R } from './registo.js';
import { atual } from '../perfil.js';
import { inR, st } from '../periodo.js';
import { carregarScript, postJson } from '../servidor.js';
import { DB, b64, el, sum, toast } from '../util.js';

let SAPD = null;
const SAPX = { novos: true };
function carregarSap() {
  return fetch('data/sap.php', { cache: 'no-store' })
    .then((r) => r.json())
    .then((d) => {
      SAPD = d && d.config ? d : { erro: (d && d.erro) || t('Sem dados') };
    })
    .catch(() => {
      SAPD = { erro: t('O ficheiro para o SAP precisa do servidor da plataforma (PHP).') };
    });
}
const postSap = (corpo) => postJson('data/sap.php', corpo);
const codigoTipo = (tp) => (tp === 'Factura' ? 'FT' : 'FR');
const r2 = (v) => Math.round((+v || 0) * 100) / 100;
const rotasPortal = () => [...new Set([...Object.keys(DB.mapRota || {}), ...(DB.vendas || []).map((v) => v.rota)])].filter(Boolean).sort();
// Loja, SAP location e empregado de cada rota: o que estiver configurado ou, por omissão, o padrão do modelo
const mapaSap = (rota, cfg) => {
  const c = cfg || SAPD.config,
    m = c.rotas[rota] || {};
  return { loja: m.loja || `${rota} - ${c.prefixo}`, local: m.local || '', empregado: m.empregado || `${c.prefixo} - ROTA ${rota} - ${nomeRota(rota)}` };
};
function linhasSap() {
  const c = SAPD.config;
  const V = (DB.vendas || []).filter((v) => inR(v.data)); // rota e tipo de documento vêm da barra de filtros
  const porDoc = {};
  (DB.artigos || []).forEach((a) => {
    (porDoc[a.documento] = porDoc[a.documento] || []).push(a);
  });
  const docs = [],
    linhas = [],
    erros = [],
    avisos = [],
    semLocal = new Set();
  let repetidos = 0;
  V.slice()
    .sort((a, b) => (a.data + a.hora + a.documento < b.data + b.hora + b.documento ? -1 : 1))
    .forEach((v) => {
      if (SAPD.exportados[v.documento] && SAPX.novos) {
        repetidos++;
        return;
      }
      const ls = porDoc[v.documento] || [],
        m = mapaSap(v.rota),
        docTxt = codigoTipo(v.tipo) + ' ' + v.documento;
      const pg = v.pagamentos || [];
      const cash = r2(
          sum(
            pg.filter((p) => p.codigo === 'NU'),
            'valor',
          ),
        ),
        card = r2(
          sum(
            pg.filter((p) => p.codigo === 'MC'),
            'valor',
          ),
        );
      const outros = pg.filter((p) => p.codigo !== 'NU' && p.codigo !== 'MC');
      if (!m.local) semLocal.add(v.rota);
      if (!ls.length) {
        erros.push(t('{d}: o documento não tem linhas de artigo.', { d: docTxt }));
        return;
      }
      const soma = r2(sum(ls, (a) => r2(a.valor_sem_iva) + r2(a.iva)));
      if (Math.abs(soma - r2(v.total)) > 1)
        avisos.push(t('{d}: a soma das linhas ({a}) não bate com o total do documento ({b}).', { d: docTxt, a: kz(soma), b: kz(v.total) }));
      if (outros.length)
        avisos.push(
          t('{d}: {v} pagos por {m}; o modelo só tem Cash e MULTICAIXA.', { d: docTxt, v: kz(sum(outros, 'valor')), m: outros.map((p) => p.meio).join(', ') }),
        );
      ls.forEach((a) => {
        if (!a.codigo) erros.push(t('{d}: linha «{a}» sem código de artigo.', { d: docTxt, a: a.artigo }));
        const net = r2(a.valor_sem_iva),
          tax = r2(a.iva);
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
          local: m.local,
          net,
          tax,
          gross: r2(net + tax),
          moeda: c.moeda,
          inv: r2(v.total),
          cash: cash || null,
          card: card || null,
          cliente: v.cliente || '',
          empregado: m.empregado,
          rota: v.rota,
        });
      });
      docs.push(v);
    });
  if (semLocal.size) erros.unshift(t('Falta a SAP location das rotas {r} (Correspondência com o SAP, mais abaixo).', { r: [...semLocal].sort().join(', ') }));
  return { docs, linhas, erros, avisos, repetidos };
}
// Gera o ficheiro a partir do pacote do próprio modelo do SAP (RTM_Sales_SAP_Upload_Format.xlsx):
// estilos, tema, larguras, folha e as linhas 1 e 2 ficam intactos; só as linhas de dados são escritas,
// cada célula com o mesmo estilo da coluna na primeira linha de exemplo do modelo.
const carregarJSZip = () =>
  carregarScript('jszip.min.js', 'JSZip').catch(() => {
    throw new Error(t('Não foi possível abrir o modelo do SAP.'));
  });
const xmlEsc = (v) => String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
function gerarExcelSap(linhas) {
  let zip;
  return carregarJSZip()
    .then(() => fetch('data/sap.php?modelo=1', { cache: 'no-store' }))
    .then((r) => {
      if (!r.ok) throw new Error(t('Não foi possível abrir o modelo do SAP.'));
      return r.arrayBuffer();
    })
    .then((buf) => JSZip.loadAsync(buf))
    .then((z) => {
      zip = z;
      return Promise.all(
        ['xl/worksheets/sheet1.xml', 'xl/sharedStrings.xml', '[Content_Types].xml', 'xl/_rels/workbook.xml.rels'].map((f) => zip.file(f).async('string')),
      );
    })
    .then(([folha, sst, tipos, rels]) => {
      const dom = new DOMParser();
      // textos partilhados: ficam os das linhas 1 e 2 do modelo, pela mesma ordem; os dos dados vêm a seguir
      const fs = dom.parseFromString(folha, 'application/xml'),
        NS = fs.documentElement.namespaceURI;
      const linhasXml = [...fs.getElementsByTagNameNS(NS, 'row')];
      const cab = linhasXml.filter((r) => +r.getAttribute('r') <= 2),
        ex = linhasXml.find((r) => r.getAttribute('r') === '3');
      let maxIdx = -1;
      cab.forEach((r) =>
        [...r.getElementsByTagNameNS(NS, 'c')].forEach((c) => {
          if (c.getAttribute('t') === 's') maxIdx = Math.max(maxIdx, +c.textContent);
        }),
      );
      const sis = (sst.match(/<si>[\s\S]*?<\/si>/g) || []).slice(0, maxIdx + 1);
      const idx = new Map();
      const texto = (v) => {
        if (!idx.has(v)) {
          const sp = /^\s|\s$/.test(v) ? ' xml:space="preserve"' : '';
          idx.set(v, sis.length);
          sis.push(`<si><t${sp}>${xmlEsc(v)}</t></si>`);
        }
        return idx.get(v);
      };
      // estilo de cada coluna na linha de exemplo (linha 3 do modelo)
      const estilo = {};
      [...ex.getElementsByTagNameNS(NS, 'c')].forEach((c) => {
        estilo[c.getAttribute('r').replace(/\d+/g, '')] = c.getAttribute('s');
      });
      const COL = 'ABCDEFGHIJKLMNOPQ'.split('');
      const dyd = ex.getAttribute('x14ac:dyDescent');
      let usos = 0;
      const corpo = linhas
        .map((l, i) => {
          const n = 3 + i,
            [y, m, d] = l.data.split('-').map(Number);
          const serial = Date.UTC(y, m - 1, d) / 86400000 + 25569;
          const vals = [
            l.doc,
            serial,
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
          const cels = vals
            .map((v, k) => {
              const ref = COL[k] + n,
                s = estilo[COL[k]] != null ? ` s="${estilo[COL[k]]}"` : '';
              if (v == null || v === '') return `<c r="${ref}"${s}/>`;
              if (typeof v === 'number') return `<c r="${ref}"${s}><v>${v}</v></c>`;
              usos++;
              return `<c r="${ref}"${s} t="s"><v>${texto(String(v))}</v></c>`;
            })
            .join('');
          return `<row r="${n}" spans="1:17"${dyd ? ` x14ac:dyDescent="${dyd}"` : ''}>${cels}</row>`;
        })
        .join('');
      // folha: linhas 1 e 2 exactamente como no modelo, seguidas dos dados; sem as linhas de exemplo
      const cabXml = ['1', '2'].map((n) => (folha.match(new RegExp('<row r="' + n + '"[\\s\\S]*?<\\/row>')) || [''])[0]).join('');
      const ultima = 2 + linhas.length;
      const nova = folha
        .replace(/<sheetData>[\s\S]*<\/sheetData>|<sheetData\/>/, () => `<sheetData>${cabXml}${corpo}</sheetData>`)
        .replace(/<dimension ref="[^"]*"\/>/, `<dimension ref="A1:Q${ultima}"/>`);
      let totalUsos = usos;
      cab.forEach((r) =>
        [...r.getElementsByTagNameNS(NS, 'c')].forEach((c) => {
          if (c.getAttribute('t') === 's') totalUsos++;
        }),
      );
      const sstNovo = sst.replace(
        /<sst([^>]*)>[\s\S]*<\/sst>/,
        (m0, attrs) =>
          `<sst${attrs.replace(/ count="\d+"/, ` count="${totalUsos}"`).replace(/ uniqueCount="\d+"/, ` uniqueCount="${sis.length}"`)}>${sis.join('')}</sst>`,
      );
      zip.file('xl/worksheets/sheet1.xml', nova);
      zip.file('xl/sharedStrings.xml', sstNovo);
      // sem fórmulas, a cadeia de cálculo do exemplo deixa de existir
      if (zip.file('xl/calcChain.xml')) {
        zip.remove('xl/calcChain.xml');
        zip.file('[Content_Types].xml', tipos.replace(/<Override[^>]*calcChain[^>]*\/>/, ''));
        zip.file('xl/_rels/workbook.xml.rels', rels.replace(/<Relationship[^>]*calcChain[^>]*\/>/, ''));
      }
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
    t('{n} documentos, {l} linhas, {v} facturados, de {a} a {b}. Os documentos ficam marcados como incluídos neste ficheiro.', {
      n: n0(L.docs.length),
      l: n0(L.linhas.length),
      v: kz(total),
      a: dl(st.de),
      b: dl(st.ate),
    }),
    (b, ft, fechar) => {
      if (L.avisos.length) {
        b.append(el('p', 'aviso', t('{n} avisos (ver a lista na página). Pode gerar na mesma.', { n: L.avisos.length })));
      }
      if (!SAPX.novos && L.repetidos === 0) {
        const rep = L.docs.filter((v) => SAPD.exportados[v.documento]).length;
        if (rep)
          b.append(
            el('p', 'aviso', t('Atenção: {n} destes documentos já estavam num ficheiro anterior. Confirme no SAP que não ficam em duplicado.', { n: rep })),
          );
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
              repetidos: L.docs.filter((v) => SAPD.exportados[v.documento]).length,
              xlsx: b64(buf),
            }).then((r) => {
              go.disabled = false;
              go.textContent = t('Gerar e descarregar');
              if (!r.ok) {
                erro.textContent = t(r.erro || 'Não foi possível gerar.');
                erro.hidden = false;
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
  if (!SAPD) {
    pg.append(el('div', 'glass empty', t('A carregar…')));
    carregarSap().then(() => {
      if (atual === 'sap-exportar') draw();
    });
    return;
  }
  if (SAPD.erro) {
    pg.append(secBox(t('Ficheiro para o SAP'), '', el('p', null, SAPD.erro)));
    return;
  }
  const gera = SAPD.pode_gerar,
    L = linhasSap(),
    rotas = rotasPortal();
  // filtros próprios: rotas, tipos de documento e documentos já enviados
  const barra = el('section', 'glass sec sap-barra fixo');
  const tog = (txt, on, fn, title) => {
    const b = el('button', 'sap-chip' + (on ? ' on' : ''), txt);
    b.type = 'button';
    b.setAttribute('aria-pressed', on ? 'true' : 'false');
    if (title) b.title = title;
    b.addEventListener('click', fn);
    return b;
  };
  const op = el('div', 'sap-op');
  op.append(
    tog(
      t('Só documentos ainda não enviados'),
      SAPX.novos,
      () => {
        SAPX.novos = !SAPX.novos;
        draw();
      },
      t('Exclui os documentos já incluídos num ficheiro anterior'),
    ),
  );
  const acc = el('div', 'pc-acc');
  if (gera) acc.append(botao(t('Gerar ficheiro para o SAP'), 'sm', gerarFicheiroSap));
  barra.append(
    el('p', 'sap-per', t('Período, rotas e tipo de documento escolhem-se na barra de filtros, em cima: {a} a {b}.', { a: dl(st.de), b: dl(st.ate) })),
    op,
    acc,
  );
  pg.append(barra);
  const cash = sum(L.docs, (v) =>
      sum(
        (v.pagamentos || []).filter((p) => p.codigo === 'NU'),
        'valor',
      ),
    ),
    card = sum(L.docs, (v) =>
      sum(
        (v.pagamentos || []).filter((p) => p.codigo === 'MC'),
        'valor',
      ),
    );
  pg.append(
    tiles([
      { l: t('Documentos a incluir'), v: n0(L.docs.length), s: t('{n} linhas de artigo', { n: n0(L.linhas.length) }) },
      { l: t('Total facturado'), v: kz2(sum(L.docs, 'total')), cls: 'full-value' },
      { l: t('Cash'), v: kz2(cash), cls: 'full-value' },
      { l: t('Multicaixa'), v: kz2(card), cls: 'full-value' },
      {
        l: t('Já enviados ao SAP'),
        v: n0(L.repetidos),
        s: SAPX.novos ? t('excluídos deste ficheiro') : t('incluídos outra vez'),
        tone: !SAPX.novos && L.repetidos ? 'warn' : null,
      },
      {
        l: t('Por corrigir'),
        v: n0(L.erros.length),
        s: t('{n} avisos', { n: L.avisos.length }),
        tone: L.erros.length ? 'bad' : L.avisos.length ? 'warn' : null,
      },
    ]),
  );
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
      nota: t('Uma linha por artigo, como no modelo RTM_Sales_SAP_Upload_Format. O total, o Cash e o MULTICAIXA são do documento e repetem-se em cada linha.'),
      sort: 'doc',
      dir: 1,
      total: true,
      rows: L.linhas,
      cols: [
        { key: 'doc', label: 'INVOICENO', p: 0 },
        { key: 'data', label: 'DOCUMENT_DATE', type: 'date' },
        { key: 'loja', label: 'LOJA' },
        { key: 'codigo', label: 'PRODUCT' },
        { key: 'artigo', label: t('Artigo'), wrap: true },
        { key: 'quantidade', label: 'QUANTITY', type: 'n', sum: true },
        { key: 'uom', label: 'UNIT' },
        { key: 'local', label: 'STORAGELOCATION' },
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
    el('p', null, t('Para cada rota: Loja, SAP location e empregado de vendas como existem no SAP. Em branco fica o padrão mostrado.')),
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
  const tb = el('div', 'sap-map');
  const cab = el('div', 'sap-map-l sap-map-h');
  [t('Rota'), 'LOJA', 'STORAGELOCATION', 'SALES EMPLOYEE'].forEach((x) => cab.append(el('span', null, x)));
  tb.append(cab);
  rotas.forEach((r) => {
    const l = el('div', 'sap-map-l'),
      m = (cfg.rotas[r] = { ...(cfg.rotas[r] || {}) }),
      pad = mapaSap(r, { ...cfg, rotas: {} });
    const c = (k, ph) => {
      const i = entrada('text', m[k] || '', { placeholder: ph });
      i.disabled = !gera;
      i.setAttribute('aria-label', r + ' ' + k);
      i.addEventListener('input', () => {
        m[k] = i.value.trim();
      });
      return i;
    };
    const rt = el('span', 'sap-map-r');
    rt.append(el('b', null, r), el('small', null, nomeRota(r)));
    const loc = c('local', t('obrigatório'));
    if (!m.local) loc.classList.add('falta');
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
  // lotes gerados
  if (SAPD.lotes.length)
    pg.append(
      tabela({
        id: 'sap-h',
        titulo: t('Ficheiros gerados'),
        nota: t('Cada ficheiro fica guardado e pode ser descarregado outra vez.'),
        sort: 'numero',
        total: false,
        rows: SAPD.lotes.map((x) => ({
          ...x,
          chave: x.numero,
          periodo: dl(x.de) + (x.ate !== x.de ? ' – ' + dl(x.ate) : ''),
          rotas_t: x.rotas.length ? x.rotas.join(', ') : t('Todas'),
        })),
        cols: [
          { key: 'numero', label: t('Lote'), type: 'n', p: 0 },
          { key: 'em', label: t('Gerado em'), render: (v) => document.createTextNode(dl(v) + ' ' + v.slice(11, 16)) },
          { key: 'por_nome', label: t('Por') },
          { key: 'periodo', label: t('Período') },
          { key: 'rotas_t', label: t('Rotas') },
          { key: 'n_documentos', label: t('Documentos'), type: 'n' },
          { key: 'linhas', label: t('Linhas'), type: 'n' },
          { key: 'total', label: t('Total'), type: 'kz' },
          {
            key: 'ficheiro',
            label: t('Ficheiro'),
            render: (v, r) => {
              const a = el('a', 'linkbtn', t('Descarregar'));
              a.href = 'data/sap.php?xlsx=' + r.numero;
              a.title = v;
              return a;
            },
            xl: (r) => r.ficheiro,
          },
        ],
      }),
    );
};
