// App de operações «Naval Operações» (operacoes/index.html): telemóvel, para o trabalho no terreno e no armazém. Cada
// pessoa vê só as suas tarefas (decididas no servidor pela matriz de acessos, <meta name="naval-tarefas">):
//   - Entregar dinheiro (vendedor): notas e moedas que entrega à tesouraria, com o vendido em dinheiro no Pinguim BO
//     ao lado; a declaração chega logo ao portal, para a tesouraria contar (às cegas) e validar;
//   - Contar a carrinha (Logística): inventário às cegas, linha a linha, e validação da própria contagem;
//   - Carregar PO (armazém de carga): lê o QR da PO com a câmara (ou escreve-se o número), mostra o que carregar e
//     confere linha a linha, com o motivo de cada diferença.
// As mesmas regras do portal (o servidor decide e regista); nada fica guardado no telefone além do rascunho do que se
// está a escrever. O lançamento linha a linha é o do portal (componentes/lancamento.js).
import { apiFetch, apiJson } from './api.js';
import { lancamento, diferencaViva, quantidadeAtipica } from './componentes/lancamento.js';
import { campoMotivo } from './componentes/motivos-carga.js';
import { n0 } from './formatos.js';
import { LANG, mudarIdioma, t } from './i18n/i18n.js';
import { el } from './util.js';

const $ = (id) => document.getElementById(id);
const kz = (v) => (v == null || isNaN(v) ? '—' : n0(Math.round(v)) + ' Kz');
const hoje = () => {
  const d = new Date();
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
};
const hora = (s) => (s ? String(s).slice(11, 16) : '');
const TAREFAS = {
  visitas: 'Clientes do dia',
  entrega: 'Entregar dinheiro',
  contagem: 'Contar a carrinha',
  carga: 'Carregar PO',
};
const meta = (n) => (document.querySelector('meta[name="' + n + '"]') || {}).content || '';
const minhas = meta('naval-tarefas')
  .split(',')
  .filter((x) => TAREFAS[x]);
const E = { sessao: null, tarefa: minhas[0] || '', numeroPO: '' };

// POST com o código CSRF da sessão
const postJson = (rota, corpo) =>
  apiFetch(rota, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...corpo, csrf: E.sessao && E.sessao.csrf }) }).then(
    (r) => r.json().catch(() => ({ ok: false, erro: 'Sem resposta do servidor.' })),
    () => ({ ok: false, erro: 'Sem resposta do servidor.' }),
  );
const botao = (txt, cls, fn) => {
  const b = el('button', 'ag-botao' + (cls ? ' ' + cls : ''), txt);
  b.type = 'button';
  if (fn) b.addEventListener('click', fn);
  return b;
};
const numero = (valor, rotulo) => {
  const i = el('input');
  i.type = 'number';
  i.min = '0';
  i.step = 'any';
  i.inputMode = 'decimal';
  if (valor != null) i.value = valor;
  i.setAttribute('aria-label', rotulo);
  return i;
};
const erroBox = () => {
  const p = el('p', 'op-erro');
  p.hidden = true;
  p.setAttribute('role', 'alert');
  return p;
};
const mostrarErro = (p, x) => {
  p.hidden = false;
  p.textContent = t((x && x.erro) || 'Não foi possível gravar.');
};
const aviso = (txt) => {
  const s = $('op-estado');
  if (s) s.textContent = txt;
};

function desenharTarefas() {
  const nav = $('op-tarefas');
  nav.textContent = '';
  nav.hidden = minhas.length < 2;
  minhas.forEach((k) => {
    const b = el('button', null, t(TAREFAS[k]));
    b.type = 'button';
    b.setAttribute('role', 'tab');
    b.setAttribute('aria-selected', String(k === E.tarefa));
    b.addEventListener('click', () => {
      E.tarefa = k;
      desenhar();
    });
    nav.append(b);
  });
}

function desenhar() {
  desenharTarefas();
  $('op-h1').textContent = t(TAREFAS[E.tarefa] || 'Naval Operações');
  const c = $('op-conteudo');
  c.textContent = '';
  c.append(el('p', 'ag-vazio', t('A carregar…')));
  if (!E.tarefa) {
    c.textContent = '';
    c.append(el('p', 'ag-vazio', t('O seu perfil não tem tarefas nesta app.')));
    return;
  }
  ({ visitas: vistaVisitas, entrega: vistaEntrega, contagem: vistaContagem, carga: vistaCarga })[E.tarefa](c);
}

