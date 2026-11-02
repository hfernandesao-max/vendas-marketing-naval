// App de gestão «Naval Gestão» (gestao/index.html): telemóvel, só de leitura. Separadores Hoje, Rotas, Alertas e
// Assistente; os dados vêm da API central com o âmbito da conta (v1/sessao, v1/indicadores, v1/assistente) e
// actualizam-se a cada 5 minutos enquanto a app está aberta. Nada fica guardado no telefone.
import { apiFetch, apiJson } from './api.js';
import { dl, n0, setFmt } from './formatos.js';
import { sinal, textoAlerta, textoRico } from './gestao-textos.js';
import { LANG, mudarIdioma, t } from './i18n/i18n.js';
import { el, svg } from './util.js';

const $ = (id) => document.getElementById(id);
// valores redondos no telemóvel (sem casas decimais)
const kz = (v) => (v == null || isNaN(v) ? '—' : n0(Math.round(v)) + ' Kz');
// POST com o código CSRF da sessão (sem os módulos do portal completo)
const postJson = (rota, corpo) =>
  apiFetch(rota, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...corpo, csrf: E.sessao && E.sessao.csrf }) }).then(
    (r) => r.json().catch(() => ({ ok: false, erro: 'Sem resposta do servidor.' })),
    () => ({ ok: false, erro: 'Sem resposta do servidor.' }),
  );
const E = { sessao: null, ind: null, erro: null, sep: 'hoje', conversa: [], aPensar: false };
const ICONE = { hoje: 'home', rotas: 'trophy', alertas: 'sino', assistente: 'ia' };

const variacao = (v, txt) => {
  const s = el('span', v == null ? '' : v < -0.02 ? 'desce' : v > 0.02 ? 'sobe' : '', sinal(v));
  const w = el('span', 'ag-sub');
  w.append(s, document.createTextNode(' ' + txt));
  return w;
};
const barra = (frac) => {
  const b = el('div', 'ag-barra'),
    i = el('i');
  i.style.width = Math.max(0, Math.min(100, Math.round((frac || 0) * 100))) + '%';
  b.append(i);
  return b;
};
const cartao = (...filhos) => {
  const c = el('section', 'ag-cartao');
  c.append(...filhos);
  return c;
};

function hoje() {
  const d = E.ind;
  const h = d.hoje;
  const prog = h.previsto ? h.total / h.previsto : null;
  const out = [
    cartao(
      el('div', 'ag-rotulo', t('Vendas de hoje até agora')),
      el('div', 'ag-grande', kz(h.total)),
      ...(h.previsto ? [barra(prog)] : []),
      el(
        'div',
        'ag-sub',
        h.previsto
          ? t('{p} da previsão do dia ({v}) · {n} documentos', { p: n0(Math.round(prog * 100)) + '%', v: kz(h.previsto), n: n0(h.documentos) })
          : t('{n} documentos', { n: n0(h.documentos) }),
      ),
    ),
  ];
  const g = el('div', 'ag-grelha');
  g.append(
    cartao(
      el('div', 'ag-rotulo', t('Ontem ({d})', { d: dl(d.ontem.dia) })),
      el('div', 'ag-medio', kz(d.ontem.total)),
      variacao(d.ontem.variacao, t('face ao habitual')),
    ),
    cartao(el('div', 'ag-rotulo', t('Semana até hoje')), el('div', 'ag-medio', kz(d.semana.total)), variacao(d.semana.variacao, t('face à semana passada'))),
    cartao(el('div', 'ag-rotulo', t('Mês até hoje')), el('div', 'ag-medio', kz(d.mes.total)), variacao(d.mes.variacao, t('face ao mês passado'))),
  );
  out.push(g);
  if (d.alertas && d.alertas.critico + d.alertas.aviso) {
    const a = cartao(
      el('div', 'ag-rotulo', t('Alertas')),
      el('div', 'ag-medio', t('{c} críticos · {a} avisos', { c: n0(d.alertas.critico), a: n0(d.alertas.aviso) })),
    );
    const ir = el('button', 'ag-botao sec', t('Ver alertas'));
    ir.type = 'button';
    ir.addEventListener('click', () => mudar('alertas'));
    a.append(ir);
    out.push(a);
  }
  return out;
}

