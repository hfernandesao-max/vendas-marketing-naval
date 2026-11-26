// Consulta na app de gestão «RTM Gestão»: vendas (até aos últimos 12 meses), documentos com as linhas de artigos,
// clientes, artigos e cobranças — o que se consulta no portal, no telemóvel. Só leitura.
//  - os dados vêm da mesma API do portal (v1/vendas), com o âmbito da conta (rotas, NIF), e cada secção só aparece com
//    o recurso correspondente na matriz de acessos (ISO/IEC 27001 A.5.15 e A.8.3);
//  - ficam só na memória enquanto a app está aberta (nada no telefone; ISO/IEC 27701, minimização);
//  - mesmas regras do portal: vendas sem IVA (desempenho), recebido com IVA (dinheiro), notas de crédito a abater,
//    substituições de facturas sem venda nova.
import { apiJson } from './api.js';
import { dl, n0 } from './formatos.js';
import { t } from './i18n/i18n.js';
import { eNC, pendenteDe, recebidoDe } from './meios.js';
import { el } from './util.js';

let H = null; // peças da app (app-gestao.js): desenhar, cartões, gráficos, folha
export function ligarConsulta(h) {
  H = h;
}

export const C = {
  cache: new Map(), // «de|ate|linhas» → {estado, docs, artigos, em}
  pv: 'mes', // período da secção Vendas
  pa: 'mes', // período da secção Artigos
  dia: '', // dia da secção Documentos
};
const MAX_CACHE = 6;
const pad = (n) => String(n).padStart(2, '0');
const iso = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
export const hojeIso = () => (H && H.E.ind && H.E.ind.hoje && H.E.ind.hoje.dia) || iso(new Date());
const maisDias = (s, n) => {
  const d = new Date(s + 'T12:00:00');
  d.setDate(d.getDate() + n);
  return iso(d);
};
const MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
const liq = (v) => (v.total_sem_iva != null ? +v.total_sem_iva : +v.total || 0);

/** Intervalo de um período: hoje, semana (ISO 8601, de segunda), mês ou 12 meses (365 dias). */
export function intervalo(p) {
  const h = hojeIso();
  if (p === 'hoje') return [h, h];
  if (p === 'semana') {
    const ds = (new Date(h + 'T12:00:00').getDay() + 6) % 7;
    return [maisDias(h, -ds), h];
  }
  if (p === 'ano') return [maisDias(h, -364), h];
  return [h.slice(0, 8) + '01', h];
}

// Validade na memória: com o dia de hoje, 2 minutos (como a cache do servidor); dias já fechados, 1 hora.
const validade = (ate) => (ate >= hojeIso() ? 120000 : 3600000);
const fresco = (c, ate) => c && (c.estado === 'a-ler' || (c.estado === 'ok' && Date.now() - c.em < validade(ate)));

/** Lê um período da API (uma vez; pedidos repetidos partilham a mesma leitura). fundo: antecipação, sem redesenhar. */
function ler(de, ate, linhas, fundo) {
  const k = de + '|' + ate + '|' + (linhas ? 1 : 0);
  let c = C.cache.get(k);
  if (fresco(c, ate)) {
    if (!fundo) c.ecra = true;
    return c;
  }
  // as linhas de um período incluem os documentos: serve também quem só precisa destes
  if (!linhas) {
    const l = C.cache.get(de + '|' + ate + '|1');
    if (l && l.estado === 'ok' && fresco(l, ate)) {
      if (!fundo) l.ecra = true;
      return l;
    }
  }
  c = { estado: 'a-ler', docs: [], artigos: [], em: Date.now(), erro: '', ecra: !fundo };
  C.cache.set(k, c);
  while (C.cache.size > MAX_CACHE) C.cache.delete(C.cache.keys().next().value);
  const f = (x) => x.replace(/-/g, '');
  const fim = () => {
    c.em = Date.now();
    // só redesenha se o ecrã estiver à espera destes dados (a antecipação não mexe no que se está a ver)
    if (c.ecra) H.desenhar();
    H.aoCarregar();
  };
  c.p = apiJson('vendas?start_date=' + f(de) + '&end_date=' + f(ate) + (linhas ? '&include_items=1' : '')).then(
    (d) => {
      const docs = Array.isArray(d) ? d : d && Array.isArray(d.vendas) ? d.vendas : null;
      if (!docs) {
        c.estado = 'erro';
        c.erro = (d && d.erro) || 'Não foi possível ler os dados.';
      } else {
        // substituições de facturas de dias anteriores: sem venda nova nem dinheiro (como no portal)
        c.docs = docs.filter((v) => !v.substituicao);
        c.artigos = (d && Array.isArray(d.artigos) && d.artigos) || [];
        c.estado = 'ok';
      }
      fim();
    },
    () => {
      c.estado = 'erro';
      c.erro = 'Não foi possível ler os dados.';
      fim();
    },
  );
  return c;
}

