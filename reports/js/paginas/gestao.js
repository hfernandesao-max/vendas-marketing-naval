// Gestão (análise e IA): Alertas, Previsão, Assistente e Painel da sala comercial.
// Os números vêm sempre do servidor (nucleo/api/v1/analise.php, assistente.php, sala.php), com o âmbito de quem pede;
// os textos dos alertas compõem-se aqui a partir dos parâmetros, para aparecerem na língua escolhida.
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { pill, secBox, tiles } from '../componentes/blocos.js';
import { cartaoGrafico, grafBarras, grafColunas, kzc } from '../componentes/graficos.js';
import {
  comLegenda,
  corRota,
  grafAbc,
  grafAgrupadas,
  grafBalas,
  grafCalor,
  grafCascata,
  grafDispersao,
  grafTendencia,
} from '../componentes/graficos-gestao.js';
import { arrastavel } from '../componentes/arrastar.js';
import { botao, campo, copiar, entrada, janela, msgErro } from '../componentes/janelas.js';
import { dl, ds, kz, n0, n1, pct } from '../formatos.js';
import { LANG, t } from '../i18n/i18n.js';
import { SESSAO, atual, podeVer } from '../perfil.js';
import { postJson } from '../servidor.js';
import { el, toast } from '../util.js';
import { abrir as abrirLig, abrirPeriodo, celulaLigada } from '../componentes/ligacoes.js';
import { tArtigo } from '../i18n/artigos.js';
import { previaSala } from '../componentes/sala-previa.js';
import { R } from './registo.js';
import { sinal, textoAlerta, textoRico } from '../gestao-textos.js';

const DADOS = {};
const A_CARREGAR = {};
/** Lê uma rota da API uma vez (fresco: volta a calcular no servidor) e redesenha a página quando chega. */
function obter(chave, rota, pagina, fresco) {
  if (A_CARREGAR[chave]) return;
  A_CARREGAR[chave] = true;
  apiJson(rota + (fresco ? (rota.includes('?') ? '&' : '?') + 'fresco=1' : '')).then((d) => {
    A_CARREGAR[chave] = false;
    DADOS[chave] = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os dados.') };
    if (atual === pagina) draw();
  });
}
const esperar = (pg, chave, rota, pagina) => {
  const d = DADOS[chave];
  if (!d) {
    pg.append(el('div', 'glass empty', t('A calcular…')));
    obter(chave, rota, pagina);
    return null;
  }
  if (d.falhou) {
    pg.append(el('div', 'glass empty', t(d.falhou)));
    return null;
  }
  return d;
};
const tomVar = (v) => (v != null && v <= -0.1 ? 'bad' : null);
const actualizarBtn = (chave, rota, pagina) =>
  botao(t('Recalcular'), 'sec', () => {
    delete DADOS[chave];
    obter(chave, rota, pagina, true);
    draw();
  });

// ───────────────────────── Alertas ─────────────────────────

const GRAV = { critico: ['Crítico', 'p-bad'], aviso: ['Aviso', 'p-warn'], info: ['Destaque', 'p-ok'] };
let filtroGrav = '';
let filtroEstado = 'activos';
// Tratamento dos alertas (ISA-18.2 / ITIL 4): assumir, resolver com nota, silenciar até uma data com motivo, reabrir.
// O servidor guarda quem, quando e a nota (alertas-tratamento.php); um resolvido que continua reabre sozinho em 24 h.
const ESTADO_AL = {
  novo: ['Novo', 'p-bad'],
  assumido: ['Em tratamento', 'p-warn'],
  reaberto: ['Reaberto', 'p-bad'],
  resolvido: ['Resolvido', 'p-ok'],
  silenciado: ['Silenciado', 'p-ok'],
};
const NOMES_AL = {};
const quemAl = (l) => (l ? (NOMES_AL[l] || l) + (NOMES_AL[l] ? ' (' + l + ')' : '') : '');
const quandoAl = (s) => (s ? dl(String(s).slice(0, 10)) + ' ' + String(s).slice(11, 16) : '');
const ACCAO_AL = { assumir: 'Assumido', resolver: 'Resolvido', silenciar: 'Silenciado', reabrir: 'Reaberto', largar: 'Largado' };
function tratarAlerta(a, accao, fechar, erro, extra = {}) {
  return postJson('alertas', { accao, id: a.id, ...extra }).then((x) => {
    if (!x || !x.ok) {
      if (erro) {
        erro.hidden = false;
        erro.textContent = t((x && x.erro) || 'Não foi possível gravar.');
      } else toast(t((x && x.erro) || 'Não foi possível gravar.'), 5000);
      return;
    }
    if (fechar) fechar();
    toast(
      t(
        { assumir: 'Alerta assumido.', resolver: 'Alerta resolvido.', silenciar: 'Alerta silenciado.', reabrir: 'Alerta reaberto.', largar: 'Alerta largado.' }[
          accao
        ],
      ),
      3500,
    );
    delete DADOS.alertas;
    draw();
  });
}
function janelaAlerta(a, accao) {
  const [titulo] = textoAlerta(a);
  const cfg = {
    resolver: [
      t('Resolver o alerta'),
      t('O que foi feito para resolver? Fica registado com o seu nome. Se o problema continuar, o alerta reabre sozinho em 24 horas.'),
      t('Resolver'),
    ],
    silenciar: [t('Silenciar o alerta'), t('Até quando e porquê? No máximo 30 dias; depois volta sozinho.'), t('Silenciar')],
    reabrir: [t('Reabrir o alerta'), t('Porque reabre este alerta?'), t('Reabrir')],
  }[accao];
  janela(cfg[0], titulo, (b, ft, fechar) => {
    b.append(el('p', 'note', cfg[1]));
    const nota = el('textarea');
    nota.rows = 3;
    nota.maxLength = 500;
    nota.setAttribute('aria-label', accao === 'resolver' ? t('Acção tomada') : t('Motivo'));
    let ate = null;
    if (accao === 'silenciar') {
      const hojeIso = new Date().toISOString().slice(0, 10);
      const max = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10);
      ate = entrada('date', new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10), { min: hojeIso, max });
      ate.setAttribute('aria-label', t('Silenciado até'));
      b.append(campo(t('Silenciado até'), ate));
    }
    b.append(campo(accao === 'resolver' ? t('Acção tomada') : t('Motivo'), nota));
    const erro = msgErro();
    b.append(erro);
    ft.append(
      botao(t('Cancelar'), 'ghost', fechar),
      botao(cfg[2], null, () => tratarAlerta(a, accao, fechar, erro, { nota: nota.value, ...(ate ? { ate: ate.value } : {}) })),
    );
    setTimeout(() => nota.focus(), 0);
  });
}
/** Estado, acções e histórico do tratamento de um alerta. */
function blocoTratamento(a) {
  const tr = a.tratamento || { estado: 'novo' };
  const w = el('div', 'gst-trat');
  const est = ESTADO_AL[tr.estado] || ESTADO_AL.novo;
  const linha = el('div', 'gst-trat-l');
  linha.append(pill(t(est[0]), est[1]));
  const txt =
    tr.estado === 'assumido'
      ? t('por {p} · {q}', { p: quemAl(tr.por), q: quandoAl(tr.em) })
      : tr.estado === 'resolvido'
        ? t('por {p} · {q}', { p: quemAl(tr.por), q: quandoAl(tr.em) }) + (tr.nota ? ' · ' + tr.nota : '')
        : tr.estado === 'silenciado'
          ? t('até {d}, por {p}', { d: dl(tr.ate), p: quemAl(tr.por) }) + (tr.nota ? ' · ' + tr.nota : '')
          : tr.estado === 'reaberto'
            ? t('continua depois de resolvido por {p}', { p: quemAl(tr.por) })
            : '';
  if (txt) linha.append(el('span', 'note', txt));
  w.append(linha);
  const acc = el('div', 'gst-trat-acc');
  const eu = atualLogin();
  const activo = ['novo', 'assumido', 'reaberto'].includes(tr.estado);
  if (activo && tr.estado !== 'assumido') acc.append(botao(t('Assumir'), 'sec sm', () => tratarAlerta(a, 'assumir')));
  if (tr.estado === 'assumido' && tr.por === eu) acc.append(botao(t('Largar'), 'ghost sm', () => tratarAlerta(a, 'largar')));
  if (tr.estado === 'assumido' && tr.por !== eu) acc.append(botao(t('Assumir'), 'ghost sm', () => tratarAlerta(a, 'assumir')));
  if (activo)
    acc.append(
      botao(t('Resolver'), 'sec sm', () => janelaAlerta(a, 'resolver')),
      botao(t('Silenciar'), 'ghost sm', () => janelaAlerta(a, 'silenciar')),
    );
  else acc.append(botao(t('Reabrir'), 'ghost sm', () => janelaAlerta(a, 'reabrir')));
  w.append(acc);
  if (tr.hist && tr.hist.length) {
    const det = el('details', 'gst-trat-h');
    det.append(el('summary', null, t('Histórico ({n})', { n: tr.hist.length })));
    const ul = el('ul');
    tr.hist
      .slice()
      .reverse()
      .forEach((h) =>
        ul.append(
          el(
            'li',
            null,
            quandoAl(h.em) +
              ' · ' +
              t(ACCAO_AL[h.accao] || h.accao) +
              ' · ' +
              quemAl(h.por) +
              (h.ate ? ' · ' + t('até {d}', { d: dl(h.ate) }) : '') +
              (h.nota ? ' · ' + h.nota : ''),
          ),
        ),
      );
    det.append(ul);
    w.append(det);
  }
  return w;
}
const atualLogin = () => (SESSAO && SESSAO.login) || '';

