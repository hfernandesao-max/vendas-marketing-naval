// Finanças › Multicaixa por TPA: o fecho de cada TPA (talão «Desactivar (fecho)») comparado com o Multicaixa do
// Pinguim BO, máquina a máquina e dia a dia; os totais por empresa, banco e conta (o que aparece no extracto, para
// limpar a conta de cartões no SAP); «Conferido no extracto» com a referência do movimento; o registo dos terminais e
// quem recebe o resumo de fim de dia. Servidor: nucleo/api/v1/multicaixa-tpa.php e nucleo/servicos/tpa.php.
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { pill, tiles } from '../componentes/blocos.js';
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

function terminais(pg) {
  const lista = D.terminais || [];
  const editar = (x) =>
    janela(x ? t('TPA {tpa}', { tpa: x.tpa }) : t('Registar TPA'), t('Os dados estão no talão do TPA: «Ident. TPA» e «Id. Estab.».'), (b, ft, fechar) => {
      const v = x || { tpa: '', estab: '', empresa: 'NAVAL', banco: '', conta: '', rota: '', activo: true, nota: '' };
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
        t('Sem rota atribuída'),
      );
      const act = el('input');
      act.type = 'checkbox';
      act.checked = v.activo !== false;
      const nota = entrada('text', v.nota || '', { maxLength: 120 });
      const lin = (rot, c) => {
        const l = el('label', 'campo');
        l.append(el('span', null, rot), c);
        return l;
      };
      const lAct = el('label', 'mc-sim');
      lAct.append(act, el('span', null, t('Activo')));
      const e = msgErro();
      b.append(
        lin(t('Ident. TPA (8 dígitos)'), tpa),
        lin(t('Id. Estab.'), estab),
        lin(t('Empresa'), emp),
        lin(t('Banco'), bco),
        lin(t('Conta'), conta),
        lin(t('Rota atribuída'), rota),
        lin(t('Nota informativa (facultativa)'), nota),
        lAct,
        e,
      );
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
            activo: act.checked,
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
    tr.append(td(x.tpa), td(x.estab), td(empresa(x.empresa)), td(banco(x.banco)), td(x.conta), td(prom(x.rota)), el('td', null, ''), a);
    tr.children[6].append(x.activo ? pill(t('Activo'), 'p-ok') : pill(t('Desactivado'), 'p-mut'));
    return tr;
  });
  const s = seccao(
    t('Terminais (TPA)'),
    t('Cada TPA com o banco, a conta, a empresa e a rota a que está atribuído. É o registo que dá o banco e a conta a cada talão de fecho da folha de caixa.'),
    lista.length
      ? tabela([[t('Ident. TPA')], [t('Id. Estab.')], [t('Empresa')], [t('Banco')], [t('Conta')], [t('Rota atribuída')], [t('Estado')], ['']], linhas)
      : el('p', 'note', t('Ainda sem TPA registados. Enquanto não houver, a folha de caixa mantém os talões como antes (banco e número do talão).')),
  );
  if (D.gere) s.append(botao(t('Registar TPA'), 'ghost sm', () => editar(null)));
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
  if (vista === 'terminais') terminais(pg);
  else if (vista === 'correio') correio(pg);
  else relatorio(pg);
};
// período no endereço (#multicaixa-tpa?de=…&ate=…): o correio do Financeiro abre o dia certo
R['multicaixa-tpa'].estadoURL = () => (de ? 'de=' + de + '&ate=' + ate : '');
