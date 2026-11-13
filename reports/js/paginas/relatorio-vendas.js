// Vendas › Relatório de Vendas diário do Marketing: um só documento com o resumo de vendas e cobranças, a
// conciliação por promotor (Pinguim BO × caixa × TPA), a folha de caixa, o registo de vendas e o stock das carrinhas,
// e (com o conector ligado) a facturação SAP de todas as empresas. Só se gera com todas as fontes do dia completas;
// depois fica congelado (versão e impressão digital) e o PDF e o Excel saem dessa fotografia.
// Servidor: nucleo/api/v1/relatorio-vendas.php e nucleo/servicos/relatorio-vendas.php.
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { pill, tabela, tiles } from '../componentes/blocos.js';
import { botao, entrada, janela, msgErro } from '../componentes/janelas.js';
import { relatorioVendasPdf } from '../componentes/relatorio-vendas-pdf.js';
import { dl, ds, kz, n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { atual } from '../perfil.js';
import { hoje } from '../periodo.js';
import { postJson } from '../servidor.js';
import { el, somaDias, toast } from '../util.js';
import { R } from './registo.js';

let C = null; // controlos e versões do dia
let REL = null; // relatório aberto
let dia = null;
let aLer = false;
export const CONTROLOS = [
  ['pinguim', 'Vendas do Pinguim BO completas (todos os telemóveis sincronizados depois do fecho)'],
  ['conciliacao', 'Conciliação de todas as carrinhas fechada'],
  ['sap_ficheiro', 'Vendas do dia carregadas no SAP'],
  ['caixa', 'Folha de caixa fechada e aprovada'],
  ['sap_odata', 'Facturação SAP das outras empresas (conector SAP)'],
];
const ESTADO_CTL = { ok: ['Completo', 'p-ok'], pendente: ['Em falta', 'p-warn'], erro: ['Sem ligação', 'p-bad'], por_ligar: ['Por ligar', 'p-mut'] };

function ler() {
  if (aLer) return;
  aLer = true;
  apiJson('relatorio-vendas?dia=' + dia).then((d) => {
    aLer = false;
    C = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os dados.') };
    if (C.versoes && C.versoes.length && !REL) abrir(C.versoes[C.versoes.length - 1].versao);
    if (atual === 'relatorio-vendas') draw();
  });
}
function abrir(v) {
  apiJson('relatorio-vendas?dia=' + dia + '&versao=' + v).then((d) => {
    if (!d || !d.ok) return toast(t((d && d.erro) || 'Não foi possível ler os dados.'), 5000);
    REL = d.relatorio;
    draw();
  });
}
function mudarDia(d) {
  if (!d || d > hoje()) return;
  dia = d;
  C = null;
  REL = null;
  draw();
}
function gerar(motivo, erro, depois) {
  return postJson('relatorio-vendas', { accao: 'gerar', dia, motivo }).then((x) => {
    if (!x.ok) {
      erro.hidden = false;
      erro.textContent = t(x.erro || 'Não foi possível gerar.');
      return;
    }
    REL = x.relatorio;
    C = null;
    toast(t('Relatório {n} gerado.', { n: x.relatorio.numero }), 4000);
    if (depois) depois();
    ler();
    draw();
  });
}

function controlos() {
  const s = el('section', 'glass sec rv-ctl');
  const h = el('div', 'sec-h');
  const hl = el('div');
  hl.append(
    el('h2', null, t('Fecho do dia: as fontes estão completas?')),
    el('p', null, t('O relatório só se gera quando todas as fontes do dia estão completas e fechadas. O conector SAP por ligar não impede a geração.')),
  );
  h.append(hl);
  s.append(h);
  const ul = el('ul', 'rv-lista');
  CONTROLOS.forEach(([k, nome]) => {
    const x = C.controlos[k];
    if (!x) return;
    const [txt, cls] = ESTADO_CTL[x.estado] || [x.estado, 'p-mut'];
    const li = el('li', 'rv-' + x.estado);
    li.append(pill(t(txt), cls), el('b', null, t(nome)), el('span', 'note', x.detalhe));
    ul.append(li);
  });
  s.append(ul);
  const erro = msgErro();
  const acc = el('div', 'cc-acc');
  if (C.pode_gerar) {
    const jaHa = (C.versoes || []).length > 0;
    const b = botao(jaHa ? t('Gerar nova versão') : t('Gerar o relatório'), jaHa ? 'ghost' : null, () => {
      if (!jaHa) return gerar('', erro);
      janela(t('Nova versão do relatório de {d}', { d: dl(dia) }), t('A versão anterior fica guardada. Indique o motivo da nova versão.'), (bx, ft, fechar) => {
        const m = el('textarea');
        m.rows = 2;
        m.maxLength = 300;
        m.setAttribute('aria-label', t('Motivo da nova versão'));
        const e2 = msgErro();
        bx.append(m, e2);
        ft.append(
          botao(t('Cancelar'), 'ghost', fechar),
          botao(t('Gerar'), null, () => gerar(m.value, e2, fechar)),
        );
      });
    });
    b.disabled = !C.pronto;
    if (!C.pronto) b.title = t('Faltam fontes do dia: veja a lista acima.');
    acc.append(b);
  }
  s.append(erro, acc);
  if ((C.versoes || []).length) {
    const v = el('p', 'note rv-versoes');
    v.append(document.createTextNode(t('Versões') + ': '));
    C.versoes.forEach((x) => {
      const a = el('button', 'linkbtn', x.numero);
      a.type = 'button';
      a.title =
        t('Gerado por {p} em {d}', { p: x.gerado_por, d: dl(x.gerado_em.slice(0, 10)) + ' ' + x.gerado_em.slice(11, 16) }) + (x.motivo ? ' · ' + x.motivo : '');
      a.addEventListener('click', () => abrir(x.versao));
      v.append(a, document.createTextNode(' '));
    });
    s.append(v);
  }
  return s;
}

function relatorio(pg) {
  const d = REL.dados,
    r = d.resumo;
  const cab = el('section', 'glass sec rv-cab');
  cab.append(
    el('b', null, t('Relatório {n}', { n: REL.numero })),
    el('span', 'note', t('gerado por {p} em {d}', { p: REL.gerado_por, d: dl(REL.gerado_em.slice(0, 10)) + ' ' + REL.gerado_em.slice(11, 16) })),
    el('span', 'note rv-sha', 'SHA-256 ' + REL.sha256.slice(0, 16) + '…'),
    botao(t('PDF do relatório'), 'sm', () => relatorioVendasPdf(REL).catch(() => toast(t('Não foi possível gerar o PDF.'), 5000))),
  );
  if (REL.motivo) cab.append(el('span', 'note', t('Motivo') + ': ' + REL.motivo));
  pg.append(cab);
  pg.append(
    tiles([
      { l: t('Vendas RTM (líquidas)'), v: kz(r.vendas), s: t('{n} documentos · {p} promotores', { n: n0(r.documentos), p: n0(r.promotores) }) },
      { l: t('Dinheiro'), v: kz(r.dinheiro), s: t('entregue na caixa: {v}', { v: kz(r.entregue) }), tone: Math.abs(r.dif_dinheiro) >= 0.01 ? 'bad' : null },
      { l: t('Multicaixa'), v: kz(r.multicaixa), s: t('talões do TPA: {v}', { v: kz(r.tpa) }), tone: Math.abs(r.dif_tpa) >= 0.01 ? 'bad' : null },
      { l: t('Por receber'), v: kz(r.por_receber), tone: r.por_receber > 0 ? 'warn' : null },
      { l: t('Notas de crédito'), v: kz(r.notas_credito) },
      { l: t('Saldo final da caixa'), v: kz(d.caixa.contagem_fisica), s: t('teórico: {v}', { v: kz(d.caixa.saldo_teorico) }) },
    ]),
  );
  const difCol = (key, label) => ({
    key,
    label,
    type: 'kz',
    sum: true,
    render: (v) => el('span', Math.abs(v) >= 0.01 ? 'rv-dif' : null, kz(v)),
  });
  pg.append(
    tabela({
      id: 'rv-prom',
      titulo: t('Vendas e cobranças por promotor'),
      nota: t(
        'Pinguim BO × caixa × TPA. Diferença do dinheiro: entregue na caixa − recebido em dinheiro no Pinguim BO; diferença do Multicaixa: talões do TPA − recebido em Multicaixa.',
      ),
      rows: d.promotores.map((p) => ({ ...p, nome: p.rota + ' · ' + p.promotor })),
      sort: 'vendas',
      total: true,
      cols: [
        { key: 'nome', label: t('Promotor'), p: 0 },
        { key: 'documentos', label: t('Documentos'), type: 'n', sum: true, p: 4 },
        { key: 'vendas', label: t('Vendas'), type: 'bar', sum: true, p: 1 },
        { key: 'dinheiro', label: t('Dinheiro'), type: 'kz', sum: true, p: 2 },
        { key: 'entregue', label: t('Entregue na caixa'), type: 'kz', sum: true, p: 2 },
        difCol('dif_dinheiro', t('Diferença do dinheiro')),
        { key: 'multicaixa', label: t('Multicaixa'), type: 'kz', sum: true, p: 3 },
        { key: 'tpa', label: t('Talões do TPA'), type: 'kz', sum: true, p: 3 },
        difCol('dif_tpa', t('Diferença do Multicaixa')),
        { key: 'por_receber', label: t('Por receber'), type: 'kz', sum: true, p: 4 },
        { key: 'notas_credito', label: t('Notas de crédito'), type: 'kz', sum: true, p: 5 },
      ],
    }),
  );
  const cx = d.caixa;
  pg.append(
    tabela({
      id: 'rv-caixa',
      titulo: t('Caixa do dia'),
      nota: t('Saldo inicial + dinheiro entregue − saídas = saldo teórico; a contagem física fecha a caixa.'),
      search: false,
      rows: [
        { item: t('Saldo inicial'), valor: cx.saldo_inicial },
        { item: t('Dinheiro entregue pelos promotores'), valor: cx.entregas },
        ...cx.saidas.map((s) => ({
          item:
            '− ' +
            t(
              s.tipo === 'deposito_banco'
                ? 'Depósito no banco'
                : s.tipo === 'fundo_maneio'
                  ? 'Fundo de maneio'
                  : s.tipo === 'transferencia_gestao'
                    ? 'Transferência para a gestão'
                    : 'Outra saída',
            ) +
            (s.banco ? ' · ' + s.banco : '') +
            (s.lacre ? ' · ' + t('lacre {l}', { l: s.lacre }) : '') +
            (s.empresa ? ' · ' + s.empresa : '') +
            (s.descricao ? ' · ' + s.descricao : ''),
          valor: -s.valor,
        })),
        { item: t('Saldo final teórico'), valor: cx.saldo_teorico },
        { item: t('Contagem física'), valor: cx.contagem_fisica },
        { item: t('Diferença') + (cx.explicacao ? ' · ' + cx.explicacao : ''), valor: cx.diferenca },
      ],
      cols: [
        { key: 'item', label: t('Movimento'), p: 0, wrap: true },
        { key: 'valor', label: t('Valor'), type: 'kz', p: 0 },
      ],
    }),
  );
  if (cx.tpa.length)
    pg.append(
      tabela({
        id: 'rv-tpa',
        titulo: t('Multicaixa por banco (talões do TPA)'),
        rows: cx.tpa.map((x) => ({ ...x, nome: x.rota + ' · ' + ((d.promotores.find((p) => p.rota === x.rota) || {}).promotor || '') })),
        total: true,
        cols: [
          { key: 'banco', label: t('Banco'), p: 0 },
          { key: 'empresa', label: t('Empresa'), p: 3 },
          { key: 'nome', label: t('Promotor'), p: 1 },
          { key: 'valor', label: t('Valor'), type: 'kz', sum: true, p: 0 },
        ],
      }),
    );
  pg.append(
    tabela({
      id: 'rv-registo',
      titulo: t('Registo de vendas'),
      nota: t('Linhas de venda do Pinguim BO, por promotor e documento (valor com IVA).'),
      rows: d.registo.map((x) => ({ ...x, nome: x.rota + ' · ' + x.promotor })),
      sort: 'nome',
      dir: 1,
      total: true,
      cols: [
        { key: 'nome', label: t('Promotor'), p: 1 },
        { key: 'documento', label: t('Documento'), p: 2 },
        { key: 'codigo', label: t('Código'), p: 5 },
        { key: 'artigo', label: t('Artigo'), wrap: true, p: 0 },
        { key: 'quantidade', label: t('Qtd.'), type: 'n', sum: true, p: 3 },
        { key: 'preco', label: t('Preço unit.'), type: 'kz', p: 4 },
        { key: 'valor', label: t('Valor'), type: 'kz', sum: true, p: 0 },
      ],
    }),
  );
  pg.append(
    tabela({
      id: 'rv-stock',
      titulo: t('Stock das carrinhas'),
      nota: t('Da conciliação das carrinhas: inicial + carregado − vendido − devolvido = final; contado e diferença.'),
      rows: d.stock.map((x) => ({ ...x, nome: x.rota + ' · ' + x.promotor })),
      sort: 'nome',
      dir: 1,
      total: true,
      cols: [
        { key: 'nome', label: t('Promotor'), p: 1 },
        { key: 'artigo', label: t('Artigo'), wrap: true, p: 0 },
        { key: 'inicial', label: t('Inicial'), type: 'n', sum: true, p: 3 },
        { key: 'carregado', label: t('Carregado'), type: 'n', sum: true, p: 2 },
        { key: 'vendido', label: t('Vendido'), type: 'n', sum: true, p: 2 },
        { key: 'devolvido', label: t('Devolvido'), type: 'n', sum: true, p: 4 },
        { key: 'final', label: t('Final'), type: 'n', sum: true, p: 0 },
        { key: 'contado', label: t('Contado'), type: 'n', sum: true, p: 4 },
        { key: 'diferenca', label: t('Diferença'), type: 'n', sum: true, p: 4 },
      ],
    }),
  );
  if (d.sap)
    pg.append(
      tabela({
        id: 'rv-sap',
        titulo: t('Facturação SAP por empresa'),
        nota: t('Lida do SAP: dinheiro (ZBV), depósito (ZDP) e crédito (ZF2).'),
        rows: Object.entries(d.sap.resumo).map(([e, x]) => ({ empresa: e, ...x })),
        total: true,
        cols: [
          { key: 'empresa', label: t('Empresa'), p: 0 },
          { key: 'dinheiro', label: t('Dinheiro'), type: 'kz', sum: true },
          { key: 'deposito', label: t('Depósito'), type: 'kz', sum: true },
          { key: 'credito', label: t('Crédito'), type: 'kz', sum: true },
          { key: 'outro', label: t('Outros'), type: 'kz', sum: true },
          { key: 'total', label: t('Total'), type: 'bar', sum: true, p: 0 },
        ],
      }),
    );
  else pg.append(el('p', 'note', t('Facturação SAP das outras empresas: conector SAP por ligar.')));
}

R['relatorio-vendas'] = (pg) => {
  dia = dia || somaDias(hoje(), -1);
  const barra = el('section', 'glass sec pc-barra');
  const dd = el('div', 'pc-dia');
  const ant = botao('‹', 'ghost sm', () => mudarDia(somaDias(dia, -1)));
  ant.setAttribute('aria-label', t('Dia anterior'));
  const seg = botao('›', 'ghost sm', () => mudarDia(somaDias(dia, 1)));
  seg.setAttribute('aria-label', t('Dia seguinte'));
  seg.disabled = dia >= hoje();
  const di = entrada('date', dia, { max: hoje() });
  di.setAttribute('aria-label', t('Dia'));
  di.addEventListener('change', () => mudarDia(di.value));
  const dc = el('span', 'pc-dcampo');
  dc.append(di, el('span', 'dv', dl(dia)));
  dd.append(
    ant,
    el('b', 'pc-dia-t', ds(dia)),
    dc,
    seg,
    botao(t('Actualizar'), 'ghost sm', () => ((C = null), draw())),
  );
  barra.append(dd);
  pg.append(barra);
  if (!C) {
    pg.append(el('div', 'glass empty', t('A verificar as fontes do dia…')));
    ler();
    return;
  }
  if (C.falhou) {
    pg.append(el('div', 'glass empty', t(C.falhou)));
    return;
  }
  pg.append(controlos());
  if (REL && REL.dia === dia) relatorio(pg);
  else if (!(C.versoes || []).length) pg.append(el('div', 'glass empty', t('Ainda não há relatório deste dia.')));
};