R.alertas = (pg) => {
  const d = esperar(pg, 'alertas', 'alertas', 'alertas');
  if (!d) return;
  Object.assign(NOMES_AL, d.nomes || {});
  const activos = d.alertas.filter((a) => a.activo !== false);
  const n = (g) => activos.filter((a) => a.gravidade === g).length;
  const tratados = d.alertas.length - activos.length;
  pg.append(
    tiles([
      { l: t('Críticos'), v: n0(n('critico')), s: t('a tratar hoje'), tone: n('critico') ? 'bad' : null },
      { l: t('Avisos'), v: n0(n('aviso')), s: t('a acompanhar') },
      { l: t('Tratados'), v: n0(tratados), s: t('resolvidos ou silenciados') },
      { l: t('Dia analisado'), v: dl(d.dia), s: t('calculado às {h}', { h: String(d.gerado || '').slice(11, 16) }) },
    ]),
  );
  if (d.indisponivel && d.indisponivel.length)
    pg.append(el('p', 'glass act-aviso', t('Fontes indisponíveis neste momento: {f}.', { f: d.indisponivel.map((x) => t(FONTES[x] || x)).join(', ') })));
  const barra = el('div', 'gst-filtros');
  [
    ['', 'Todos'],
    ['critico', 'Críticos'],
    ['aviso', 'Avisos'],
    ['info', 'Destaques'],
  ].forEach(([g, txt]) => {
    const b = botao(t(txt), 'sec' + (filtroGrav === g ? ' on' : ''), () => {
      filtroGrav = g;
      draw();
    });
    b.setAttribute('aria-pressed', filtroGrav === g ? 'true' : 'false');
    barra.append(b);
  });
  const sep = el('span', 'gst-sep');
  sep.setAttribute('aria-hidden', 'true');
  barra.append(sep);
  [
    ['activos', 'Activos'],
    ['tratados', 'Tratados'],
    ['todos', 'Todos os estados'],
  ].forEach(([e, txt]) => {
    const b = botao(t(txt), 'sec' + (filtroEstado === e ? ' on' : ''), () => {
      filtroEstado = e;
      draw();
    });
    b.setAttribute('aria-pressed', filtroEstado === e ? 'true' : 'false');
    barra.append(b);
  });
  barra.append(actualizarBtn('alertas', 'alertas', 'alertas'));
  const lista = el('div', 'gst-lista');
  const vis = d.alertas.filter(
    (a) => (!filtroGrav || a.gravidade === filtroGrav) && (filtroEstado === 'todos' || (filtroEstado === 'activos' ? a.activo !== false : a.activo === false)),
  );
  if (!vis.length) lista.append(el('p', 'note', t('Sem alertas: tudo dentro do habitual.')));
  vis.forEach((a) => {
    const [titulo, detalhe] = textoAlerta(a);
    const c = el('article', 'gst-alerta g-' + a.gravidade);
    const h = el('div', 'gst-alerta-h');
    h.append(pill(t(GRAV[a.gravidade][0]), GRAV[a.gravidade][1]), el('strong', null, titulo));
    c.append(h, el('p', null, detalhe));
    if (a.pagina && podeVer(a.pagina)) {
      const ir = el('a', 'linkbtn', t('Ver os dados'));
      ir.href = '#' + a.pagina;
      c.append(ir);
    }
    if (a.id) c.append(blocoTratamento(a));
    c.classList.toggle('tratado', a.activo === false);
    lista.append(c);
  });
  const corpo = el('div');
  corpo.append(barra, lista);
  pg.append(
    secBox(
      t('Alertas de {d}', { d: dl(d.dia) }),
      t(
        'Comparação com o mesmo dia da semana nas últimas 6 semanas (mediana). Cada alerta diz o que aconteceu, o que era de esperar e porquê; a decisão é sempre de quem o lê.',
      ),
      corpo,
    ),
  );
  if (podeVer('resumo-diario')) pg.append(seccaoResumo());
};
const FONTES = { vendas: 'vendas', agt: 'comunicação AGT', mdm: 'MDM Plus' };

function seccaoResumo() {
  const c = el('div', 'gst-resumo');
  c.append(el('p', null, t('Todos os dias às 07:00 segue por correio electrónico o resumo de ontem para os perfis com «Resumo diário» na matriz de acessos.')));
  const acc = el('div', 'gst-filtros');
  acc.append(
    botao(t('Ver o resumo de ontem'), 'sec', () => verResumo()),
    botao(t('Enviar-me um teste'), 'sec', (e) => {
      e.target.disabled = true;
      postJson('resumo', { accao: 'teste' }).then((r) => {
        e.target.disabled = false;
        toast(r.ok ? t('Resumo enviado para {e}.', { e: r.para }) : t(r.erro || 'Não foi possível enviar.'), 6000);
      });
    }),
  );
  c.append(acc);
  return secBox(t('Resumo diário'), null, c);
}
function verResumo() {
  janela(t('Resumo diário'), t('Pré-visualização do correio de hoje (vendas de ontem).'), (b, ft, fechar) => {
    b.append(el('p', 'note', t('A calcular…')));
    ft.append(botao(t('Fechar'), null, fechar));
    apiJson('resumo').then((d) => {
      b.replaceChildren();
      if (!d || !d.ok) {
        b.append(el('p', 'erro', t((d && d.erro) || 'Não foi possível ler os dados.')));
        return;
      }
      const info = [d.activo ? t('Envio automático às {h}', { h: d.hora }) : t('Envio automático desligado (RESUMO_ACTIVO=0)')];
      info.push(Array.isArray(d.destinatarios) ? t('{n} destinatários', { n: d.destinatarios.length }) : t('{n} destinatários', { n: d.destinatarios }));
      if (d.ultimo && d.ultimo.data) info.push(t('último envio: {d}', { d: dl(d.ultimo.data) }));
      if (!d.email_configurado) info.push(t('correio electrónico por configurar no servidor'));
      b.append(el('p', 'note', info.join(' · ')));
      const f = el('iframe', 'gst-correio');
      f.setAttribute('sandbox', '');
      f.title = t('Resumo diário');
      f.srcdoc = d.html;
      b.append(f);
    });
  });
}

