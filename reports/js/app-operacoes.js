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
import { tArtigo } from './i18n/artigos.js';
import { linhaHorario } from './componentes/horario.js';
import { lerCodigo } from './componentes/leitor-codigos.js';

const $ = (id) => document.getElementById(id);
const kz = (v) => (v == null || isNaN(v) ? '—' : n0(Math.round(v)) + ' Kz');
const hoje = () => {
  const d = new Date();
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
};
const hora = (s) => (s ? String(s).slice(11, 16) : '');
const TAREFAS = {
  visitas: 'Clientes do dia',
  mercadoria: 'Pedir mercadoria',
  entrega: 'Entregar dinheiro',
  contagem: 'Contar a carrinha',
  inventario: 'Inventário do armazém',
  carga: 'Carregar PO',
};
const meta = (n) => (document.querySelector('meta[name="' + n + '"]') || {}).content || '';
const minhas = meta('naval-tarefas')
  .split(',')
  .filter((x) => TAREFAS[x]);
const E = { sessao: null, tarefa: minhas[0] || '', numeroPO: '', inv: null };

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
  ({ visitas: vistaVisitas, mercadoria: vistaMercadoria, entrega: vistaEntrega, contagem: vistaContagem, inventario: vistaInventario, carga: vistaCarga })[
    E.tarefa
  ](c);
}