/**
 * Documentos (e, se pedidas, as linhas) de um período, só na memória. Um período que chega a hoje lê-se em duas partes:
 * os dias fechados (guardados 1 hora, aqui e no servidor) e o dia de hoje (2 minutos) — os 12 meses não se voltam a
 * pedir inteiros a cada actualização.
 */
const juntos = new Map();
export function dados(de, ate, linhas, fundo) {
  const h = hojeIso();
  if (!(de < h && ate >= h)) return ler(de, ate, linhas, fundo);
  const a = ler(de, maisDias(h, -1), linhas, fundo);
  const b = ler(h, h, linhas, fundo);
  if (a.estado === 'erro') return a;
  if (b.estado === 'erro') return b;
  if (a.estado !== 'ok' || b.estado !== 'ok') return { estado: 'a-ler', docs: [], artigos: [], p: Promise.all([a.p, b.p]) };
  const k = de + '|' + ate + '|' + (linhas ? 1 : 0);
  let j = juntos.get(k);
  if (!j || j.a !== a || j.b !== b || j.ea !== a.em || j.eb !== b.em) {
    j = { estado: 'ok', docs: a.docs.concat(b.docs), artigos: a.artigos.concat(b.artigos), a, b, ea: a.em, eb: b.em, em: Math.min(a.em, b.em) };
    juntos.set(k, j);
    while (juntos.size > MAX_CACHE) juntos.delete(juntos.keys().next().value);
  }
  return j;
}

/**
 * Antecipa, em segundo plano e um de cada vez, os dados das secções que a pessoa pode abrir — documentos de hoje,
 * vendas do mês, os 12 meses (clientes e cobranças) e os artigos do mês —, para cada secção abrir logo. Não corre com
 * a poupança de dados do telefone ligada nem em redes 2G.
 */
let antecipado = false;
export function antecipar(pode) {
  if (antecipado) return;
  antecipado = true;
  const ln = navigator.connection;
  if (ln && (ln.saveData || /2g/.test(ln.effectiveType || ''))) return;
  const h = hojeIso();
  const passos = [];
  if (pode('documentos')) passos.push(() => dados(h, h, true, true));
  if (pode('vendas')) passos.push(() => dados(...intervalo(C.pv), false, true));
  if (pode('clientes') || pode('cobrancas')) passos.push(() => dados(...intervalo('ano'), false, true));
  if (pode('artigos')) passos.push(() => dados(...intervalo(C.pa), true, true));
  passos.reduce((p, f) => p.then(() => Promise.resolve(f().p).catch(() => {})), new Promise((r) => setTimeout(r, 800)));
}

/** Cartão de «a carregar» ou de erro (com «Tentar de novo»); null quando os dados estão prontos. */
function estadoDe(c) {
  if (c.estado === 'ok') return null;
  if (c.estado === 'a-ler') return [H.cartao('ag-carrega', el('p', 'ag-vazio', t('A carregar…')))];
  const b = el('button', 'ag-botao', t('Tentar de novo'));
  b.type = 'button';
  b.addEventListener('click', () => {
    C.cache.forEach((v, k) => v === c && C.cache.delete(k));
    H.desenhar();
  });
  return [H.cartao('ag-erro', el('p', null, t(c.erro)), b)];
}