// ───────────── Clientes do dia (vendedor) ─────────────
// A carteira calculada todas as noites (Pinguim BO): os clientes do dia habitual de compra e os de compra em atraso,
// os parados primeiro, com a encomenda sugerida (o que costumam comprar), os artigos que deixaram de comprar e a
// sugestão de venda cruzada. O vendedor chega ao cliente já a saber o que propor.
const ESTADO_CLI = { parado: ['Parado', 'op-mau'], a_abrandar: ['A abrandar', 'op-mau'], em_dia: ['Em dia', 'op-ok'] };
function vistaVisitas(c) {
  apiJson('carteira?vista=visitas&dia=' + hoje()).then((d) => {
    c.textContent = '';
    if (!d || !d.ok) {
      c.append(el('p', 'ag-vazio', t((d && d.erro) || 'Não foi possível ler os dados.')));
      return;
    }
    const rotas = Object.entries(d.visitas || {});
    if (!rotas.length) c.append(el('p', 'ag-vazio', t('Sem clientes previstos para hoje.')));
    rotas.forEach(([rota, clientes]) => {
      c.append(el('h2', 'ag-sec', t('Rota {r}', { r: rota }) + ' · ' + t('{n} clientes', { n: n0(clientes.length) })));
      clientes.forEach((x) => {
        const k = el('section', 'ag-cartao op-cliente');
        const [est, cl] = ESTADO_CLI[x.estado] || [x.estado, ''];
        const cab = el('h3', null, x.n || x.c);
        const info = el('p', 'ag-nota');
        info.append(
          document.createTextNode(t(x.motivo) + ' · '),
          el('span', cl, t(est)),
          document.createTextNode(' · ' + t('compra média {v}', { v: kz(x.media) })),
        );
        k.append(cab, info);
        if ((x.encomenda || []).length) {
          k.append(el('p', 'op-sub', t('Encomenda sugerida')));
          const ul = el('ul', 'op-lista');
          x.encomenda.forEach((a) => ul.append(el('li', null, n0(a.q) + ' × ' + a.n)));
          k.append(ul);
        }
        if ((x.deixou || []).length) k.append(el('p', 'op-mau', t('Deixou de comprar: {a}', { a: x.deixou.map((a) => a.n).join(', ') })));
        if ((x.propor || []).length) k.append(el('p', 'ag-nota', t('Propor também: {a}', { a: x.propor.map((a) => a.n).join(', ') })));
        c.append(k);
      });
    });
  });
}