// ───────────── Pedir mercadoria (vendedor) ─────────────
// As PO das suas rotas (v1/requisicoes): a reposição criada sozinha da contagem validada e os pedidos extra, cada uma
// com o percurso (emitida → aprovação → carga no cais → guia no Pinguim BO), quem está à espera e, quando não se
// aprovou sozinha, porquê. Um rascunho emite-se aqui. Um pedido extra procura o artigo no catálogo com o stock
// disponível no armazém (Pinguim BO) e pede a quantidade linha a linha; a aprovação continua no coordenador.
const amanha = () => {
  const d = new Date(Date.now() + 864e5);
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
};
const ddmm = (s) => (s ? String(s).slice(8, 10) + '-' + String(s).slice(5, 7) + ' ' + String(s).slice(11, 16) : '');
/** Estado de uma PO para o vendedor: [texto, classe, passos [{feito, texto, nota}]]. */
function estadoPO(r, nomes) {
  const nome = (l) => (l === 'automatico' ? t('automático') : (nomes && nomes[l]) || l || '');
  const emitida = r.estado !== 'rascunho';
  const aprovada = !r.precisa_aprovacao || !!r.aprovada_em;
  const validada = !r.precisa_validacao || !!r.validada_em || !!r.aprovada_em;
  const carregada = r.estado === 'carregada';
  const guia = carregada && (r.fase === 'transferida' || r.fase === 'validada');
  const passos = [
    { feito: emitida, texto: t('Emitida'), nota: emitida ? nome(r.emitida_por) + ' · ' + ddmm(r.emitida_em) : t('rascunho: confirme e emita') },
    ...(r.precisa_validacao
      ? [
          {
            feito: emitida && validada,
            texto: t('Validação do stock'),
            nota: r.validada_em
              ? (r.validada_por === 'automatico' ? t('validada sozinha pelas regras') : nome(r.validada_por)) + ' · ' + ddmm(r.validada_em)
              : t('o controlo de stock ajusta as quantidades e valida'),
          },
        ]
      : []),
    {
      feito: emitida && aprovada,
      texto: t('Aprovação'),
      nota: r.aprovada_em
        ? (r.aprovada_por === 'automatico' ? t('aprovada sozinha pelas regras') : nome(r.aprovada_por)) + ' · ' + ddmm(r.aprovada_em)
        : t('o coordenador foi avisado no telemóvel'),
    },
    {
      feito: carregada,
      texto: t('Carga no cais'),
      nota: carregada ? nome(r.confirmado_por) + ' · ' + ddmm(r.confirmado_em) : t('o Fiel de Armazém lê o QR da PO'),
    },
    { feito: guia, texto: t('Guia no Pinguim BO'), nota: guia ? t('criada e confirmada') : t('sai sozinha depois da carga') },
  ];
  if (r.estado === 'anulada') return [t('Anulada'), 'op-mau', passos];
  if (r.estado === 'fechada') return [t('Fechada'), '', passos];
  if (carregada) return [t('Carregada'), 'op-ok', passos];
  if (!emitida) return [t('Rascunho'), 'op-mau', passos];
  if (!validada) return [t('Por validar'), 'op-mau', passos];
  if (!aprovada) return [t('Por aprovar'), 'op-mau', passos];
  return [t('Aprovada'), 'op-ok', passos];
}
function vistaMercadoria(c) {
  const de = new Date(Date.now() - 7 * 864e5).toISOString().slice(0, 10);
  apiJson('requisicoes?de=' + de + '&ate=' + amanha()).then((d) => {
    c.textContent = '';
    if (!d || !d.ok) {
      c.append(el('p', 'ag-vazio', t((d && d.erro) || 'Não foi possível ler os dados.')));
      return;
    }
    const rotas = Object.keys(d.rotas || {});
    const novo = botao(t('Pedir mais mercadoria'), null, () => {
      c.textContent = '';
      c.append(formPedido(rotas, d.rotas || {}));
    });
    c.append(novo);
    const lista = (d.requisicoes || []).slice().sort((a, b) => String(b.criado_em || '').localeCompare(String(a.criado_em || '')));
    if (!lista.length) c.append(el('p', 'ag-vazio', t('Ainda não tem PO nos últimos dias.')));
    lista.slice(0, 15).forEach((r) => c.append(cartaoPO(r, d.nomes || {})));
  });
}
function cartaoPO(r, nomes) {
  const k = el('section', 'ag-cartao op-po-cartao');
  k.dataset.numero = r.numero;
  const [est, cl, passos] = estadoPO(r, nomes);
  const cab = el('div', 'op-po-cab');
  cab.append(
    el('strong', null, r.numero + ' · ' + ((r.origem && r.origem.tipo) === 'conciliacao' ? t('reposição') : t('pedido extra'))),
    el('span', 'op-pill ' + cl, est),
  );
  k.append(
    cab,
    el(
      'p',
      'ag-nota',
      t('Rota {r}', { r: r.rota }) +
        ' · ' +
        t('{a} artigos · {q} unidades', { a: n0((r.linhas || []).length), q: n0((r.linhas || []).reduce((s, l) => s + (+l.quantidade || 0), 0)) }),
    ),
  );
  const ol = el('ol', 'op-passos');
  passos.forEach((p) => {
    const li = el('li', p.feito ? 'feito' : '');
    const tx = el('div');
    tx.append(el('strong', null, p.texto), el('span', null, p.nota));
    li.append(el('span', 'op-bola', p.feito ? '✓' : ''), tx);
    ol.append(li);
  });
  k.append(ol);
  const mot = (r.regras_auto && r.regras_auto.motivos) || [];
  if (r.estado === 'emitida' && r.precisa_aprovacao && !r.aprovada_em && mot.length)
    k.append(el('p', 'op-mau', t('Para aprovar porque: {m}.', { m: mot.map((x) => t(x)).join('; ') })));
  // o rascunho emite-se aqui (confirma as quantidades)
  if (r.estado === 'rascunho') {
    const erro = erroBox();
    const emitir = botao(t('Confirmar e emitir'), null, () => {
      emitir.disabled = true;
      postJson('requisicoes', { accao: 'emitir', numero: r.numero, versao: r.versao }).then((x) => {
        emitir.disabled = false;
        if (!x || !x.ok) return mostrarErro(erro, x);
        aviso(t('PO emitida'));
        desenhar();
      });
    });
    const ul = el('ul', 'op-lista');
    (r.linhas || []).forEach((l) => ul.append(el('li', null, n0(l.quantidade) + ' × ' + tArtigo(l.artigo))));
    const continuar = botao(t('Continuar o pedido'), 'ag-sec-b', () => {
      const c = $('op-conteudo');
      c.textContent = '';
      c.append(formPedido([r.rota], nomes, r));
    });
    k.append(ul, erro, emitir, continuar);
  }
  return k;
}
function formPedido(rotas, nomes, existente) {
  // existente: um rascunho começado antes (o pedido faz-se ao longo do dia e emite-se quando estiver completo)
  const w = el('section', 'ag-cartao op-pedido');
  w.append(el('h2', null, existente ? t('Pedido {n} (rascunho)', { n: existente.numero }) : t('Pedido extra')));
  let rota = existente ? existente.rota : rotas[0] || '';
  const antes = existente ? Object.fromEntries((existente.linhas || []).map((l) => [l.codigo, l.quantidade])) : {};
  if (rotas.length > 1 && !existente) {
    const sel = el('select', 'op-numero');
    sel.setAttribute('aria-label', t('Rota'));
    rotas.forEach((r) => sel.append(new Option(r + (nomes[r] ? ' · ' + nomes[r] : ''), r)));
    sel.addEventListener('change', () => (rota = sel.value));
    w.append(sel);
  }
  const procura = el('input', 'op-numero');
  procura.type = 'search';
  procura.placeholder = t('Procurar artigo (código ou nome)');
  procura.setAttribute('aria-label', t('Procurar artigo (código ou nome)'));
  const corpo = el('div');
  corpo.append(el('p', 'ag-vazio', t('A carregar…')));
  const erro = erroBox();
  const nota = el('input', 'op-numero');
  nota.type = 'text';
  nota.maxLength = 200;
  nota.placeholder = t('Motivo (ex.: encomenda de cliente)');
  nota.setAttribute('aria-label', t('Motivo do pedido extra'));
  if (existente && existente.nota) nota.value = existente.nota;
  const qs = new Map(); // pela ordem do catálogo
  let lcRef = null;
  // valores fora do normal (acima do disponível) pedem confirmação antes de emitir
  const enviar = botao(t('Emitir o pedido'), null, () => (lcRef ? lcRef.confirmar(() => guardar(true)) : guardar(true)));
  const rascunho = botao(t('Guardar e continuar depois'), 'ag-sec-b', () => guardar(false));
  let atual = existente || null;
  /** Grava o rascunho (cria-o na primeira vez); com emitir, emite-o: fica à espera da aprovação do coordenador. */
  function guardar(emitir) {
    const linhas = [...qs.values()]
      .filter((x) => +x.i.value > 0)
      .map((x) => ({ codigo: x.a.codigo, artigo: x.a.artigo, unidade: x.a.unidade || '', quantidade: Number(x.i.value) }));
    if (!linhas.length) {
      erro.hidden = false;
      erro.textContent = t('Indique a quantidade de pelo menos um artigo.');
      return;
    }
    enviar.disabled = rascunho.disabled = true;
    const passo = atual
      ? postJson('requisicoes', { accao: 'gravar', numero: atual.numero, versao: atual.versao, linhas, nota: nota.value })
      : postJson('requisicoes', { accao: 'criar', rota, linhas, nota: nota.value });
    passo.then((x) => {
      if (!x || !x.ok) {
        enviar.disabled = rascunho.disabled = false;
        return mostrarErro(erro, x);
      }
      atual = x.requisicao;
      if (lcRef) lcRef.limparRascunho();
      if (!emitir) {
        enviar.disabled = rascunho.disabled = false;
        aviso(t('Rascunho {n} guardado: continue quando quiser.', { n: atual.numero }));
        desenhar();
        return;
      }
      postJson('requisicoes', { accao: 'emitir', numero: atual.numero, versao: atual.versao }).then((y) => {
        enviar.disabled = rascunho.disabled = false;
        if (!y || !y.ok) return mostrarErro(erro, y);
        aviso(t('Pedido emitido: fica à espera da aprovação do coordenador.'));
        desenhar();
      });
    });
  }
  const voltar = botao(t('Cancelar'), 'ag-sec-b', () => desenhar());
  w.append(procura, el('p', 'ag-nota', t('Disponível = stock do armazém principal agora (Pinguim BO).')), corpo, nota, erro);
  const acc = el('div', 'op-acc');
  acc.append(enviar, rascunho, voltar);
  w.append(el('p', 'ag-nota', t('Pode começar o pedido a qualquer hora e ir juntando artigos; depois de emitido fica sempre à espera da aprovação.')), acc);
  apiJson('requisicoes?catalogo=1').then((d) => {
    corpo.textContent = '';
    if (!d || !d.ok) return corpo.append(el('p', 'op-erro', t((d && d.erro) || 'Não foi possível ler os dados.')));
    if (d.stock_erro) corpo.append(el('p', 'op-mau', t('Sem o stock do Pinguim BO agora: o pedido segue na mesma e o armazém confirma.')));
    const tab = el('table', 'op-tab');
    const cab = el('tr');
    [t('Artigo'), t('Pedir')].forEach((x, i) => cab.append(el('th', i ? 'n' : null, x)));
    tab.append(cab);
    const linhas = [];
    (d.artigos || []).forEach((a) => {
      const tr = el('tr');
      const i = numero(antes[a.codigo] != null ? antes[a.codigo] : '', t('Quantidade') + ' · ' + tArtigo(a.artigo));
      i.placeholder = '0';
      const ta = el('td', a.imagem ? 'op-com-img' : null);
      if (a.imagem) {
        const im = el('img', 'op-art-img');
        im.loading = 'lazy';
        im.alt = '';
        im.src = a.imagem;
        im.addEventListener('error', () => im.remove());
        ta.append(im);
      }
      ta.append(
        el('span', 'op-cod', a.codigo),
        el('br'),
        document.createTextNode(tArtigo(a.artigo)),
        el('br'),
        el('span', 'op-cod', a.disponivel == null ? t('disponível: por confirmar') : t('disponível: {n}', { n: n0(a.disponivel) })),
      );
      const tq = el('td', 'n');
      tq.append(i);
      tr.append(ta, tq);
      tab.append(tr);
      qs.set(a.codigo, { a, i });
      linhas.push({
        tr,
        campos: [i],
        codigo: a.codigo,
        texto: tArtigo(a.artigo),
        preenchida: () => +i.value > 0,
        // mais do que o armazém tem: o coordenador decide
        atipico: () =>
          a.disponivel != null && +i.value > a.disponivel
            ? t('acima do disponível ({n})', { n: n0(a.disponivel) })
            : quantidadeAtipica(i.value, { unidade: a.unidade }),
      });
    });
    lcRef = lancamento({ tabela: tab, linhas, gravar: enviar, progresso: false, procurar: false, chave: 'op-pedido-' + rota });
    // procura própria: no topo do cartão
    procura.addEventListener('input', () => {
      const q = procura.value
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .trim();
      linhas.forEach(
        (x) =>
          (x.tr.hidden =
            !!q &&
            !(x.codigo + ' ' + x.texto)
              .normalize('NFD')
              .replace(/[\u0300-\u036f]/g, '')
              .toLowerCase()
              .includes(q)),
      );
    });
    corpo.append(tab);
  });
  return w;
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
// Notas do Kwanza desenhadas (imagem ilustrativa, não reprodução da nota): cor e valor grandes, para se reconhecer
// à primeira vista que nota se está a contar. As cores seguem a família de cada nota.
const COR_NOTA = { 5000: '#9b3b2f', 2000: '#2f7a4f', 1000: '#2f5fa8', 500: '#7a4fa0', 200: '#b5651d', 100: '#3f7f8c', 50: '#8a6d1f' };
function imagemNota(n) {
  const s = el('span', 'op-nota-img');
  s.setAttribute('aria-hidden', 'true');
  const cor = COR_NOTA[n] || '#4a5a7a';
  const v = String(Math.round(+n) || 0);
  // nosemgrep: insecure-innerhtml -- SVG estático; o único valor é um número inteiro
  s.innerHTML =
    '<svg viewBox="0 0 120 60" width="120" height="60"><rect x="1" y="1" width="118" height="58" rx="6" fill="' +
    cor +
    '"/><rect x="5" y="5" width="110" height="50" rx="4" fill="none" stroke="#fff" stroke-opacity=".45" stroke-dasharray="3 2"/>' +
    '<circle cx="28" cy="30" r="15" fill="#fff" fill-opacity=".18"/><circle cx="28" cy="30" r="9" fill="none" stroke="#fff" stroke-opacity=".5"/>' +
    '<text x="112" y="27" text-anchor="end" font-size="20" font-weight="800" fill="#fff" font-family="system-ui,sans-serif">' +
    v +
    '</text><text x="112" y="45" text-anchor="end" font-size="9" font-weight="700" letter-spacing="1.5" fill="#fff" fill-opacity=".9" font-family="system-ui,sans-serif">KWANZAS</text></svg>';
  return s;
}
function imagemMoedas() {
  const s = el('span', 'op-nota-img op-moedas-img');
  s.setAttribute('aria-hidden', 'true');
  // nosemgrep: insecure-innerhtml -- SVG estático
  s.innerHTML =
    '<svg viewBox="0 0 120 60" width="120" height="60"><circle cx="44" cy="34" r="20" fill="#b08d3a"/><circle cx="44" cy="34" r="14" fill="none" stroke="#fff" stroke-opacity=".5"/>' +
    '<circle cx="74" cy="28" r="20" fill="#c9a548"/><circle cx="74" cy="28" r="14" fill="none" stroke="#fff" stroke-opacity=".5"/>' +
    '<text x="74" y="33" text-anchor="middle" font-size="13" font-weight="800" fill="#fff" font-family="system-ui,sans-serif">Kz</text></svg>';
  return s;
}
/** Botões − e + à volta do campo da quantidade (toque grande no telemóvel); mudam o campo como se fosse escrito. */
function comPassos(i, nome, tipo = 'nota') {
  const passo = 1;
  const w = el('span', 'op-passos');
  const mudar = (d) => {
    const v = Math.max(0, (+i.value || 0) + d);
    i.value = String(v);
    i.dispatchEvent(new Event('input', { bubbles: true }));
  };
  const menos = el('button', 'op-passo', '−');
  menos.type = 'button';
  menos.setAttribute('aria-label', tipo === 'nota' ? t('Menos uma nota de {n}', { n: nome }) : t('Menos um: {n}', { n: nome }));
  menos.addEventListener('click', () => mudar(-passo));
  const mais = el('button', 'op-passo', '+');
  mais.type = 'button';
  mais.setAttribute('aria-label', tipo === 'nota' ? t('Mais uma nota de {n}', { n: nome }) : t('Mais um: {n}', { n: nome }));
  mais.addEventListener('click', () => mudar(passo));
  w.append(menos, i, mais);
  return w;
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
  tab.classList.add('op-notas');
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
    ti.append(comPassos(i, n0(n) + ' Kz'));
    const tn = el('td', 'op-nota');
    tn.append(imagemNota(n), el('span', 'op-nota-t', n0(n) + ' Kz'));
    tr.append(tn, ti, v);
    tab.append(tr);
    linhas.push({ tr, campos: [i], codigo: String(n), texto: n0(n) + ' Kz', valor, chave: String(n), i });
  });
  // moedas: valor total em Kz
  const trM = el('tr');
  const iM = numero(ant.moedas != null ? ant.moedas : '', t('Moedas (valor em Kz)'));
  const tdM = el('td', 'n');
  tdM.append(iM);
  const tnM = el('td', 'op-nota');
  tnM.append(imagemMoedas(), el('span', 'op-nota-t', t('Moedas')));
  trM.append(tnM, tdM, el('td', 'n', ''));
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
    // o horário do dia (vendas · contagem e PO · carga), com a fase em curso
    if (d.horario) c.append(linhaHorario(d.horario));
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
    const i = numero(l.contado == null ? '' : l.contado, tArtigo(l.artigo));
    const ta = el('td');
    ta.append(el('span', 'op-cod', l.codigo), el('br'), document.createTextNode(tArtigo(l.artigo)));
    const tq = el('td', 'n');
    tq.append(i);
    if (l.unidade) tq.append(el('span', 'op-cod', ' ' + l.unidade));
    tr.append(ta, tq);
    tab.append(tr);
    linhas.push({ tr, campos: [i], codigo: l.codigo, texto: tArtigo(l.artigo), atipico: () => quantidadeAtipica(i.value, { unidade: l.unidade }) });
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

