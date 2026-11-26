// Rastreabilidade de um artigo (servidor: nucleo/api/v1/rastreabilidade.php): o percurso completo no período, desenhado
// como um fluxo em movimento — fornecedores → armazéns centrais → carrinhas → clientes, com devoluções, saídas e acertos
// de inventário. Cada seta tem a espessura da quantidade e pontos de luz que correm no sentido do movimento (sem
// animação com «reduzir movimento»). O cursor do tempo mostra o percurso dia a dia (▶ reproduz). Carregar numa caixa ou
// numa seta abre os documentos dessa caixa/ligação; carregar num documento abre o detalhe (cabeçalho e linhas).
import { apiJson } from '../api.js';
import { dl, kz, n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { el } from '../util.js';
import { botao, janela } from './janelas.js';

const SVG = 'http://www.w3.org/2000/svg';
const s = (tag, at, txt) => {
  const e = document.createElementNS(SVG, tag);
  Object.entries(at || {}).forEach(([k, v]) => e.setAttribute(k, v));
  if (txt != null) e.textContent = txt;
  return e;
};
const reduzir = () => window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const COL = { fornecedores: 0, inventario: 1, central: 1, carrinha: 2, saidas: 2, clientes: 3 };
const NOME_FIXO = {
  FORN: 'Fornecedores e entradas',
  CLI: 'Clientes',
  SAI: 'Saídas e quebras',
  INV: 'Acertos de inventário',
};
let memo = { chave: '', d: null, aLer: false };

/** Secção da rastreabilidade de um artigo (código exacto) no período; lê uma vez por artigo e período. */
export function seccaoRastreio(artigo, de, ate, redesenhar) {
  const sec = el('section', 'secbox rast');
  sec.append(el('h3', null, t('Rastreabilidade do artigo')));
  const chave = [artigo, de, ate].join('|');
  if (memo.chave !== chave) {
    memo = { chave, d: null, aLer: true };
    apiJson('rastreabilidade?' + new URLSearchParams({ artigo, de, ate }).toString()).then((d) => {
      memo.aLer = false;
      memo.d = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler a rastreabilidade.') };
      redesenhar();
    });
  }
  const d = memo.d;
  if (!d) {
    sec.append(el('p', 'note', t('A ler o percurso do artigo no Pinguim BO…')));
    return sec;
  }
  if (d.falhou) {
    sec.append(el('p', 'erro', d.falhou));
    return sec;
  }
  sec.append(
    el(
      'p',
      'note',
      t('{c} · {n}: {d} documentos de {a} a {b}. Carregue numa caixa ou numa seta para ver os documentos; num documento, para o detalhe.', {
        c: d.artigo,
        n: d.nome,
        d: n0(d.documentos),
        a: dl(d.de),
        b: dl(d.ate),
      }),
    ),
  );
  if (!d.ligacoes.length) {
    sec.append(el('p', 'note', t('Sem movimentos deste artigo no período.')));
    return sec;
  }
  // dias do período (cursor do tempo)
  const diasP = [];
  for (let x = Date.parse(d.de + 'T12:00:00Z'); x <= Date.parse(d.ate + 'T12:00:00Z'); x += 864e5) diasP.push(new Date(x).toISOString().slice(0, 10));
  const palco = el('div', 'rast-palco');
  const barra = el('div', 'rast-tempo');
  const rng = el('input');
  rng.type = 'range';
  rng.min = '0';
  rng.max = String(diasP.length - 1);
  rng.value = String(diasP.length - 1);
  rng.setAttribute('aria-label', t('Dia do percurso'));
  const rotulo = el('span', 'rast-dia');
  let tempo = 0;
  const play = botao('▶ ' + t('Reproduzir'), 'sm', () => {
    if (tempo) {
      clearInterval(tempo);
      tempo = 0;
      play.textContent = '▶ ' + t('Reproduzir');
      return;
    }
    if (+rng.value >= diasP.length - 1) rng.value = '0';
    play.textContent = '❚❚ ' + t('Pausa');
    tempo = setInterval(() => {
      if (!document.body.contains(palco) || +rng.value >= diasP.length - 1) {
        clearInterval(tempo);
        tempo = 0;
        play.textContent = '▶ ' + t('Reproduzir');
        return;
      }
      rng.value = String(+rng.value + 1);
      desenhar();
    }, 700);
  });
  rng.addEventListener('input', desenhar);
  barra.append(play, rng, rotulo);
  sec.append(barra, palco);
  desenhar();
  return sec;

  function desenhar() {
    const ate = diasP[+rng.value];
    rotulo.textContent = +rng.value >= diasP.length - 1 ? t('Todo o período') : t('até {d}', { d: dl(ate) });
    palco.textContent = '';
    palco.append(fluxo(d, ate));
  }
}

/** Desenho do fluxo até ao dia $ate (inclusive): caixas por coluna e setas com a espessura da quantidade. */
function fluxo(d, ate) {
  const ligs = d.ligacoes
    .map((l) => {
      const docs = l.docs.filter((x) => x.em.slice(0, 10) <= ate);
      return { ...l, docs, q: docs.reduce((a, x) => a + x.q, 0) };
    })
    .filter((l) => l.q > 0);
  const nos = d.nos.map((n) => ({ ...n, nome: NOME_FIXO[n.id] ? t(NOME_FIXO[n.id]) : n.nome || n.cod }));
  const cols = [[], [], [], []];
  nos.forEach((n) => cols[COL[n.tipo] != null ? COL[n.tipo] : 1].push(n));
  // ordem: armazéns pelo código, carrinhas pela rota; saídas e inventário no fim da coluna
  cols.forEach((c) =>
    c.sort(
      (a, b) =>
        (a.id.length === 3 || a.id === 'FORN' ? 1 : 0) - (b.id.length === 3 || b.id === 'FORN' ? 1 : 0) ||
        String(a.rota || a.cod || '').localeCompare(String(b.rota || b.cod || '')),
    ),
  );
  const L = 230,
    A = 58,
    GAP = 18,
    XS = [20, 330, 640, 950];
  const altura = Math.max(...cols.map((c) => c.length)) * (A + GAP) + 40;
  const pos = {};
  cols.forEach((c, i) => {
    const total = c.length * (A + GAP) - GAP;
    const y0 = (altura - total) / 2;
    c.forEach((n, j) => (pos[n.id] = { x: XS[i], y: y0 + j * (A + GAP) }));
  });
  const max = Math.max(1, ...ligs.map((l) => l.q));
  const svg = s('svg', { viewBox: '0 0 1200 ' + altura, class: 'rast-svg', role: 'img', 'aria-label': t('Fluxo do artigo entre locais') });
  ligs.forEach((l, i) => {
    const a = pos[l.de],
      b = pos[l.para];
    if (!a || !b) return;
    const x1 = a.x + L,
      y1 = a.y + A / 2,
      x2 = b.x,
      y2 = b.y + A / 2;
    // devoluções (para trás): a curva passa por baixo das caixas
    const tras = x2 <= x1;
    const dpath = tras
      ? 'M' + a.x + ' ' + (y1 + 8) + ' C ' + (a.x - 80) + ' ' + (altura - 5) + ', ' + (b.x + L + 80) + ' ' + (altura - 5) + ', ' + (b.x + L) + ' ' + (y2 + 8)
      : 'M' + x1 + ' ' + y1 + ' C ' + (x1 + (x2 - x1) / 2) + ' ' + y1 + ', ' + (x1 + (x2 - x1) / 2) + ' ' + y2 + ', ' + x2 + ' ' + y2;
    const larg = 2 + (16 * l.q) / max;
    const id = 'rast-l-' + i;
    const p = s('path', { d: dpath, id, class: 'rast-lig' + (tras ? ' tras' : ''), 'stroke-width': larg.toFixed(1) });
    p.append(
      s(
        'title',
        null,
        (NOME_FIXO[l.de] ? t(NOME_FIXO[l.de]) : l.de.slice(2)) + ' → ' + (NOME_FIXO[l.para] ? t(NOME_FIXO[l.para]) : l.para.slice(2)) + ': ' + n0(l.q),
      ),
    );
    p.addEventListener('click', () => listaDocs(t('Ligação'), l.docs, [l]));
    svg.append(p);
    if (!reduzir()) {
      const n = Math.max(1, Math.min(6, Math.round((6 * l.q) / max)));
      for (let k = 0; k < n; k++) {
        const c = s('circle', { r: (2 + larg / 5).toFixed(1), class: 'rast-ponto' });
        const am = s('animateMotion', { dur: '3.2s', repeatCount: 'indefinite', begin: ((3.2 * k) / n).toFixed(2) + 's' });
        am.append(s('mpath', { href: '#' + id }));
        c.append(am);
        svg.append(c);
      }
    }
    // quantidade a meio da seta
    const t1 = s('text', { class: 'rast-q' }, n0(l.q));
    const tp = s('textPath', { href: '#' + id, startOffset: '50%', 'text-anchor': 'middle' });
    tp.textContent = n0(l.q);
    t1.textContent = '';
    t1.append(tp);
    svg.append(t1);
  });
  nos.forEach((n) => {
    const p = pos[n.id];
    if (!p) return;
    const g = s('g', { class: 'rast-no rast-' + n.tipo, tabindex: '0', role: 'button' });
    g.append(s('rect', { x: p.x, y: p.y, width: L, height: A, rx: 10 }));
    const nome = String(n.nome || '').slice(0, 30);
    g.append(
      s(
        'text',
        { x: p.x + 12, y: p.y + 22, class: 'rast-nome' },
        (n.tipo === 'carrinha' && n.rota ? n.rota + ' · ' : n.cod && n.tipo === 'central' ? n.cod + ' · ' : '') + nome,
      ),
    );
    const ent = ligs.filter((l) => l.para === n.id).reduce((a, l) => a + l.q, 0),
      sai = ligs.filter((l) => l.de === n.id).reduce((a, l) => a + l.q, 0);
    const linha2 =
      n.inicial != null ? t('stock {a} → {b}', { a: n0(n.inicial), b: n0(n.final) }) + ' · +' + n0(ent) + ' −' + n0(sai) : '+' + n0(ent) + ' −' + n0(sai);
    g.append(s('text', { x: p.x + 12, y: p.y + 44, class: 'rast-sub' }, linha2));
    if (n.diferenca && Math.abs(n.diferenca) > 0.001)
      g.append(s('text', { x: p.x + L - 10, y: p.y + 22, class: 'rast-dif', 'text-anchor': 'end' }, '⚠ ' + n0(n.diferenca)));
    g.append(s('title', null, n.nome));
    const abrir = () => {
      const docs = [];
      ligs.forEach((l) => {
        if (l.para === n.id || l.de === n.id)
          l.docs.forEach((x) => docs.push({ ...x, sentido: l.para === n.id ? 'entrada' : 'saida', outro: l.para === n.id ? l.de : l.para }));
      });
      listaDocs(n.nome, docs, null, n);
    };
    g.addEventListener('click', abrir);
    g.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        abrir();
      }
    });
    svg.append(g);
  });
  return svg;
}