// ───────────────────────── Previsão ─────────────────────────

// Objectivos automáticos do mês: base estatística + crescimento, nunca abaixo do ritmo do mês anterior + mínimo;
// congelados no início do mês (o servidor calcula e guarda; aqui só se mostram e acompanham)
const ESTADO_OBJ = { acima: ['Acima do objectivo', 'p-ok'], no_caminho: ['No caminho', 'p-warn'], abaixo: ['Abaixo do objectivo', 'p-bad'] };
const pctObj = (v) => (v == null ? '—' : n0(Math.round(v * 100)) + '%');
function objectivos(pg, o) {
  if (!o || !o.total) return;
  const T = o.total;
  const mes = new Date(o.mes + '-15T12:00:00').toLocaleDateString(LANG === 'en' ? 'en-GB' : 'pt-PT', { month: 'long', year: 'numeric' });
  pg.append(
    tiles([
      { l: t('Objectivo de {m}', { m: mes }), v: kz(T.objectivo), s: t('{n} dias úteis · congelado no início do mês', { n: n0(T.dias_uteis) }) },
      { l: t('Realizado no mês'), v: kz(T.realizado), s: t('cumprimento: {p}', { p: pctObj(T.cumprimento) }) },
      {
        l: t('Ritmo face ao objectivo até hoje'),
        v: pctObj(T.ritmo),
        s: t('desvio: {v}', { v: kz(T.desvio) }),
        tone: T.estado === 'abaixo' ? 'bad' : T.estado === 'no_caminho' ? 'warn' : null,
      },
      { l: t('Necessário por dia útil'), v: kz(T.necessario_dia), s: t('{n} dias úteis por vender', { n: n0(T.dias_restantes) }) },
      {
        l: t('Projecção do fecho'),
        v: kz(T.projeccao),
        s: t('ao ritmo actual: {p} do objectivo', { p: pctObj(T.objectivo ? T.projeccao / T.objectivo : null) }),
      },
    ]),
  );
  const tw = el('div', 'tw');
  const tab = el('table');
  const hr = el('tr');
  [
    t('Rota'),
    t('Objectivo do mês'),
    t('Crescimento'),
    t('Realizado'),
    t('Cumprimento'),
    t('Objectivo até hoje'),
    t('Ritmo'),
    t('Necessário por dia'),
    t('Projecção'),
    t('Estado'),
  ].forEach((x, i) => hr.append(el('th', i ? 'n' : null, x)));
  const th = el('thead');
  th.append(hr);
  const tb = el('tbody');
  o.rotas.forEach((r) => {
    const tr = el('tr');
    const [txt, cls] = ESTADO_OBJ[r.estado] || [r.estado, 'p-mut'];
    const cresc = el('td', 'n', r.crescimento == null ? '—' : sinal(r.crescimento));
    cresc.title =
      r.criterio === 'minimo' ? t('Pelo mínimo crescente: ritmo do mês anterior + crescimento mínimo') : t('Pela tendência das vendas + crescimento');
    const est = el('td', 'n');
    est.append(pill(t(txt), cls));
    tr.append(
      el('td', null, r.rota + (r.promotor ? ' · ' + r.promotor : '')),
      el('td', 'n', n0(r.objectivo)),
      cresc,
      el('td', 'n', n0(r.realizado)),
      el('td', 'n', pctObj(r.cumprimento)),
      el('td', 'n', n0(r.objectivo_ate_hoje)),
      el('td', 'n', pctObj(r.ritmo)),
      el('td', 'n', r.necessario_dia == null ? '—' : n0(r.necessario_dia)),
      el('td', 'n', n0(r.projeccao)),
      est,
    );
    tb.append(tr);
  });
  tab.append(th, tb);
  tw.append(tab);
  const corpo = el('div');
  const par = o.parametros || {};
  corpo.append(
    tw,
    el(
      'p',
      'note',
      t(
        'Calculados sozinhos no início de cada mês a partir das vendas diárias: previsão do mês (mesmo dia da semana × tendência) + {a}% de crescimento, e nunca abaixo do ritmo por dia útil do mês anterior (realizado ou objectivo, o maior) + {m}%. Repartidos pelos dias úteis e congelados durante o mês.',
        { a: n0(Math.round((par.crescimento_alvo || 0) * 100)), m: n0(Math.round((par.crescimento_min || 0) * 100)) },
      ),
    ),
  );
  pg.append(secBox(t('Objectivos do mês por rota (Kz)'), t('Ordenados do mais atrasado para o mais adiantado.'), corpo));
}

R.previsao = (pg) => {
  const d = esperar(pg, 'previsao', 'previsao', 'previsao');
  if (!d) return;
  objectivos(pg, d.objectivos);
  const tot = d.total;
  if (!tot) {
    pg.append(el('div', 'glass empty', t('Ainda não há histórico suficiente para prever (são precisas pelo menos 3 semanas de vendas).')));
    return;
  }
  const q = tot.qualidade || {};
  pg.append(
    tiles([
      { l: t('Previsão dos próximos 7 dias'), v: kz(tot.semana), s: t('de {i} a {f}', { i: dl(d.dias[0]), f: dl(d.dias[d.dias.length - 1]) }) },
      {
        l: t('Tendência'),
        v: sinal(tot.tendencia - 1),
        s: t('últimas 4 semanas face às 4 anteriores (amortecida, no máximo ±20%)'),
        tone: tomVar(tot.tendencia - 1),
      },
      {
        l: t('Precisão medida'),
        v: q.precisao == null ? '—' : n0(Math.round(q.precisao * 100)) + '%',
        s: t('nos últimos {n} dias, com os dados de antes de cada dia', { n: q.dias || 0 }),
      },
    ]),
  );
  const itens = [
    ...Object.entries(tot.historico).map(([dia, v]) => ({
      dia,
      rot: ds(dia),
      valor: v,
      titulo: ds(dia),
      linhas: [[kz(v), t('real')]],
      abrir: () => abrirPeriodo(dia),
    })),
  ].slice(-14);
  const prev = tot.dias.map((x) => ({
    rot: ds(x.dia),
    valor: x.previsto,
    titulo: ds(x.dia),
    linhas: [
      [kz(x.previsto), t('previsto')],
      [kz(x.minimo) + ' – ' + kz(x.maximo), t('intervalo de 80%')],
    ],
  }));
  const grid = el('div', 'viz-grid gst-prev');
  grid.append(
    cartaoGrafico({
      titulo: t('Vendas reais (últimos 14 dias)'),
      sub: t('todas as rotas a que tem acesso'),
      desenhar: (w) => grafColunas(w, { itens }),
      colunas: [
        [t('Dia'), 0],
        [t('Vendas'), 1],
      ],
      linhas: itens.map((x) => [x.titulo, kz(x.valor)]),
      abrirLinha: (i) => abrirPeriodo(itens[i].dia),
    }),
    cartaoGrafico({
      titulo: t('Previsão (próximos 7 dias)'),
      sub: t('média ponderada do mesmo dia da semana × tendência'),
      desenhar: (w) => grafColunas(w, { itens: prev, cor: '#0057B8' }),
      colunas: [
        [t('Dia'), 0],
        [t('Previsto'), 1],
        [t('Mínimo'), 1],
        [t('Máximo'), 1],
      ],
      linhas: tot.dias.map((x) => [ds(x.dia), kz(x.previsto), kz(x.minimo), kz(x.maximo)]),
    }),
  );
  pg.append(grid);
  const tw = el('div', 'tw');
  const tab = el('table');
  const thead = el('thead'),
    hr = el('tr');
  [t('Rota'), ...d.dias.map(ds), t('Semana'), t('Tendência'), t('Precisão')].forEach((x, i) => hr.append(el('th', i ? 'n' : null, x)));
  thead.append(hr);
  const tb = el('tbody');
  d.rotas.forEach((r) => {
    const tr = el('tr');
    tr.append(el('td', null, r.rota + (r.promotor ? ' · ' + r.promotor : '')));
    r.dias.forEach((x) => {
      const td = el('td', 'n', n0(x.previsto));
      td.title = kz(x.minimo) + ' – ' + kz(x.maximo);
      tr.append(td);
    });
    tr.append(
      el('td', 'n', n0(r.semana)),
      el('td', 'n', sinal(r.tendencia - 1)),
      el('td', 'n', r.qualidade.precisao == null ? '—' : n0(Math.round(r.qualidade.precisao * 100)) + '%'),
    );
    tb.append(tr);
  });
  tab.append(thead, tb);
  tw.append(tab);
  const corpo = el('div');
  corpo.append(tw);
  if (d.sem_historico && d.sem_historico.length) corpo.append(el('p', 'note', t('Sem histórico suficiente: rotas {r}.', { r: d.sem_historico.join(', ') })));
  corpo.append(
    el(
      'p',
      'note',
      t(
        'Método: média ponderada do mesmo dia da semana nas últimas 6 semanas (a mais recente pesa mais), multiplicada pela tendência. A precisão é medida todos os dias e mostrada sempre. As quantidades sugeridas no plano de cargas usam a tendência de cada rota.',
      ),
    ),
    actualizarBtn('previsao', 'previsao', 'previsao'),
  );
  pg.append(secBox(t('Previsão por rota (Kz)'), t('Passe o rato por cima de um valor para ver o intervalo de 80%.'), corpo));
};