// ───────────── Inventário do armazém (técnico conta às cegas; controlo de stock valida; responsável aprova) ─────────────
// O técnico escolhe o armazém e conta artigo a artigo, pela lista desse armazém ou lendo o código de barras com a
// câmara (cada leitura soma uma unidade). A contagem entra «por validar»: o controlo de stock vê as diferenças, pede a
// recontagem dos artigos duvidosos ou valida com o motivo de cada diferença; o responsável aprova. Três pessoas.
const ESTADO_INV = {
  em_contagem: 'Em contagem',
  por_validar: 'Por validar (controlo de stock)',
  por_aprovar: 'Por aprovar (responsável)',
  aprovado: 'Aprovado',
  anulado: 'Anulado',
};
let BARRAS = null;
const garantirBarras = () => (BARRAS ? Promise.resolve(BARRAS) : apiJson('inventario?barras=1').then((d) => (BARRAS = (d && d.ok && d.barras) || {})));

function vistaInventario(c) {
  apiJson('inventario').then((d) => {
    c.textContent = '';
    if (!d || !d.ok) {
      c.append(el('p', 'ag-vazio', t((d && d.erro) || 'Não foi possível ler os dados.')));
      return;
    }
    E.inv = d;
    const p = d.pode;
    if (p.contar) c.append(cartaoNovoInventario());
    const L = d.lista || [];
    const grupo = (titulo, lista) => {
      if (!lista.length) return;
      c.append(el('h2', 'op-sub', titulo));
      lista.forEach((x) => c.append(cartaoInventario(x, d)));
    };
    grupo(
      t('Em contagem'),
      L.filter((x) => x.estado === 'em_contagem'),
    );
    if (p.validar || p.aprovar)
      grupo(
        t('Por validar'),
        L.filter((x) => x.estado === 'por_validar'),
      );
    if (p.aprovar || p.validar)
      grupo(
        t('Por aprovar'),
        L.filter((x) => x.estado === 'por_aprovar'),
      );
    grupo(t('Fechados (últimos 60 dias)'), L.filter((x) => x.estado === 'aprovado' || x.estado === 'anulado').slice(0, 10));
    if (!L.length && !p.contar) c.append(el('p', 'ag-vazio', t('Sem inventários por validar ou aprovar.')));
  });
}

