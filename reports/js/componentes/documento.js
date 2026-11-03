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

/** Representação do documento, como um talão impresso (dados do Pinguim BO). */
function talao(v, linhas, pags) {
  const box = el('div', 'doc-talao');
  box.setAttribute('aria-label', t('Representação do documento'));
  const cab = el('div', 'dt-cab');
  cab.append(el('b', null, t(v.tipo || (eNC(v) ? 'Nota de crédito' : 'Factura'))), el('span', null, v.documento));
  box.append(cab, el('p', 'dt-data', dl(v.data) + (v.hora ? ' ' + v.hora : '') + ' · ' + t('Rota {r}', { r: promotorRota(v.rota) })));
  const cli = el('div', 'dt-cli');
  cli.append(el('span', null, v.cliente || t('Consumidor final')));
  if (v.nif) cli.append(el('span', null, t('NIF') + ' ' + v.nif));
  box.append(cli);
  const tab = el('table', 'dt-linhas');
  if (linhas === null) {
    const tr = el('tr');
    const td = el('td', 'dt-espera', t('A carregar os artigos…'));
    td.colSpan = 2;
    tr.append(td);
    tab.append(tr);
  } else if (!linhas.length) {
    const tr = el('tr');
    const td = el('td', 'dt-espera', t('Sem linhas de artigos no Pinguim BO.'));
    td.colSpan = 2;
    tr.append(td);
    tab.append(tr);
  }
  (linhas || []).forEach((a) => {
    const tr = el('tr');
    const d = el('td');
    d.append(
      el('span', 'dt-art', a.artigo || a.codigo),
      el('span', 'dt-q', qtd(Math.abs(a.quantidade)) + ' ' + (a.unidade || '') + ' × ' + kz(Math.abs(a.preco_unitario))),
    );
    tr.append(d, el('td', 'n', kz(a.total)));
    tab.append(tr);
  });
  box.append(tab);
  const tot = el('div', 'dt-tot');
  const linha = (l, val, cls) => {
    const p = el('p', cls || null);
    p.append(el('span', null, l), el('span', null, val));
    tot.append(p);
  };
  if (linhas && linhas.length) {
    const iva = sum(linhas, 'iva'),
      desc = sum(linhas, 'desconto');
    if (Math.abs(desc) > 0.005) linha(t('Desconto'), kz(-Math.abs(desc)));
    linha(t('IVA'), kz(iva));
  }
  linha(t('Total'), kz(v.total), 'dt-grande');
  // devoluções de notas de crédito sem meio no Pinguim BO: no meio da factura corrigida (ou numerário presumido)
  pags.forEach((p) =>
    linha(
      t(p.meio || p.codigo || 'Sem meio indicado') + (p.atribuido ? ' · ' + t('meio da {d}', { d: p.atribuido }) : p.presumido ? ' · ' + t('presumido') : ''),
      kz(p.valor),
    ),
  );
  if (!eNC(v) && pendenteDe(v) > 0.005) linha(t('Em falta'), kz(pendenteDe(v)), 'dt-falta');
  box.append(tot);
  box.append(el('p', 'dt-nota', t('Representação a partir dos dados do Pinguim BO; o documento fiscal original está no Pinguim BO.')));
  return box;
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
    const fechar = botao('×', 'ghost sm doc-fechar', () => dlg.close());
    fechar.setAttribute('aria-label', t('Fechar'));
    cab.append(el('div', null), fechar);
    cab.firstChild.append(tit, st);
    corpo.append(cab);
    // resumo: total, recebido, em falta, meio
    const res = el('div', 'doc-resumo');
    const item = (l, val) => {
      const d = el('div');
      d.append(el('span', null, l), el('b', null, val));
      res.append(d);
    };
    item(t('Total'), kz(v.total));
    if (!eNC(v)) {
      item(t('Recebido'), kz(recebidoDe(v)));
      item(t('Em falta'), kz(pendenteDe(v)));
    }
    item(t('Meio de cobrança'), pags.length ? pags.map((p) => t(p.meio || p.codigo)).join(', ') : '—');
    corpo.append(res);
    corpo.append(talao(v, linhas, pags));
    // artigos em tabela (para conferir e copiar)
    if (linhas && linhas.length) {
      const sec = el('section', 'doc-sec');
      sec.append(el('h3', null, t('Artigos')));
      const tb = el('table', 'cc-tab');
      const hr = el('tr');
      [t('Código'), t('Artigo'), t('Quantidade'), t('Preço'), t('Desconto'), t('IVA'), t('Total')].forEach((h, i) =>
        hr.append(el('th', i > 1 ? 'n' : null, h)),
      );
      tb.append(hr);
      linhas.forEach((a) => {
        const tr = el('tr');
        tr.append(
          el('td', null, a.codigo || '—'),
          el('td', null, a.artigo || '—'),
          el('td', 'n', qtd(a.quantidade) + ' ' + (a.unidade || '')),
          el('td', 'n', kz(a.preco_unitario)),
          el('td', 'n', kz(a.desconto)),
          el('td', 'n', kz(a.iva)),
          el('td', 'n', kz(a.total)),
        );
        tb.append(tr);
      });
      const w = el('div', 'cc-scroll');
      w.append(tb);
      sec.append(w);
      corpo.append(sec);
    }
    const acc = el('div', 'doc-acc');
    acc.append(
      botao(t('Imprimir'), 'ghost', () => {
        document.body.classList.add('a-imprimir-doc');
        window.print();
        setTimeout(() => document.body.classList.remove('a-imprimir-doc'), 500);
      }),
      botao(t('Fechar'), '', () => dlg.close()),
    );
    corpo.append(acc);
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