// ───────────────────────── Assistente ─────────────────────────

const CONVERSA = [];
let aPensar = false;
const SUGESTOES = [
  'Quanto vendemos ontem face ao habitual?',
  'Que rotas estão a crescer e quais estão a cair este mês?',
  'Quais são os 10 clientes que mais compraram este mês?',
  'Há algum alerta crítico hoje? O que devo fazer primeiro?',
  'Qual é a previsão de vendas para esta semana?',
  'Porque é que o Fecho do dia de ontem ainda não está completo?',
  'Como funcionam os lotes complementares do ficheiro para o SAP?',
];
const NOMES_FONTE = {
  vendas_resumo: 'Vendas',
  comparar_periodos: 'Comparação de períodos',
  alertas: 'Alertas',
  previsao: 'Previsão',
  plano_cargas: 'Plano de cargas',
  agt_estado: 'Comunicação AGT',
  conhecimento: 'Base de conhecimento do portal',
  fecho_do_dia: 'Fecho do dia',
  dia_pinguim: 'Abertura e fecho do dia no Pinguim BO',
  rotas: 'Rotas',
};

function perguntar(pergunta) {
  pergunta = String(pergunta || '').trim();
  if (!pergunta || aPensar) return;
  const historico = CONVERSA.filter((m) => !m.erro).map((m) => ({ papel: m.papel, texto: m.texto }));
  CONVERSA.push({ papel: 'pessoa', texto: pergunta });
  aPensar = true;
  draw();
  postJson('assistente', { pergunta, historico, idioma: LANG }).then((r) => {
    aPensar = false;
    CONVERSA.push(
      r.ok
        ? { papel: 'assistente', texto: r.resposta, fontes: r.fontes || [] }
        : { papel: 'assistente', texto: t(r.erro || 'Sem resposta do servidor.'), erro: true },
    );
    delete DADOS.assistente;
    if (atual === 'assistente') draw();
  });
}

R.assistente = (pg) => {
  const d = esperar(pg, 'assistente', 'assistente', 'assistente');
  if (!d) return;
  if (!d.configurada) {
    pg.append(
      el(
        'p',
        'glass act-aviso',
        t('O assistente ainda não está configurado no servidor: correr «php ferramentas/configurar-ia.php» com a chave da API da conta comercial da empresa.'),
      ),
    );
  }
  const chat = el('section', 'glass sec gst-chat');
  const msgs = el('div', 'gst-msgs');
  msgs.setAttribute('aria-live', 'polite');
  if (!CONVERSA.length) {
    const ola = el('div', 'gst-ola');
    ola.append(
      el('h2', null, t('Pergunte sobre as vendas, as rotas, os clientes, os alertas e a previsão — e sobre como o portal funciona')),
      el('p', null, t('As respostas usam só os dados do portal a que tem acesso. Confirme sempre os números importantes nas páginas indicadas como fonte.')),
    );
    const sug = el('div', 'gst-sug');
    SUGESTOES.forEach((s) => sug.append(botao(t(s), 'sec', () => perguntar(t(s)))));
    ola.append(sug);
    msgs.append(ola);
  }
  CONVERSA.forEach((m) => {
    const b = el('div', 'gst-msg ' + (m.papel === 'pessoa' ? 'eu' : 'ia') + (m.erro ? ' erro' : ''));
    if (m.papel === 'pessoa') b.append(el('p', null, m.texto));
    else {
      b.append(textoRico(m.texto));
      if (m.fontes && m.fontes.length) b.append(el('div', 'gst-fontes', t('Fontes: {f}', { f: m.fontes.map((f) => t(NOMES_FONTE[f] || f)).join(', ') })));
    }
    msgs.append(b);
  });
  if (aPensar) msgs.append(el('div', 'gst-msg ia a-pensar', t('A analisar os dados…')));
  const form = el('form', 'gst-form');
  const ta = el('textarea');
  ta.rows = 2;
  ta.maxLength = 1500;
  ta.placeholder = t('Escreva a pergunta e carregue em Enter');
  ta.setAttribute('aria-label', t('Pergunta ao assistente'));
  ta.disabled = aPensar || !d.configurada;
  ta.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      perguntar(ta.value);
    }
  });
  const enviar = botao(t('Perguntar'), null, () => perguntar(ta.value));
  enviar.disabled = ta.disabled;
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    perguntar(ta.value);
  });
  form.append(ta, enviar);
  const rod = el('div', 'gst-rod');
  rod.append(
    el(
      'span',
      'note',
      t('Respostas geradas por IA ({m}) a partir dos dados do portal. Hoje fez {n} de {l} perguntas.', {
        m: d.modelo,
        n: d.uso.minhas_hoje,
        l: d.limites.perguntas_dia,
      }),
    ),
  );
  if (CONVERSA.length)
    rod.append(
      botao(t('Nova conversa'), 'sec', () => {
        CONVERSA.length = 0;
        draw();
      }),
    );
  chat.append(msgs, form, rod);
  pg.append(chat);
  requestAnimationFrame(() => {
    msgs.scrollTop = msgs.scrollHeight;
    if (!ta.disabled) ta.focus();
  });
};

// ───────────────────────── Painel da sala comercial ─────────────────────────