const nomeLocal = (id) => (NOME_FIXO[id] ? t(NOME_FIXO[id]) : String(id).replace(/^A:/, ''));

/** Documentos de uma caixa ou de uma ligação; cada um abre o detalhe. */
function listaDocs(titulo, docs, ligs, no) {
  janela(titulo, no && no.inicial != null ? t('Stock no início {a} · no fim {b}', { a: n0(no.inicial), b: n0(no.final) }) : '', (b, ft, fechar) => {
    const ord = docs.slice().sort((x, y) => x.em.localeCompare(y.em));
    if (!ord.length) b.append(el('p', 'note', t('Sem documentos até ao dia escolhido.')));
    else {
      const tb = el('table', 'cc-tab rast-docs');
      const h = el('tr');
      [t('Data e hora'), t('Documento'), t('Tipo'), t('De / para'), t('Cliente ou fornecedor'), t('Quantidade')].forEach((x, i) =>
        h.append(el('th', i === 5 ? 'n' : null, x)),
      );
      const th = el('thead');
      th.append(h);
      const bd = el('tbody');
      ord.forEach((x) => {
        const tr = el('tr', 'rast-doc');
        tr.tabIndex = 0;
        const sentido = x.sentido
          ? (x.sentido === 'entrada' ? '← ' : '→ ') + nomeLocal(x.outro)
          : ligs
            ? nomeLocal(ligs[0].de) + ' → ' + nomeLocal(ligs[0].para)
            : '';
        tr.append(
          el('td', null, dl(x.em) + ' ' + x.em.slice(11, 16)),
          el('td', null, x.doc),
          el('td', null, x.tipo_nome ? x.tipo + ' · ' + x.tipo_nome : x.tipo),
          el('td', null, sentido),
          el('td', null, x.entidade || '—'),
          el('td', 'n', (x.sentido === 'saida' ? '−' : '') + n0(x.q)),
        );
        const abrir = () => detalheDoc(x.id);
        tr.addEventListener('click', abrir);
        tr.addEventListener('keydown', (e) => {
          if (e.key === 'Enter') abrir();
        });
        bd.append(tr);
      });
      tb.append(th, bd);
      b.append(tb);
    }
    ft.append(botao(t('Fechar'), 'ghost', fechar));
  });
}