// ───────────── Entregar dinheiro (vendedor) ─────────────
function vistaEntrega(c) {
  const dia = hoje();
  apiJson('caixa-entrega?dia=' + dia).then((d) => {
    c.textContent = '';
    if (!d || !d.ok) {
      c.append(el('p', 'ag-vazio', t((d && d.erro) || 'Não foi possível ler os dados.')));
      return;
    }
    if (!d.rotas.length) c.append(el('p', 'ag-vazio', t('Sem rotas atribuídas à sua conta.')));
    d.rotas.forEach((r) => c.append(cartaoEntrega(r, d, dia)));
  });
}
function cartaoEntrega(r, d, dia) {
  const k = el('section', 'ag-cartao op-entrega');
  k.dataset.rota = r.rota;
  k.append(el('h2', null, t('Rota {r}', { r: r.rota }) + (r.promotor ? ' · ' + r.promotor : '')));
  if (r.vendido_dinheiro != null) k.append(el('p', 'ag-nota', t('Vendido em dinheiro hoje (Pinguim BO): {v}', { v: kz(r.vendido_dinheiro) })));
  if (r.contada) {
    k.append(el('p', 'op-ok', t('A tesouraria já contou e registou a sua entrega.')));
    if (r.declaracao) k.append(el('p', 'ag-nota', t('Declarou {v} às {h}.', { v: kz(r.declaracao.total), h: hora(r.declaracao.em) })));
    return k;
  }
  if (d.folha !== 'aberta') {
    k.append(el('p', 'ag-nota', t('A folha de caixa de hoje já foi fechada: fale com a tesouraria.')));
    return k;
  }
  if (r.declaracao)
    k.append(
      el('p', 'op-ok', t('Enviado à tesouraria às {h}: {v}. Pode corrigir até a tesouraria contar.', { h: hora(r.declaracao.em), v: kz(r.declaracao.total) })),
    );
  const tab = el('table', 'op-tab');
  const cab = el('tr');
  [t('Nota'), t('Quantidade'), t('Valor')].forEach((x, i) => cab.append(el('th', i ? 'n' : null, x)));
  tab.append(cab);
  const ant = (r.declaracao && r.declaracao.notas) || {};
  const linhas = [];
  const total = el('strong', null, kz(0));
  const dif = el('span', 'ag-nota');
  const somar = () => {
    let s = 0;
    linhas.forEach((x) => (s += x.valor()));
    total.textContent = kz(s);
    if (r.vendido_dinheiro != null) {
      const v = Math.round((s - r.vendido_dinheiro) * 100) / 100;
      dif.textContent =
        Math.abs(v) < 0.01 ? t('Igual ao vendido em dinheiro') : t('Diferença face ao vendido: {v}', { v: (v > 0 ? '+' : '−') + kz(Math.abs(v)) });
      dif.className = Math.abs(v) < 0.01 ? 'op-ok' : 'op-mau';
    }
    return s;
  };
  d.notas.forEach((n) => {
    const tr = el('tr');
    const i = numero(ant[n] != null ? ant[n] : '', t('Notas de {n} Kz', { n: n0(n) }));
    i.step = '1';
    i.inputMode = 'numeric';
    const v = el('td', 'n', kz(0));
    const valor = () => (+i.value || 0) * n;
    i.addEventListener('input', () => {
      v.textContent = kz(valor());
      somar();
    });
    v.textContent = kz(valor());
    const ti = el('td', 'n');
    ti.append(i);
    tr.append(el('td', null, n0(n) + ' Kz'), ti, v);
    tab.append(tr);
    linhas.push({ tr, campos: [i], codigo: String(n), texto: n0(n) + ' Kz', valor, chave: String(n), i });
  });
  // moedas: valor total em Kz
  const trM = el('tr');
  const iM = numero(ant.moedas != null ? ant.moedas : '', t('Moedas (valor em Kz)'));
  const tdM = el('td', 'n');
  tdM.append(iM);
  trM.append(el('td', null, t('Moedas')), tdM, el('td', 'n', ''));
  iM.addEventListener('input', somar);
  tab.append(trM);
  linhas.push({ tr: trM, campos: [iM], codigo: 'moedas', texto: t('Moedas'), valor: () => +iM.value || 0, chave: 'moedas', i: iM });
  const erro = erroBox();
  const enviar = botao(r.declaracao ? t('Corrigir e enviar à tesouraria') : t('Enviar à tesouraria'), null, () => {
    const notas = {};
    linhas.forEach((x) => {
      if (x.i.value !== '') notas[x.chave] = Number(x.i.value);
    });
    if (somar() <= 0) {
      erro.hidden = false;
      erro.textContent = t('Indique as notas e moedas que vai entregar.');
      return;
    }
    enviar.disabled = true;
    postJson('caixa-entrega', { accao: 'declarar', dia, rota: r.rota, notas }).then((x) => {
      enviar.disabled = false;
      if (!x || !x.ok) return mostrarErro(erro, x);
      lc.limparRascunho();
      aviso(t('Enviado à tesouraria'));
      desenhar();
    });
  });
  // notas: sem visto nem contador (zero também é resposta); Enter passa à nota seguinte e, no fim, ao «Enviar»
  const lc = lancamento({ tabela: tab, linhas, gravar: enviar, visto: false, progresso: false, procurar: false, chave: 'op-entrega-' + dia + '-' + r.rota });
  const tot = el('div', 'op-total');
  tot.append(el('span', null, t('Total a entregar')), total);
  const acc = el('div', 'op-acc');
  acc.append(enviar);
  k.append(tab, tot, dif, erro, acc);
  somar();
  return k;
}