function cartaoNovoInventario() {
  const k = el('section', 'ag-cartao op-inv-novo');
  k.append(el('h2', null, t('Novo inventário')));
  const erro = erroBox();
  const corpo = el('div');
  const comecar = botao(t('Escolher o armazém'), null, () => {
    comecar.disabled = true;
    corpo.textContent = '';
    corpo.append(el('p', 'ag-nota', t('A carregar…')));
    apiJson('inventario?armazens=1').then((d) => {
      comecar.disabled = false;
      corpo.textContent = '';
      if (!d || !d.ok) return mostrarErro(erro, d);
      if (!d.armazens.length) return corpo.append(el('p', 'ag-vazio', t('Sem armazéns com stock no Pinguim BO.')));
      const sel = el('select', 'op-numero');
      sel.setAttribute('aria-label', t('Armazém'));
      d.armazens.forEach((a) => sel.append(new Option(a.nome + ' · ' + t('{n} artigos', { n: n0(a.artigos) }), a.codigo)));
      const ir = botao(t('Começar a contagem'), null, () => {
        ir.disabled = true;
        postJson('inventario', { accao: 'criar', armazem: sel.value }).then((x) => {
          ir.disabled = false;
          if (!x || !x.ok) return mostrarErro(erro, x);
          mostrarInventario(x);
        });
      });
      corpo.append(sel, el('p', 'ag-nota', t('O stock do sistema fica congelado agora: não movimente o armazém (cargas, devoluções) durante a contagem.')), ir);
    });
  });
  k.append(el('p', 'ag-nota', t('Escolha o armazém e conte o que está lá, com a lista dos artigos ou o código de barras.')), comecar, corpo, erro);
  return k;
}