R['painel-sala'] = (pg) => {
  const d = esperar(pg, 'sala', 'sala/ligacoes', 'painel-sala');
  if (!d) return;
  const c = el('div');
  c.append(
    el(
      'p',
      null,
      t(
        'Cada ecrã (TV ou mini-PC ligado à TV) usa uma ligação própria, sem conta nem palavra-passe, que só mostra o painel: vendas do dia, classificação e destaques, sem clientes nem NIF. Pode revogá-la a qualquer momento.',
      ),
    ),
  );
  const lista = el('div', 'gst-lista');
  const activas = d.ligacoes.filter((l) => !l.revogado);
  if (!activas.length) lista.append(el('p', 'note', t('Ainda não há ligações activas.')));
  d.ligacoes.forEach((l) => {
    const linha = el('div', 'gst-lig' + (l.revogado ? ' revogada' : ''));
    linha.append(
      el('strong', null, l.nome),
      pill(t(LOCAIS_SALA[l.local || 'interna'][0]), l.local === 'aberta' ? 'p-ok' : 'p-mut'),
      el(
        'span',
        'note',
        l.revogado
          ? t('revogada em {d}', { d: dl(l.revogado) })
          : t('criada em {d} · último acesso: {u}', { d: dl(l.criado), u: l.ultimo_acesso ? dl(l.ultimo_acesso) + ' ' + l.ultimo_acesso.slice(11, 16) : '—' }),
      ),
    );
    if (!l.revogado && (l.ip || l.ip_fixo))
      linha.append(el('span', 'note sala-ip', l.ip_fixo ? t('presa ao IP {ip}', { ip: l.ip_fixo }) : t('último acesso do IP {ip}', { ip: l.ip })));
    if (!l.revogado && (l.ip || l.ip_fixo))
      linha.append(
        botao(l.ip_fixo ? t('Soltar do IP') : t('Fixar a este IP'), 'sec', () =>
          postJson('sala/ligacoes', { accao: l.ip_fixo ? 'soltar_ip' : 'fixar_ip', id: l.id }).then((r) => {
            if (r.ok) {
              DADOS.sala = { ok: true, ligacoes: r.ligacoes };
              toast(l.ip_fixo ? t('A ligação volta a abrir em qualquer ecrã.') : t('A ligação passa a abrir só neste ecrã.'), 5000);
            } else toast(t(r.erro || 'Não foi possível gravar.'), 6000);
            draw();
          }),
        ),
      );
    // versão do painel no ecrã: actualiza-se sozinho depois de cada actualização do portal (em até 2 minutos)
    if (!l.revogado && l.ultimo_acesso)
      linha.append(
        l.em_dia
          ? pill('✔ ' + t('Ecrã na versão actual'), 'p-ok')
          : Object.assign(pill(t('A actualizar o ecrã…'), 'p-warn'), {
              title: t('O ecrã recarrega-se sozinho na leitura seguinte (até 2 minutos). Se estiver desligado, actualiza-se quando ligar.'),
            }),
      );
    if (!l.revogado)
      linha.append(
        botao(t('Recarregar ecrã'), 'sec', () =>
          postJson('sala/ligacoes', { accao: 'recarregar', id: l.id }).then((r) => {
            if (r.ok) {
              DADOS.sala = { ok: true, ligacoes: r.ligacoes };
              toast(t('O ecrã recarrega a página em até 2 minutos.'), 5000);
            } else toast(t(r.erro || 'Não foi possível gravar.'), 6000);
            draw();
          }),
        ),
      );
    // manutenção da TV (super administrador): a app da TV abre a depuração (adb) até à hora indicada e fecha-a sozinha
    if (!l.revogado && SESSAO && SESSAO.perfil === 'SADM') {
      const aberta =
        l.manutencao_ate && l.manutencao_ate > new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 19).replace('T', ' ');
      if (aberta) linha.append(pill(t('Manutenção aberta até {h}', { h: String(l.manutencao_ate).slice(11, 16) }), 'p-warn'));
      linha.append(
        botao(aberta ? t('Fechar manutenção') : t('Abrir manutenção da TV (1 h)'), 'sec', () =>
          postJson('sala/ligacoes', { accao: 'manutencao', id: l.id, minutos: aberta ? 0 : 60 }).then((r) => {
            if (r.ok) {
              DADOS.sala = { ok: true, ligacoes: r.ligacoes };
              toast(
                aberta ? t('A TV fecha a depuração em até 2 minutos.') : t('A TV abre a depuração em até 2 minutos e fecha-a sozinha daqui a 1 hora.'),
                6000,
              );
            } else toast(t(r.erro || 'Não foi possível gravar.'), 6000);
            draw();
          }),
        ),
      );
    }
    if (!l.revogado) linha.append(botao(t('Ecrãs'), 'sec', () => configurarEcras(l)));
    if (!l.revogado)
      linha.append(
        botao(t('Revogar'), 'sec', () =>
          postJson('sala/ligacoes', { accao: 'revogar', id: l.id }).then((r) => {
            if (r.ok) DADOS.sala = { ok: true, ligacoes: r.ligacoes };
            else toast(t(r.erro || 'Não foi possível revogar.'));
            draw();
          }),
        ),
      );
    lista.append(linha);
  });
  c.append(lista, botao(t('Criar ligação para um ecrã'), null, criarLigacao));
  pg.append(secBox(t('Painel da sala comercial'), t('Ecrã de TV com as vendas do dia, actualizado a cada 2 minutos.'), c));
  pg.append(
    secBox(
      t('Pré-visualização ao vivo'),
      t('O ecrã como aparece na TV, com os dados de agora. Escolha o local para ver o que cada um mostra.'),
      previaSala({ local: 'interna', escolha: true }),
    ),
  );
  pg.append(guiaExposicao());
};
/** Local do ecrã: o servidor só envia o que esse local pode mostrar (nucleo/api/v1/sala.php). */
const LOCAIS_SALA = {
  interna: ['Sala interna', 'Sala comercial fechada, vista só pela equipa: valores em Kz, classificações com o nome do promotor e destaques.'],
  aberta: [
    'Open space',
    'Área comum, recepção ou corredor, onde visitantes, fornecedores e outras equipas podem ver: só percentagens, tendências e contagens, sem valores em Kz, sem nomes e sem classificações.',
  ],
};
/** Ecrãs que podem estar num espaço aberto (na versão sem valores nem nomes). */
const ECRAS_ABERTA = [
  'hoje',
  'acumulado',
  'previsao',
  'comparacoes',
  'ritmo',
  'calor',
  'tendencia',
  'visitas',
  'funil',
  'rotas',
  'clientes',
  'recordes',
  'contagem',
  'destaques',
  'momentos',
];
/**
 * Guia (com exemplos) do que pode estar à vista em cada local, segundo a classificação da informação (ISO/IEC 27001
 * A.5.12 e A.7.7 «ecrã limpo»), a protecção de dados pessoais (Lei 22/11) e as boas práticas de gestão visual
 * (reconhecimento positivo, nunca o último lugar).
 */