function rotas() {
  const rs = E.ind.rotas;
  if (!rs.length) return [el('p', 'ag-vazio', t('Sem vendas no período.'))];
  const max = Math.max(...rs.map((r) => r.mes), 1);
  const c = cartao(el('div', 'ag-rotulo', t('Classificação do mês (vendas até hoje)')));
  rs.forEach((r, i) => {
    const l = el('div', 'ag-rota');
    const nome = el('div');
    nome.append(el('strong', null, r.rota), document.createTextNode(r.promotor ? ' · ' + r.promotor : ''));
    if (r.sequencia >= 3) nome.append(el('span', 'ag-selo', t('{n} dias acima do habitual', { n: r.sequencia })));
    const info = el('div', 'ag-sub');
    info.append(document.createTextNode(t('hoje {v}', { v: kz(r.hoje) }) + ' · '), variacao(r.variacao_mes, t('face ao mês passado')));
    nome.append(info);
    l.append(el('span', 'ag-pos', String(i + 1)), nome, el('span', 'ag-valor', kz(r.mes)), barra(r.mes / max));
    c.append(l);
  });
  return [c];
}

function alertas() {
  const a = E.ind.alertas;
  if (!a) return [el('p', 'ag-vazio', t('O seu perfil não tem acesso aos alertas.'))];
  const out = [cartao(el('div', 'ag-medio', t('{c} críticos · {a} avisos · {i} destaques', { c: n0(a.critico), a: n0(a.aviso), i: n0(a.info) })))];
  if (!a.principais.length) out.push(el('p', 'ag-vazio', t('Sem alertas: tudo dentro do habitual.')));
  a.principais.forEach((x) => {
    const [titulo, detalhe] = textoAlerta(x);
    const c = cartao(el('strong', null, titulo), el('p', null, detalhe || ''));
    c.classList.add('ag-alerta', x.gravidade);
    out.push(c);
  });
  const ver = el('a', 'ag-ligacao', t('Ver todos os alertas no portal'));
  ver.href = '../reports/#alertas';
  out.push(ver);
  return out;
}

const SUGESTOES = [
  'Quanto vendemos ontem face ao habitual?',
  'Que rotas estão a crescer e quais estão a cair este mês?',
  'Há algum alerta crítico hoje? O que devo fazer primeiro?',
];
function perguntar(p) {
  p = String(p || '').trim();
  if (!p || E.aPensar) return;
  const historico = E.conversa.filter((m) => !m.erro).map((m) => ({ papel: m.papel, texto: m.texto }));
  E.conversa.push({ papel: 'pessoa', texto: p });
  E.aPensar = true;
  desenhar();
  postJson('assistente', { pergunta: p, historico, idioma: LANG }).then((r) => {
    E.aPensar = false;
    E.conversa.push(r.ok ? { papel: 'assistente', texto: r.resposta } : { papel: 'assistente', texto: t(r.erro || 'Sem resposta do servidor.'), erro: true });
    desenhar();
  });
}
function assistente() {
  const out = [];
  if (!E.conversa.length) {
    const s = el('div', 'ag-sug');
    SUGESTOES.forEach((x) => {
      const b = el('button', 'ag-botao sec', t(x));
      b.type = 'button';
      b.addEventListener('click', () => perguntar(t(x)));
      s.append(b);
    });
    out.push(
      cartao(
        el(
          'div',
          'ag-sub',
          t('As respostas usam só os dados do portal a que tem acesso. Confirme sempre os números importantes nas páginas indicadas como fonte.'),
        ),
        s,
      ),
    );
  }
  E.conversa.forEach((m) => {
    const b = el('div', 'ag-msg ' + (m.papel === 'pessoa' ? 'eu' : 'ia'));
    if (m.papel === 'pessoa') b.append(el('p', null, m.texto));
    else b.append(textoRico(m.texto));
    out.push(b);
  });
  if (E.aPensar) out.push(el('div', 'ag-msg ia', t('A analisar os dados…')));
  const f = el('form', 'ag-form');
  const ta = el('textarea');
  ta.rows = 1;
  ta.maxLength = 1500;
  ta.placeholder = t('Escreva a pergunta');
  ta.setAttribute('aria-label', t('Pergunta ao assistente'));
  ta.disabled = E.aPensar;
  const b = el('button', 'ag-botao', t('Perguntar'));
  b.type = 'submit';
  b.disabled = E.aPensar;
  f.addEventListener('submit', (e) => {
    e.preventDefault();
    perguntar(ta.value);
  });
  f.append(ta, b);
  out.push(f);
  return out;
}