const PERIODOS = () => [
  ['hoje', t('Hoje')],
  ['semana', t('Semana')],
  ['mes', t('Mês')],
  ['ano', t('12 meses')],
];
const nomePeriodo = (p) => (PERIODOS().find((x) => x[0] === p) || ['', ''])[1];
const vendaDe = (docs) => docs.reduce((s, v) => s + liq(v), 0);
const facturas = (docs) => docs.filter((v) => !eNC(v));

/** Campo de pesquisa que filtra uma lista sem redesenhar o ecrã (não perde o foco nem o teclado). */
function pesquisa(rotulo, lista, desenharLista) {
  const i = el('input', 'ag-pesquisa');
  i.type = 'search';
  i.placeholder = rotulo;
  i.setAttribute('aria-label', rotulo);
  i.autocomplete = 'off';
  i.enterKeyHint = 'search';
  i.addEventListener('input', () => desenharLista(i.value));
  desenharLista('');
  return [i, lista];
}
const normal = (s) =>
  String(s || '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '');

/** Linha de lista: título, subtítulo, valor à direita e seta; abre o detalhe. */
function linha(tit, sub, valor, fn, extra) {
  const b = el('button', 'ag-rota ag-linha');
  b.type = 'button';
  const n = el('span', 'ag-rota-n');
  n.append(el('strong', null, tit));
  if (sub) n.append(el('span', 'ag-rota-s', sub));
  if (extra) n.append(extra);
  b.append(n, el('span', 'ag-valor', valor));
  b.insertAdjacentHTML('beforeend', H.SETA);
  b.addEventListener('click', fn);
  return b;
}
const estadoDoc = (v) =>
  eNC(v)
    ? el('span', 'ag-estado-doc nc', t('Nota de crédito'))
    : pendenteDe(v) > 0.5
      ? el('span', 'ag-estado-doc pend', t('Por pagar'))
      : el('span', 'ag-estado-doc ok', t('Pago'));

// ───────────── Vendas ─────────────
export function vendas() {
  const [de, ate] = intervalo(C.pv);
  const out = [
    H.segmentos(
      PERIODOS(),
      C.pv,
      (id) => {
        C.pv = id;
        H.desenhar();
      },
      t('Período'),
    ),
  ];
  const c = dados(de, ate, false);
  const e = estadoDe(c);
  if (e) return out.concat(e);
  const docs = c.docs;
  const fac = facturas(docs);
  const total = vendaDe(docs);
  const comIva = docs.reduce((s, v) => s + (+v.total || 0), 0);
  out.push(
    H.cartao(
      'ag-heroi',
      el('div', 'ag-rotulo', t('Vendas · sem IVA') + ' · ' + (de === ate ? dl(de) : dl(de) + ' – ' + dl(ate))),
      el('div', 'ag-grande', H.kz(total)),
      el('div', 'ag-iva', t('com IVA: {v}', { v: H.kz(comIva) })),
      grafVendas(docs, de, ate),
    ),
  );
  const g = el('div', 'ag-grelha');
  g.append(
    H.cartaoKpi(
      t('Documentos'),
      n0(fac.length),
      undefined,
      docs.length - fac.length === 1 ? t('1 nota de crédito') : t('{n} notas de crédito', { n: docs.length - fac.length }),
    ),
    H.cartaoKpi(t('Valor médio'), H.kzc(fac.length ? total / fac.length : null), undefined, t('por compra')),
    H.cartaoKpi(t('Recebido'), H.kzc(docs.reduce((s, v) => s + recebidoDe(v), 0)), undefined, t('com IVA')),
    H.cartaoKpi(t('Por receber'), H.kzc(docs.reduce((s, v) => s + pendenteDe(v), 0)), undefined, t('com IVA')),
  );
  out.push(g);
  // por rota
  const porRota = agrupar(docs, (v) => String(v.rota || '—'));
  const max = Math.max(1, ...porRota.map((x) => x.total));
  if (porRota.length) {
    out.push(H.titulo(t('Por rota')));
    const l = el('div', 'ag-lista');
    porRota.forEach((x, i) => {
      const nome = x.docs[0].promotor && x.docs[0].promotor !== x.chave ? x.chave + ' · ' + x.docs[0].promotor : x.chave;
      l.append(
        linha(
          String(i + 1) + '. ' + nome,
          t('{n} documentos', { n: facturas(x.docs).length }),
          H.kzc(x.total),
          () => H.folhaRota(x.chave),
          H.barra(x.total / max),
        ),
      );
    });
    out.push(l);
  }
  // melhores clientes
  const cl = agrupar(docs, chaveCliente).slice(0, 5);
  if (cl.length) {
    out.push(
      H.titulo(
        t('Melhores clientes'),
        H.botaoLigacao(t('Ver todos'), () => H.mudar('clientes')),
      ),
    );
    const l = el('div', 'ag-lista');
    cl.forEach((x) =>
      l.append(linha(x.docs[0].cliente || t('Cliente'), t('{n} compras', { n: facturas(x.docs).length }), H.kzc(x.total), () => folhaCliente(x.chave))),
    );
    out.push(l);
  }
  out.push(el('p', 'ag-nota', t('Vendas sem IVA (desempenho); recebido e por receber com IVA (dinheiro). Notas de crédito a abater.')));
  return out;
}

function agrupar(docs, chave) {
  const m = new Map();
  docs.forEach((v) => {
    const k = chave(v);
    if (!m.has(k)) m.set(k, { chave: k, total: 0, docs: [] });
    const x = m.get(k);
    x.total += liq(v);
    x.docs.push(v);
  });
  return [...m.values()].sort((a, b) => b.total - a.total);
}
const chaveCliente = (v) => String(v.cliente_codigo || v.nif || v.cliente || '—');

/** Gráfico do período: por hora (hoje), por dia (semana e mês) ou por mês (12 meses). */
function grafVendas(docs, de, ate) {
  if (de === ate) {
    const h = Array(24).fill(0);
    docs.forEach((v) => {
      const x = parseInt(String(v.hora || '').slice(0, 2), 10);
      if (x >= 0 && x < 24) h[x] += liq(v);
    });
    const ini = Math.min(7, ...h.map((v, i) => (v ? i : 24)));
    const fim = Math.max(18, ...h.map((v, i) => (v ? i : 0)));
    const vals = h.slice(ini, fim + 1);
    return H.barras(
      vals,
      vals.map((_, i) => pad(ini + i) + 'h'),
      null,
      t('Vendas por hora'),
    );
  }
  if (C.pv === 'ano') {
    const meses = [];
    for (let i = 11; i >= 0; i--) {
      const d = new Date(ate + 'T12:00:00');
      d.setDate(1);
      d.setMonth(d.getMonth() - i);
      meses.push(iso(d).slice(0, 7));
    }
    const vals = meses.map((m) => docs.filter((v) => String(v.data).slice(0, 7) === m).reduce((s, v) => s + liq(v), 0));
    return H.barras(
      vals,
      meses.map((m) => t(MESES[+m.slice(5, 7) - 1]) + ' ' + m.slice(2, 4)),
      null,
      t('Vendas por mês'),
    );
  }
  const dias = [];
  for (let d = de; d <= ate; d = maisDias(d, 1)) dias.push(d);
  const vals = dias.map((d) => docs.filter((v) => String(v.data).slice(0, 10) === d).reduce((s, v) => s + liq(v), 0));
  return H.barras(vals, dias.map(dl), null, t('Vendas por dia'));
}

// ───────────── Documentos ─────────────
export function documentos() {
  if (!C.dia) C.dia = hojeIso();
  const out = [barraDia()];
  const c = dados(C.dia, C.dia, true);
  const e = estadoDe(c);
  if (e) return out.concat(e);
  const docs = c.docs.slice().sort((a, b) => String(b.hora || '').localeCompare(String(a.hora || '')));
  out.push(
    H.cartao(
      'ag-resumo',
      el('div', 'ag-rotulo', t('{n} documentos', { n: docs.length })),
      el('div', 'ag-medio', H.kz(vendaDe(docs))),
      el('div', 'ag-nota', t('sem IVA')),
    ),
  );
  if (!docs.length) return out.concat(el('p', 'ag-vazio', t('Sem documentos neste dia.')));
  const l = el('div', 'ag-lista');
  out.push(
    ...pesquisa(t('Procurar documento, cliente, NIF ou rota'), l, (q) => {
      const n = normal(q);
      const f = n ? docs.filter((v) => normal([v.documento, v.cliente, v.nif, v.rota, v.promotor].join(' ')).includes(n)) : docs;
      l.replaceChildren(
        ...f
          .slice(0, 200)
          .map((v) =>
            linha(
              v.documento,
              [String(v.hora || '').slice(0, 5), v.cliente, t('Rota {r}', { r: v.rota })].filter(Boolean).join(' · '),
              H.kzc(liq(v)),
              () => folhaDocumento(v.documento, String(v.data).slice(0, 10)),
              estadoDoc(v),
            ),
          ),
      );
      if (!f.length) l.append(el('p', 'ag-vazio', t('Nada encontrado.')));
    }),
  );
  return out;
}
function barraDia() {
  const w = el('div', 'ag-dia');
  const mudarDia = (d) => {
    if (!d || d > hojeIso()) return;
    C.dia = d;
    H.desenhar();
  };
  const ant = el('button', 'ag-icone', '‹');
  ant.type = 'button';
  ant.setAttribute('aria-label', t('Dia anterior'));
  ant.addEventListener('click', () => mudarDia(maisDias(C.dia, -1)));
  // a data mostra-se sempre como dd-mm-aaaa; o calendário do telefone abre ao tocar (campo nativo por cima)
  const caixa = el('label', 'ag-dia-caixa');
  const i = el('input');
  i.type = 'date';
  i.value = C.dia;
  i.max = hojeIso();
  i.setAttribute('aria-label', t('Dia') + ': ' + dl(C.dia));
  i.addEventListener('change', () => mudarDia(i.value));
  caixa.append(el('span', null, (C.dia === hojeIso() ? t('Hoje') + ' · ' : '') + dl(C.dia)), i);
  const seg = el('button', 'ag-icone', '›');
  seg.type = 'button';
  seg.setAttribute('aria-label', t('Dia seguinte'));
  seg.disabled = C.dia >= hojeIso();
  seg.addEventListener('click', () => mudarDia(maisDias(C.dia, 1)));
  w.append(ant, caixa, seg);
  return w;
}

/** Documento: cabeçalho, cliente, linhas de artigos, totais e pagamentos (como no portal). */
export function folhaDocumento(numero, dia) {
  const c = dados(dia, dia, true);
  const v = c.docs.find((x) => x.documento === numero);
  const corpo = el('div', 'ag-folha-corpo');
  if (c.estado !== 'ok' || !v) {
    corpo.append(...(estadoDe(c) || [el('p', 'ag-vazio', t('Documento não encontrado.'))]));
    H.abrirFolha({ doc: numero, dia }, t('Documento {n}', { n: numero }), '', corpo);
    return;
  }
  const m = el('dl', 'ag-ficha');
  const par = (k, val, fn) => {
    if (val == null || val === '') return;
    const dd = el('dd');
    if (fn) {
      const b = el('button', 'ag-mais', val);
      b.type = 'button';
      b.addEventListener('click', fn);
      dd.append(b);
    } else dd.textContent = val;
    m.append(el('dt', null, k), dd);
  };
  par(t('Data'), dl(String(v.data).slice(0, 10)) + (v.hora ? ' ' + String(v.hora).slice(0, 5) : ''));
  par(t('Cliente'), v.cliente + (v.cliente_codigo ? ' (' + v.cliente_codigo + ')' : ''), () => folhaCliente(chaveCliente(v)));
  par(t('NIF'), v.nif);
  par(t('Rota'), v.rota + (v.promotor && v.promotor !== v.rota ? ' · ' + v.promotor : ''));
  const est = el('dd');
  est.append(estadoDoc(v));
  m.append(el('dt', null, t('Estado')), est);
  corpo.append(H.cartao(null, m));
  // linhas de artigos
  const ls = c.artigos.filter((a) => a.documento === numero);
  corpo.append(H.titulo(t('Artigos ({n})', { n: ls.length })));
  const l = el('div', 'ag-lista');
  ls.forEach((a) => {
    const r = el('div', 'ag-doc-linha');
    const n = el('div', 'ag-rota-n');
    n.append(
      el('strong', null, a.artigo),
      el('span', 'ag-rota-s', (a.codigo ? a.codigo + ' · ' : '') + n0(a.quantidade) + ' ' + (a.unidade || '') + ' × ' + H.kz(a.preco_unitario)),
    );
    if (+a.desconto) n.append(el('span', 'ag-rota-s', t('desconto {v}', { v: H.kz(a.desconto) })));
    r.append(n, el('span', 'ag-valor', H.kz(a.total)));
    l.append(r);
  });
  if (!ls.length) l.append(el('p', 'ag-vazio', t('Sem linhas de artigos.')));
  corpo.append(l);
  // totais e pagamentos
  const tot = el('dl', 'ag-ficha ag-totais');
  const sem = liq(v);
  [
    [t('Total sem IVA'), H.kz(sem)],
    [t('IVA'), H.kz((+v.total || 0) - sem)],
    [t('Total'), H.kz(v.total)],
    [t('Recebido'), H.kz(recebidoDe(v))],
    [t('Por receber'), H.kz(pendenteDe(v))],
  ].forEach(([k, val]) => tot.append(el('dt', null, k), el('dd', null, val)));
  (v.pagamentos || []).forEach((p) => tot.append(el('dt', null, t(p.meio || 'Pagamento')), el('dd', null, H.kz(p.valor))));
  corpo.append(H.cartao(null, tot));
  H.abrirFolha({ doc: numero, dia }, t('Documento {n}', { n: numero }), t(v.tipo || ''), corpo);
}

// ───────────── Clientes ─────────────
export function clientes() {
  const [de, ate] = intervalo('ano');
  const out = [el('p', 'ag-nota', t('Últimos 12 meses') + ' · ' + dl(de) + ' – ' + dl(ate))];
  const c = dados(de, ate, false);
  const e = estadoDe(c);
  if (e) return out.concat(e);
  const cl = agrupar(c.docs, chaveCliente);
  const l = el('div', 'ag-lista');
  out.push(
    ...pesquisa(t('Procurar cliente, código ou NIF'), l, (q) => {
      const n = normal(q);
      const f = n ? cl.filter((x) => normal([x.docs[0].cliente, x.docs[0].cliente_codigo, x.docs[0].nif].join(' ')).includes(n)) : cl;
      l.replaceChildren(
        ...f.slice(0, 100).map((x) => {
          const ult = x.docs.reduce((m, v) => (String(v.data) > m ? String(v.data) : m), '').slice(0, 10);
          return linha(
            x.docs[0].cliente || t('Cliente'),
            t('{n} compras', { n: facturas(x.docs).length }) + ' · ' + t('última {d}', { d: dl(ult) }),
            H.kzc(x.total),
            () => folhaCliente(x.chave),
          );
        }),
      );
      if (!f.length) l.append(el('p', 'ag-vazio', t('Nada encontrado.')));
    }),
  );
  out.push(el('p', 'ag-nota', t('{n} clientes com compras; os 100 maiores na lista (procure para ver os outros).', { n: cl.length })));
  return out;
}

/** Cliente: vendas dos últimos 12 meses por mês, indicadores e documentos (abrem com as linhas). */
export function folhaCliente(chave) {
  const [de, ate] = intervalo('ano');
  const c = dados(de, ate, false);
  const corpo = el('div', 'ag-folha-corpo');
  const docs = c.docs.filter((v) => chaveCliente(v) === chave);
  if (c.estado !== 'ok' || !docs.length) {
    corpo.append(...(estadoDe(c) || [el('p', 'ag-vazio', t('Sem compras nos últimos 12 meses.'))]));
    H.abrirFolha({ cliente: chave }, t('Cliente'), '', corpo);
    return;
  }
  const v0 = docs[0];
  const fac = facturas(docs);
  const total = vendaDe(docs);
  const ult = docs.reduce((m, v) => (String(v.data) > m ? String(v.data) : m), '').slice(0, 10);
  const g = el('div', 'ag-grelha');
  g.append(
    H.cartaoKpi(t('Vendas 12 meses'), H.kzc(total), undefined, t('sem IVA')),
    H.cartaoKpi(t('Compras'), n0(fac.length), undefined, t('valor médio {v}', { v: H.kzc(fac.length ? total / fac.length : null) })),
    H.cartaoKpi(t('Última compra'), dl(ult), undefined, t('Rota {r}', { r: v0.rota })),
    H.cartaoKpi(t('Por receber'), H.kzc(docs.reduce((s, v) => s + pendenteDe(v), 0)), undefined, t('com IVA')),
  );
  const meses = [];
  for (let i = 11; i >= 0; i--) {
    const d = new Date(ate + 'T12:00:00');
    d.setDate(1);
    d.setMonth(d.getMonth() - i);
    meses.push(iso(d).slice(0, 7));
  }
  const vals = meses.map((m) => docs.filter((v) => String(v.data).slice(0, 7) === m).reduce((s, v) => s + liq(v), 0));
  corpo.append(
    g,
    H.cartao(
      'ag-heroi',
      el('div', 'ag-rotulo', t('Vendas por mês')),
      H.barras(
        vals,
        meses.map((m) => t(MESES[+m.slice(5, 7) - 1]) + ' ' + m.slice(2, 4)),
        null,
        t('Vendas por mês'),
      ),
    ),
    H.titulo(t('Documentos')),
  );
  const l = el('div', 'ag-lista');
  docs
    .slice()
    .sort((a, b) => (String(b.data) + String(b.hora || '')).localeCompare(String(a.data) + String(a.hora || '')))
    .slice(0, 40)
    .forEach((v) =>
      l.append(
        linha(v.documento, dl(String(v.data).slice(0, 10)), H.kzc(liq(v)), () => folhaDocumento(v.documento, String(v.data).slice(0, 10)), estadoDoc(v)),
      ),
    );
  corpo.append(l);
  H.abrirFolha({ cliente: chave }, v0.cliente || t('Cliente'), [v0.cliente_codigo, v0.nif && t('NIF {n}', { n: v0.nif })].filter(Boolean).join(' · '), corpo);
}

// ───────────── Artigos ─────────────
export function artigos() {
  const per = PERIODOS().filter(([id]) => id !== 'ano');
  if (C.pa === 'ano') C.pa = 'mes';
  const [de, ate] = intervalo(C.pa);
  const out = [
    H.segmentos(
      per,
      C.pa,
      (id) => {
        C.pa = id;
        H.desenhar();
      },
      t('Período'),
    ),
  ];
  const c = dados(de, ate, true);
  const e = estadoDe(c);
  if (e) return out.concat(e);
  const m = new Map();
  c.artigos.forEach((a) => {
    const k = String(a.codigo || a.artigo);
    if (!m.has(k)) m.set(k, { codigo: a.codigo, artigo: a.artigo, unidade: a.unidade, qtd: 0, valor: 0, docs: new Set() });
    const x = m.get(k);
    const sinal = a.nc ? -1 : 1;
    x.qtd += sinal * (+a.quantidade || 0);
    x.valor += sinal * Math.abs(+a.valor_sem_iva || 0);
    x.docs.add(a.documento);
  });
  const lista = [...m.values()].sort((a, b) => b.valor - a.valor);
  const max = Math.max(1, ...lista.map((x) => x.valor));
  out.push(
    H.cartao(
      'ag-resumo',
      el('div', 'ag-rotulo', nomePeriodo(C.pa) + ' · ' + t('{n} artigos diferentes', { n: lista.length })),
      el('div', 'ag-medio', H.kz(lista.reduce((s, x) => s + x.valor, 0))),
      el('div', 'ag-nota', t('sem IVA')),
    ),
  );
  const l = el('div', 'ag-lista');
  out.push(
    ...pesquisa(t('Procurar artigo ou código'), l, (q) => {
      const n = normal(q);
      const f = n ? lista.filter((x) => normal(x.artigo + ' ' + x.codigo).includes(n)) : lista;
      l.replaceChildren(
        ...f.slice(0, 60).map((x, i) => {
          const r = el('div', 'ag-doc-linha');
          const nm = el('div', 'ag-rota-n');
          nm.append(
            el('strong', null, (n ? '' : i + 1 + '. ') + x.artigo),
            el('span', 'ag-rota-s', (x.codigo ? x.codigo + ' · ' : '') + n0(x.qtd) + ' ' + (x.unidade || '') + ' · ' + t('{n} documentos', { n: x.docs.size })),
            H.barra(x.valor / max),
          );
          r.append(nm, el('span', 'ag-valor', H.kzc(x.valor)));
          return r;
        }),
      );
      if (!f.length) l.append(el('p', 'ag-vazio', t('Nada encontrado.')));
    }),
  );
  return out;
}

// ───────────── Cobranças ─────────────
export function cobrancas() {
  const [de, ate] = intervalo('ano');
  const out = [];
  const c = dados(de, ate, false);
  const e = estadoDe(c);
  if (e) return e;
  const pend = c.docs.filter((v) => pendenteDe(v) > 0.5);
  const total = pend.reduce((s, v) => s + pendenteDe(v), 0);
  const idade = (v) => Math.max(0, Math.round((new Date(ate + 'T12:00:00') - new Date(String(v.data).slice(0, 10) + 'T12:00:00')) / 864e5));
  const escaloes = [
    [t('até 30 dias'), (d) => d <= 30],
    [t('31 a 60 dias'), (d) => d > 30 && d <= 60],
    [t('61 a 90 dias'), (d) => d > 60 && d <= 90],
    [t('mais de 90 dias'), (d) => d > 90],
  ];
  out.push(
    H.cartao(
      'ag-heroi',
      el('div', 'ag-rotulo', t('Por receber · com IVA')),
      el('div', 'ag-grande', H.kz(total)),
      el('div', 'ag-nota', t('{n} documentos por liquidar nos últimos 12 meses', { n: pend.length })),
    ),
  );
  const g = el('div', 'ag-grelha');
  escaloes.forEach(([nome, f]) => {
    const ds = pend.filter((v) => f(idade(v)));
    g.append(H.cartaoKpi(nome, H.kzc(ds.reduce((s, v) => s + pendenteDe(v), 0)), undefined, t('{n} documentos', { n: ds.length })));
  });
  out.push(g);
  if (!pend.length) return out.concat(el('p', 'ag-vazio', t('Nada por receber.')));
  const m = new Map();
  pend.forEach((v) => {
    const k = chaveCliente(v);
    if (!m.has(k)) m.set(k, { chave: k, nome: v.cliente, valor: 0, n: 0, antigo: String(v.data) });
    const x = m.get(k);
    x.valor += pendenteDe(v);
    x.n++;
    if (String(v.data) < x.antigo) x.antigo = String(v.data);
  });
  const lista = [...m.values()].sort((a, b) => b.valor - a.valor);
  out.push(H.titulo(t('Por cliente')));
  const l = el('div', 'ag-lista');
  out.push(
    ...pesquisa(t('Procurar cliente'), l, (q) => {
      const n = normal(q);
      const f = n ? lista.filter((x) => normal(x.nome).includes(n)) : lista;
      l.replaceChildren(
        ...f
          .slice(0, 100)
          .map((x) =>
            linha(x.nome || t('Cliente'), t('{n} documentos', { n: x.n }) + ' · ' + t('desde {d}', { d: dl(x.antigo.slice(0, 10)) }), H.kzc(x.valor), () =>
              folhaCliente(x.chave),
            ),
          ),
      );
      if (!f.length) l.append(el('p', 'ag-vazio', t('Nada encontrado.')));
    }),
  );
  return out;
}