function guiaExposicao() {
  const linhas = [
    ['Progresso da equipa face ao objectivo do dia (%)', 'sim', 'sim', '«Equipa RTM: 87 % do objectivo de hoje»'],
    ['Tendências (▲ ▼) face a ontem, à semana e ao mês', 'sim', 'sim', '«Este mês ▲ +12 % face ao mês passado»'],
    ['Contagens: vendas e clientes com compras', 'sim', 'sim', '«214 vendas · 180 clientes com compras»'],
    ['Destaques por rota, sem nomes nem valores', 'sim', 'sim', '«Maior subida do mês: Rota 1004 · +23 %»'],
    ['Comparativos com as datas dos dois períodos (só a variação no open space)', 'sim', 'sim', '«Semana até ontem ▲ +8 % · 23–24 nov × 16–17 nov»'],
    ['Gráficos: vendas por hora (contagens) e últimos 14 dias (índice face à média)', 'sim', 'sim', '«Hoje: 112 (100 = média dos 14 dias)»'],
    ['Visitas do plano: quantas, quantas já compraram, conversão', 'sim', 'sim', '«68 % do plano já comprou · 12 compras fora do plano»'],
    ['Rotas a vender e rotas com o objectivo atingido (só as que chegaram)', 'sim', 'sim', '«9 / 11 rotas a vender · objectivo: ✔ 1004 ✔ 1010»'],
    [
      'Ritmo acumulado, previsão de fecho e contagem do mês (em % e índice no open space)',
      'sim',
      'sim',
      '«▲ 8 % à frente do ritmo · hoje deve fechar em 104 % do objectivo»',
    ],
    ['Mapa de calor, funil das visitas, recordes e momentos do dia', 'sim', 'sim', '«Melhor terça-feira de sempre» · «Rota 1004 atingiu o objectivo»'],
    [
      'Posição das carrinhas no mapa, artigos, Pareto, quadrante das rotas, stock e pendentes',
      'sim',
      'nao',
      'só para a gestão: localização e dados comerciais',
    ],
    ['Valores de vendas em Kz', 'sim', 'nao', '«Vendas de hoje: 30 539 500 Kz»'],
    ['Classificação por rota com o nome do promotor', 'sim', 'nao', '«1.º Rota 1003 · Nome do promotor · 4,2 M Kz»'],
    ['Último lugar ou rotas abaixo do esperado', 'nao', 'nao', '«Rota 1007 a 40 % do objectivo» — corrige-se em privado'],
    ['Clientes, NIF, documentos e preços', 'nao', 'nao', 'nunca num ecrã de parede: dados pessoais e comerciais'],
    ['Caixa, faltas na carrinha, perdas, anulados e risco por pessoa', 'nao', 'nao', 'só no portal, com sessão e permissões'],
  ];
  const marca = (v) => pill(v === 'sim' ? '✔ ' + t('Pode') : '✕ ' + t('Não'), v === 'sim' ? 'p-ok' : 'p-bad');
  const tw = el('div', 'tw');
  const tab = el('table');
  const h = el('tr');
  [t('Informação'), t('Sala interna'), t('Open space'), t('Exemplo')].forEach((x) => h.append(el('th', null, x)));
  const th = el('thead');
  th.append(h);
  const tb = el('tbody');
  linhas.forEach(([info, interna, aberta, ex]) => {
    const tr = el('tr');
    const c1 = el('td', null, t(info));
    const c2 = el('td');
    c2.append(marca(interna));
    const c3 = el('td');
    c3.append(marca(aberta));
    tr.append(c1, c2, c3, el('td', 'note', t(ex)));
    tb.append(tr);
  });
  tab.append(th, tb);
  tw.append(tab);
  const c = el('div');
  c.append(
    tw,
    el(
      'p',
      'note',
      t(
        'O servidor aplica estas regras: um ecrã marcado como open space nunca recebe valores em Kz, nomes nem classificações, mesmo que alguém abra a ligação noutro sítio.',
      ),
    ),
  );
  return secBox(
    t('O que pode estar à vista em cada local'),
    t('Classificação da informação (ISO/IEC 27001), protecção de dados pessoais (Lei 22/11) e gestão visual com reconhecimento positivo.'),
    c,
  );
}
const ECRAS_SALA = [
  ['hoje', 'Vendas de hoje'],
  ['acumulado', 'Ritmo acumulado × objectivo (gráfico)'],
  ['previsao', 'Previsão de fecho do dia e do mês'],
  ['comparacoes', 'Comparações (dia, semana, mês, 7 e 30 dias, homólogos)'],
  ['ritmo', 'Ritmo do dia (vendas por hora)'],
  ['calor', 'Mapa de calor (dia da semana × hora)'],
  ['tendencia', 'Últimos 14 dias (gráfico)'],
  ['visitas', 'Visitas de hoje (plano e conversão)'],
  ['funil', 'Funil das visitas (plano, compra, GPS, acima da média)'],
  ['rotas', 'Rotas no terreno'],
  ['mapa', 'Rotas no mapa (posição das carrinhas)'],
  ['clientes', 'Clientes (hoje e mês)'],
  ['pareto', 'Concentração dos clientes (Pareto)'],
  ['artigos', 'Artigos que mais sobem e descem'],
  ['quadrante', 'Quadrante das rotas (compras × valor médio)'],
  ['stock', 'Cobertura do stock (dias de venda)'],
  ['operacao', 'Saúde da operação (o que está por fazer)'],
  ['chegadas', 'Chegadas à sala e sincronização dos telemóveis'],
  ['recordes', 'Recordes'],
  ['contagem', 'Contagem do mês (dias e ritmo necessário)'],
  ['momentos', 'Momentos do dia (celebração quando acontece)'],
  ['rank-hoje', 'Classificação de hoje'],
  ['rank-mes', 'Classificação do mês'],
  ['destaques', 'Destaques'],
];
/** Ecrãs do painel da TV: escolher e arrastar para mudar a ordem; tempo de cada ecrã. Grava na ligação (servidor). */
function configurarEcras(l) {
  janela(t('Ecrãs do painel'), t('Escolha os ecrãs e arraste-os para mudar a ordem.'), (b, ft, fechar) => {
    const cfg = l.config || { ecras: ECRAS_SALA.map((e) => e[0]), segundos: 20 };
    const local = el('select');
    local.setAttribute('aria-label', t('Onde fica o ecrã'));
    Object.entries(LOCAIS_SALA).forEach(([k, [nome]]) => {
      const o = el('option', null, t(nome));
      o.value = k;
      if (k === (l.local || 'interna')) o.selected = true;
      local.append(o);
    });
    const explica = el('p', 'note');
    const ul = el('ul', 'sala-ecras');
    const ordem = [...cfg.ecras, ...ECRAS_SALA.map((e) => e[0]).filter((k) => !cfg.ecras.includes(k))];
    ordem.forEach((k) => {
      const li = el('li');
      li.dataset.grafico = k;
      const cb = entrada('checkbox');
      cb.checked = cfg.ecras.includes(k);
      const lb = el('label');
      lb.append(cb, document.createTextNode(' ' + t((ECRAS_SALA.find((e) => e[0] === k) || [k, k])[1])));
      li.append(
        el('span', 'sala-pega', '⋮⋮'),
        lb,
        el('small', 'note sala-onde', ECRAS_ABERTA.includes(k) ? t('Sala interna e open space') : t('Só sala interna')),
      );
      ul.append(li);
    });
    arrastavel(ul, () => {});
    // num espaço aberto as classificações ficam desligadas (o servidor também as recusa)
    const aplicarLocal = () => {
      explica.textContent = t(LOCAIS_SALA[local.value][1]);
      [...ul.children].forEach((li) => {
        const cb = li.querySelector('input');
        const proibido = local.value === 'aberta' && !ECRAS_ABERTA.includes(li.dataset.grafico);
        cb.disabled = proibido;
        if (proibido) cb.checked = false;
        li.classList.toggle('sala-off', proibido);
      });
    };
    local.addEventListener('change', aplicarLocal);
    aplicarLocal();
    const seg = el('select');
    [10, 20, 30, 60].forEach((s) => {
      const o = el('option', null, String(s));
      o.value = s;
      if (s === cfg.segundos) o.selected = true;
      seg.append(o);
    });
    const erro = msgErro();
    b.append(campo(t('Onde fica o ecrã'), local), explica, ul, campo(t('Segundos por ecrã'), seg), erro);
    ft.append(
      botao(t('Cancelar'), 'sec', fechar),
      botao(t('Guardar'), null, () => {
        const ecras = [...ul.children].filter((li) => li.querySelector('input').checked).map((li) => li.dataset.grafico);
        postJson('sala/ligacoes', { accao: 'configurar', id: l.id, ecras, segundos: +seg.value, local: local.value }).then((r) => {
          if (!r.ok) {
            erro.hidden = false;
            erro.textContent = t(r.erro || 'Não foi possível gravar.');
            return;
          }
          DADOS.sala = { ok: true, ligacoes: r.ligacoes };
          fechar();
          toast(t('Ecrãs guardados: o ecrã muda na próxima actualização (até 2 minutos).'), 6000);
          draw();
        });
      }),
    );
  });
}
function criarLigacao() {
  janela(t('Criar ligação para um ecrã'), t('Indique onde fica o ecrã.'), (b, ft, fechar) => {
    const nome = entrada('text', '', { maxLength: 60, placeholder: t('Sala comercial · Viana') });
    const erro = msgErro();
    const escolha = el('fieldset', 'sala-local');
    escolha.append(el('legend', null, t('Quem pode ver o ecrã?')));
    Object.entries(LOCAIS_SALA).forEach(([k, [n, txt]]) => {
      const r = entrada('radio');
      r.name = 'sala-local';
      r.value = k;
      const lb = el('label', 'sala-local-op');
      const tx = el('span');
      tx.append(el('b', null, t(n)), el('small', 'note', t(txt)));
      lb.append(r, tx);
      escolha.append(lb);
    });
    b.append(campo(t('Local do ecrã'), nome), escolha, erro);
    ft.append(
      botao(t('Cancelar'), 'sec', fechar),
      botao(t('Criar'), null, () => {
        const sel = escolha.querySelector('input:checked');
        if (!sel) {
          erro.hidden = false;
          erro.textContent = t('Escolha quem pode ver o ecrã: sala interna ou open space.');
          return;
        }
        postJson('sala/ligacoes', { accao: 'criar', nome: nome.value, local: sel.value }).then((r) => {
          if (!r.ok) {
            erro.hidden = false;
            erro.textContent = t(r.erro || 'Não foi possível criar.');
            return;
          }
          DADOS.sala = { ok: true, ligacoes: r.ligacoes };
          const url = new URL('../sala/#t=' + r.codigo, location.href).href;
          b.replaceChildren(
            el('p', null, t('Abra este endereço no navegador do ecrã (em ecrã inteiro). Por segurança, só é mostrado agora: copie-o já.')),
            el('code', 'gst-url', url),
          );
          ft.replaceChildren(
            botao(t('Copiar'), 'sec', () => copiar(url)),
            botao(t('Fechar'), null, () => {
              fechar();
              draw();
            }),
          );
        });
      }),
    );
  });
}