const SEPARADORES = [
  ['hoje', 'Hoje', hoje],
  ['rotas', 'Rotas', rotas],
  ['alertas', 'Alertas', alertas],
  ['assistente', 'Assistente', assistente],
];
const disponiveis = () => SEPARADORES.filter(([id]) => id !== 'assistente' || (E.sessao && (E.sessao.permissoes || {}).assistente));

function mudar(id) {
  E.sep = id;
  desenhar();
  window.scrollTo(0, 0);
}
function desenhar() {
  const c = $('ag-conteudo');
  const nav = $('ag-separadores');
  nav.replaceChildren(
    ...disponiveis().map(([id, nome]) => {
      const b = el('button');
      b.type = 'button';
      b.innerHTML = svg(ICONE[id]); // nosemgrep: insecure-innerhtml -- ícone SVG estático do próprio código
      b.append(el('span', null, t(nome)));
      if (id === E.sep) b.setAttribute('aria-current', 'page');
      b.addEventListener('click', () => mudar(id));
      return b;
    }),
  );
  $('ag-actualizar').setAttribute('aria-label', t('Actualizar'));
  if (E.erro) {
    c.replaceChildren(el('p', 'ag-vazio', t(E.erro)));
    return;
  }
  if (!E.ind) {
    c.replaceChildren(el('p', 'ag-vazio', t('A carregar…')));
    return;
  }
  $('ag-hora').textContent = t('actualizado às {h}', { h: String(E.ind.hora).slice(11, 16) });
  const sep = disponiveis().find(([id]) => id === E.sep) || disponiveis()[0];
  c.replaceChildren(...sep[2]());
}

function carregar(fresco) {
  return apiJson('indicadores' + (fresco ? '?fresco=1' : '')).then((d) => {
    if (d && d.ok) {
      E.ind = d;
      E.erro = null;
    } else E.erro = (d && d.erro) || 'Não foi possível ler os dados.';
    desenhar();
  });
}

apiJson('sessao').then((s) => {
  if (!s || !s.login) {
    location.href = '../geo/login.php?redirect=../gestao/';
    return;
  }
  E.sessao = s;
  const l = s.idioma && s.idioma !== LANG ? mudarIdioma(s.idioma).then(setFmt) : Promise.resolve();
  l.then(() => {
    document.documentElement.lang = { pt: 'pt-PT', en: 'en', ti: 'ti' }[LANG] || 'pt-PT';
    desenhar();
    carregar();
  });
});
$('ag-actualizar').addEventListener('click', () => carregar(true));
// actualiza a cada 5 minutos enquanto a app está visível
setInterval(() => {
  if (document.visibilityState === 'visible' && E.ind) carregar();
}, 300000);
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible' && E.ind && Date.now() - new Date(E.ind.hora.replace(' ', 'T')).getTime() > 300000) carregar();
});
