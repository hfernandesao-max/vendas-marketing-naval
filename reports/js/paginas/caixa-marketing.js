// Cobranças › Folha de caixa (tesouraria do Marketing): dinheiro entregue por promotor, talões do TPA por banco, saídas
// (depósito, fundo de maneio, transferência para a gestão), saldo inicial e final, contagem física, fecho e aprovação.
// Substitui a folha Excel «Cash control». Servidor: nucleo/api/v1/caixa.php e nucleo/servicos/caixa.php.
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { pill, tiles } from '../componentes/blocos.js';
import { botao, entrada, janela, msgErro } from '../componentes/janelas.js';
import { marcarPorGravar } from '../componentes/rascunho.js';
import { dl, ds, kz, n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { atual } from '../perfil.js';
import { hoje } from '../periodo.js';
import { postJson } from '../servidor.js';
import { el, somaDias, toast } from '../util.js';
import { R } from './registo.js';

let D = null; // resposta do servidor
let dia = null;
let ed = null; // cópia em edição
let aLer = false;
const ESTADOS = {
  aberta: ['Aberta', 'p-warn'],
  fechada: ['Validada, por aprovar (1.º nível)', 'p-serio'],
  verificada: ['Aprovada no 1.º nível, falta a aprovação final', 'p-serio'],
  aprovada: ['Aprovada', 'p-ok'],
};

function ler() {
  if (aLer) return;
  aLer = true;
  apiJson('caixa?dia=' + dia).then((d) => {
    aLer = false;
    D = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os dados.') };
    ed = D.caixa ? copia(D.caixa) : null;
    // contagem às cegas: numa folha aberta e ainda vazia, uma linha por promotor que recebeu dinheiro ou Multicaixa no
    // Pinguim BO, sem valor; quem recebe só conta as notas e moedas e indica o talão de fecho do TPA, e o valor esperado
    // só aparece na conferência
    if (ed && D.gere && D.caixa.estado === 'aberta' && D.esperado && !ed.entregas.length && !ed.tpa.length) {
      Object.entries(D.esperado).forEach(([r, x]) => {
        if (x.dinheiro > 0) ed.entregas.push({ rota: r, empresa: '', valor: null, notas: null, nota: '' });
        if (x.multicaixa > 0) ed.tpa.push({ rota: r, banco: '', talao: '', empresa: '', valor: null });
      });
      if (ed.entregas.length || ed.tpa.length) {
        ed.sugerido = true;
        ed.mudou = true;
      }
    }
    if (atual === 'caixa-marketing') draw();
  });
}
const copia = (c) => ({
  entregas: c.entregas.map((x) => ({ ...x, notas: x.notas ? { ...x.notas } : null })),
  tpa: c.tpa.map((x) => ({ ...x })),
  saidas: c.saidas.map((x) => ({ ...x })),
  saldo_inicial: c.saldo_inicial,
  mudou: false,
});
function mudarDia(d) {
  if (!d || d > hoje()) return;
  dia = d;
  D = null;
  marcarPorGravar('caixa', false);
  draw();
}
const opcoes = (s, lista, valor, vazio) => {
  if (vazio) {
    const o = el('option', null, vazio);
    o.value = '';
    s.append(o);
  }
  lista.forEach(([v, txt]) => {
    const o = el('option', null, txt);
    o.value = v;
    if (String(v) === String(valor || '')) o.selected = true;
    s.append(o);
  });
  return s;
};
const somaDe = (l) => l.reduce((a, x) => a + (+x.valor || 0), 0);
const notasTxt = (n) =>
  n
    ? Object.entries(n)
        .filter(([k]) => k !== 'moedas')
        .sort((a, b) => b[0] - a[0])
        .map(([k, q]) => q + ' × ' + n0(+k))
        .concat(n.moedas ? [t('moedas {v}', { v: kz(n.moedas) })] : [])
        .join(' · ')
    : '';

/** Grelha de contagem por notas (e moedas), com o total ao vivo. Devolve {el, ler()}. */
function grelhaNotas(inicial) {
  const w = el('div', 'cx-notas');
  const campos = {};
  const tot = el('b', 'cx-notas-tot');
  const somar = () => {
    let s0 = 0;
    Object.entries(campos).forEach(([k, i]) => (s0 += k === 'moedas' ? +i.value || 0 : (+k || 0) * (parseInt(i.value, 10) || 0)));
    tot.textContent = t('Total: {v}', { v: kz(s0) });
    return s0;
  };
  [...(D.notas || [5000, 2000, 1000, 500, 200]).map(String), 'moedas'].forEach((k) => {
    const l = el('label', 'cx-nota');
    const i = entrada(
      'number',
      inicial && inicial[k] != null ? inicial[k] : '',
      k === 'moedas' ? { min: 0, step: '0.01', inputMode: 'decimal' } : { min: 0, step: 1, inputMode: 'numeric' },
    );
    i.setAttribute('aria-label', k === 'moedas' ? t('Moedas (valor em Kz)') : t('Notas de {v}', { v: n0(+k) + ' Kz' }));
    i.addEventListener('input', somar);
    campos[k] = i;
    l.append(el('span', null, k === 'moedas' ? t('Moedas (Kz)') : n0(+k) + ' Kz ×'), i);
    w.append(l);
  });
  w.append(tot);
  somar();
  return {
    el: w,
    total: somar,
    ler: () => {
      const o = {};
      Object.entries(campos).forEach(([k, i]) => {
        if (i.value !== '' && +i.value > 0) o[k] = k === 'moedas' ? +i.value : parseInt(i.value, 10);
      });
      return Object.keys(o).length ? o : null;
    },
  };
}

/** Janela para contar as notas de uma entrega; ao confirmar, o valor da entrega passa a ser o total contado. */
function contarNotas(l, mudou) {
  janela(t('Notas entregues'), t('Quantas notas de cada valor e o total das moedas. O valor da entrega passa a ser o total contado.'), (b, ft, fechar) => {
    const g = grelhaNotas(l.notas);
    b.append(g.el);
    ft.append(
      botao(t('Cancelar'), 'ghost', fechar),
      botao(t('Confirmar'), null, () => {
        l.notas = g.ler();
        l.valor = l.notas ? Math.round(g.total() * 100) / 100 : null;
        fechar();
        mudou();
        draw();
      }),
    );
  });
}

/** Tabela editável de linhas (entregas, talões, saídas). */
function grelha(titulo, nota, linhas, campos, nova, editavel, mudou) {
  const s = el('section', 'glass sec cx-sec');
  const h = el('div', 'sec-h');
  const hl = el('div');
  hl.append(el('h2', null, titulo), el('p', null, nota));
  h.append(hl);
  s.append(h);
  const tab = el('table', 'cc-tab cx-tab');
  const cab = el('tr');
  // campos obrigatórios marcados com * (e «aria-required» no campo)
  campos.forEach((c) => cab.append(el('th', (c.n ? 'n' : '') + (c.obrigatorio ? ' cx-obr' : '') || null, c.label)));
  if (editavel) cab.append(el('th', null, ''));
  tab.append(cab);
  linhas.forEach((l, i) => {
    const tr = el('tr');
    campos.forEach((c) => {
      const td = el('td', c.n ? 'n' : null);
      if (c.extra) {
        c.extra(l, td, editavel);
        tr.append(td);
        return;
      }
      if (editavel) {
        let inp;
        if (c.lista) inp = opcoes(el('select'), c.lista(), l[c.key], c.vazio);
        else
          inp = entrada(
            c.n ? 'number' : 'text',
            l[c.key] == null ? '' : l[c.key],
            c.n ? { min: 0, step: '0.01', inputMode: 'decimal' } : { maxLength: c.max || 120 },
          );
        inp.setAttribute('aria-label', c.label + ' · ' + (i + 1));
        if (c.obrigatorio) {
          inp.required = true;
          inp.setAttribute('aria-required', 'true');
          inp.classList.toggle('cx-falta', !l[c.key]);
        }
        // valor de uma entrega contada por notas: é o total das notas (muda-se pelas notas)
        if (c.n && l.notas) {
          inp.readOnly = true;
          inp.title = t('Total das notas contadas');
        }
        inp.addEventListener(c.lista ? 'change' : 'input', () => {
          l[c.key] = c.n ? (inp.value === '' ? null : Number(inp.value)) : inp.value;
          if (c.obrigatorio) inp.classList.toggle('cx-falta', !inp.value.trim());
          mudou();
          if (c.refaz) draw();
        });
        td.append(inp);
      } else td.textContent = c.ver ? c.ver(l) : c.n ? kz(l[c.key]) : l[c.key] || '—';
      tr.append(td);
    });
    if (editavel) {
      const td = el('td');
      const b = botao('×', 'ghost sm', () => {
        linhas.splice(i, 1);
        mudou();
        draw();
      });
      b.setAttribute('aria-label', t('Retirar a linha {n}', { n: i + 1 }));
      td.append(b);
      tr.append(td);
    }
    tab.append(tr);
  });
  const tot = el('tr', 'cx-total');
  campos.forEach((c, i) => {
    const td = el('td', c.n ? 'n' : null, i === 0 ? t('Total') : c.n ? kz(somaDe(linhas)) : '');
    // o total acompanha o que se escreve (sem redesenhar a página, para não perder o campo onde se está a escrever)
    if (c.n) td.dataset.somaDe = String(linhas === ed.entregas ? 'entregas' : linhas === ed.tpa ? 'tpa' : 'saidas');
    tot.append(td);
  });
  if (editavel) tot.append(el('td'));
  tab.append(tot);
  const wrap = el('div', 'cc-scroll');
  wrap.append(tab);
  s.append(wrap);
  if (editavel)
    s.append(
      botao(t('Juntar linha'), 'ghost sm', () => {
        linhas.push(nova());
        mudou();
        draw();
      }),
    );
  return s;
}

function enviar(corpo, erro, ok) {
  return postJson('caixa', { dia, versao: D.caixa.versao, ...corpo }).then((x) => {
    if (!x.ok) {
      erro.hidden = false;
      erro.textContent = t(x.erro || 'Não foi possível gravar.');
      return null;
    }
    D.caixa = x.caixa;
    ed = copia(x.caixa);
    marcarPorGravar('caixa', false);
    if (ok) toast(t(ok), 4000);
    draw();
    return x.caixa;
  });
}

R['caixa-marketing'] = (pg) => {
  dia = dia || hoje();
  const barra = el('section', 'glass sec pc-barra');
  const d = el('div', 'pc-dia');
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
  d.append(ant, el('b', 'pc-dia-t', ds(dia)), dc, seg);
  barra.append(d);
  pg.append(barra);
  if (!D) {
    pg.append(el('div', 'glass empty', t('A carregar…')));
    ler();
    return;
  }
  if (D.falhou) {
    pg.append(el('div', 'glass empty', t(D.falhou)));
    return;
  }
  // valor de cada talão do TPA = Multicaixa recebido pelo promotor no Pinguim BO (o servidor confirma ao gravar)
  if (ed && D.esperado && D.caixa.estado === 'aberta')
    ed.tpa.forEach((l) => {
      const v = l.rota && D.esperado[l.rota] ? D.esperado[l.rota].multicaixa : 0;
      l.valor = v > 0 ? v : null;
    });
  const c = D.caixa,
    aberta = c.estado === 'aberta' && D.gere,
    mudou = () => {
      ed.mudou = true;
      marcarPorGravar('caixa', true);
      const n = document.getElementById('cx-estado');
      if (n) n.textContent = t('Alterações por gravar.');
      totais();
    };
  // Totais e saldo teórico ao vivo, enquanto se escreve
  const totais = () => {
    document.querySelectorAll('[data-soma-de]').forEach((td) => (td.textContent = kz(somaDe(ed[td.dataset.somaDe]))));
    const e = somaDe(ed.entregas),
      s0 = somaDe(ed.saidas),
      i0 = ed.saldo_inicial == null ? null : +ed.saldo_inicial;
    const v = [i0, e, s0, i0 == null ? null : i0 + e - s0, somaDe(ed.tpa)];
    document.querySelectorAll('#page .tiles .tile .v').forEach((x, i) => {
      if (i < v.length) x.textContent = kz(v[i]);
    });
    conferir();
  };
  // Conferência por promotor: entregue na caixa × recebido no Pinguim BO, ao vivo
  const confCorpo = el('tbody');
  const conferir = () => {
    if (!D.esperado) return;
    confCorpo.textContent = '';
    const rotas = [...new Set([...Object.keys(D.esperado), ...ed.entregas.map((x) => x.rota), ...ed.tpa.map((x) => x.rota)])].filter(Boolean).sort();
    rotas.forEach((r) => {
      const esp = D.esperado[r] || { dinheiro: 0, multicaixa: 0 };
      const ent = somaDe(ed.entregas.filter((x) => x.rota === r)),
        tpa = somaDe(ed.tpa.filter((x) => x.rota === r));
      const tr = el('tr');
      const celDif = (feito, v) => {
        const td = el('td', 'n' + (feito && Math.abs(v) >= 0.01 ? ' rv-dif' : ''), feito ? kz(v) : '—');
        return td;
      };
      tr.append(
        el('td', null, r + (D.promotores && D.promotores[r] ? ' · ' + D.promotores[r] : '')),
        el('td', 'n', kz(ent)),
        el('td', 'n', kz(esp.dinheiro)),
        celDif(true, ent - esp.dinheiro),
        el('td', 'n', kz(tpa)),
        el('td', 'n', kz(esp.multicaixa)),
        celDif(true, tpa - esp.multicaixa),
      );
      confCorpo.append(tr);
    });
  };
  if (ed.mudou) marcarPorGravar('caixa', true);
  const promotores = () => Object.entries(D.promotores || {}).map(([r, p]) => [r, r + ' · ' + p]);
  const empresas = () => (D.empresas || []).map((e) => [e, e]);
  const bancos = () => (D.bancos || []).map((b) => [b, b]);
  const saidasTipos = () => Object.entries(D.saidas || {}).map(([k, v]) => [k, t(v)]);
  const prom = (l) => (D.promotores && D.promotores[l.rota] ? l.rota + ' · ' + D.promotores[l.rota] : l.rota);
  const teo = () => (ed.saldo_inicial == null ? null : +ed.saldo_inicial + somaDe(ed.entregas) - somaDe(ed.saidas));
  const ent = somaDe(ed.entregas),
    sai = somaDe(ed.saidas),
    si = ed.saldo_inicial == null ? null : +ed.saldo_inicial,
    teorico = si == null ? null : si + ent - sai;
  const [txt, cls] = ESTADOS[c.estado] || [c.estado, 'p-mut'];
  pg.append(
    tiles([
      { l: t('Saldo inicial'), v: kz(si), s: c.saldo_inicial_origem === 'manual' ? t('primeiro dia: escrito à mão') : t('saldo aprovado do dia anterior') },
      { l: t('Dinheiro entregue'), v: kz(ent), s: t('{n} entregas', { n: ed.entregas.length }) },
      { l: t('Saídas'), v: kz(sai), s: t('depósitos, fundos de maneio e transferências') },
      { l: t('Saldo final teórico'), v: kz(teorico), s: c.contagem_fisica != null ? t('contado: {v}', { v: kz(c.contagem_fisica) }) : t('por contar') },
      { l: t('Multicaixa (talões do TPA)'), v: kz(somaDe(ed.tpa)), s: t('não entra no saldo da caixa') },
    ]),
  );
  const cab = el('section', 'glass sec cx-cab');
  cab.append(el('b', null, t('Folha de caixa de {d}', { d: dl(dia) }) + ' '), pill(t(txt), cls));
  if (c.fechada_por) cab.append(el('span', 'note', t('validada por {p}', { p: c.fechada_por })));
  if (c.verificada_por) cab.append(el('span', 'note', t('1.º nível por {p}', { p: c.verificada_por })));
  if (c.aprovada_por) cab.append(el('span', 'note', t('aprovada por {p}', { p: c.aprovada_por })));
  if (c.diferenca != null && Math.abs(c.diferenca) >= 0.01)
    cab.append(pill(t('Diferença na contagem: {v}', { v: kz(c.diferenca) }) + (c.explicacao ? ' · ' + c.explicacao : ''), 'p-bad'));
  pg.append(cab);
  if (aberta && ed.sugerido)
    pg.append(
      el(
        'p',
        'note cx-sugerido',
        t(
          'Uma linha por promotor com recebimentos no Pinguim BO. Conte as notas e moedas de cada um («Contar notas») e indique o talão de fecho de cada TPA; o valor esperado só aparece na conferência.',
        ),
      ),
    );
  if (aberta && c.saldo_inicial_origem === 'manual') {
    const s = el('section', 'glass sec cx-sec');
    const i = entrada('number', ed.saldo_inicial == null ? '' : ed.saldo_inicial, { min: 0, step: '0.01', inputMode: 'decimal' });
    i.setAttribute('aria-label', t('Saldo inicial'));
    i.addEventListener('input', () => {
      ed.saldo_inicial = i.value === '' ? null : Number(i.value);
      mudou();
    });
    s.append(el('b', null, t('Saldo inicial')), i, el('span', 'note', t('Só no primeiro dia; a partir daí é o saldo aprovado do dia anterior.')));
    pg.append(s);
  }
  pg.append(
    grelha(
      t('Dinheiro entregue pelos promotores'),
      t('Uma linha por entrega; o valor é o total das notas e moedas contadas. Compara-se com o dinheiro recebido no Pinguim BO.'),
      ed.entregas,
      [
        { key: 'rota', label: t('Promotor'), lista: promotores, vazio: t('Escolha'), ver: prom },
        { key: 'valor', label: t('Valor'), n: true, extra: (l, td) => (td.textContent = l.valor == null ? '—' : kz(l.valor)) },
        {
          key: 'notas',
          label: t('Notas'),
          extra: (l, td, editavel) => {
            if (l.notas) td.append(el('span', 'note cx-notas-txt', notasTxt(l.notas)));
            if (editavel) td.append(botao(l.notas ? t('Corrigir notas') : t('Contar notas'), 'ghost sm', () => contarNotas(l, mudou)));
            else if (!l.notas) td.textContent = '—';
          },
        },
        { key: 'nota', label: t('Nota') },
      ],
      () => ({ rota: '', empresa: '', valor: null, notas: null, nota: '' }),
      aberta,
      mudou,
    ),
    grelha(
      t('Talões do TPA (Multicaixa)'),
      t('Fecho de cada TPA, por banco, com o número e o valor do talão. Compara-se com o Multicaixa recebido no Pinguim BO.'),
      ed.tpa,
      [
        { key: 'rota', label: t('Promotor'), lista: promotores, vazio: t('Escolha'), ver: prom, refaz: true },
        { key: 'banco', label: t('Banco'), lista: bancos, vazio: t('Escolha') },
        { key: 'talao', label: t('Nº do talão de fecho'), max: 30, obrigatorio: true },
        // valor do Pinguim BO (Multicaixa recebido pelo promotor); só se escreve sem ligação ao Pinguim BO
        D.esperado
          ? {
              key: 'valor',
              label: t('Valor (Pinguim BO)'),
              n: true,
              extra: (l, td) => (td.textContent = l.valor == null ? '—' : kz(l.valor)),
            }
          : { key: 'valor', label: t('Valor'), n: true },
      ],
      () => ({ rota: '', banco: '', talao: '', empresa: '', valor: null }),
      aberta,
      mudou,
    ),
    grelha(
      t('Saídas da caixa'),
      t('Depósitos no banco, fundos de maneio de cada empresa, transferências para a gestão e outras saídas (com descrição).'),
      ed.saidas,
      [
        { key: 'tipo', label: t('Tipo'), lista: saidasTipos, vazio: t('Escolha'), ver: (l) => t((D.saidas || {})[l.tipo] || l.tipo) },
        { key: 'empresa', label: t('Empresa'), lista: empresas, vazio: '—' },
        { key: 'descricao', label: t('Descrição'), max: 160 },
        { key: 'valor', label: t('Valor'), n: true },
      ],
      () => ({ tipo: '', empresa: '', descricao: '', valor: null }),
      aberta,
      mudou,
    ),
  );
  if (D.esperado) {
    const s = el('section', 'glass sec cx-sec');
    const h = el('div', 'sec-h');
    const hl = el('div');
    hl.append(
      el('h2', null, t('Conferência por promotor')),
      el('p', null, t('Entregue na caixa × recebido no Pinguim BO, por promotor; as diferenças a vermelho.')),
    );
    h.append(hl);
    const tab = el('table', 'cc-tab cx-tab');
    const cab2 = el('tr');
    [
      t('Promotor'),
      t('Dinheiro entregue'),
      t('Dinheiro no Pinguim BO'),
      t('Diferença'),
      t('Talões do TPA'),
      t('Multicaixa no Pinguim BO'),
      t('Diferença'),
    ].forEach((x, i) => cab2.append(el('th', i ? 'n' : null, x)));
    const th = el('thead');
    th.append(cab2);
    tab.append(th, confCorpo);
    const w = el('div', 'cc-scroll');
    w.append(tab);
    s.append(h, w);
    pg.append(s);
    conferir();
  } else if (c.estado === 'aberta') pg.append(el('p', 'note', t('Sem ligação ao Pinguim BO: a conferência por promotor aparece quando a ligação voltar.')));
  const erro = msgErro();
  const estado = el('p', 'note');
  estado.id = 'cx-estado';
  estado.setAttribute('aria-live', 'polite');
  if (ed.mudou) estado.textContent = t('Alterações por gravar.');
  const acc = el('div', 'cc-acc');
  if (aberta) {
    // o número do talão de fecho é obrigatório em cada TPA (o servidor também o exige)
    const semTalao = () => {
      if (!ed.tpa.some((l) => !String(l.talao || '').trim())) return false;
      erro.hidden = false;
      erro.textContent = t('Indique o número do talão de fecho de cada TPA.');
      const i = document.querySelector('.cx-falta');
      if (i) i.focus();
      return true;
    };
    const g = botao(t('Gravar'), 'ghost', () => !semTalao() && enviar({ accao: 'gravar', ...ed }, erro, 'Folha de caixa gravada.'));
    const f = botao(
      t('Validar a caixa'),
      null,
      () =>
        !semTalao() &&
        janela(
          t('Validar a caixa de {d}', { d: dl(dia) }),
          t('Conte o dinheiro que está na caixa, nota a nota. Saldo teórico: {v}.', { v: kz(teo()) }),
          (b, ft, fechar) => {
            const teorico = teo();
            const q = entrada('number', '', { min: 0, step: '0.01', inputMode: 'decimal' });
            q.setAttribute('aria-label', t('Dinheiro contado na caixa'));
            // contagem por notas (o total passa para o campo do dinheiro contado)
            const g = grelhaNotas(null);
            g.el.addEventListener('input', () => {
              const tot = g.total();
              q.value = tot ? String(Math.round(tot * 100) / 100) : '';
              q.dispatchEvent(new Event('input'));
            });
            const ex = el('textarea');
            ex.rows = 2;
            ex.maxLength = 300;
            ex.placeholder = t('Explicação da diferença (obrigatória se a contagem não bater)');
            ex.setAttribute('aria-label', t('Explicação da diferença'));
            const dif = el('p', 'note');
            q.addEventListener('input', () => {
              dif.textContent = q.value === '' || teorico == null ? '' : t('Diferença: {v}', { v: kz(Number(q.value) - teorico) });
            });
            const e2 = msgErro();
            b.append(g.el, q, dif, ex, e2);
            ft.append(
              botao(t('Cancelar'), 'ghost', fechar),
              botao(t('Validar a caixa'), null, () => {
                // grava primeiro o que está no ecrã e depois fecha com a contagem
                const fechar2 = () =>
                  postJson('caixa', {
                    accao: 'fechar',
                    dia,
                    versao: D.caixa.versao,
                    contagem: q.value === '' ? null : Number(q.value),
                    notas: g.ler(),
                    explicacao: ex.value,
                  }).then((x) => {
                    if (!x.ok) {
                      e2.hidden = false;
                      e2.textContent = t(x.erro || 'Não foi possível gravar.');
                      return;
                    }
                    fechar();
                    D.caixa = x.caixa;
                    ed = copia(x.caixa);
                    marcarPorGravar('caixa', false);
                    toast(t('Caixa validada. Falta a aprovação do 1.º nível.'), 5000);
                    draw();
                  });
                if (ed.mudou)
                  enviar({ accao: 'gravar', ...ed }, e2).then((x) => {
                    if (x) fechar2();
                  });
                else fechar2();
              }),
            );
          },
        ),
    );
    acc.append(g, f);
  } else if ((c.estado === 'fechada' || c.estado === 'verificada') && (D.gere || (D.aprova || []).length)) {
    const nivel = c.estado === 'fechada' ? 1 : 2;
    acc.append(
      botao(t('Reabrir para corrigir'), 'ghost', () =>
        janela(
          t('Reabrir a caixa de {d}?', { d: dl(dia) }),
          t('A contagem é anulada e a folha volta a ficar aberta. Fica registado, com o motivo.'),
          (b, ft, fechar) => {
            const m = el('textarea');
            m.rows = 2;
            m.maxLength = 300;
            m.setAttribute('aria-label', t('Motivo da reabertura'));
            const e2 = msgErro();
            b.append(m, e2);
            ft.append(
              botao(t('Cancelar'), 'ghost', fechar),
              botao(t('Reabrir'), null, () =>
                enviar({ accao: 'reabrir', motivo: m.value }, e2, 'Caixa reaberta.').then((x) => {
                  if (x) fechar();
                }),
              ),
            );
          },
        ),
      ),
    );
    if ((D.aprova || []).includes(nivel))
      acc.append(
        nivel === 1
          ? botao(t('Aprovar (1.º nível)'), null, () => enviar({ accao: 'aprovar' }, erro, 'Caixa aprovada no 1.º nível.'))
          : botao(t('Aprovação final'), null, () => enviar({ accao: 'aprovar' }, erro, 'Caixa aprovada.')),
      );
    estado.textContent = t(
      'Quem lança valida; aprova o superior de quem lança (1.º nível) e, por fim, o responsável máximo. Três pessoas diferentes (segregação de funções).',
    );
  }
  pg.append(estado, erro, acc);
};