// ───────────────────────── Painel de gestão RTM ─────────────────────────

/** Detalhe de uma classe da curva ABC: os seus clientes ou artigos (os de maior valor), com a ficha de cada um. */
function abrirClasseAbc(titulo, classe, x, tipo) {
  const lista = ((x.itens || {})[classe] || []).slice();
  const nomeClasse = { A: t('A (80% do valor)'), B: t('B (seguintes 15%)'), C: t('C (últimos 5%)') }[classe];
  janela(titulo + ' · ' + nomeClasse, t('{n} na classe; os de maior valor primeiro.', { n: n0(x.classes[classe]) }), (b, ft, fechar) => {
    if (!lista.length) b.append(el('p', 'note', t('O detalhe desta classe chega com a próxima actualização dos dados.')));
    else {
      const tw = el('div', 'tw');
      const tab = el('table');
      const h = el('tr');
      ['#', tipo === 'cliente' ? t('Cliente') : t('Artigo'), ...(tipo === 'cliente' ? [t('Rota')] : []), t('Valor'), t('% do total'), t('% acumulada')].forEach(
        (c, i) => h.append(el('th', i >= (tipo === 'cliente' ? 3 : 2) || i === 0 ? 'n' : null, c)),
      );
      const th = el('thead');
      th.append(h);
      const tb = el('tbody');
      lista.forEach((it, i) => {
        const tr = el('tr');
        const nome = el('td');
        nome.append(
          tipo === 'cliente'
            ? celulaLigada('cliente', it.nome, { cliente_codigo: it.codigo, cliente: it.nome, rota: it.rota })
            : celulaLigada('artigo', it.nome, { codigo: it.codigo, artigo: it.nome }),
        );
        tr.append(el('td', 'n', n0(i + 1)), nome);
        if (tipo === 'cliente') {
          const r = el('td');
          r.append(celulaLigada('rota', it.rota, { rota: it.rota }));
          tr.append(r);
        }
        tr.append(el('td', 'n', kz(it.valor)), el('td', 'n', pct(it.pct)), el('td', 'n', pct(it.acumulado)));
        tb.append(tr);
      });
      tab.append(th, tb);
      tw.append(tab);
      b.append(tw);
      if (x.classes[classe] > lista.length)
        b.append(el('p', 'note', t('A mostrar os {a} de maior valor de {b}.', { a: n0(lista.length), b: n0(x.classes[classe]) })));
    }
    ft.append(botao(t('Fechar'), null, fechar));
  }).classList.add('dlg-largo');
}

const DIAS_CURTOS = ['seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'];
const MESES_CURTOS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
const nomeRota = (r) => r.rota + (r.promotor ? ' · ' + r.promotor : '');

/**
 * Gráficos do painel de gestão RTM (dados de v1/painel), para a grelha do Início: {tendencia, balas, ponte, abcClientes,
 * abcArtigos, distribuicao, dispersao, calor, carteira}. Cada cartão traz a vista em tabela e desenha-se à altura do quadro.
 */
