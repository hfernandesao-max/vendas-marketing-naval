// Detalhe de um documento (factura, factura-recibo, nota de crédito): clicar no número do documento em qualquer
// tabela abre um painel lateral (no telemóvel, de baixo para cima) com a representação do documento, as linhas de
// artigos, os meios de cobrança e o estado. Tudo vem dos dados já lidos do Pinguim BO (as linhas pedem-se se ainda
// não chegaram); a representação não substitui o original fiscal, que está no Pinguim BO.
import { artigosProntos, garantirArtigos } from '../fontes.js';
import { eNC, pagosDe, pendenteDe, promotorRota, recebidoDe } from '../calculos.js';
import { dl, kz, n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { DB, el, sum, toast } from '../util.js';
import { botao } from './janelas.js';

const estado = (v) => (eNC(v) ? 'Nota de crédito' : v.liquidado && pendenteDe(v) <= 0.005 ? 'Pago' : 'Pendente');
const qtd = (q) => (Number.isInteger(+q) ? n0(q) : (+q).toLocaleString(undefined, { maximumFractionDigits: 3 }));

/** Coluna «Documento» que abre o painel do documento (o Excel continua a levar só o número). */
export const colDocumento = (extra = {}) => ({
  key: 'documento',
  label: t('Documento'),
  nowrap: true,
  render: (v, r) => {
    if (!v) return document.createTextNode('—');
    const b = el('button', 'doc-link', String(v));
    b.type = 'button';
    b.setAttribute('aria-label', t('Abrir o documento {d}', { d: v }));
    b.addEventListener('click', (e) => {
      e.stopPropagation();
      abrirDocumento((r && r.documento) || v);
    });
    return b;
  },
  xl: (r) => r.documento,
  ...extra,
});

const pctIva = (a) => {
  const base = Math.abs(+a.valor_sem_iva || 0);
  return base > 0.005 ? Math.round((Math.abs(+a.iva || 0) / base) * 100) : 0;
};

/**
 * Representação do documento como foi emitido (estrutura de uma factura: tipo e número, datas, cliente e NIF, linhas
 * com quantidade, unidade, preço, desconto, taxa e valor do IVA, resumo do IVA por taxa, totais e meios de pagamento),
 * em página branca e tipo de letra legível. Dados do Pinguim BO; o original fiscal está no Pinguim BO.
 */
function folha(v, linhas, pags) {
  const f = el('article', 'doc-folha');
  f.setAttribute('aria-label', t('Representação do documento'));
  // cabeçalho: tipo e número à direita, dados do documento por baixo
  const cab = el('header', 'df-cab');
  const esq = el('div', 'df-orig');
  esq.append(el('span', 'df-rot', t('Rota')), el('b', null, promotorRota(v.rota)));
  const dir = el('div', 'df-id');
  dir.append(el('span', 'df-tipo', t(v.tipo || (eNC(v) ? 'Nota de crédito' : 'Factura'))), el('span', 'df-num', v.documento));
  cab.append(esq, dir);
  f.append(cab);
  const meta = el('dl', 'df-meta');
  const par = (k, val) => {
    const d = el('div');
    d.append(el('dt', null, k), el('dd', null, val));
    meta.append(d);
  };
  par(t('Data'), dl(v.data));
  if (v.hora) par(t('Hora'), v.hora);
  par(t('Cliente'), v.cliente || t('Consumidor final'));
  par(t('NIF'), v.nif || '—');
  if (v.cliente_codigo) par(t('Código do cliente'), v.cliente_codigo);
  f.append(meta);
  // linhas
  const w = el('div', 'df-tabw');
  const tb = el('table', 'df-linhas');
  const hr = el('tr');
  [[t('Código')], [t('Descrição')], [t('Qtd.'), 'n'], [t('Un.')], [t('Preço unit.'), 'n'], [t('Desc.'), 'n'], [t('IVA'), 'n'], [t('Total'), 'n']].forEach(
    ([h, c]) => {
      const th = el('th', c || null, h);
      th.scope = 'col';
      hr.append(th);
    },
  );
  const th = el('thead');
  th.append(hr);
  tb.append(th);
  const tbody = el('tbody');
  if (!linhas || !linhas.length) {
    const tr = el('tr');
    const td = el('td', 'df-espera', linhas === null ? t('A carregar os artigos…') : t('Sem linhas de artigos no Pinguim BO.'));
    td.colSpan = 8;
    tr.append(td);
    tbody.append(tr);
  }
  (linhas || []).forEach((a) => {
    const tr = el('tr');
    tr.append(
      el('td', null, a.codigo || '—'),
      el('td', null, a.artigo || '—'),
      el('td', 'n', qtd(Math.abs(+a.quantidade || 0))),
      el('td', null, a.unidade || ''),
      el('td', 'n', kz(Math.abs(+a.preco_unitario || 0))),
      el('td', 'n', kz(Math.abs(+a.desconto || 0))),
      el('td', 'n', pctIva(a) + ' %'),
      el('td', 'n', kz(Math.abs(+a.total || 0))),
    );
    tbody.append(tr);
  });
  tb.append(tbody);
  w.append(tb);
  f.append(w);
  // rodapé: resumo do IVA e pagamentos à esquerda, totais à direita
  const pe = el('div', 'df-pe');
  const esqPe = el('div');
  if (linhas && linhas.length) {
    const taxas = new Map();
    linhas.forEach((a) => {
      const k = pctIva(a);
      const o = taxas.get(k) || { base: 0, iva: 0 };
      o.base += Math.abs(+a.valor_sem_iva || 0);
      o.iva += Math.abs(+a.iva || 0);
      taxas.set(k, o);
    });
    const ti = el('table', 'df-iva');
    const cab2 = el('tr');
    [t('Taxa de IVA'), t('Incidência'), t('Valor do IVA')].forEach((h, i) => cab2.append(el('th', i ? 'n' : null, h)));
    ti.append(cab2);
    [...taxas.entries()]
      .sort((a, b) => b[0] - a[0])
      .forEach(([k, o]) => {
        const tr = el('tr');
        tr.append(el('td', null, k + ' %'), el('td', 'n', kz(o.base)), el('td', 'n', kz(o.iva)));
        ti.append(tr);
      });
    esqPe.append(el('h4', null, t('Resumo do IVA')), ti);
  }
  esqPe.append(el('h4', null, eNC(v) ? t('Devolução') : t('Meios de pagamento')));
  const ul = el('ul', 'df-pags');
  if (!pags.length) ul.append(el('li', null, '—'));
  // devoluções de notas de crédito sem meio no Pinguim BO: no meio da factura corrigida (ou numerário presumido)
  pags.forEach((p) => {
    const li = el('li');
    li.append(
      el(
        'span',
        null,
        t(p.meio || p.codigo || 'Sem meio indicado') + (p.atribuido ? ' · ' + t('meio da {d}', { d: p.atribuido }) : p.presumido ? ' · ' + t('presumido') : ''),
      ),
      el('b', null, kz(p.valor)),
    );
    ul.append(li);
  });
  esqPe.append(ul);
  const tot = el('dl', 'df-tot');
  const linha = (k, val, cls) => {
    const d = el('div', cls || null);
    d.append(el('dt', null, k), el('dd', null, val));
    tot.append(d);
  };
  if (linhas && linhas.length) {
    const base = sum(linhas, (a) => Math.abs(+a.valor_sem_iva || 0)),
      desc = sum(linhas, (a) => Math.abs(+a.desconto || 0)),
      iva = sum(linhas, (a) => Math.abs(+a.iva || 0));
    linha(t('Total ilíquido'), kz(base + desc));
    linha(t('Descontos'), kz(desc));
    linha(t('Total do IVA'), kz(iva));
  }
  linha(t('Total do documento'), kz(v.total), 'df-grande');
  if (!eNC(v)) {
    linha(t('Recebido'), kz(recebidoDe(v)));
    if (pendenteDe(v) > 0.005) linha(t('Em falta'), kz(pendenteDe(v)), 'df-falta');
  }
  pe.append(esqPe, tot);
  f.append(pe);
  f.append(el('p', 'df-nota', t('Representação a partir dos dados do Pinguim BO; o documento fiscal original está no Pinguim BO.')));
  return f;
}

/** Abre o painel do documento (pelo número). */
export function abrirDocumento(numero) {
  const v = (DB.vendas || []).find((x) => x.documento === numero);
  if (!v) {
    toast(t('O documento {d} não está no período carregado.', { d: numero }), 5000);
    return;
  }
  document.querySelectorAll('dialog.doc-painel').forEach((d) => d.close());
  const dlg = el('dialog', 'doc-painel');
  dlg.setAttribute('aria-labelledby', 'doc-titulo');
  const corpo = el('div', 'doc-corpo');
  const desenhar = () => {
    const linhas = artigosProntos() ? (DB.artigos || []).filter((a) => a.documento === v.documento) : null;
    const pags = pagosDe(v);
    corpo.replaceChildren();
    const cab = el('header', 'doc-cab');
    const tit = el('h2', null, v.documento);
    tit.id = 'doc-titulo';
    const e = estado(v);
    const st = el('span', 'pill ' + (e === 'Pago' ? 'p-ok' : e === 'Pendente' ? 'p-warn' : 'p-mut'), t(e));
    // barra fixa no topo: número e estado à esquerda, Imprimir e Fechar à direita (sempre à mão)
    const imprimir = botao(t('Imprimir'), 'ghost sm', () => {
      document.body.classList.add('a-imprimir-doc');
      window.print();
      setTimeout(() => document.body.classList.remove('a-imprimir-doc'), 500);
    });
    const fechar = botao(t('Fechar'), 'sm doc-fechar', () => dlg.close());
    const acc = el('div', 'doc-acc');
    acc.append(imprimir, fechar);
    const id = el('div', 'doc-idt');
    id.append(tit, st);
    cab.append(id, acc);
    corpo.append(cab);
    corpo.append(folha(v, linhas, pags));
  };
  dlg.append(corpo);
  // clicar fora do painel fecha-o; Esc fecha (nativo); o foco volta ao botão que o abriu
  const origem = document.activeElement;
  dlg.addEventListener('click', (e) => {
    if (e.target === dlg) dlg.close();
  });
  dlg.addEventListener('close', () => {
    setTimeout(() => dlg.remove(), 0);
    if (origem && origem.focus) origem.focus();
  });
  document.body.append(dlg);
  desenhar();
  dlg.showModal();
  if (!artigosProntos())
    garantirArtigos().then(() => {
      if (dlg.open) desenhar();
    });
}