/** Detalhe de um documento do Pinguim BO: cabeçalho e todas as linhas. */
function detalheDoc(id) {
  janela(t('Documento'), '', (b, ft, fechar) => {
    b.append(el('p', 'note', t('A carregar…')));
    ft.append(botao(t('Fechar'), 'ghost', fechar));
    apiJson('rastreabilidade?doc=' + encodeURIComponent(id)).then((r) => {
      b.textContent = '';
      if (!r || !r.ok) {
        b.append(el('p', 'erro', t((r && r.erro) || 'Não foi possível ler o documento.')));
        return;
      }
      const d = r.documento;
      const cab = el('dl', 'rast-cab');
      [
        [t('Documento'), d.doc + (d.anulado ? ' · ' + t('anulado') : '')],
        [t('Tipo'), d.tipo + (d.tipo_nome ? ' · ' + d.tipo_nome : '')],
        [t('Data e hora'), dl(d.em) + ' ' + String(d.em).slice(11, 16)],
        [t('Cliente ou fornecedor'), d.entidade || '—'],
        [t('Loja / rota'), d.loja || '—'],
        [t('Total'), d.total != null ? kz(d.total) : '—'],
      ].forEach(([k, v]) => cab.append(el('dt', null, k), el('dd', null, v)));
      b.append(cab);
      const tb = el('table', 'cc-tab');
      const inv = d.tipo === 'INV';
      const cols = inv
        ? [t('Código'), t('Artigo'), t('Armazém'), t('Teórico'), t('Contado'), t('Acerto')]
        : [t('Código'), t('Artigo'), t('Armazém'), t('Quantidade'), t('Efeito no stock'), t('Preço'), t('Total')];
      const h = el('tr');
      cols.forEach((x, i) => h.append(el('th', i >= 3 ? 'n' : null, x)));
      const th = el('thead');
      th.append(h);
      const bd = el('tbody');
      d.linhas.forEach((l) => {
        const tr = el('tr');
        tr.append(el('td', null, l.codigo), el('td', null, l.artigo), el('td', null, l.armazem || '—'));
        if (inv) tr.append(el('td', 'n', n0(l.teorico)), el('td', 'n', n0(l.contado)), el('td', 'n', n0(l.quantidade)));
        else
          tr.append(
            el('td', 'n', n0(l.quantidade)),
            el('td', 'n', (l.efeito > 0 ? '+' : '') + n0(l.efeito)),
            el('td', 'n', kz(l.preco)),
            el('td', 'n', kz(l.total)),
          );
        bd.append(tr);
      });
      tb.append(th, bd);
      b.append(tb);
    });
  });
}
