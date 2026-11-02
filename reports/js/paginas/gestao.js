// Gestão (análise e IA): Alertas, Previsão, Assistente e Painel da sala comercial.
// Os números vêm sempre do servidor (nucleo/api/v1/analise.php, assistente.php, sala.php), com o âmbito de quem pede;
// os textos dos alertas compõem-se aqui a partir dos parâmetros, para aparecerem na língua escolhida.
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { pill, secBox, tiles } from '../componentes/blocos.js';
import { cartaoGrafico, grafColunas } from '../componentes/graficos.js';
import { botao, campo, copiar, entrada, janela, msgErro } from '../componentes/janelas.js';
import { dl, ds, kz, n0 } from '../formatos.js';
import { LANG, t } from '../i18n/i18n.js';
import { atual, podeVer } from '../perfil.js';
import { postJson } from '../servidor.js';
import { el, toast } from '../util.js';
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

R.alertas = (pg) => {
  const d = esperar(pg, 'alertas', 'alertas', 'alertas');
  if (!d) return;
  const n = (g) => d.alertas.filter((a) => a.gravidade === g).length;
  pg.append(
    tiles([
      { l: t('Críticos'), v: n0(n('critico')), s: t('a tratar hoje'), tone: n('critico') ? 'bad' : null },
      { l: t('Avisos'), v: n0(n('aviso')), s: t('a acompanhar') },
      { l: t('Destaques'), v: n0(n('info')), s: t('boas notícias a partilhar') },
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
  barra.append(actualizarBtn('alertas', 'alertas', 'alertas'));
  const lista = el('div', 'gst-lista');
  const vis = d.alertas.filter((a) => !filtroGrav || a.gravidade === filtroGrav);
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

R.previsao = (pg) => {
  const d = esperar(pg, 'previsao', 'previsao', 'previsao');
  if (!d) return;
  const tot = d.total;
  if (!tot) {
    pg.append(el('div', 'glass empty', t('Ainda não há histórico suficiente para prever (são precisas pelo menos 3 semanas de vendas).')));
    return;
  }
  const q = tot.qualidade || {};
  pg.append(
    tiles([
      { l: t('Previsão dos próximos 7 dias'), v: kz(tot.semana), s: t('de {de} a {ate}', { de: dl(d.dias[0]), ate: dl(d.dias[d.dias.length - 1]) }) },
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
  const itens = [...Object.entries(tot.historico).map(([dia, v]) => ({ rot: ds(dia), valor: v, titulo: ds(dia), linhas: [[kz(v), t('real')]] }))].slice(-14);
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
];
const NOMES_FONTE = {
  vendas_resumo: 'Vendas',
  comparar_periodos: 'Comparação de períodos',
  alertas: 'Alertas',
  previsao: 'Previsão',
  plano_cargas: 'Plano de cargas',
  agt_estado: 'Comunicação AGT',
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
      el('h2', null, t('Pergunte sobre as vendas, as rotas, os clientes, os alertas e a previsão')),
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
      el(
        'span',
        'note',
        l.revogado
          ? t('revogada em {d}', { d: dl(l.revogado) })
          : t('criada em {d} · último acesso: {u}', { d: dl(l.criado), u: l.ultimo_acesso ? dl(l.ultimo_acesso) + ' ' + l.ultimo_acesso.slice(11, 16) : '—' }),
      ),
    );
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
};
function criarLigacao() {
  janela(t('Criar ligação para um ecrã'), t('Indique onde fica o ecrã.'), (b, ft, fechar) => {
    const nome = entrada('text', '', { maxLength: 60, placeholder: t('Sala comercial · Viana') });
    const erro = msgErro();
    b.append(campo(t('Local do ecrã'), nome), erro);
    ft.append(
      botao(t('Cancelar'), 'sec', fechar),
      botao(t('Criar'), null, () => {
        postJson('sala/ligacoes', { accao: 'criar', nome: nome.value }).then((r) => {
          if (!r.ok) {
            erro.hidden = false;
            erro.textContent = t(r.erro || 'Não foi possível criar.');
            return;
          }
          DADOS.sala = { ok: true, ligacoes: r.ligacoes };
          const url = new URL('../sala/?t=' + r.codigo, location.href).href;
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
