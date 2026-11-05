// Clientes › Pedidos dos clientes (validação pelo back-office) e Cartazes QR (A4 para colar no estabelecimento).
// Servidor: nucleo/api/v1/pedidos-clientes.php e nucleo/servicos/clientes-qr.php. Os pedidos chegam pela página pública
// do cartaz (c/) e ficam pendentes; aprovar uma encomenda avisa o promotor da rota, aprovar dados junta-os à lista a
// lançar no Pinguim BO (só de leitura para o portal), aprovar um consentimento regista-o para as mensagens.
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { pill, secBox, tiles } from '../componentes/blocos.js';
import { botao, campo, entrada, janela, msgErro } from '../componentes/janelas.js';
import { dl, n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { atual } from '../perfil.js';
import { carregarScript, postJson } from '../servidor.js';
import { el, toast } from '../util.js';
import { R } from './registo.js';

let PED = null;
let aLer = false;
let filtro = 'pendente';
function lerPedidos() {
  if (aLer) return;
  aLer = true;
  apiJson('pedidos-clientes').then((d) => {
    aLer = false;
    PED = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os dados.') };
    if (atual === 'pedidos-clientes') draw();
  });
}
const TIPOS = { encomenda: 'Encomenda', dados: 'Actualização de dados', consentimento: 'Mensagens por WhatsApp' };
const ESTADOS = { pendente: ['Pendente', 'p-warn'], aprovado: ['Aprovado', 'p-ok'], recusado: ['Recusado', 'p-bad'] };
const CAMPOS = {
  nome_comercial: 'Nome comercial',
  contacto_nome: 'Pessoa de contacto',
  contacto_telefone: 'Telefone',
  email: 'Correio electrónico',
  morada: 'Morada',
  horario: 'Horário',
  observacoes: 'Observações',
};
const hora = (s) => dl(s) + ' ' + String(s || '').slice(11, 16);

function conteudo(p) {
  const c = el('div', 'pc-conteudo');
  const x = p.conteudo || {};
  if (p.tipo === 'encomenda') {
    const ul = el('ul');
    (x.linhas || []).forEach((l) => {
      const li = el('li', null, n0(l.quantidade) + ' ' + (l.unidade || '') + ' · ' + l.nome + ' ');
      const v = (p.verificacao || {})[l.codigo];
      if (v)
        li.append(
          v.fora
            ? pill(v.habitual ? t('fora do habitual: {f}× o que costuma levar', { f: n0(v.factor) }) : t('artigo novo em grande quantidade'), 'p-warn')
            : pill(v.habitual ? t('dentro do habitual ({q})', { q: n0(v.habitual) }) : t('artigo novo'), 'p-mut'),
        );
      ul.append(li);
    });
    c.append(ul);
  }
  if (p.tipo === 'consentimento') c.append(el('p', null, t('Aceitou o texto do consentimento em {d}.', { d: hora(p.criado) })));
  Object.keys(CAMPOS).forEach((k) => {
    if (x[k]) c.append(el('p', null, t(CAMPOS[k]) + ': ' + x[k]));
  });
  if (x.latitude != null) {
    const a = el('a', 'linkbtn', t('Ver a localização no mapa'));
    a.href = `https://www.google.com/maps?q=${x.latitude},${x.longitude}`;
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    c.append(a);
  }
  return c;
}
function decidir(p, accao) {
  janela(
    accao === 'aprovar' ? t('Aprovar o pedido') : t('Recusar o pedido'),
    accao === 'aprovar'
      ? t('Confirme antes com o cliente, pelo telefone indicado, se o pedido é dele.')
      : t('O motivo fica registado. O cliente não é avisado automaticamente.'),
    (b, ft, fechar) => {
      const motivo = entrada('text', '', { maxLength: 300 });
      const erro = msgErro();
      b.append(campo(accao === 'aprovar' ? t('Nota (facultativa)') : t('Motivo'), motivo), erro);
      ft.append(
        botao(t('Cancelar'), 'sec', fechar),
        botao(accao === 'aprovar' ? t('Aprovar') : t('Recusar'), accao === 'aprovar' ? null : 'perigo', () =>
          postJson('pedidos-clientes', { id: p.id, accao, motivo: motivo.value }).then((r) => {
            if (!r.ok) {
              erro.hidden = false;
              erro.textContent = t(r.erro || 'Não foi possível gravar.');
              return;
            }
            fechar();
            PED = null;
            toast(accao === 'aprovar' ? t('Pedido aprovado.') : t('Pedido recusado.'));
            draw();
          }),
        ),
      );
    },
  );
}

R['pedidos-clientes'] = (pg) => {
  if (!PED) {
    pg.append(el('div', 'glass empty', t('A carregar…')));
    lerPedidos();
    return;
  }
  if (PED.falhou) {
    pg.append(el('div', 'glass empty', t(PED.falhou)));
    return;
  }
  const n = (e, tp) => PED.pedidos.filter((p) => p.estado === e && (!tp || p.tipo === tp)).length;
  pg.append(
    tiles([
      { l: t('Por validar'), v: n0(n('pendente')), s: t('pedidos dos clientes'), tone: n('pendente') ? 'warn' : null },
      { l: t('Encomendas pendentes'), v: n0(n('pendente', 'encomenda')), s: t('confirmar com o cliente') },
      { l: t('Aprovados (30 dias)'), v: n0(n('aprovado')), s: t('encomendas, dados e consentimentos') },
    ]),
  );
  const barra = el('div', 'gst-filtros');
  [
    ['pendente', 'Por validar'],
    ['aprovado', 'Aprovados'],
    ['recusado', 'Recusados'],
  ].forEach(([e, txt]) => {
    const b = botao(t(txt), 'sec' + (filtro === e ? ' on' : ''), () => ((filtro = e), draw()));
    b.setAttribute('aria-pressed', filtro === e ? 'true' : 'false');
    barra.append(b);
  });
  const lista = el('div', 'gst-lista');
  const vis = PED.pedidos.filter((p) => p.estado === filtro);
  if (!vis.length) lista.append(el('p', 'note', t('Sem pedidos.')));
  vis.forEach((p) => {
    const c = el('article', 'gst-alerta g-' + (p.estado === 'pendente' ? 'aviso' : p.estado === 'aprovado' ? 'info' : 'critico'));
    const h = el('div', 'gst-alerta-h');
    h.append(
      pill(t(ESTADOS[p.estado][0]), ESTADOS[p.estado][1]),
      el('strong', null, t(TIPOS[p.tipo] || p.tipo) + ' · ' + p.nome + ' (' + t('rota {r}', { r: p.rota }) + ')'),
    );
    c.append(h, el('p', null, t('Recebido em {d}', { d: hora(p.criado) })), conteudo(p));
    if (p.estado !== 'pendente')
      c.append(el('p', null, t('Decidido por {q} em {d}', { q: p.decidido_por, d: hora(p.decidido) }) + (p.motivo ? ' · ' + p.motivo : '')));
    else if (PED.gere) {
      const acc = el('div', 'gst-filtros');
      acc.append(
        botao(t('Aprovar'), null, () => decidir(p, 'aprovar')),
        botao(t('Recusar'), 'sec', () => decidir(p, 'recusar')),
      );
      c.append(acc);
    }
    lista.append(c);
  });
  const corpo = el('div');
  corpo.append(barra, lista);
  pg.append(
    secBox(
      t('Pedidos dos clientes'),
      t(
        'Feitos pelos clientes no código QR do cartaz. Nada muda sem validação: as encomendas aprovadas avisam o promotor da rota; os dados aprovados ficam na lista para lançar no Pinguim BO.',
      ),
      corpo,
    ),
  );
};

// ───────────────────────── Cartazes QR ─────────────────────────

let QR = null;
let rotaQr = '';
const escolhidos = new Set();
function lerQr(rota) {
  QR = { aLer: true };
  apiJson('clientes-qr?rota=' + encodeURIComponent(rota)).then((d) => {
    QR = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os dados.') };
    escolhidos.clear();
    if (atual === 'clientes-qr') draw();
  });
}

/** Cartazes A4 (um por página) com o código QR de cada cliente, impressos pelo navegador (ou guardados em PDF). */
function imprimir(codigos) {
  carregarScript('qrcode.js', 'qrcode').then(() => {
    const zona = el('div', 'cqr-impressao');
    codigos.forEach((c) => {
      const f = el('section', 'cqr-folha');
      const q = window.qrcode(0, 'M');
      q.addData(c.url);
      q.make();
      const qr = el('div', 'cqr-qr');
      qr.innerHTML = q.createSvgTag({ cellSize: 10, margin: 2, scalable: true }); // nosemgrep: insecure-innerhtml -- SVG gerado pela biblioteca de QR
      const logo = el('img');
      logo.src = '../assets/naval-emblema.png';
      logo.alt = '';
      f.append(
        logo,
        el('h1', null, 'Encomende aqui'),
        el('p', 'cqr-sub', 'e actualize os dados da sua empresa'),
        qr,
        el('ol', null),
        el('p', 'cqr-cliente', c.nome),
        el('p', 'cqr-rodape', 'Rota ' + c.rota + ' · Grupo Naval · Os pedidos são confirmados pela nossa equipa.'),
      );
      const ol = f.querySelector('ol');
      [
        'Aponte a câmara do telemóvel ao código.',
        'Escolha os artigos e as quantidades, ou actualize os seus dados.',
        'Envie: confirmamos consigo pelo telefone.',
      ].forEach((x) => ol.append(el('li', null, x)));
      // cada cartaz numa página A4 própria, sem margens do navegador (sem título, endereço, data nem n.º de página)
      const pag = el('div', 'cqr-pagina');
      pag.append(f);
      zona.append(pag);
    });
    document.body.append(zona);
    document.body.classList.add('a-imprimir');
    // página sem margens só para os cartazes: o navegador deixa de imprimir título, endereço, data e n.º de página
    const pagina = el('link');
    pagina.rel = 'stylesheet';
    pagina.href = 'css/cartaz-impressao.css';
    pagina.id = 'cqr-pagina-css';
    const fim = () => {
      document.body.classList.remove('a-imprimir');
      zona.remove();
      pagina.remove();
      window.removeEventListener('afterprint', fim);
    };
    window.addEventListener('afterprint', fim);
    const imprimir = () => setTimeout(() => window.print(), 200);
    pagina.addEventListener('load', imprimir, { once: true });
    pagina.addEventListener('error', imprimir, { once: true });
    document.head.append(pagina);
  });
}
function gerar(novo) {
  const sel = QR.clientes.filter((c) => escolhidos.has(c.cliente));
  if (!sel.length) return toast(t('Escolha pelo menos um cliente.'));
  postJson('clientes-qr', { rota: rotaQr, clientes: sel.map((c) => ({ cliente: c.cliente })), novo }).then((r) => {
    if (!r.ok) return toast(t(r.erro || 'Não foi possível gerar os códigos.'), 6000);
    imprimir(r.codigos);
    lerQr(rotaQr);
  });
}

R['clientes-qr'] = (pg) => {
  const topo = el('div', 'gst-filtros');
  const sel = el('select');
  sel.setAttribute('aria-label', t('Rota'));
  const rotas = (QR && QR.rotas) || [];
  sel.append(el('option', null, t('Escolha a rota')));
  rotas.forEach((r) => {
    const o = el('option', null, r.rota + (r.promotor ? ' · ' + r.promotor : ''));
    o.value = r.rota;
    if (r.rota === rotaQr) o.selected = true;
    sel.append(o);
  });
  sel.addEventListener('change', () => {
    rotaQr = sel.value;
    lerQr(rotaQr);
    draw();
  });
  topo.append(sel);
  if (!QR) {
    // primeira abertura: a lista de rotas vem com a primeira rota do perfil
    apiJson('clientes-qr?rota=' + encodeURIComponent(rotaQr || '0')).then((d) => {
      QR = { rotas: (d && d.rotas) || [], clientes: [] };
      if (!rotaQr && QR.rotas.length) {
        rotaQr = QR.rotas[0].rota;
        lerQr(rotaQr);
      }
      if (atual === 'clientes-qr') draw();
    });
    QR = { aLer: true, rotas: [] };
  }
  const corpo = el('div');
  corpo.append(topo);
  if (QR.aLer) corpo.append(el('p', 'note', t('A carregar…')));
  else if (QR.falhou) corpo.append(el('p', 'note', t(QR.falhou)));
  else if (QR.clientes) {
    const tw = el('div', 'tw'),
      tab = el('table'),
      th = el('thead'),
      tr0 = el('tr');
    const todos = entrada('checkbox');
    todos.setAttribute('aria-label', t('Escolher todos'));
    todos.checked = QR.clientes.length > 0 && escolhidos.size === QR.clientes.length;
    todos.addEventListener('change', () => {
      QR.clientes.forEach((c) => (todos.checked ? escolhidos.add(c.cliente) : escolhidos.delete(c.cliente)));
      draw();
    });
    const c0 = el('th');
    c0.append(todos);
    tr0.append(c0, el('th', null, t('Cliente')), el('th', 'n', t('Compras (120 dias)')), el('th', null, t('Última compra')), el('th', null, t('Cartaz')));
    th.append(tr0);
    const tb = el('tbody');
    QR.clientes.forEach((c) => {
      const tr = el('tr');
      const cb = entrada('checkbox');
      cb.checked = escolhidos.has(c.cliente);
      cb.setAttribute('aria-label', c.nome);
      cb.addEventListener('change', () => (cb.checked ? escolhidos.add(c.cliente) : escolhidos.delete(c.cliente)));
      const td = el('td');
      td.append(cb);
      tr.append(
        td,
        el('td', null, c.nome || c.cliente),
        el('td', 'n', n0(c.compras)),
        el('td', null, dl(c.ultima)),
        el('td', null, c.qr ? t('criado em {d}', { d: dl(c.qr) }) : '—'),
      );
      tb.append(tr);
    });
    tab.append(th, tb);
    tw.append(tab);
    const acc = el('div', 'gst-filtros');
    acc.append(
      botao(t('Imprimir cartazes (A4)'), null, () => gerar(false)),
      botao(t('Substituir códigos e imprimir'), 'sec', () => gerar(true)),
    );
    corpo.append(
      tw,
      acc,
      el(
        'p',
        'note',
        t(
          '«Substituir» invalida os cartazes anteriores dos clientes escolhidos (cartaz perdido ou copiado). Imprimir em A4, a cores, e colar em local visível.',
        ),
      ),
    );
  }
  pg.append(
    secBox(
      t('Cartazes QR dos clientes'),
      t('Cada cliente tem um código próprio e aleatório; o cartaz leva-o à página de encomendas e de actualização de dados.'),
      corpo,
    ),
  );
};