function cartaoInventario(x, d) {
  const k = el('section', 'ag-cartao op-inv');
  k.dataset.numero = x.numero;
  k.append(el('h3', null, x.numero + ' · ' + x.armazem_nome));
  k.append(el('p', 'ag-nota', t(ESTADO_INV[x.estado] || x.estado) + ' · ' + t('começado por {p} em {d}', { p: x.criado_por, d: ddmm(x.criado_em) })));
  if (x.estado !== 'em_contagem' && x.resumo)
    k.append(
      el(
        'p',
        x.resumo.diferencas ? 'op-mau' : 'op-ok',
        t('{n} artigos · {d} diferença(s) · falta {f} · sobra {s}', {
          n: n0(x.resumo.artigos),
          d: n0(x.resumo.diferencas),
          f: n0(x.resumo.faltas),
          s: n0(x.resumo.sobras),
        }),
      ),
    );
  if (x.estado === 'em_contagem' && x.recontar) k.append(el('p', 'op-mau', t('Recontagem pedida: {n} artigo(s).', { n: x.recontar })));
  const p = d.pode;
  let rot = t('Ver');
  if (x.estado === 'em_contagem' && p.contar) rot = t('Continuar a contagem');
  else if (x.estado === 'por_validar' && p.validar && x.contado_por !== d.eu) rot = t('Validar');
  else if (x.estado === 'por_aprovar' && p.aprovar && x.contado_por !== d.eu && x.validado_por !== d.eu) rot = t('Aprovar');
  k.append(botao(rot, rot === t('Ver') ? 'ag-sec-b' : null, () => abrirInventario(x.numero)));
  return k;
}

function abrirInventario(numero) {
  const c = $('op-conteudo');
  c.textContent = '';
  c.append(el('p', 'ag-vazio', t('A carregar…')));
  apiJson('inventario?numero=' + encodeURIComponent(numero)).then((d) => {
    if (!d || !d.ok) {
      c.textContent = '';
      c.append(el('p', 'ag-vazio', t((d && d.erro) || 'Não foi possível ler os dados.')));
      return;
    }
    mostrarInventario(d);
  });
}