// ───────────── Contar a carrinha (Logística) ─────────────
const ESTADO_CC = {
  por_contar: 'Por contar',
  por_validar: 'Por validar',
  por_aprovar: 'Por aprovar (coordenador)',
  por_fechar: 'Por aprovar (responsável)',
  aprovada: 'Fechada',
};
function vistaContagem(c) {
  const dia = hoje();
  apiJson('conciliacao-carrinha?data=' + dia).then((d) => {
    c.textContent = '';
    if (!d || !d.ok) {
      c.append(el('p', 'ag-vazio', t((d && d.erro) || 'Não foi possível ler os dados.')));
      return;
    }
    const rotas = d.rotas || [];
    if (!rotas.length) c.append(el('p', 'ag-vazio', t('Sem carrinhas para contar hoje.')));
    // primeiro as que pedem acção (por contar e por validar)
    const ordem = { por_contar: 0, por_validar: 1 };
    rotas
      .slice()
      .sort((a, b) => (ordem[a.resumo.estado] ?? 9) - (ordem[b.resumo.estado] ?? 9) || String(a.rota).localeCompare(String(b.rota)))
      .forEach((r) => c.append(cartaoContagem(r, d, dia)));
  });
}
function cartaoContagem(r, d, dia) {
  const k = el('section', 'ag-cartao op-carrinha');
  k.dataset.rota = r.rota;
  const est = r.resumo.estado;
  k.append(el('h2', null, t('Rota {r}', { r: r.rota }) + (r.promotor ? ' · ' + r.promotor : '')), el('p', 'ag-nota', t(ESTADO_CC[est] || est)));
  const erro = erroBox();
  const eu = E.sessao && E.sessao.login;
  if (est === 'por_contar') {
    const abrir = botao(t('Contar esta carrinha'), null, () => {
      abrir.remove();
      k.append(formContagem(r, dia, erro), erro);
    });
    k.append(abrir);
  } else if (est === 'por_validar' && r.contado_por && eu && r.contado_por.toLowerCase() === eu.toLowerCase()) {
    k.append(
      el('p', 'ag-nota', t('{n} diferença(s). Confira os números no portal; se estiverem certos, valide.', { n: n0(r.resumo.diferencas) })),
      erro,
      botao(t('Validar a minha contagem'), null, (e) => {
        e.target.disabled = true;
        postJson('conciliacao-carrinha', { accao: 'validar', data: dia, rota: r.rota }).then((x) => {
          e.target.disabled = false;
          if (!x || !x.ok) return mostrarErro(erro, x);
          aviso(t('Contagem validada'));
          desenhar();
        });
      }),
    );
  } else if (est === 'por_validar') k.append(el('p', 'ag-nota', t('Aguarda a validação de {p}, que fez a contagem.', { p: r.contado_por || '—' })));
  return k;
}
function formContagem(r, dia, erro) {
  const w = el('div', 'op-contagem');
  const tab = el('table', 'op-tab');
  const cab = el('tr');
  [t('Artigo'), t('Voltou')].forEach((x, i) => cab.append(el('th', i ? 'n' : null, x)));
  tab.append(cab);
  const linhas = [];
  (r.linhas || []).forEach((l) => {
    const tr = el('tr');
    const i = numero(l.contado == null ? '' : l.contado, l.artigo);
    const ta = el('td');
    ta.append(el('span', 'op-cod', l.codigo), el('br'), document.createTextNode(l.artigo));
    const tq = el('td', 'n');
    tq.append(i);
    if (l.unidade) tq.append(el('span', 'op-cod', ' ' + l.unidade));
    tr.append(ta, tq);
    tab.append(tr);
    linhas.push({ tr, campos: [i], codigo: l.codigo, texto: l.artigo, atipico: () => quantidadeAtipica(i.value, { unidade: l.unidade }) });
  });
  const gravar = botao(t('Gravar contagem'), null, () => (linhas.some((x) => x.campos[0].value === '') ? enviar() : lc.confirmar(enviar)));
  function enviar() {
    const contagem = {};
    for (const x of linhas) {
      if (x.campos[0].value === '') {
        erro.hidden = false;
        erro.textContent = t('Preencha todos os artigos (0 se não voltou nada).');
        x.campos[0].focus();
        return;
      }
      contagem[x.codigo] = Number(x.campos[0].value);
    }
    gravar.disabled = true;
    postJson('conciliacao-carrinha', { accao: 'contar', data: dia, rota: r.rota, contagem }).then((x) => {
      gravar.disabled = false;
      if (!x || !x.ok) return mostrarErro(erro, x);
      lc.limparRascunho();
      aviso(t('Contagem gravada'));
      desenhar();
    });
  }
  const lc = lancamento({ tabela: tab, linhas, gravar, chave: 'op-contagem-' + dia + '-' + r.rota });
  w.append(el('p', 'ag-nota', t('Conte o que voltou na carrinha. Os números do sistema só aparecem depois de gravar a contagem.')), lc.barra, tab);
  const acc = el('div', 'op-acc');
  acc.append(gravar);
  w.append(acc);
  setTimeout(() => linhas[0] && linhas[0].campos[0].focus(), 0);
  return w;
}

