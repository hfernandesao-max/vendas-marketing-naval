// Finanças › Multicaixa por TPA: o fecho de cada TPA (talão «Desactivar (fecho)») comparado com o Multicaixa do
// Pinguim BO, máquina a máquina e dia a dia; os totais por empresa, banco e conta (o que aparece no extracto, para
// limpar a conta de cartões no SAP); «Conferido no extracto» com a referência do movimento; o registo dos terminais e
// quem recebe o resumo de fim de dia. Servidor: nucleo/api/v1/multicaixa-tpa.php e nucleo/servicos/tpa.php.
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { pill, tabela as tabelaB, tiles } from '../componentes/blocos.js';
import { botao, entrada, janela, msgErro } from '../componentes/janelas.js';
import { dl, kz, n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { atual } from '../perfil.js';
import { hoje } from '../periodo.js';
import { postJson } from '../servidor.js';
import { el, somaDias, toast } from '../util.js';
import { R } from './registo.js';

let D = null;
let chave = '';
let aLer = false;
let vista = 'relatorio';
const param = (k) => new URLSearchParams(location.hash.split('?')[1] || '').get(k) || '';
const dataOk = (s) => /^\d{4}-\d{2}-\d{2}$/.test(s);
let de = '';
let ate = '';

function periodoInicial() {
  if (de) return;
  const a = param('ate'),
    d = param('de');
  ate = dataOk(a) ? a : somaDias(hoje(), -1);
  de = dataOk(d) && d <= ate ? d : ate;
}

function ler(forcar) {
  const k = de + '|' + ate;
  if (aLer || (!forcar && D && chave === k)) return;
  aLer = true;
  apiJson('multicaixa-tpa?de=' + de + '&ate=' + ate).then((d) => {
    aLer = false;
    chave = k;
    D = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os dados.') };
    if (atual === 'multicaixa-tpa') draw();
  });
}

const prom = (r) => (D && D.promotores && D.promotores[r] ? r + ' · ' + D.promotores[r] : r || '—');
const BANCO = { ATLANTICO: 'Atlântico' };
const banco = (b) => BANCO[b] || b || '—';
const EMPRESA = { NAVAL: 'Naval Trading', HIGITEC: 'Higitec', FOODTEC: 'Foodtec', REFITECH: 'Refitec' };
const empresa = (e) => EMPRESA[e] || e || '—';
const MOTIVO = {
  sem_referencia: 'Sem referência «Per/Tr» no campo «Número».',
  formato: 'Referência no formato errado: esperado «período/transacção» (ex.: 192/003).',
  fora_do_periodo: 'O período da referência não é o do fecho do TPA desse dia.',
};

function tabela(cabecalho, linhas, rodape) {
  const tab = el('table', 'cc-tab');
  const cab = el('tr');
  cabecalho.forEach(([txt, n]) => cab.append(el('th', n ? 'n' : null, txt)));
  tab.append(cab);
  linhas.forEach((l) => tab.append(l));
  if (rodape) tab.append(rodape);
  const w = el('div', 'cc-scroll');
  w.append(tab);
  return w;
}
const td = (txt, cls) => el('td', cls || null, txt == null || txt === '' ? '—' : String(txt));
function seccao(titulo, nota, ...conteudo) {
  const s = el('section', 'glass sec');
  const h = el('div', 'sec-h');
  const d = el('div');
  d.append(el('h2', null, titulo));
  if (nota) d.append(el('p', null, nota));
  h.append(d);
  s.append(h, ...conteudo);
  return s;
}

/** «Conferido no extracto»: referência e data do movimento (o Financeiro marca; outra pessoa pode retirar). */
function conferir(f) {
  janela(
    t('Conferido no extracto'),
    t('TPA {tpa} · período {p} · {v}. Indique o movimento do extracto em que o banco creditou este fecho.', { tpa: f.tpa, p: f.periodo, v: kz(f.valor) }),
    (b, ft, fechar) => {
      const ref = entrada('text', '', { maxLength: 40 });
      ref.setAttribute('aria-label', t('Referência do movimento'));
      const data = entrada('date', somaDias(f.dia, 1), { max: hoje() });
      data.setAttribute('aria-label', t('Data do movimento'));
      const e = msgErro();
      const lin = (rot, c) => {
        const l = el('label', 'campo');
        l.append(el('span', null, rot), c);
        return l;
      };
      b.append(lin(t('Referência do movimento'), ref), lin(t('Data do movimento'), data), e);
      ft.append(
        botao(t('Cancelar'), 'ghost', fechar),
        botao(t('Marcar como conferido'), null, () =>
          postJson('multicaixa-tpa', { accao: 'conferir', dia: f.dia, tpa: f.tpa, periodo: f.periodo, ref: ref.value, data: data.value }).then((x) => {
            if (!x || !x.ok) {
              e.hidden = false;
              e.textContent = t((x && x.erro) || 'Não foi possível gravar.');
              return;
            }
            fechar();
            toast(t('Fecho conferido no extracto.'), 3000);
            ler(true);
          }),
        ),
      );
    },
  );
}

function relatorio(pg) {
  const r = D.relatorio;
  const tt = r.totais;
  const dif = Math.abs(tt.diferenca) >= 0.01;
  if (!r.pinguim_ligado)
    pg.append(el('div', 'glass note', t('Sem ligação ao Pinguim BO: os fechos aparecem, mas a comparação com os pagamentos fica por fazer.')));
  pg.append(
    tiles([
      { l: t('Fechos dos TPA (total a movimentar)'), v: kz(tt.fechos), s: t('{n} fechos · {o} operações', { n: tt.n_fechos, o: n0(tt.operacoes) }) },
      { l: t('Multicaixa no Pinguim BO'), v: kz(tt.pinguim), s: t('mesmas rotas e dias') },
      { l: t('Diferença'), v: kz(tt.diferenca), s: dif ? t('ver as linhas assinaladas') : t('sem diferenças'), cls: dif ? 'tile-alerta' : '' },
      { l: t('Conferido no extracto'), v: t('{a} de {b}', { a: tt.n_conferidos, b: tt.n_fechos }), s: t('por conferir: {v}', { v: kz(tt.por_conferir) }) },
    ]),
  );
  // por TPA
  const linhas = r.fechos.map((f) => {
    const tr = el('tr');
    const d = f.diferenca;
    const ext = el('td');
    if (f.conferido) {
      ext.append(pill(t('Conferido'), 'p-ok'), el('div', 'note', dl(f.conferido.data) + ' · ' + f.conferido.ref));
      if (D.gere) {
        const b = botao(t('Retirar'), 'ghost sm', () =>
          postJson('multicaixa-tpa', { accao: 'retirar_conferencia', dia: f.dia, tpa: f.tpa, periodo: f.periodo }).then(() => ler(true)),
        );
        b.setAttribute('aria-label', t('Retirar a conferência do TPA {tpa}', { tpa: f.tpa }));
        ext.append(b);
      }
    } else if (d != null && Math.abs(d) >= 0.01) ext.append(pill(t('Diferença'), 'p-serio'));
    else ext.append(pill(t('Por conferir'), 'p-mut'));
    if (!f.conferido && D.gere) {
      const b = botao(t('Conferir'), 'ghost sm', () => conferir(f));
      b.setAttribute('aria-label', t('Conferir no extracto o TPA {tpa}, período {p}', { tpa: f.tpa, p: f.periodo }));
      ext.append(b);
    }
    tr.append(
      td(dl(f.dia)),
      td(empresa(f.empresa)),
      td(banco(f.banco) + (f.conta ? ' · ' + f.conta : '')),
      td(f.tpa),
      td(prom(f.rota)),
      td(f.periodo),
      td(f.operacoes, 'n'),
      td(kz(f.valor), 'n'),
      td(f.pinguim == null ? (f.partilhado ? t('(na 1.ª linha da rota)') : '—') : kz(f.pinguim), 'n'),
      el('td', 'n' + (d != null && Math.abs(d) >= 0.01 ? ' rv-dif' : ''), d == null ? '—' : kz(d)),
      ext,
    );
    return tr;
  });
  const rod = el('tr', 'cx-total');
  rod.append(
    el('td', null, t('Total')),
    el('td'),
    el('td'),
    el('td'),
    el('td'),
    el('td'),
    td(n0(tt.operacoes), 'n'),
    td(kz(tt.fechos), 'n'),
    td(kz(tt.pinguim), 'n'),
    el('td', 'n' + (dif ? ' rv-dif' : ''), kz(tt.diferenca)),
    el('td'),
  );
  pg.append(
    seccao(
      t('Por TPA (máquina)'),
      t('Uma linha por fecho de período. O banco credita o «total a movimentar» de cada período: TPA + período identificam a linha no extracto.'),
      r.fechos.length
        ? tabela(
            [
              [t('Dia')],
              [t('Empresa')],
              [t('Banco · conta')],
              [t('Ident. TPA')],
              [t('Rota')],
              [t('Período')],
              [t('Operações'), 1],
              [t('Total do fecho'), 1],
              [t('Pinguim BO'), 1],
              [t('Diferença'), 1],
              [t('Extracto')],
            ],
            linhas,
            rod,
          )
        : el('p', 'note', t('Sem talões de fecho de TPA registados na folha de caixa neste período.')),
    ),
  );
  // rotas com Multicaixa no Pinguim BO e sem talão de fecho
  if (r.sem_fecho.length)
    pg.append(
      seccao(
        t('Multicaixa sem talão de fecho'),
        t('Rotas com pagamentos Multicaixa no Pinguim BO e sem nenhum talão de fecho do TPA na folha de caixa desse dia.'),
        tabela(
          [[t('Dia')], [t('Rota')], [t('TPA atribuído')], [t('Pinguim BO'), 1]],
          r.sem_fecho.map((s) => {
            const tr = el('tr');
            tr.append(td(dl(s.dia)), td(prom(s.rota)), td(s.tpa), el('td', 'n rv-dif', kz(s.pinguim)));
            return tr;
          }),
        ),
      ),
    );
  // por banco e conta
  const rb = el('tr', 'cx-total');
  const tc = r.bancos.reduce((a, b) => a + b.conferido, 0);
  rb.append(
    el('td', null, t('Total')),
    el('td'),
    el('td'),
    td(
      r.bancos.reduce((a, b) => a + b.tpa, 0),
      'n',
    ),
    td(kz(tt.fechos), 'n'),
    td(kz(tc), 'n'),
  );
  if (r.bancos.length)
    pg.append(
      seccao(
        t('Por empresa, banco e conta'),
        t('O que aparece no extracto: é com estes totais que o Financeiro limpa a conta de cartões (POS Card GL) no SAP.'),
        tabela(
          [[t('Empresa')], [t('Banco')], [t('Conta')], [t('TPA'), 1], [t('A creditar'), 1], [t('Conferido'), 1]],
          r.bancos.map((b) => {
            const tr = el('tr');
            tr.append(td(empresa(b.empresa)), td(banco(b.banco)), td(b.conta), td(b.tpa, 'n'), td(kz(b.valor), 'n'), td(kz(b.conferido), 'n'));
            return tr;
          }),
          rb,
        ),
      ),
    );
  // pagamentos a rever (referência «Per/Tr» no Pinguim BO)
  if (!r.referencias)
    pg.append(
      seccao(
        t('Pagamentos a rever'),
        t(
          'Falta indicar ao portal a coluna do Pinguim BO onde fica o «Número» do pagamento (PINGUIM_MC_REFERENCIA). Com ela, cada pagamento Multicaixa é conferido pela referência «período/transacção» do talão.',
        ),
      ),
    );
  else
    pg.append(
      seccao(
        t('Pagamentos a rever'),
        t('Pagamentos Multicaixa do Pinguim BO sem referência «Per/Tr», com o formato errado ou de outro período que não o fechado.'),
        r.rever.length
          ? tabela(
              [[t('Dia')], [t('Documento')], [t('Rota')], [t('Valor'), 1], [t('«Número» no Pinguim')], [t('O que se passa')]],
              r.rever.map((x) => {
                const tr = el('tr');
                tr.append(
                  td(dl(x.dia)),
                  td(x.documento),
                  td(prom(x.rota)),
                  td(kz(x.valor), 'n'),
                  td(x.ref || t('(vazio)')),
                  td(t(MOTIVO[x.motivo] || x.motivo)),
                );
                return tr;
              }),
            )
          : el('p', 'note', t('Todos os pagamentos Multicaixa têm a referência certa.')),
      ),
    );
}

const SIT_COR = { em_funcionamento: 'p-ok', reserva: 'p-mut', em_revisao: 'p-warn', avariado: 'p-bad', abatido: 'p-mut' };
const situacao = (x) => x.situacao || (x.activo === false ? 'reserva' : 'em_funcionamento');
function terminais(pg) {
  const lista = D.terminais || [];
  const editar = (x) =>
    janela(x ? t('TPA {tpa}', { tpa: x.tpa }) : t('Registar TPA'), t('Os dados estão no talão do TPA: «Ident. TPA» e «Id. Estab.».'), (b, ft, fechar) => {
      const v = x || { tpa: '', estab: '', empresa: 'NAVAL', banco: '', conta: '', rota: '', serie: '', situacao: 'em_funcionamento', defeito: '', nota: '' };
      const sel = (lista2, val, vazio) => {
        const s = el('select');
        if (vazio) {
          const o = el('option', null, vazio);
          o.value = '';
          s.append(o);
        }
        lista2.forEach(([k, txt]) => {
          const o = el('option', null, txt);
          o.value = k;
          if (String(k) === String(val || '')) o.selected = true;
          s.append(o);
        });
        return s;
      };
      const tpa = entrada('text', v.tpa, { maxLength: 8, inputMode: 'numeric' });
      if (x) tpa.readOnly = true;
      const estab = entrada('text', v.estab, { maxLength: 15, inputMode: 'numeric' });
      const emp = sel(
        (D.empresas || []).map((e) => [e, empresa(e)]),
        v.empresa,
      );
      const bco = sel(
        (D.bancos || []).map((e) => [e, banco(e)]),
        v.banco,
        t('Escolha'),
      );
      const conta = entrada('text', v.conta, { maxLength: 40 });
      const rota = sel(
        Object.keys(D.promotores || {}).map((r) => [r, prom(r)]),
        v.rota,
        t('Sem promotor associado'),
      );
      const serie = entrada('text', v.serie || '', { maxLength: 40 });
      const sit = sel(
        Object.entries(D.situacoes || {}).map(([k, n]) => [k, t(n)]),
        situacao(v),
      );
      const defeito = entrada('text', v.defeito || '', { maxLength: 120 });
      const nota = entrada('text', v.nota || '', { maxLength: 120 });
      const lin = (rot, c) => {
        const l = el('label', 'campo');
        l.append(el('span', null, rot), c);
        return l;
      };
      const e = msgErro();
      b.append(
        lin(t('Ident. TPA (8 dígitos)'), tpa),
        lin(t('Número de série'), serie),
        lin(t('Id. Estab.'), estab),
        lin(t('Empresa'), emp),
        lin(t('Banco'), bco),
        lin(t('Conta'), conta),
        lin(t('Promotor (rota)'), rota),
        lin(t('Situação'), sit),
        lin(t('Avaria ou defeito'), defeito),
        lin(t('Nota informativa (facultativa)'), nota),
        e,
      );
      // cadeia de custódia: quem teve o terminal e quando
      const cad = (v.associacoes || []).slice().reverse();
      if (cad.length) {
        b.append(el('h3', null, t('Promotores que tiveram este TPA')));
        b.append(
          tabela(
            [[t('Promotor')], [t('Desde')], [t('Até')], [t('Associado por')]],
            cad.map((a) => {
              const tr = el('tr');
              tr.append(
                td(prom(a.rota)),
                td(dl(a.de.slice(0, 10)) + ' ' + a.de.slice(11, 16)),
                td(a.ate ? dl(a.ate.slice(0, 10)) + ' ' + a.ate.slice(11, 16) : t('em vigor')),
                td(a.por),
              );
              return tr;
            }),
          ),
        );
      }
      ft.append(
        botao(t('Cancelar'), 'ghost', fechar),
        botao(t('Gravar'), null, () =>
          postJson('multicaixa-tpa', {
            accao: 'terminal',
            tpa: tpa.value,
            estab: estab.value,
            empresa: emp.value,
            banco: bco.value,
            conta: conta.value,
            rota: rota.value,
            serie: serie.value,
            situacao: sit.value,
            defeito: defeito.value,
            nota: nota.value,
          }).then((r) => {
            if (!r || !r.ok) {
              e.hidden = false;
              e.textContent = t((r && r.erro) || 'Não foi possível gravar.');
              return;
            }
            D.terminais = r.terminais;
            fechar();
            toast(t('TPA gravado.'), 2500);
            draw();
          }),
        ),
      );
    });
  const linhas = lista.map((x) => {
    const tr = el('tr');
    const a = el('td');
    if (D.gere) {
      const b = botao(t('Alterar'), 'ghost sm', () => editar(x));
      b.setAttribute('aria-label', t('Alterar o TPA {tpa}', { tpa: x.tpa }));
      a.append(b);
    }
    // promotor associado: escolhe-se na lista, logo na tabela (fica a cadeia de custódia)
    const tdP = el('td');
    if (D.gere) {
      const ps = el('select');
      ps.setAttribute('aria-label', t('Promotor do TPA {tpa}', { tpa: x.tpa }));
      [['', t('Sem promotor')], ...Object.keys(D.promotores || {}).map((r) => [r, prom(r)])].forEach(([k, txt]) => {
        const o = el('option', null, txt);
        o.value = k;
        if (String(k) === String(x.rota || '')) o.selected = true;
        ps.append(o);
      });
      ps.addEventListener('change', () =>
        postJson('multicaixa-tpa', { accao: 'associar', tpa: x.tpa, rota: ps.value }).then((r) => {
          if (!r || !r.ok) {
            toast(t((r && r.erro) || 'Não foi possível gravar.'), 4000);
            ps.value = x.rota || '';
            return;
          }
          D.terminais = r.terminais;
          const outros = (r.outros_tpa || []).join(', ');
          toast(
            outros
              ? t('Este promotor já tem outro TPA em funcionamento: {x}. Se este o substitui, mude a situação do outro.', { x: outros })
              : t('Promotor associado ao TPA {tpa}.', { tpa: x.tpa }),
            outros ? 6000 : 2500,
          );
          draw();
        }),
      );
      tdP.append(ps);
    } else tdP.append(prom(x.rota));
    const sitTd = el('td');
    const sv = situacao(x);
    sitTd.append(pill(t((D.situacoes || {})[sv] || sv), SIT_COR[sv] || 'p-mut'));
    if (x.defeito) sitTd.append(el('div', 'note', x.defeito));
    tr.append(td(x.tpa), td(x.serie), td(empresa(x.empresa)), td(banco(x.banco)), td(x.conta), tdP, sitTd, a);
    return tr;
  });
  const s = seccao(
    t('Terminais (TPA)'),
    t(
      'Inventário dos TPA: banco, conta, empresa, situação e o promotor que o tem. É o registo que dá o banco e a conta a cada talão de fecho da folha de caixa e o banco do TPA de cada rota. Ao mudar o promotor, fica registado quem o teve e quando.',
    ),
    lista.length
      ? tabela([[t('Ident. TPA')], [t('Número de série')], [t('Empresa')], [t('Banco')], [t('Conta')], [t('Promotor (rota)')], [t('Situação')], ['']], linhas)
      : el('p', 'note', t('Ainda sem TPA registados. Enquanto não houver, a folha de caixa mantém os talões como antes (banco e número do talão).')),
  );
  if (D.gere) s.append(botao(t('Registar TPA'), 'ghost sm', () => editar(null)));
  pg.append(s);
}

// Formas de pagamento: tabela das formas válidas no RTM (lista branca) e reconciliação por dia e promotor — vendas do
// Pinguim BO por forma × dinheiro entregue na caixa e talões do TPA (o mapa «Rtm sales reconciliation», agora da base
// de dados). Servidor: nucleo/servicos/formas-pagamento.php.
let F = null;
let chaveF = '';
let aLerF = false;
function lerFormas(forcar) {
  const k = de + '|' + ate;
  if (aLerF || (!forcar && F && chaveF === k)) return;
  aLerF = true;
  apiJson('multicaixa-tpa?formas=1&de=' + de + '&ate=' + ate).then((d) => {
    aLerF = false;
    chaveF = k;
    F = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os dados.') };
    if (atual === 'multicaixa-tpa') draw();
  });
}
const FORMA = { numerario: 'Numerário', multicaixa: 'Multicaixa' };
const TIPO_FP = {
  forma_invalida: 'Forma de pagamento não válida',
  banco_diferente: 'Multicaixa de outro banco',
  provavel_troca: 'Provável numerário registado como Multicaixa',
  tpa_outra_empresa: 'TPA de outra empresa',
};
const ESTADO_CORR = {
  pendente: ['Por aprovar', 'p-warn'],
  aprovada: ['Aprovada', 'p-ok'],
  recusada: ['Recusada', 'p-mut'],
  anulada: ['Anulada', 'p-mut'],
};
const pillCorr = (e) => pill(t((ESTADO_CORR[e] || [e])[0]), (ESTADO_CORR[e] || [0, 'p-mut'])[1]);
const formasTxt = (x) =>
  [
    x.numerario ? t('Numerário') + ' ' + kz(x.numerario) : '',
    x.multicaixa ? t('Multicaixa') + ' ' + kz(x.multicaixa) : '',
    x.invalida ? t('sem forma válida') + ' ' + kz(x.invalida) : '',
  ]
    .filter(Boolean)
    .join(' · ') || '—';

// Correcção da forma de pagamento de um dia e promotor: documento a documento, com justificação e evidência. O
// documento do Pinguim BO não muda; a correcção só conta depois de aprovada por outra pessoa.
function abrirCorrecao(linha) {
  janela(
    t('Corrigir as formas de pagamento · {p} · {d}', { p: prom(linha.rota), d: dl(linha.dia) }),
    t('O documento do Pinguim BO não muda. A correcção fica registada com a justificação e só conta depois de aprovada por outra pessoa.'),
    (b, ft, fechar, dlg) => {
      dlg.classList.add('dlg-fp');
      const corpo = el('div', 'fp-corr');
      corpo.append(el('p', 'note', t('A carregar…')));
      b.append(corpo);
      const just = el('textarea');
      just.rows = 2;
      just.maxLength = 600;
      just.placeholder = t('Ex.: o cliente pagou em dinheiro; o promotor escolheu Multicaixa por engano. Confirmado com a folha de caixa e o talão do TPA.');
      just.setAttribute('aria-label', t('Justificação (obrigatória)'));
      const evid = entrada('text', '', { maxLength: 200, placeholder: t('Ex.: talão de fecho do TPA 00444134, período 103') });
      evid.setAttribute('aria-label', t('Evidência'));
      const lj = el('label', 'campo');
      lj.append(el('span', null, t('Justificação (obrigatória)')), just);
      const le = el('label', 'campo');
      le.append(el('span', null, t('Evidência')), evid);
      const e2 = msgErro();
      const campos = el('div', 'fp-campos');
      campos.append(lj, le);
      b.append(campos, e2);
      const valores = [];
      apiJson('multicaixa-tpa?formas_docs=1&dia=' + linha.dia + '&rota=' + encodeURIComponent(linha.rota)).then((d) => {
        corpo.replaceChildren();
        if (!d || !d.ok) {
          corpo.append(el('p', 'note', t((d && d.erro) || 'Não foi possível ler os documentos.')));
          return;
        }
        const mcs = Object.entries(F.formas.codigos).filter(([, x]) => x.forma === 'multicaixa');
        const comCorr = d.documentos.some((doc) => (doc.correcoes || []).some((c) => c.estado === 'pendente' || c.estado === 'aprovada'));
        const tab = el('table', 'fp-tab');
        const cab = el('tr');
        [
          [t('Documento'), ''],
          [t('Cobrado'), 'n'],
          [t('Numerário'), 'n'],
          [t('Multicaixa'), 'n'],
          ...(mcs.length > 1 ? [[t('Banco'), '']] : []),
          ...(comCorr ? [[t('Estado'), '']] : []),
        ].forEach(([txt, c]) => cab.append(el('th', c || null, txt)));
        tab.append(cab);
        d.documentos.forEach((doc) => {
          const tr = el('tr');
          const emVigor = (doc.correcoes || []).find((c) => c.estado === 'pendente' || c.estado === 'aprovada');
          const alvo = doc.pinguim.total_pago > 0 ? doc.pinguim.total_pago : doc.total;
          const base = emVigor || { numerario: doc.pinguim.numerario, multicaixa: doc.pinguim.multicaixa + (doc.pinguim.invalida || 0) };
          const bloqueado = !doc.corrigivel || !!emVigor;
          const num = (v, rot) => {
            const i = entrada('number', v || '', { min: 0, step: '0.01', inputMode: 'decimal', placeholder: '0' });
            i.setAttribute('aria-label', rot);
            i.disabled = bloqueado;
            return i;
          };
          const nu = num(base.numerario, t('Numerário do documento {d}', { d: doc.documento }));
          const mc = num(base.multicaixa, t('Multicaixa do documento {d}', { d: doc.documento }));
          // ao mudar um valor, o outro completa o total cobrado
          nu.addEventListener('input', () => (mc.value = Math.max(0, Math.round((alvo - (+nu.value || 0)) * 100) / 100)));
          mc.addEventListener('input', () => (nu.value = Math.max(0, Math.round((alvo - (+mc.value || 0)) * 100) / 100)));
          const bs = el('select');
          bs.setAttribute('aria-label', t('Banco do Multicaixa do documento {d}', { d: doc.documento }));
          const codAtual = (emVigor && emVigor.codigo_multicaixa) || doc.pinguim.codigo_multicaixa || (mcs[0] || [''])[0];
          mcs.forEach(([cod, x]) => {
            const o = el('option', null, x.banco ? banco(x.banco) : cod);
            o.value = cod;
            if (cod === codAtual) o.selected = true;
            bs.append(o);
          });
          bs.disabled = bloqueado;
          if (!bloqueado) valores.push({ documento: doc.documento, nu, mc, bs });
          // documento, hora e o que está no Pinguim BO na mesma célula (sem colunas a mais)
          const tdD = el('td', 'fp-doc');
          const formaP = [
            doc.pinguim.numerario ? t('Numerário') : '',
            doc.pinguim.multicaixa ? t('Multicaixa') : '',
            doc.pinguim.invalida ? t('sem forma válida') : '',
          ]
            .filter(Boolean)
            .join(' + ');
          tdD.append(el('b', null, doc.documento), el('span', 'note', [doc.hora, formaP].filter(Boolean).join(' · ')));
          tdD.title = t('No Pinguim BO') + ': ' + formasTxt(doc.pinguim);
          // data-l: o nome da coluna, para o ecrã estreito (telemóvel), onde cada documento é um cartão
          const cel = (x, c, rot) => {
            const c2 = el('td', c || null);
            c2.dataset.l = rot;
            c2.append(x);
            return c2;
          };
          tr.append(tdD, cel(kz(alvo), 'n', t('Cobrado')), cel(nu, 'n', t('Numerário')), cel(mc, 'n', t('Multicaixa')));
          if (mcs.length > 1) tr.append(cel(bs, '', t('Banco')));
          if (comCorr) {
            const es = el('td');
            if (emVigor) es.append(pillCorr(emVigor.estado));
            else if (!doc.corrigivel) es.append(el('span', 'note', doc.nc ? t('nota de crédito') : t('não se corrige')));
            tr.append(es);
          }
          tab.append(tr);
        });
        // atalhos: tudo em numerário ou tudo em Multicaixa (os casos mais comuns)
        const tudo = (em) => () =>
          valores.forEach((x) => {
            const tot = (+x.nu.value || 0) + (+x.mc.value || 0);
            x.nu.value = em === 'nu' ? tot : 0;
            x.mc.value = em === 'mc' ? tot : 0;
          });
        const at = el('div', 'fp-atalhos');
        if (valores.length) at.append(botao(t('Tudo em Numerário'), 'ghost sm', tudo('nu')), botao(t('Tudo em Multicaixa'), 'ghost sm', tudo('mc')));
        corpo.append(tab, at);
      });
      ft.append(
        botao(t('Cancelar'), 'ghost', fechar),
        botao(t('Pedir a correcção'), null, () => {
          const docs = valores.map((x) => ({
            documento: x.documento,
            numerario: +x.nu.value || 0,
            multicaixa: +x.mc.value || 0,
            codigo_multicaixa: x.bs.value,
          }));
          postJson('multicaixa-tpa', { accao: 'corrigir', dia: linha.dia, rota: linha.rota, docs, justificacao: just.value, evidencia: evid.value }).then(
            (x) => {
              if (!x || !x.ok) {
                e2.hidden = false;
                e2.textContent = t((x && x.erro) || 'Não foi possível gravar.');
                return;
              }
              fechar();
              toast(t('Correcção pedida: {n} documento(s). Fica por aprovar.', { n: x.correcoes.length }), 4000);
              F = null;
              draw();
            },
          );
        }),
      );
    },
  );
}

function decidirCorrecao(c, accao) {
  const aprovar = accao === 'aprovar_correcao';
  const titulo = { aprovar_correcao: 'Aprovar a correcção {id}?', recusar_correcao: 'Recusar a correcção {id}?', anular_correcao: 'Anular a correcção {id}?' }[
    accao
  ];
  janela(
    t(titulo, { id: c.id }),
    t('{doc} · {d} · {p}: Pinguim BO {a} → corrigido {b}.', {
      doc: c.documento,
      d: dl(c.dia),
      p: prom(c.rota),
      a: formasTxt(c.pinguim),
      b: formasTxt(c),
    }),
    (b, ft, fechar) => {
      b.append(el('p', 'note', t('Justificação: {j}', { j: c.justificacao })));
      if (c.evidencia) b.append(el('p', 'note', t('Evidência: {e}', { e: c.evidencia })));
      const m = el('textarea');
      m.rows = 2;
      m.maxLength = 300;
      m.setAttribute('aria-label', aprovar ? t('Nota (opcional)') : t('Motivo (obrigatório)'));
      m.placeholder = aprovar ? t('Nota (opcional)') : t('Motivo (obrigatório)');
      const e2 = msgErro();
      b.append(m, e2);
      ft.append(
        botao(t('Cancelar'), 'ghost', fechar),
        botao(t({ aprovar_correcao: 'Aprovar', recusar_correcao: 'Recusar', anular_correcao: 'Anular' }[accao]), aprovar ? null : 'perigo', () =>
          postJson('multicaixa-tpa', { accao, id: c.id, motivo: m.value }).then((x) => {
            if (!x || !x.ok) {
              e2.hidden = false;
              e2.textContent = t((x && x.erro) || 'Não foi possível gravar.');
              return;
            }
            fechar();
            F = null;
            draw();
          }),
        ),
      );
    },
  );
}

/** Aprovar de uma vez as correcções por aprovar que esta pessoa pode decidir (não as que pediu). */
function aprovarVarias(lista) {
  const soma = (k) => lista.reduce((a, c) => a + Number(c[k] || 0), 0);
  janela(
    t('Aprovar {n} correcções?', { n: n0(lista.length) }),
    t('Fica registado, uma a uma, quem aprovou e quando. Reveja a lista antes de aprovar.'),
    (b, ft, fechar) => {
      const ul = el('ul', 'fp-lista-aprovar');
      lista.forEach((c) =>
        ul.append(el('li', null, c.id + ' · ' + dl(c.dia) + ' · ' + prom(c.rota) + ' · ' + c.documento + ': ' + formasTxt(c.pinguim) + ' → ' + formasTxt(c))),
      );
      b.append(el('p', 'note', t('Total corrigido: numerário {a} · Multicaixa {b}', { a: kz(soma('numerario')), b: kz(soma('multicaixa')) })), ul);
      const m = el('textarea');
      m.rows = 2;
      m.maxLength = 300;
      m.setAttribute('aria-label', t('Nota (opcional)'));
      m.placeholder = t('Nota (opcional)');
      const e2 = msgErro();
      b.append(m, e2);
      ft.append(
        botao(t('Cancelar'), 'ghost', fechar),
        botao(t('Aprovar {n}', { n: n0(lista.length) }), null, () =>
          postJson('multicaixa-tpa', { accao: 'aprovar_correcoes', ids: lista.map((c) => c.id), motivo: m.value }).then((x) => {
            if (!x || !x.ok) {
              e2.hidden = false;
              e2.textContent = t((x && x.erro) || 'Não foi possível gravar.');
              return;
            }
            fechar();
            toast(
              x.falhas.length
                ? t('{n} aprovadas; {f} não foram aprovadas: {e}', { n: n0(x.aprovadas.length), f: n0(x.falhas.length), e: t(x.falhas[0].erro) })
                : t('{n} correcções aprovadas.', { n: n0(x.aprovadas.length) }),
              6000,
            );
            F = null;
            draw();
          }),
        ),
      );
    },
  );
}

function correcoes(pg) {
  const lista = F.correcoes || [];
  const pend = lista.filter((c) => c.estado === 'pendente').length;
  const minhas = F.aprova_correcao ? lista.filter((c) => c.estado === 'pendente' && c.pedido_por !== F.eu) : [];
  if (minhas.length > 1) {
    const barra = el('div', 'glass fp-barra');
    barra.append(
      el('span', null, t('{n} correcções esperam a sua decisão.', { n: n0(minhas.length) })),
      botao(t('Rever e aprovar todas'), 'sm', () => aprovarVarias(minhas)),
    );
    pg.append(barra);
  }
  pg.append(
    tabelaB({
      id: 'fp-corr',
      titulo: t('Correcções da forma de pagamento ({n} por aprovar)', { n: n0(pend) }),
      nota: t(
        'O documento do Pinguim BO não muda. Pede a Tesouraria, com justificação; aprova ou recusa outra pessoa que aprove a caixa (1.º nível ou aprovação final). Só as aprovadas contam; nada se apaga.',
      ),
      rows: lista.map((c, i) => ({
        ...c,
        ordem: (c.estado === 'pendente' ? 0 : 1) * 10000 + i,
        quem: prom(c.rota),
        de_txt: formasTxt(c.pinguim),
        para_txt: formasTxt(c),
        estado_t: t((ESTADO_CORR[c.estado] || [c.estado])[0]),
      })),
      sort: 'ordem',
      dir: 1,
      cols: [
        { key: 'id', label: t('Correcção'), p: 0 },
        { key: 'dia', label: t('Dia'), render: (v) => dl(v), p: 1 },
        { key: 'quem', label: t('Promotor'), p: 1 },
        { key: 'documento', label: t('Documento'), p: 0 },
        { key: 'de_txt', label: t('No Pinguim BO'), wrap: true, p: 2 },
        { key: 'para_txt', label: t('Corrigido'), wrap: true, p: 0 },
        { key: 'justificacao', label: t('Justificação'), wrap: true, p: 2 },
        { key: 'pedido_por', label: t('Pedido por'), p: 3 },
        {
          key: 'estado_t',
          label: t('Estado'),
          p: 0,
          render: (v, c) => {
            const w = el('span');
            w.append(pillCorr(c.estado));
            if (c.decidido_por) w.append(el('span', 'note', ' ' + c.decidido_por));
            if (F.aprova_correcao && c.estado === 'pendente' && c.pedido_por !== F.eu)
              w.append(
                botao(t('Aprovar'), 'sm', () => decidirCorrecao(c, 'aprovar_correcao')),
                botao(t('Recusar'), 'ghost sm', () => decidirCorrecao(c, 'recusar_correcao')),
              );
            if (F.aprova_correcao && c.estado === 'aprovada') w.append(botao(t('Anular'), 'ghost sm', () => decidirCorrecao(c, 'anular_correcao')));
            return w;
          },
        },
      ],
    }),
  );
}
function formas(pg) {
  if (!F || chaveF !== de + '|' + ate) {
    pg.append(el('div', 'glass empty', t('A carregar…')));
    lerFormas();
    return;
  }
  if (F.falhou) {
    pg.append(el('div', 'glass empty', t(F.falhou)));
    return;
  }
  const r = F.reconciliacao;
  const tt = r.totais;
  if (!r.pinguim_ligado) pg.append(el('div', 'glass note', t('Sem ligação ao Pinguim BO: só aparecem as folhas de caixa.')));
  const nExc = (tipo) => r.excepcoes.filter((x) => x.tipo === tipo).length;
  pg.append(
    tiles([
      { l: t('Numerário (Pinguim BO)'), v: kz(tt.numerario), s: t('entregue na caixa: {v}', { v: kz(tt.entregue) }) },
      { l: t('Multicaixa (Pinguim BO)'), v: kz(tt.multicaixa), s: t('talões do TPA: {v}', { v: kz(tt.talaos) }) },
      {
        l: t('Forma de pagamento não válida'),
        v: kz(tt.invalida),
        s: t('{n} pagamento(s)', { n: nExc('forma_invalida') }),
        cls: tt.invalida ? 'tile-alerta' : '',
      },
      {
        l: t('Provável numerário registado como Multicaixa'),
        v: t('{n} dia(s)', { n: nExc('provavel_troca') }),
        s: t('Multicaixa de outro banco: {n}', { n: nExc('banco_diferente') }),
        cls: nExc('provavel_troca') || nExc('banco_diferente') ? 'tile-alerta' : '',
      },
    ]),
  );
  if (r.excepcoes.length)
    pg.append(
      tabelaB({
        id: 'fp-exc',
        titulo: t('Excepções das formas de pagamento ({n})', { n: n0(r.excepcoes.length) }),
        nota: t('O documento fiscal não se altera: a forma certa escolhe-se no telemóvel, na venda; a diferença explica-se na folha de caixa.'),
        rows: r.excepcoes.map((x, i) => ({ ...x, ordem: i, tipo_t: t(TIPO_FP[x.tipo] || x.tipo), quem: prom(x.rota) })),
        sort: 'ordem',
        dir: 1,
        cols: [
          { key: 'dia', label: t('Dia'), render: (v) => dl(v), p: 0 },
          { key: 'tipo_t', label: t('Excepção'), p: 0 },
          { key: 'quem', label: t('Promotor'), p: 1 },
          { key: 'documento', label: t('Documento'), p: 2 },
          { key: 'detalhe', label: t('Detalhe'), wrap: true, p: 3 },
          { key: 'valor', label: t('Valor'), type: 'kz', p: 0 },
          // corrigir logo a partir da excepção (os documentos desse dia e promotor)
          ...(F.pede_correcao
            ? [
                {
                  key: 'corrigir',
                  label: t('Correcção'),
                  p: 0,
                  render: (v, x) => (x.tipo === 'tpa_outra_empresa' ? '—' : botao(t('Corrigir'), 'ghost sm', () => abrirCorrecao(x))),
                },
              ]
            : []),
        ],
      }),
    );
  const dif = (v) => (v == null ? '—' : el('span', Math.abs(v) >= 0.01 ? 'rv-dif' : null, kz(v)));
  pg.append(
    tabelaB({
      id: 'fp-rec',
      titulo: t('Reconciliação das vendas RTM por forma de pagamento'),
      nota: t(
        'Por dia e promotor: Numerário do Pinguim BO × dinheiro entregue na caixa; Multicaixa do Pinguim BO × talões do TPA. Banco do TPA da rota na tabela das formas de pagamento.',
      ),
      total: true,
      rows: r.linhas.map((x) => ({ ...x, quem: prom(x.rota), banco: banco(x.banco_tpa) })),
      sort: 'dia',
      dir: 1,
      cols: [
        { key: 'dia', label: t('Dia'), render: (v) => dl(v), p: 0 },
        { key: 'quem', label: t('Promotor'), p: 0 },
        { key: 'banco', label: t('Banco do TPA'), p: 3 },
        { key: 'documentos', label: t('Docs.'), type: 'n', sum: true, p: 4 },
        { key: 'numerario', label: t('Numerário'), type: 'kz', sum: true, p: 1 },
        { key: 'entregue', label: t('Entregue na caixa'), type: 'kz', sum: true, p: 2 },
        { key: 'dif_numerario', label: t('Dif. numerário'), type: 'kz', sum: true, p: 1, render: dif },
        { key: 'multicaixa', label: t('Multicaixa'), type: 'kz', sum: true, p: 1 },
        { key: 'talaos', label: t('Talões do TPA'), type: 'kz', sum: true, p: 2 },
        { key: 'dif_multicaixa', label: t('Dif. Multicaixa'), type: 'kz', sum: true, p: 1, render: dif },
        { key: 'invalida', label: t('Forma não válida'), type: 'kz', sum: true, p: 2 },
        { key: 'por_receber', label: t('Por receber'), type: 'kz', sum: true, p: 3 },
        { key: 'total', label: t('Total'), type: 'kz', sum: true, p: 0 },
        { key: 'corrigidos', label: t('Corrigidos'), type: 'n', sum: true, p: 3 },
        ...(F.pede_correcao
          ? [{ key: 'corrigir', label: t('Correcção'), p: 0, render: (v, x) => botao(t('Corrigir'), 'ghost sm', () => abrirCorrecao(x)) }]
          : []),
      ],
    }),
  );
  correcoes(pg);
  configFormas(pg);
}

// tabela das formas de pagamento válidas e banco do TPA de cada rota (quem gere o Multicaixa por TPA altera)
function configFormas(pg) {
  const cfg = F.formas;
  const codigos = Object.entries(cfg.codigos).map(([codigo, x]) => ({ codigo, forma: x.forma, banco: x.banco || '' }));
  const rotas = { ...cfg.rotas };
  const efectivos = cfg.bancos_rotas || {};
  Object.keys(D && D.promotores ? D.promotores : F.promotores || {}).forEach((r) => (rotas[r] = rotas[r] || ''));
  const s = seccao(
    t('Formas de pagamento válidas no RTM'),
    t(
      'Lista branca: só os códigos do Pinguim BO desta tabela contam como Numerário ou Multicaixa; qualquer outro código, ou um documento sem forma de pagamento, é uma excepção. O banco do TPA de cada rota assinala o Multicaixa de outro banco.',
    ),
  );
  if (cfg.alterado_por)
    s.append(el('p', 'note', t('Alterado por {p} em {d}', { p: cfg.alterado_por, d: dl(cfg.alterado_em.slice(0, 10)) + ' ' + cfg.alterado_em.slice(11, 16) })));
  const sel = (opcoes, val, rot) => {
    const x = el('select');
    x.setAttribute('aria-label', rot);
    opcoes.forEach(([k, txt]) => {
      const o = el('option', null, txt);
      o.value = k;
      if (k === val) o.selected = true;
      x.append(o);
    });
    x.disabled = !D.gere;
    return x;
  };
  const bancos = [['', '—'], ...(F.bancos || []).map((b) => [b, banco(b)])];
  const linhasC = codigos.map((c) => {
    const tr = el('tr');
    const fs = sel(
      Object.keys(FORMA).map((k) => [k, t(FORMA[k])]),
      c.forma,
      t('Forma do código {c}', { c: c.codigo }),
    );
    const bs = sel(bancos, c.banco, t('Banco do código {c}', { c: c.codigo }));
    fs.addEventListener('change', () => (c.forma = fs.value));
    bs.addEventListener('change', () => (c.banco = bs.value));
    const tdF = el('td');
    tdF.append(fs);
    const tdB = el('td');
    tdB.append(bs);
    tr.append(el('td', null, c.codigo), tdF, tdB);
    return tr;
  });
  s.append(tabela([[t('Código no Pinguim BO')], [t('Forma')], [t('Banco')]], linhasC));
  const novo = entrada('text', '', { maxLength: 10, placeholder: t('ex.: MCA') });
  novo.setAttribute('aria-label', t('Novo código'));
  const linhasR = Object.keys(rotas)
    .sort()
    .map((rt) => {
      const tr = el('tr');
      const bs = sel(bancos, rotas[rt], t('Banco do TPA da rota {r}', { r: rt }));
      bs.addEventListener('change', () => (rotas[rt] = bs.value));
      const tdB = el('td');
      tdB.append(bs);
      // o registo dos terminais manda: se a rota tem um TPA registado de outro banco, mostra-se o que está em vigor
      if (efectivos[rt] && efectivos[rt] !== rotas[rt])
        tdB.append(el('span', 'note', ' ' + t('em vigor pelo registo dos TPA: {b}', { b: banco(efectivos[rt]) })));
      tr.append(el('td', null, prom(rt)), tdB);
      return tr;
    });
  s.append(el('h3', null, t('Banco do TPA de cada rota')), tabela([[t('Rota')], [t('Banco do TPA')]], linhasR));
  if (D.gere) {
    const e = msgErro();
    const lN = el('label', 'campo');
    lN.append(el('span', null, t('Acrescentar código do Pinguim BO')), novo);
    s.append(
      lN,
      e,
      botao(t('Gravar as formas de pagamento'), null, () => {
        const cod = {};
        codigos.forEach((c) => (cod[c.codigo] = { forma: c.forma, banco: c.banco }));
        const n = novo.value.trim().toUpperCase();
        if (n) cod[n] = { forma: 'multicaixa', banco: '' };
        postJson('multicaixa-tpa', { accao: 'formas', codigos: cod, rotas }).then((x) => {
          if (!x || !x.ok) {
            e.hidden = false;
            e.textContent = t((x && x.erro) || 'Não foi possível gravar.');
            return;
          }
          e.hidden = true;
          toast(t('Formas de pagamento gravadas.'), 2500);
          F = null;
          draw();
        });
      }),
    );
  }
  pg.append(s);
}

function correio(pg) {
  const s = seccao(
    t('Resumo de fim de dia para o Financeiro'),
    t(
      'Quando a folha de caixa recebe a aprovação final, segue por correio o resumo do dia: Multicaixa por banco, conta, TPA e período, numerário, depósitos e o que está a rever, com a ligação a este relatório.',
    ),
  );
  if (!D.gere) {
    s.append(el('p', 'note', t('Só quem gere o Multicaixa por TPA escolhe os destinatários.')));
    pg.append(s);
    return;
  }
  const txt = el('textarea');
  txt.rows = 4;
  txt.value = (D.destinatarios || []).join('\n');
  txt.setAttribute('aria-label', t('Destinatários (um endereço por linha)'));
  txt.placeholder = 'financeiro@naval.co.ao';
  const e = msgErro();
  const l = el('label', 'campo');
  l.append(el('span', null, t('Destinatários (um endereço por linha)')), txt);
  s.append(
    l,
    e,
    botao(t('Gravar destinatários'), null, () =>
      postJson('multicaixa-tpa', { accao: 'destinatarios', destinatarios: txt.value.split(/[\s,;]+/).filter(Boolean) }).then((r) => {
        if (!r || !r.ok) {
          e.hidden = false;
          e.textContent = t((r && r.erro) || 'Não foi possível gravar.');
          return;
        }
        D.destinatarios = r.destinatarios;
        e.hidden = true;
        toast(t('Destinatários gravados.'), 2500);
      }),
    ),
  );
  pg.append(s);
}

R['multicaixa-tpa'] = (pg) => {
  periodoInicial();
  // período (até 62 dias) e separadores
  const barra = el('div', 'glass mc-barra');
  const fd = entrada('date', de, { max: hoje() });
  fd.setAttribute('aria-label', t('De'));
  const fa = entrada('date', ate, { max: hoje() });
  fa.setAttribute('aria-label', t('Até'));
  const mudar = () => {
    if (!dataOk(fd.value) || !dataOk(fa.value)) return;
    de = fd.value <= fa.value ? fd.value : fa.value;
    ate = fd.value <= fa.value ? fa.value : fd.value;
    D = null;
    draw();
  };
  fd.addEventListener('change', mudar);
  fa.addEventListener('change', mudar);
  const l1 = el('label', 'campo');
  l1.append(el('span', null, t('De')), fd);
  const l2 = el('label', 'campo');
  l2.append(el('span', null, t('Até')), fa);
  const seps = el('div', 'seg');
  seps.setAttribute('role', 'group');
  seps.setAttribute('aria-label', t('Vista'));
  [
    ['relatorio', t('Relatório')],
    ['formas', t('Formas de pagamento')],
    ['terminais', t('Terminais (TPA)')],
    ['correio', t('Resumo por correio')],
  ].forEach(([id, nome]) => {
    const b = botao(nome, 'sm' + (vista === id ? '' : ' ghost'), () => {
      vista = id;
      draw();
    });
    b.setAttribute('aria-pressed', String(vista === id));
    seps.append(b);
  });
  barra.append(l1, l2, seps);
  pg.append(barra);
  if (!D || chave !== de + '|' + ate) {
    pg.append(el('div', 'glass empty', t('A carregar…')));
    ler();
    return;
  }
  if (D.falhou) {
    pg.append(el('div', 'glass empty', t(D.falhou)));
    return;
  }
  if (vista === 'formas') formas(pg);
  else if (vista === 'terminais') terminais(pg);
  else if (vista === 'correio') correio(pg);
  else relatorio(pg);
};
// período no endereço (#multicaixa-tpa?de=…&ate=…): o correio do Financeiro abre o dia certo
R['multicaixa-tpa'].estadoURL = () => (de ? 'de=' + de + '&ate=' + ate : '');