/** Mostra o inventário no ecrã certo para quem o abre (contar, validar, aprovar ou só ver). */
function mostrarInventario(d) {
  const c = $('op-conteudo');
  c.textContent = '';
  const inv = d.inventario;
  const eu = (E.inv && E.inv.eu) || '';
  if (inv.estado === 'em_contagem' && d.pode.contar) c.append(formInventario(inv));
  else if (inv.estado === 'por_validar' && d.pode.validar && inv.contado_por !== eu) c.append(formValidarInventario(inv, d.motivos));
  else if (inv.estado === 'por_aprovar' && d.pode.aprovar && inv.contado_por !== eu && inv.validado_por !== eu) c.append(formAprovarInventario(inv, d.motivos));
  else c.append(detalheInventario(inv, d.motivos));
  c.append(botao(t('Voltar aos inventários'), 'ag-sec-b', () => desenhar()));
}

/** Contagem às cegas: lista do armazém, leitura do código de barras (cada leitura soma 1) e artigos fora da lista. */
function formInventario(inv) {
  const w = el('section', 'ag-cartao op-inventario');
  w.append(el('h2', null, inv.numero + ' · ' + inv.armazem_nome));
  w.append(
    el(
      'p',
      'ag-nota',
      t('Contagem às cegas: conte o que está fisicamente no armazém, artigo a artigo (0 se não houver). Os números do sistema só aparecem a quem valida.'),
    ),
  );
  const rec = new Set(inv.recontar || []);
  if (rec.size) w.append(el('p', 'op-mau', t('O controlo de stock pediu para recontar {n} artigo(s), assinalados a amarelo.', { n: rec.size })));
  const tab = el('table', 'op-tab');
  const cab = el('tr');
  [t('Artigo'), t('Contado')].forEach((x, i) => cab.append(el('th', i ? 'n' : null, x)));
  tab.append(cab);
  const linhas = [];
  const porCodigo = new Map();
  const extra = {};
  const juntar = (l, novo) => {
    const tr = el('tr', rec.has(l.codigo) ? 'op-recontar' : null);
    const i = numero(l.contado == null ? '' : l.contado, tArtigo(l.artigo));
    const ta = el('td');
    ta.append(
      el('span', 'op-cod', l.codigo + (l.fora_da_lista || novo ? ' · ' + t('fora da lista') : '')),
      el('br'),
      document.createTextNode(tArtigo(l.artigo)),
    );
    const tq = el('td', 'n');
    tq.append(comPassos(i, tArtigo(l.artigo), 'artigo'));
    tr.append(ta, tq);
    const x = { tr, campos: [i], codigo: l.codigo, texto: tArtigo(l.artigo), artigo: l.artigo, i };
    linhas.push(x);
    porCodigo.set(String(l.codigo), x);
    if (novo) tab.insertBefore(tr, tab.children[1] || null);
    else tab.append(tr);
    return x;
  };
  // os artigos a recontar primeiro
  const ls = (inv.linhas || []).slice().sort((a, b) => (rec.has(b.codigo) ? 1 : 0) - (rec.has(a.codigo) ? 1 : 0));
  ls.forEach((l) => juntar(l, false));
  const erro = erroBox();
  const estado = el('p', 'ag-nota op-lido-t');
  estado.setAttribute('aria-live', 'polite');
  const somar = (cod, txt) => {
    let x = porCodigo.get(cod);
    if (!x) {
      extra[cod] = t('Artigo {c}', { c: cod });
      x = juntar({ codigo: cod, artigo: extra[cod], contado: null }, true);
    }
    x.i.value = String((+x.i.value || 0) + 1);
    x.i.dispatchEvent(new Event('input', { bubbles: true }));
    x.tr.classList.add('op-lido');
    setTimeout(() => x.tr.classList.remove('op-lido'), 1200);
    const msg = tArtigo(x.artigo) + ': ' + x.i.value;
    estado.textContent = msg;
    if (txt) txt.textContent = msg;
  };
  const ler = botao(t('Ler códigos de barras'), null, () => {
    garantirBarras().then((barras) =>
      lerCodigo({
        tipo: 'barras',
        continuo: true,
        titulo: t('Aponte para o código de barras: cada leitura soma uma unidade.'),
        aceitar: (txt, linha) => {
          const cod = barras[txt] || (porCodigo.has(txt) ? txt : null);
          if (!cod) {
            linha.textContent = t('O código {c} não corresponde a nenhum artigo: junte-o à mão.', { c: txt });
            return false;
          }
          somar(String(cod), linha);
          return true;
        },
      }).catch((e) => {
        erro.hidden = false;
        erro.textContent = e.message;
      }),
    );
  });
  // artigo encontrado no armazém que não está na lista do sistema
  const fora = el('details', 'op-fora');
  fora.append(el('summary', null, t('Juntar um artigo que não está na lista')));
  const fc = el('input', 'op-numero');
  fc.placeholder = t('Código do artigo');
  fc.setAttribute('aria-label', t('Código do artigo'));
  const fn = el('input', 'op-numero');
  fn.placeholder = t('Nome do artigo');
  fn.setAttribute('aria-label', t('Nome do artigo'));
  fora.append(
    fc,
    fn,
    botao(t('Juntar'), 'ag-sec-b', () => {
      const cod = fc.value.trim();
      if (!/^[A-Za-z0-9._/-]{1,40}$/.test(cod)) {
        erro.hidden = false;
        erro.textContent = t('Código do artigo inválido.');
        return;
      }
      if (!porCodigo.has(cod)) {
        extra[cod] = fn.value.trim() || cod;
        juntar({ codigo: cod, artigo: extra[cod], contado: null }, true);
      }
      porCodigo.get(cod).i.focus();
      fc.value = fn.value = '';
    }),
  );
  const contagem = () => {
    const o = {};
    linhas.forEach((x) => (o[x.codigo] = x.i.value === '' ? '' : Number(x.i.value)));
    return o;
  };
  const enviar = (accao) => {
    erro.hidden = true;
    gravar.disabled = terminar.disabled = true;
    return postJson('inventario', { accao, numero: inv.numero, contagem: contagem(), extra }).then((x) => {
      gravar.disabled = terminar.disabled = false;
      if (!x || !x.ok) {
        mostrarErro(erro, x);
        const falta = (x && x.em_falta) || [];
        falta.forEach((cod) => porCodigo.has(cod) && porCodigo.get(cod).tr.classList.add('op-recontar'));
        if (falta.length && porCodigo.has(falta[0])) porCodigo.get(falta[0]).i.focus();
        return null;
      }
      return x;
    });
  };
  const gravar = botao(t('Gravar e continuar depois'), 'ag-sec-b', () =>
    enviar('gravar').then((x) => {
      if (!x) return;
      lc.limparRascunho();
      aviso(t('Contagem gravada: pode continuar mais tarde.'));
    }),
  );
  const terminar = botao(t('Terminar a contagem'), null, () =>
    lc.confirmar(() =>
      enviar('terminar').then((x) => {
        if (!x) return;
        lc.limparRascunho();
        aviso(t('Contagem terminada: segue para a validação.'));
        desenhar();
      }),
    ),
  );
  const lc = lancamento({ tabela: tab, linhas, gravar: terminar, chave: 'op-inventario-' + inv.numero });
  const topo = el('div', 'op-acc');
  topo.append(ler);
  w.append(topo, estado, lc.barra, tab, fora, erro);
  const acc = el('div', 'op-acc');
  acc.append(terminar, gravar);
  w.append(acc);
  return w;
}