// ───────────── Carregar PO (armazém de carga) ─────────────
function vistaCarga(c) {
  c.textContent = '';
  const k = el('section', 'ag-cartao');
  const f = el('form', 'op-abrir');
  const i = el('input', 'op-numero');
  i.type = 'text';
  i.placeholder = 'PO-2026-000000';
  i.autocomplete = 'off';
  i.value = E.numeroPO;
  i.setAttribute('aria-label', t('Número da PO'));
  const erro = erroBox();
  const abrir = (n) => {
    E.numeroPO = String(n || '')
      .trim()
      .toUpperCase();
    if (!/^(PO|RQ)-\d{4}-\d{6}$/.test(E.numeroPO)) {
      erro.hidden = false;
      erro.textContent = t('Número da PO inválido.');
      return;
    }
    corpo.textContent = '';
    corpo.append(el('p', 'ag-vazio', t('A carregar…')));
    apiJson('requisicoes?numero=' + encodeURIComponent(E.numeroPO)).then((d) => {
      corpo.textContent = '';
      if (!d || !d.ok) return mostrarErro(erro, d);
      erro.hidden = true;
      corpo.append(conferirPO(d.requisicao));
    });
  };
  f.addEventListener('submit', (e) => {
    e.preventDefault();
    abrir(i.value);
  });
  const ler = botao(t('Ler o QR da PO'), null, () => lerQR((n) => ((i.value = n), abrir(n)), erro));
  const ab = botao(t('Abrir'), 'ag-sec-b');
  ab.type = 'submit';
  f.append(i, el('div', 'op-acc'));
  f.lastChild.append(ler, ab);
  const corpo = el('div', 'op-po');
  k.append(el('p', 'ag-nota', t('Leia o QR impresso na PO (ou escreva o número) para ver o que carregar.')), f, erro);
  c.append(k, corpo);
  if (E.numeroPO) abrir(E.numeroPO);
}
/** Leitura do QR da PO com a câmara (BarcodeDetector do telemóvel; sem ele, escreve-se o número). */
function lerQR(depois, erro) {
  if (!('BarcodeDetector' in window) || !navigator.mediaDevices) {
    erro.hidden = false;
    erro.textContent = t('Este telemóvel não lê códigos QR nesta app: escreva o número da PO.');
    return;
  }
  const d = el('dialog', 'ag-folha op-qr');
  const v = el('video', 'op-video');
  v.setAttribute('playsinline', '');
  v.muted = true;
  let parar = false;
  let fluxo = null;
  const acabar = () => {
    parar = true;
    if (fluxo) fluxo.getTracks().forEach((x) => x.stop());
    if (d.open) d.close();
    d.remove();
  };
  d.append(el('p', 'ag-nota', t('Aponte a câmara para o QR impresso na PO.')), v, botao(t('Cancelar'), 'ag-sec-b', acabar));
  document.body.append(d);
  d.showModal();
  navigator.mediaDevices
    .getUserMedia({ video: { facingMode: 'environment' } })
    .then(async (s) => {
      fluxo = s;
      v.srcObject = s;
      await v.play();
      const det = new window.BarcodeDetector({ formats: ['qr_code'] });
      const passo = async () => {
        if (parar) return;
        const r = await det.detect(v).catch(() => []);
        const m = r.length && String(r[0].rawValue).match(/(PO|RQ)-\d{4}-\d{6}/);
        if (m) {
          acabar();
          depois(m[0]);
          return;
        }
        setTimeout(passo, 250);
      };
      passo();
    })
    .catch(() => {
      acabar();
      erro.hidden = false;
      erro.textContent = t('Sem acesso à câmara: escreva o número da PO.');
    });
}
function conferirPO(r) {
  const k = el('section', 'ag-cartao op-conferir');
  k.append(el('h2', null, r.numero + ' · ' + t('rota {r}', { r: r.rota }) + (r.promotor ? ' · ' + r.promotor : '')));
  if (r.estado === 'carregada') {
    k.append(el('p', 'op-ok', t('Carga já conferida por {p} às {h}.', { p: r.confirmado_por || '—', h: hora(r.confirmado_em) })));
    return k;
  }
  if (r.estado !== 'emitida') {
    k.append(el('p', 'ag-nota', t('Esta PO não está à espera de carga.')));
    return k;
  }
  if (r.pronta_carga === false) {
    k.append(el('p', 'op-mau', t('Esta PO ainda não foi aprovada: só fica disponível para a carga depois de aprovada. Não carregue.')));
    return k;
  }
  const eu = E.sessao && E.sessao.login;
  if (eu && r.emitida_por && r.emitida_por.toLowerCase() === eu.toLowerCase()) {
    k.append(el('p', 'op-mau', t('Segregação de funções: quem emitiu a PO não confere a carga. Peça a outro Fiel de Armazém.')));
    return k;
  }
  const tab = el('table', 'op-tab');
  const cab = el('tr');
  [t('Artigo'), t('Pedido'), t('Carregado')].forEach((x, i) => cab.append(el('th', i ? 'n' : null, x)));
  tab.append(cab);
  const linhas = r.linhas.map((l) => {
    const tr = el('tr');
    const q = numero(l.quantidade, t('Carregado') + ' · ' + l.artigo);
    const m = campoMotivo(t('Motivo') + ' · ' + l.artigo);
    m.w.hidden = true;
    q.addEventListener('input', () => (m.w.hidden = q.value === '' || Number(q.value) === Number(l.quantidade)));
    const ta = el('td');
    ta.append(el('span', 'op-cod', l.codigo), el('br'), document.createTextNode(l.artigo));
    const tq = el('td', 'n');
    tq.append(q, diferencaViva(q, l.quantidade), m.w);
    tr.append(ta, el('td', 'n', n0(l.quantidade)), tq);
    tab.append(tr);
    return {
      tr,
      campos: [q],
      codigo: l.codigo,
      texto: l.artigo,
      l,
      q,
      m,
      atipico: () => quantidadeAtipica(q.value, { unidade: l.unidade, esperado: Number(l.quantidade) }),
    };
  });
  const erro = erroBox();
  const ok = botao(t('Confirmar a carga'), null, () => (linhas.some((x) => x.q.value === '') ? concluir() : lc.confirmar(concluir)));
  function concluir() {
    const entregue = {};
    const motivos = {};
    for (const x of linhas) {
      if (x.q.value === '' || Number(x.q.value) < 0) {
        erro.hidden = false;
        erro.textContent = t('Indique a quantidade carregada de cada artigo (0 se não foi carregado).');
        return x.q.focus();
      }
      entregue[x.l.codigo] = Number(x.q.value);
      if (!x.m.w.hidden) {
        const v = x.m.valor();
        if (!v || (v.codigo === 'outro' && v.texto.length < 3)) {
          erro.hidden = false;
          erro.textContent = t('Indique o motivo de cada diferença entre o pedido e o carregado.');
          return x.m.sel.focus();
        }
        motivos[x.l.codigo] = v;
      }
    }
    ok.disabled = true;
    postJson('requisicoes', { accao: 'confirmar_carga', numero: r.numero, entregue, motivos }).then((x) => {
      ok.disabled = false;
      if (!x || !x.ok) return mostrarErro(erro, x);
      lc.limparRascunho();
      aviso(t('Carga conferida'));
      E.numeroPO = '';
      desenhar();
    });
  }
  // a quantidade abre igual ao pedido: o visto aparece em todas; o que muda pede o motivo
  const lc = lancamento({ tabela: tab, linhas, gravar: ok, progresso: false, chave: 'op-carga-' + r.numero });
  const acc = el('div', 'op-acc');
  acc.append(ok);
  k.append(el('p', 'ag-nota', t('Confira linha a linha o que foi realmente carregado. Cada diferença pede o motivo.')), lc.barra, tab, erro, acc);
  return k;
}

// ───────────── arranque ─────────────
function arrancar() {
  aviso(t('A ligar…'));
  apiJson('sessao').then((s) => {
    if (!s || !s.login) {
      aviso(t('Sessão terminada'));
      return;
    }
    E.sessao = s;
    aviso(s.nome || s.login);
    const fim = () => desenhar();
    if (s.idioma && s.idioma !== LANG) mudarIdioma(s.idioma).then(fim, fim);
    else fim();
  });
}
$('op-actualizar').addEventListener('click', () => desenhar());
arrancar();