export function cartoesGestao(d) {
  const grid = el('div');
  const tend = d.tendencia;
  grid.append(
    cartaoGrafico({
      titulo: t('Tendência diária e previsão'),
      sub: t('últimos 90 dias, média móvel de 7 dias e previsão com intervalo de 80%'),
      desenhar: (w, h) =>
        comLegenda(grafTendencia(w, { dias: tend.dias, previsao: tend.previsao, rot: (x) => dl(x).slice(0, 5), altura: h && h - 20 }), [
          ['#8fb4ff', t('vendas do dia')],
          ['#3987e5', t('média móvel de 7 dias')],
          ['#3987e5', t('previsão'), true],
        ]),
      colunas: [
        [t('Dia'), 0],
        [t('Vendas'), 1],
        [t('Média móvel de 7 dias'), 1],
      ],
      linhas: tend.dias.map((x) => [dl(x.dia), kz(x.vendas), x.mm7 == null ? '—' : kz(x.mm7)]),
      abrirLinha: (i) => abrirPeriodo(tend.dias[i].dia),
    }),
  );
  const mesRotas = d.mes.rotas;
  grid.append(
    cartaoGrafico({
      titulo: t('Mês até hoje por rota'),
      sub: t('barra: este mês; traço: mesmos dias do mês anterior'),
      desenhar: (w, h) =>
        grafBalas(w, {
          altura: h,
          itens: mesRotas.map((r) => ({ rotulo: w < 520 ? r.rota : nomeRota(r), actual: r.actual, anterior: r.anterior, variacao: r.variacao })),
        }),
      colunas: [
        [t('Rota'), 0],
        [t('Mês até hoje'), 1],
        [t('Mês anterior'), 1],
        [t('Variação'), 1],
      ],
      linhas: mesRotas.map((r) => [nomeRota(r), kz(r.actual), kz(r.anterior), sinal(r.variacao)]),
      abrirLinha: (i) => abrirLig('rota', { rota: String(mesRotas[i].rota), promotor: mesRotas[i].promotor || '' }),
    }),
    cartaoGrafico({
      titulo: t('De onde vem a variação do mês'),
      sub: t('contributo de cada rota, dos mesmos dias do mês anterior até hoje'),
      desenhar: (w, h) =>
        grafCascata(w, {
          altura: h,
          inicio: d.mes.total.anterior,
          fim: d.mes.total.actual,
          passos: [...mesRotas].sort((a, b) => b.contributo - a.contributo).map((r) => ({ rot: r.rota, valor: r.contributo })),
          rotInicio: t('Anterior'),
          rotFim: t('Actual'),
        }),
      colunas: [
        [t('Rota'), 0],
        [t('Contributo'), 1],
      ],
      linhas: mesRotas.map((r) => [nomeRota(r), kz(r.contributo)]),
      abrirLinha: (i) => abrirLig('rota', { rota: String(mesRotas[i].rota), promotor: mesRotas[i].promotor || '' }),
    }),
  );
  const abc = (titulo, sub, x, nome, tipo) =>
    cartaoGrafico({
      titulo,
      sub,
      desenhar: (w, h) => grafAbc(w, { curva: x.curva, ate80: x.ate80, n: x.n, nome, altura: h }),
      colunas: [
        [t('Classe'), 0],
        [t('Quantidade'), 1],
      ],
      linhas: [
        [t('A (80% do valor)'), n0(x.classes.A)],
        [t('B (seguintes 15%)'), n0(x.classes.B)],
        [t('C (últimos 5%)'), n0(x.classes.C)],
      ],
      // detalhe da classe: os clientes (ou artigos) que a compõem, com a ficha de cada um
      abrirLinha: (i) => abrirClasseAbc(titulo, ['A', 'B', 'C'][i], x, tipo),
    });
  grid.append(
    abc(t('Curva ABC de clientes'), t('últimos 90 dias: quantos clientes fazem 80% das vendas'), d.abc_clientes, t('clientes'), 'cliente'),
    abc(t('Curva ABC de artigos'), t('últimos 90 dias: quantos artigos fazem 80% das vendas'), d.abc_artigos, t('artigos'), 'artigo'),
    cartaoGrafico({
      titulo: t('Distribuição numérica'),
      sub: t('% dos clientes activos (30 dias) que compraram cada artigo; os 15 artigos de maior venda'),
      desenhar: (w, h) =>
        grafBarras(w, {
          itens: d.distribuicao.slice(0, h ? Math.max(1, Math.floor((h - 8) / (w < 480 ? 32 : 22))) : 15).map((a) => ({
            rot: tArtigo(a.nome),
            valor: (a.distribuicao || 0) * 100,
            titulo: tArtigo(a.nome),
            linhas: [
              [n0((a.distribuicao || 0) * 100) + '%', t('dos clientes activos')],
              [n0(a.clientes), t('clientes')],
            ],
          })),
          fmt: (v) => n0(v) + '%',
          altura: h,
        }),
      colunas: [
        [t('Artigo'), 0],
        [t('Clientes'), 1],
        [t('Distribuição numérica'), 1],
      ],
      linhas: d.distribuicao.map((a) => [tArtigo(a.nome), n0(a.clientes), a.distribuicao == null ? '—' : n0(a.distribuicao * 100) + '%']),
      abrirLinha: (i) => abrirLig('artigo', { codigo: String(d.distribuicao[i].codigo), artigo: d.distribuicao[i].nome }),
    }),
    cartaoGrafico({
      titulo: t('Valor médio e artigos por compra'),
      sub: t('por rota, últimos 30 dias; tamanho: vendas; linhas tracejadas: médias'),
      desenhar: (w, h) => {
        // cada rota com a sua cor (e anel da 8.ª em diante), legenda por baixo e rótulo ao lado da bola
        const todas = d.por_rota.map((r) => r.rota);
        const itens = d.por_rota
          .filter((r) => r.artigos_por_compra != null)
          .map((r) => ({ x: r.valor_medio, y: r.artigos_por_compra, tamanho: r.vendas, rot: r.rota, titulo: nomeRota(r), ...corRota(r.rota, todas) }));
        const legenda = itens.length > 1 && (!h || h >= 170);
        const g = grafDispersao(w, {
          altura: h && legenda ? h - (itens.length > 6 ? 46 : 24) : h,
          itens,
          fx: kzc,
          fy: (v) => n1(v),
          rx: t('valor médio por compra (Kz)'),
          ry: t('artigos por compra'),
        });
        return legenda
          ? comLegenda(
              g,
              [...itens].sort((a, b) => String(a.rot).localeCompare(String(b.rot), 'pt', { numeric: true })).map((x) => [x.cor, x.rot, false, x.anel]),
            )
          : g;
      },
      colunas: [
        [t('Rota'), 0],
        [t('Compras'), 1],
        [t('Valor médio por compra'), 1],
        [t('Artigos por compra'), 1],
      ],
      linhas: d.por_rota.map((r) => [nomeRota(r), n0(r.compras), kz(r.valor_medio), r.artigos_por_compra == null ? '—' : n1(r.artigos_por_compra)]),
      abrirLinha: (i) => abrirLig('rota', { rota: String(d.por_rota[i].rota), promotor: d.por_rota[i].promotor || '' }),
    }),
    cartaoGrafico({
      titulo: t('Vendas por rota e dia da semana'),
      sub: t('média por dia nas últimas 8 semanas'),
      desenhar: (w, h) =>
        grafCalor(w, {
          altura: h,
          // a rota com o nome do promotor (do Pinguim BO, sempre actualizado); em ecrã estreito, só o número
          linhas: d.calor.map((l) => ({
            rot: w < 520 ? l.rota : nomeRota(l),
            titulo: nomeRota(l),
            valores: l.dias,
            abrir: () => abrirLig('rota', { rota: String(l.rota), promotor: l.promotor || '' }),
          })),
          colunas: DIAS_CURTOS.map((x) => t(x)),
          fmt: kzc,
        }),
      colunas: [[t('Rota'), 0], ...DIAS_CURTOS.map((x) => [t(x), 1])],
      linhas: d.calor.map((l) => [nomeRota(l), ...l.dias.map(kz)]),
      abrirLinha: (i) => abrirLig('rota', { rota: String(d.calor[i].rota), promotor: d.calor[i].promotor || '' }),
    }),
    cartaoGrafico({
      titulo: t('Carteira de clientes por mês'),
      sub: t('activos, novos (sem compras nos 3 meses anteriores) e perdidos (compraram no mês anterior)'),
      desenhar: (w, h) =>
        comLegenda(
          grafAgrupadas(w, {
            altura: h && h - 20,
            cats: d.carteira.map((m) => t(MESES_CURTOS[+m.mes.slice(5) - 1]) + (m.incompleto ? '*' : '')),
            series: [
              { nome: t('activos'), cor: '#3987e5', valores: d.carteira.map((m) => m.activos) },
              { nome: t('novos'), cor: '#8fb4ff', valores: d.carteira.map((m) => m.novos) },
              { nome: t('perdidos'), cor: '#d03b3b', valores: d.carteira.map((m) => m.perdidos || 0) },
            ],
          }),
          [
            ['#3987e5', t('activos')],
            ['#8fb4ff', t('novos')],
            ['#d03b3b', t('perdidos')],
          ],
        ),
      colunas: [
        [t('Mês'), 0],
        [t('Activos'), 1],
        [t('Novos'), 1],
        [t('Perdidos'), 1],
      ],
      linhas: d.carteira.map((m) => [m.mes, n0(m.activos), n0(m.novos), m.perdidos == null ? '—' : n0(m.perdidos)]),
      // o mês: a carteira de clientes desse mês
      abrirLinha: (i) => {
        const m = d.carteira[i].mes;
        const fim = new Date(Date.UTC(+m.slice(0, 4), +m.slice(5, 7), 0)).toISOString().slice(0, 10);
        abrirPeriodo(m + '-01', fim < d.hoje ? fim : d.hoje, 'clientes');
      },
    }),
  );
  const chaves = ['tendencia', 'balas', 'ponte', 'abcClientes', 'abcArtigos', 'distribuicao', 'dispersao', 'calor', 'carteira'];
  const m = {};
  [...grid.children].forEach((c, i) => {
    c.dataset.grafico = chaves[i];
    m[chaves[i]] = c;
  });
  m.tendencia.classList.add('dupla');
  return m;
}