/** Tabela das diferenças (quem valida e quem aprova). */
function tabelaDiferencas(inv, motivos, comControlos) {
  const tab = el('table', 'op-tab op-inv-dif');
  const cab = el('tr');
  [t('Artigo'), t('Sistema'), t('Contado'), t('Diferença')].forEach((x, i) => cab.append(el('th', i ? 'n' : null, x)));
  tab.append(cab);
  const controlos = {};
  const dif = (inv.linhas || []).filter((l) => l.diferenca != null && Math.abs(l.diferenca) > 0.0001);
  const iguais = (inv.linhas || []).length - dif.length;
  dif.forEach((l) => {
    const tr = el('tr', l.diferenca < 0 ? 'op-falta' : 'op-sobra');
    const ta = el('td');
    ta.append(el('span', 'op-cod', l.codigo), el('br'), document.createTextNode(tArtigo(l.artigo)));
    tr.append(ta, el('td', 'n', n0(l.sistema)), el('td', 'n', n0(l.contado)), el('td', 'n', (l.diferenca > 0 ? '+' : '') + n0(l.diferenca)));
    tab.append(tr);
    const m = (inv.motivos || {})[l.codigo] || {};
    if (comControlos) {
      const tr2 = el('tr', 'op-inv-mot');
      const td = el('td');
      td.colSpan = 4;
      const sel = el('select', 'op-numero');
      sel.setAttribute('aria-label', t('Motivo') + ' · ' + tArtigo(l.artigo));
      sel.append(new Option(t('Motivo da diferença…'), ''));
      Object.entries(motivos || {}).forEach(([k, v]) => sel.append(new Option(t(v), k)));
      const nota = el('input', 'op-numero');
      nota.maxLength = 200;
      nota.placeholder = t('Nota (obrigatória em «Outro»)');
      nota.setAttribute('aria-label', t('Nota') + ' · ' + tArtigo(l.artigo));
      const rc = el('label', 'op-recontar-l');
      const cb = el('input');
      cb.type = 'checkbox';
      rc.append(cb, document.createTextNode(' ' + t('Pedir recontagem deste artigo')));
      td.append(sel, nota, rc);
      tr2.append(td);
      tab.append(tr2);
      controlos[l.codigo] = { sel, nota, cb };
    } else if (m.motivo) {
      const tr2 = el('tr', 'op-inv-mot');
      const td = el('td', 'ag-nota', t((motivos || {})[m.motivo] || m.motivo) + (m.nota ? ' · ' + m.nota : ''));
      td.colSpan = 4;
      tr2.append(td);
      tab.append(tr2);
    }
  });
  return { tab, controlos, dif, iguais };
}

function cabecalhoInventario(inv) {
  const f = el('div');
  f.append(el('h2', null, inv.numero + ' · ' + inv.armazem_nome));
  const r = inv.resumo || {};
  f.append(
    el(
      'p',
      'ag-nota',
      t('Contado por {p} em {d} · sistema congelado em {b}', { p: inv.contado_por || '—', d: ddmm(inv.contado_em), b: ddmm(inv.base_em) }) +
        (inv.validado_por ? ' · ' + t('validado por {p}', { p: inv.validado_por }) : ''),
    ),
    el(
      'p',
      r.diferencas ? 'op-mau' : 'op-ok',
      t('{n} artigos · {d} diferença(s) · falta {f} · sobra {s}', {
        n: n0(r.artigos || 0),
        d: n0(r.diferencas || 0),
        f: n0(r.faltas || 0),
        s: n0(r.sobras || 0),
      }),
    ),
  );
  return f;
}

