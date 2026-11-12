// Logística › Conciliação da carrinha: por rota e dia, o que foi carregado, vendido e devolvido (Pinguim BO), a contagem
// do técnico do armazém no regresso (cega: não vê o esperado antes de gravar) e a aprovação das diferenças, com motivo.
// Servidor: nucleo/api/v1/carrinha.php e nucleo/servicos/carrinha.php.
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { pill, secBox, tiles } from '../componentes/blocos.js';
import { botao, entrada, janela, msgErro } from '../componentes/janelas.js';
import { dl, ds, kz, n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { atual } from '../perfil.js';
import { hoje } from '../periodo.js';
import { postJson } from '../servidor.js';
import { el, somaDias, toast } from '../util.js';
import { R } from './registo.js';
import { folhaContagemCega, resultadoContagem } from '../componentes/contagem-pdf.js';
import { apagarRascunho, guardarRascunho, lerRascunho } from '../componentes/rascunho.js';

let CC = null;
let dia = null;
let aberta = null;
let aLer = false;
const ESTADOS = {
  por_contar: ['Por contar', 'p-warn'],
  por_validar: ['Por validar', 'p-warn'],
  por_aprovar: ['Por aprovar (coordenador)', 'p-serio'],
  por_fechar: ['Por aprovar (responsável)', 'p-serio'],
  aprovada: ['Fechada', 'p-ok'],
};
const ALERTAS = {
  negativo: 'Stock negativo na carrinha',
  sem_carga: 'Vendido mais do que estava na carrinha',
  nao_explicado: 'Movimento sem documento',
};

function ler(actualizar) {
  if (aLer) return;
  aLer = true;
  dia = dia || hoje();
  apiJson('conciliacao-carrinha?data=' + dia + (actualizar ? '&actualizar=1' : '')).then((d) => {
    aLer = false;
    CC = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os dados.') };
    if (atual === 'conciliacao-carrinha') draw();
  });
}
function mudarDia(d) {
  if (!d || d > hoje()) return;
  dia = d;
  CC = null;
  aberta = null;
  draw();
}
const q = (v) => (v == null ? '—' : n0(v));
const difTxt = (v) => (v == null ? '—' : (v > 0 ? '+' : '') + n0(v));

function barra() {
  const b = el('section', 'glass sec pc-barra');
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
  if (dia !== hoje()) d.append(botao(t('Hoje'), 'ghost sm', () => mudarDia(hoje())));
  d.append(botao(t('Actualizar'), 'ghost sm', () => ((CC = null), ler(true), draw())));
  b.append(d);
  return b;
}

/** Botão que gera um PDF da contagem (fica inactivo enquanto o gera; avisa se falhar). */
function pdfBotao(txt, gerar) {
  const b = botao(txt, 'ghost sm cc-pdf', () => {
    b.disabled = true;
    Promise.resolve()
      .then(gerar)
      .catch((e) => {
        console.error(e);
        toast(t('Não foi possível gerar o PDF.'), 5000);
      })
      .then(() => (b.disabled = false));
  });
  return b;
}

/** Contagem cega: só o artigo e o campo da quantidade que voltou na carrinha. */
function formContagem(r) {
  const f = el('div', 'cc-contagem');
  const tab = el('table', 'cc-tab');
  const th = el('tr');
  [t('Artigo'), t('Quantidade que voltou')].forEach((x) => th.append(el('th', null, x)));
  tab.append(th);
  const campos = {};
  // Rascunho da contagem: cada número escrito fica guardado neste navegador até gravar; a página pode actualizar
  // (ou voltar a abrir) sem apagar o que já foi contado
  const chave = 'contagem-' + dia + '-' + r.rota;
  const rasc = lerRascunho(chave);
  const guardar = () => {
    const v = {};
    Object.entries(campos).forEach(([c, i]) => (v[c] = i.value));
    guardarRascunho(chave, v);
    estado.textContent = Object.values(v).some((x) => x !== '')
      ? t('Rascunho guardado neste navegador às {h}; só fica registado ao gravar.', { h: new Date().toTimeString().slice(0, 5) })
      : '';
  };
  const estado = el('p', 'note cc-rascunho');
  estado.setAttribute('aria-live', 'polite');
  if (rasc)
    estado.textContent = t('Rascunho reposto: {n} artigo(s) já contados. Confira e grave.', { n: n0(Object.values(rasc.v).filter((x) => x !== '').length) });
  r.linhas.forEach((l) => {
    const tr = el('tr');
    const inicial = rasc && rasc.v[l.codigo] != null ? rasc.v[l.codigo] : l.contado == null ? '' : l.contado;
    const i = entrada('number', inicial, { min: 0, step: 'any', inputMode: 'decimal' });
    i.setAttribute('aria-label', l.artigo);
    i.addEventListener('input', guardar);
    campos[l.codigo] = i;
    const td = el('td');
    td.append(i, el('span', 'cc-un', l.unidade || ''));
    tr.append(el('td', null, l.artigo + ' · ' + l.codigo), td);
    tab.append(tr);
  });
  const erro = msgErro();
  f.append(
    pdfBotao(t('Imprimir folha de contagem'), () => folhaContagemCega(r, dia)),
    el('p', 'note', t('Conte o que voltou na carrinha (artigos por vender e devoluções). Os números do sistema só aparecem depois de gravar a contagem.')),
    tab,
    estado,
    erro,
    botao(t('Gravar contagem'), null, () => {
      const contagem = {};
      for (const [c, i] of Object.entries(campos)) {
        if (i.value === '') {
          erro.hidden = false;
          erro.textContent = t('Preencha todos os artigos (0 se não voltou nada).');
          i.focus();
          return;
        }
        contagem[c] = Number(i.value);
      }
      postJson('conciliacao-carrinha', { accao: 'contar', data: dia, rota: r.rota, contagem }).then((x) => {
        if (!x.ok) {
          erro.hidden = false;
          erro.textContent = t(x.erro || 'Não foi possível gravar.');
          return;
        }
        toast(
          x.resumo.diferencas
            ? t('Contagem gravada com {n} diferença(s): confira e valide.', { n: x.resumo.diferencas })
            : t('Contagem gravada, sem diferenças: confira e valide.'),
          4000,
        );
        apagarRascunho(chave);
        CC = null;
        ler();
        draw();
      });
    }),
  );
  if (rasc) guardarRascunho(chave, rasc.v);
  return f;
}

// Grupos de colunas com cor própria (prática dos sistemas de inventário físico): o que diz o sistema, o que foi
// contado, a diferença entre os dois e a justificação. A mesma legenda e as mesmas cores vão para o PDF.
const GRUPOS = [
  { id: 'sis', nome: 'Sistema · Pinguim BO', cols: ['Inicial', 'Carregado', 'Vendido', 'Devolvido', 'Tem de voltar'] },
  { id: 'cont', nome: 'Contagem física', cols: ['Contado'] },
  { id: 'dif', nome: 'Diferença', cols: ['Diferença', 'Valor'] },
];
const quando = (s) => (s ? dl(String(s).slice(0, 10)) + ' ' + String(s).slice(11, 16) : '');

/** Circuito de aprovação: contada → validada por quem contou → coordenador (1.º nível) → responsável (2.º nível, fecha). */
function circuito(r) {
  const auto = r.aprovado_por === 'automatico';
  const passos = [
    ['Contado', r.contado_por, r.contado_em],
    ['Validada por quem contou', r.validado_por, r.validado_em],
    ['Aprovada pelo coordenador', auto ? null : r.coordenado_por, auto ? null : r.coordenado_em],
    [auto ? 'Fechada sozinha (sem diferenças)' : 'Aprovada pelo responsável · fechada', auto ? t('automático') : r.aprovado_por, r.aprovado_em],
  ];
  const ol = el('ol', 'cc-circuito');
  ol.setAttribute('aria-label', t('Circuito de aprovação'));
  let actual = false;
  passos.forEach(([nome, quem, em], i) => {
    if (auto && i === 2) return;
    const feito = !!em;
    const li = el('li', feito ? 'feito' : !actual ? 'actual' : null);
    if (!feito) actual = true;
    li.append(el('b', null, t(nome)), el('span', null, feito ? (quem || '') + ' · ' + quando(em) : t('pendente')));
    ol.append(li);
  });
  return ol;
}

/** Tabela completa (inicial, carregado, vendido, devolvido, esperado, contado, diferença) e as acções de cada passo. */
function tabelaRota(r) {
  const est = r.resumo.estado,
    eu = String(CC.eu || '').toLowerCase(),
    pode = CC.pode || { contar: CC.gere },
    fui = (k) => !!eu && String(r[k] || '').toLowerCase() === eu;
  const aprovar = est === 'por_aprovar' && pode.coordenar && !fui('contado_por');
  const box = el('div', 'cc-detalhe');
  box.append(circuito(r));
  const tab = el('table', 'cc-tab cc-cores');
  const g = el('tr', 'cc-grupos');
  g.append(el('th', null, ''));
  GRUPOS.forEach((x) => {
    const th = el('th', 'cc-g-' + x.id, t(x.nome));
    th.colSpan = x.cols.length;
    th.scope = 'colgroup';
    g.append(th);
  });
  g.append(el('th', 'cc-g-mot', t(est === 'por_contar' ? 'Alertas' : 'Justificação')));
  const th = el('tr');
  th.append(el('th', null, t('Artigo')));
  GRUPOS.forEach((x) => x.cols.forEach((c) => th.append(el('th', 'n cc-g-' + x.id, t(c)))));
  th.append(el('th', 'cc-g-mot', t(est === 'por_contar' ? 'Alertas' : 'Motivo')));
  const cab = el('thead');
  cab.append(g, th);
  tab.append(cab);
  const corpo = el('tbody');
  const escolhas = {};
  r.linhas.forEach((l) => {
    const tr = el('tr', l.diferenca ? (l.diferenca < 0 ? 'cc-falta' : 'cc-sobra') : l.alertas.length ? 'cc-alerta' : null);
    tr.append(el('td', null, l.artigo));
    [l.inicial, l.carregado, l.vendido, l.devolvido, l.esperado].forEach((v) => tr.append(el('td', 'n cc-g-sis', q(v))));
    tr.append(el('td', 'n cc-g-cont', q(l.contado)));
    const sinal = l.diferenca ? (l.diferenca < 0 ? ' cc-neg' : ' cc-pos') : '';
    tr.append(el('td', 'n cc-g-dif' + sinal, difTxt(l.diferenca)), el('td', 'n cc-g-dif' + sinal, l.valor == null ? '—' : kz(l.valor)));
    const ult = el('td', 'cc-g-mot');
    if (est === 'por_contar') l.alertas.forEach((a) => ult.append(pill(t(ALERTAS[a] || a), 'p-warn')));
    else if (l.diferenca && aprovar) {
      const s = el('select');
      s.append(el('option', null, t('Escolha o motivo')));
      s.firstChild.value = '';
      Object.entries(CC.motivos).forEach(([k, v]) => {
        const o = el('option', null, t(v));
        o.value = k;
        s.append(o);
      });
      const nota = entrada('text', '', { maxLength: 300, placeholder: t('Nota') });
      s.setAttribute('aria-label', t('Motivo') + ' · ' + l.artigo);
      escolhas[l.codigo] = { s, nota };
      ult.append(s, nota);
    } else if (l.diferenca) {
      const m = (r.motivos || {})[l.codigo];
      ult.textContent = m ? t(CC.motivos[m.motivo] || m.motivo) + (m.nota ? ' · ' + m.nota : '') : '—';
    }
    tr.append(ult);
    corpo.append(tr);
  });
  tab.append(corpo);
  const wrap = el('div', 'cc-scroll');
  wrap.append(tab);
  box.append(wrap);
  const legenda = el('p', 'note cc-legenda');
  GRUPOS.forEach((x) => legenda.append(el('span', 'cc-g-' + x.id, t(x.nome))));
  legenda.append(el('span', 'cc-neg', t('falta')), el('span', 'cc-pos', t('sobra')));
  box.append(legenda);
  if (est !== 'por_contar') box.prepend(pdfBotao(t('PDF da contagem'), () => resultadoContagem(r, dia, CC.motivos)));

  // Acções do passo em que a conciliação está, só para quem o pode fazer (segregação de funções)
  const erro = msgErro();
  const acc = el('div', 'cc-acc');
  const enviar = (corpo, ok) =>
    postJson('conciliacao-carrinha', { data: dia, rota: r.rota, ...corpo }).then((x) => {
      if (!x.ok) {
        erro.hidden = false;
        erro.textContent = t(x.erro || 'Não foi possível gravar.') + (x.erros ? ' ' + x.erros.join(', ') : '');
        return;
      }
      toast(t(ok), 4000);
      CC = null;
      ler();
      draw();
    });
  const recontar = botao(t('Recontar'), 'ghost', () => {
    r.linhas = r.linhas.map((l) => ({ ...l, contado: null }));
    r.resumo.estado = 'por_contar';
    r.cega = true;
    draw();
  });
  const espera = (txt) => box.append(el('p', 'note cc-espera', txt));
  if (est === 'por_validar') {
    if (fui('contado_por')) {
      acc.append(
        recontar,
        botao(t('Validar contagem'), null, () =>
          enviar({ accao: 'validar' }, 'Contagem validada; segue para o coordenador. Foi criada a requisição de reposição com o que foi vendido.'),
        ),
      );
      espera(t('Confira os números: se estiverem certos, valide; se não, reconte. Depois de validada segue para o coordenador.'));
    } else espera(t('Aguarda a validação de {p}, que fez a contagem.', { p: r.contado_por || '—' }));
  } else if (est === 'por_aprovar') {
    if (aprovar) {
      if (pode.contar) acc.append(recontar);
      acc.append(
        botao(t('Aprovar (coordenador)'), null, () => {
          const motivos = {};
          Object.entries(escolhas).forEach(([c, x]) => (motivos[c] = { motivo: x.s.value, nota: x.nota.value }));
          enviar({ accao: 'aprovar', motivos }, 'Aprovada pelo coordenador; segue para o responsável.');
        }),
      );
    } else espera(fui('contado_por') ? t('Validada. Aguarda a aprovação do coordenador (quem contou não aprova).') : t('Aguarda a aprovação do coordenador.'));
  } else if (est === 'por_fechar') {
    if (pode.fechar && !fui('contado_por') && !fui('coordenado_por'))
      acc.append(botao(t('Aprovar e fechar'), null, () => enviar({ accao: 'fechar' }, 'Conciliação aprovada e fechada.')));
    else espera(t('Aguarda a aprovação final do responsável (quem contou ou aprovou o 1.º nível não aprova o 2.º).'));
  }
  if (acc.childNodes.length) box.append(erro, acc);
  return box;
}

R['conciliacao-carrinha'] = (pg) => {
  dia = dia || hoje();
  pg.append(barra());
  if (!CC) {
    pg.append(el('div', 'glass empty', t('A carregar…')));
    ler();
    return;
  }
  if (CC.falhou) {
    pg.append(el('div', 'glass empty', t(CC.falhou)));
    return;
  }
  const rs = CC.rotas;
  const n = (e) => rs.filter((r) => r.resumo.estado === e).length;
  const soma = (k) => rs.reduce((s, r) => s + (r.resumo[k] || 0), 0);
  pg.append(
    tiles([
      { l: t('Carrinhas com movimento'), v: n0(rs.length), s: t('rotas no dia') },
      { l: t('Por contar'), v: n0(n('por_contar')), tone: n('por_contar') ? 'warn' : null, s: t('técnico do armazém') },
      { l: t('Por validar'), v: n0(n('por_validar')), tone: n('por_validar') ? 'warn' : null, s: t('por quem contou') },
      {
        l: t('Por aprovar'),
        v: n0(n('por_aprovar') + n('por_fechar')),
        tone: n('por_aprovar') + n('por_fechar') ? 'serio' : null,
        s: t('coordenador: {a} · responsável: {b}', { a: n0(n('por_aprovar')), b: n0(n('por_fechar')) }),
      },
      { l: t('Fechadas'), v: n0(n('aprovada')), s: t('aprovadas nos dois níveis ou sem diferenças') },
      { l: t('Faltas'), v: kz(soma('falta_kz')), tone: soma('falta_kz') ? 'bad' : null, s: t('ao preço médio de venda do dia') },
      { l: t('Alertas automáticos'), v: n0(soma('alertas')), tone: soma('alertas') ? 'warn' : null, s: t('artigos assinalados pelo sistema') },
    ]),
  );
  if (CC.movimentos === 'saldo')
    pg.append(
      el(
        'p',
        'note',
        t(
          'Esta base de dados não separa as cargas das devoluções: carregado e devolvido vêm do saldo do dia e «tem de voltar» é o stock da carrinha no sistema.',
        ),
      ),
    );
  if (!rs.length) {
    pg.append(el('div', 'glass empty', t('Nenhuma carrinha com movimento neste dia.')));
    return;
  }
  const lista = el('div', 'gst-lista');
  rs.forEach((r) => {
    const [txt, cls] = ESTADOS[r.resumo.estado] || [r.resumo.estado, 'p-mut'];
    const c = el('article', 'gst-alerta cc-rota g-' + (r.resumo.estado === 'aprovada' ? 'info' : r.resumo.alertas || r.resumo.diferencas ? 'aviso' : 'info'));
    c.dataset.rota = r.rota;
    const h = el('div', 'cc-cab');
    h.append(el('b', null, r.rota + ' · ' + r.promotor), pill(t(txt), cls));
    const det = [t('{n} artigos', { n: n0(r.resumo.artigos) })];
    if (r.resumo.alertas) det.push(t('{n} com alerta automático', { n: n0(r.resumo.alertas) }));
    if (r.resumo.estado !== 'por_contar') det.push(t('{n} diferença(s)', { n: n0(r.resumo.diferencas) }));
    if (r.resumo.falta_kz) det.push(t('falta {v}', { v: kz(r.resumo.falta_kz) }));
    c.append(h, el('p', 'note', det.join(' · ')));
    const abrir = botao(
      aberta === r.rota ? t('Fechar') : r.cega ? t('Contar') : t('Ver'),
      'ghost sm',
      () => ((aberta = aberta === r.rota ? null : r.rota), draw()),
    );
    c.append(abrir);
    // requisição de reposição criada pela conciliação (o que foi vendido), para o promotor rever e emitir
    if (r.requisicao) {
      const a = el('a', 'btn ghost sm cc-req', t('Requisição de reposição {n}', { n: r.requisicao }));
      a.href = '#requisicoes-carrinha';
      a.addEventListener('click', () => {
        try {
          sessionStorage.setItem('vm-requisicao-abrir', r.requisicao);
        } catch (e) {
          /* sem armazenamento: abre a lista */
        }
      });
      c.append(a);
    }
    if (aberta === r.rota) c.append(r.cega ? formContagem(r) : tabelaRota(r));
    lista.append(c);
  });
  const caixaDia = secBox(
    t('Carrinhas do dia'),
    t('Carregado, vendido e devolvido por artigo; diferenças entre o que tinha de voltar e o que foi contado.'),
    lista,
  );
  const contadas = rs.filter((r) => r.resumo.estado !== 'por_contar');
  if (CC.pode && CC.pode.corrigir && contadas.length)
    lista.before(botao(t('Corrigir quem contou / validou'), 'ghost sm cc-corrigir', () => corrigirRegisto(contadas)));
  pg.append(caixaDia);
};

/**
 * Correcção do registo de quem contou e de quem validou (super administrador): não muda números nem aprovações,
 * exige motivo e fica no histórico de cada carrinha.
 */
function corrigirRegisto(contadas) {
  janela(
    t('Corrigir quem contou / validou'),
    t('Só muda os nomes registados; os números, o estado e as aprovações ficam como estão. O que estava antes e o motivo ficam no histórico de cada carrinha.'),
    (b, ft, fechar) => {
      const campo = (rotulo) => {
        const i = entrada('text', '', { maxLength: 60 });
        i.setAttribute('aria-label', rotulo);
        const l = el('label', 'cc-campo');
        l.append(el('span', null, rotulo), i);
        b.append(l);
        return i;
      };
      const cont = campo(t('Contado por'));
      const val = campo(t('Validada por'));
      const marcas = contadas.map((r) => {
        const c = entrada('checkbox', '');
        c.checked = true;
        c.setAttribute('aria-label', r.rota + ' · ' + r.promotor);
        const l = el('label', 'cc-campo-rota');
        l.append(c, el('span', null, r.rota + ' · ' + r.promotor + (r.contado_por ? ' (' + r.contado_por + ')' : '')));
        b.append(l);
        return [r.rota, c];
      });
      const m = el('textarea');
      m.rows = 2;
      m.maxLength = 300;
      m.setAttribute('aria-label', t('Motivo da correcção'));
      const e2 = msgErro();
      b.append(el('span', 'note', t('Motivo da correcção')), m, e2);
      ft.append(
        botao(t('Cancelar'), 'ghost', fechar),
        botao(t('Corrigir'), null, () =>
          postJson('conciliacao-carrinha', {
            accao: 'corrigir_registo',
            data: dia,
            rotas: marcas.filter(([, c]) => c.checked).map(([r]) => r),
            contado_por: cont.value,
            validado_por: val.value,
            motivo: m.value,
          }).then((x) => {
            if (!x.ok) {
              e2.hidden = false;
              e2.textContent = t(x.erro || 'Não foi possível gravar.');
              return;
            }
            toast(t('Registo corrigido em {n} carrinha(s).', { n: n0((x.rotas || []).length) }), 5000);
            fechar();
            CC = null;
            ler();
            draw();
          }),
        ),
      );
    },
  );
}