function formValidarInventario(inv, motivos) {
  const w = el('section', 'ag-cartao op-inventario');
  w.append(cabecalhoInventario(inv));
  const { tab, controlos, dif, iguais } = tabelaDiferencas(inv, motivos, true);
  if (!dif.length) w.append(el('p', 'op-ok', t('Sem diferenças: a contagem bate com o sistema.')));
  else w.append(el('p', 'ag-nota', t('Para cada diferença: o motivo, ou peça a recontagem do artigo ao técnico.')), tab);
  if (iguais) w.append(el('p', 'ag-nota', t('{n} artigo(s) sem diferença.', { n: n0(iguais) })));
  const erro = erroBox();
  const validar = botao(t('Validar a contagem'), null, () => {
    const recontar = Object.entries(controlos)
      .filter(([, c]) => c.cb.checked)
      .map(([k]) => k);
    const ms = {};
    Object.entries(controlos).forEach(([k, c]) => (ms[k] = { motivo: c.sel.value, nota: c.nota.value }));
    validar.disabled = true;
    postJson('inventario', { accao: 'validar', numero: inv.numero, motivos: ms, recontar }).then((x) => {
      validar.disabled = false;
      if (!x || !x.ok) return mostrarErro(erro, x);
      aviso(recontar.length ? t('Recontagem pedida ao técnico.') : t('Inventário validado: segue para a aprovação.'));
      desenhar();
    });
  });
  // assinalar algum «recontar» muda o botão: a contagem volta ao técnico só nesses artigos
  Object.values(controlos).forEach((c) =>
    c.cb.addEventListener('change', () => {
      const n = Object.values(controlos).filter((x) => x.cb.checked).length;
      validar.textContent = n ? t('Pedir a recontagem de {n} artigo(s)', { n }) : t('Validar a contagem');
    }),
  );
  const acc = el('div', 'op-acc');
  acc.append(validar, botaoAnular(inv, erro));
  w.append(erro, acc);
  return w;
}

function formAprovarInventario(inv, motivos) {
  const w = el('section', 'ag-cartao op-inventario');
  w.append(cabecalhoInventario(inv));
  const { tab, dif } = tabelaDiferencas(inv, motivos, false);
  if (dif.length) w.append(tab);
  w.append(el('p', 'ag-nota', t('Depois de aprovado, a Logística lança o acerto de inventário no Pinguim BO com estas diferenças.')));
  const erro = erroBox();
  const ok = botao(t('Aprovar o inventário'), null, () => {
    ok.disabled = true;
    postJson('inventario', { accao: 'aprovar', numero: inv.numero }).then((x) => {
      ok.disabled = false;
      if (!x || !x.ok) return mostrarErro(erro, x);
      aviso(t('Inventário aprovado.'));
      desenhar();
    });
  });
  const acc = el('div', 'op-acc');
  acc.append(ok, botaoAnular(inv, erro));
  w.append(erro, acc);
  return w;
}

function botaoAnular(inv, erro) {
  return botao(t('Anular'), 'ag-sec-b', () => {
    const motivo = window.prompt(t('Motivo da anulação do inventário {n}:', { n: inv.numero }));
    if (!motivo) return;
    postJson('inventario', { accao: 'anular', numero: inv.numero, motivo }).then((x) => {
      if (!x || !x.ok) return mostrarErro(erro, x);
      aviso(t('Inventário anulado.'));
      desenhar();
    });
  });
}

function detalheInventario(inv, motivos) {
  const w = el('section', 'ag-cartao op-inventario');
  if (inv.cega) {
    w.append(el('h2', null, inv.numero + ' · ' + inv.armazem_nome));
    w.append(el('p', 'ag-nota', t(ESTADO_INV[inv.estado] || inv.estado)));
    return w;
  }
  w.append(cabecalhoInventario(inv), el('p', 'ag-nota', t(ESTADO_INV[inv.estado] || inv.estado)));
  const { tab, dif } = tabelaDiferencas(inv, motivos, false);
  if (dif.length) w.append(tab);
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
  lerCodigo({
    tipo: 'qr',
    titulo: t('Aponte a câmara para o QR impresso na PO.'),
    aceitar: (txt) => /(PO|RQ)-\d{4}-\d{6}/.test(txt),
  })
    .then((txt) => {
      const m = txt && txt.match(/(PO|RQ)-\d{4}-\d{6}/);
      if (m) depois(m[0]);
    })
    .catch((e) => {
      erro.hidden = false;
      erro.textContent = e.message + ' ' + t('Escreva o número da PO.');
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
    const q = numero(l.quantidade, t('Carregado') + ' · ' + tArtigo(l.artigo));
    const m = campoMotivo(t('Motivo') + ' · ' + tArtigo(l.artigo));
    m.w.hidden = true;
    q.addEventListener('input', () => (m.w.hidden = q.value === '' || Number(q.value) === Number(l.quantidade)));
    const ta = el('td');
    ta.append(el('span', 'op-cod', l.codigo), el('br'), document.createTextNode(tArtigo(l.artigo)));
    const tq = el('td', 'n');
    tq.append(q, diferencaViva(q, l.quantidade), m.w);
    tr.append(ta, el('td', 'n', n0(l.quantidade)), tq);
    tab.append(tr);
    return {
      tr,
      campos: [q],
      codigo: l.codigo,
      texto: tArtigo(l.artigo),
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
