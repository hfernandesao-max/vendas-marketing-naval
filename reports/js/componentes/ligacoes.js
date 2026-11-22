// Ligações entre todas as tabelas (drill-down): em qualquer tabela do portal, o documento, o cliente, o artigo e a rota
// (ou o promotor) abrem a ficha respectiva; dentro de cada ficha tudo volta a ser clicável, para ir de uma linha de
// venda ao documento, do documento ao artigo, do artigo aos clientes que o compram e destes à rota, sem sair da página.
//   - documento → painel do documento (componentes/documento.js);
//   - cliente   → ficha do cliente (paginas/cliente-ficha.js);
//   - artigo    → ficha do artigo: no período, quantidades e valor, por rota, principais clientes e últimos documentos;
//   - rota      → ficha da rota: no período, vendas, documentos, principais clientes e artigos e atalhos às páginas
//                 da rota (conciliação da carrinha, requisições).
// Os dados são os já lidos do Pinguim BO para o período escolhido (as linhas de artigos pedem-se se faltarem); nada
// muda nos dados nem no Excel (as colunas continuam a exportar o texto).
import { artigosProntos, garantirArtigos } from '../fontes.js';
import { kz, n0, dl } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { inR } from '../periodo.js';
import { DB, el } from '../util.js';
import { botao, janela } from './janelas.js';

const TIPOS = { documento: 'documento', cliente: 'cliente', artigo: 'artigo', produto: 'artigo', rota: 'rota', promotor: 'rota' };

/** Tipo de ligação de uma coluna: c.ligar manda; senão pelo nome do campo (documento, cliente, artigo, rota, promotor). */
export function tipoLigacao(c) {
  if (c.ligar === false) return null;
  if (typeof c.ligar === 'string') return c.ligar;
  return TIPOS[c.key] || null;
}

/** O que a ligação precisa da linha para abrir a ficha certa (ou null quando a linha não chega). */
function alvo(tipo, v, r) {
  r = r || {};
  if (tipo === 'documento') return v || r.documento ? { documento: String(r.documento || v) } : null;
  if (tipo === 'cliente') {
    const codigo = r.cliente_codigo || (r.codigo && r.nome ? r.codigo : '') || '';
    if (!codigo && !r.nif && !v) return null;
    return { codigo: String(codigo), nif: String(r.nif || ''), nome: String(r.cliente || r.nome || v || ''), rota: r.rota != null ? String(r.rota) : '' };
  }
  if (tipo === 'artigo') {
    const codigo = r.codigo || r.produto_codigo || '';
    return codigo || v ? { codigo: String(codigo), artigo: String(r.artigo || r.produto || v || '') } : null;
  }
  if (tipo === 'rota') return r.rota != null && r.rota !== '' ? { rota: String(r.rota), promotor: String(r.promotor || '') } : null;
  return null;
}

/** Abre a ficha do tipo pedido. */
export function abrir(tipo, a) {
  if (tipo === 'documento') return import('./documento.js').then((m) => m.abrirDocumento(a.documento));
  if (tipo === 'cliente') return import('../paginas/cliente-ficha.js').then((m) => m.abrirFicha(a));
  if (tipo === 'artigo') return abrirArtigo(a);
  if (tipo === 'rota') return abrirRota(a);
}

/** Valor de uma célula com a ligação (botão com o texto) ou só o texto, se não houver ligação. */
export function celulaLigada(tipo, v, r, texto) {
  const a = alvo(tipo, v, r);
  const txt = texto != null ? texto : v == null || v === '' ? '—' : String(v);
  if (!a || txt === '—') return document.createTextNode(txt);
  const b = el('button', 'lig lig-' + tipo, txt);
  b.type = 'button';
  b.title = {
    documento: t('Abrir o documento'),
    cliente: t('Abrir a ficha do cliente'),
    artigo: t('Abrir a ficha do artigo'),
    rota: t('Abrir a ficha da rota'),
  }[tipo];
  b.addEventListener('click', (e) => {
    e.stopPropagation();
    abrir(tipo, a);
  });
  return b;
}

// ── fichas do artigo e da rota ──
const doPeriodo = () => (DB.vendas || []).filter((x) => inR(x.data));
const porDoc = () => {
  const m = new Map();
  doPeriodo().forEach((v) => m.set(v.documento, v));
  return m;
};
/** Soma por chave: [{k, nome, q, v, n}] ordenado pelo valor. */
function somar(linhas, chave, nome) {
  const m = new Map();
  linhas.forEach((x) => {
    const k = chave(x);
    if (k == null || k === '') return;
    const y = m.get(k) || { k, nome: nome(x), q: 0, v: 0, docs: new Set() };
    y.q += +x.quantidade || 0;
    y.v += +x.total || 0;
    if (x.documento) y.docs.add(x.documento);
    m.set(k, y);
  });
  return [...m.values()].map((y) => ({ ...y, n: y.docs.size })).sort((a, b) => b.v - a.v);
}
function quadros(lista) {
  const w = el('div', 'tiles lig-tiles');
  lista.forEach(([l, v]) => {
    const d = el('div', 'glass tile');
    d.append(el('span', 'l', l), el('span', 'v', v));
    w.append(d);
  });
  return w;
}
/** Tabela pequena da ficha: colunas [rótulo, fn(linha) → nó | texto, numérica?]. */
function tabelinha(titulo, cols, linhas, vazio) {
  const s = el('section', 'lig-sec');
  s.append(el('h3', null, titulo));
  if (!linhas.length) {
    s.append(el('p', 'note', vazio || t('Sem dados no período escolhido.')));
    return s;
  }
  const tb = el('table', 'cc-tab lig-tab');
  const h = el('tr');
  cols.forEach(([l, , n]) => h.append(el('th', n ? 'n' : null, l)));
  tb.append(h);
  linhas.forEach((x) => {
    const tr = el('tr');
    cols.forEach(([, f, n]) => {
      const td = el('td', n ? 'n' : null);
      const c = f(x);
      td.append(c instanceof Node ? c : document.createTextNode(c == null ? '—' : String(c)));
      tr.append(td);
    });
    tb.append(tr);
  });
  const w = el('div', 'cc-scroll');
  w.append(tb);
  s.append(w);
  return s;
}
/** Janela larga com o conteúdo que espera pelas linhas de artigos (pedidas se ainda não chegaram). */
function fichaComLinhas(titulo, nota, montar) {
  janela(titulo, nota, (b, ft, fechar, d) => {
    d.classList.add('dlg-largo', 'lig-dlg');
    const desenhar = () => {
      b.replaceChildren();
      if (!artigosProntos()) {
        b.append(el('p', 'note', t('A carregar as linhas de artigos…')));
        return;
      }
      montar(b);
    };
    desenhar();
    if (!artigosProntos()) garantirArtigos().then(() => d.open && desenhar());
    ft.append(botao(t('Fechar'), 'ghost', fechar));
  });
}

export function abrirArtigo(a) {
  const nome = a.artigo || a.codigo;
  fichaComLinhas(nome, [a.codigo, t('no período escolhido')].filter(Boolean).join(' · '), (b) => {
    const docs = porDoc();
    const linhas = (DB.artigos || []).filter((l) => docs.has(l.documento) && (a.codigo ? String(l.codigo) === a.codigo : l.artigo === a.artigo));
    const comDoc = linhas.map((l) => ({ ...l, venda: docs.get(l.documento) }));
    const q = comDoc.reduce((s, x) => s + (+x.quantidade || 0), 0);
    const v = comDoc.reduce((s, x) => s + (+x.total || 0), 0);
    const clientes = somar(
      comDoc,
      (x) => x.venda.cliente_codigo || x.venda.nif || x.venda.cliente,
      (x) => x.venda.cliente,
    );
    const rotas = somar(
      comDoc,
      (x) => x.venda.rota,
      (x) => x.venda.promotor,
    );
    b.append(
      quadros([
        [t('Quantidade'), n0(q) + (linhas[0] && linhas[0].unidade ? ' ' + linhas[0].unidade : '')],
        [t('Valor'), kz(v)],
        [t('Documentos'), n0(new Set(linhas.map((l) => l.documento)).size)],
        [t('Clientes'), n0(clientes.length)],
        [t('Preço médio'), q ? kz(v / q) : '—'],
      ]),
      tabelinha(
        t('Por rota'),
        [
          [t('Rota'), (x) => celulaLigada('rota', x.k, { rota: x.k, promotor: x.nome }, x.k + (x.nome ? ' · ' + x.nome : ''))],
          [t('Quantidade'), (x) => n0(x.q), true],
          [t('Valor'), (x) => kz(x.v), true],
        ],
        rotas,
      ),
      tabelinha(
        t('Principais clientes'),
        [
          [t('Cliente'), (x) => celulaLigada('cliente', x.nome, clienteDe(comDoc, x.k), x.nome || x.k)],
          [t('Quantidade'), (x) => n0(x.q), true],
          [t('Valor'), (x) => kz(x.v), true],
          [t('Documentos'), (x) => n0(x.n), true],
        ],
        clientes.slice(0, 15),
      ),
      tabelinha(
        t('Últimos documentos'),
        [
          [t('Documento'), (x) => celulaLigada('documento', x.documento, x)],
          [t('Data'), (x) => dl(x.venda.data)],
          [t('Cliente'), (x) => celulaLigada('cliente', x.venda.cliente, x.venda)],
          [t('Quantidade'), (x) => n0(x.quantidade), true],
          [t('Valor'), (x) => kz(x.total), true],
        ],
        comDoc
          .slice()
          .sort((p, s) => String(s.venda.data + (s.venda.hora || '')).localeCompare(String(p.venda.data + (p.venda.hora || ''))))
          .slice(0, 20),
      ),
    );
  });
}
const clienteDe = (comDoc, k) => {
  const x = comDoc.find((y) => (y.venda.cliente_codigo || y.venda.nif || y.venda.cliente) === k);
  return x ? x.venda : null;
};

export function abrirRota(a) {
  fichaComLinhas(t('Rota {r}', { r: a.rota }) + (a.promotor ? ' · ' + a.promotor : ''), t('no período escolhido'), (b) => {
    const vendas = doPeriodo().filter((v) => String(v.rota) === a.rota);
    const docs = new Set(vendas.map((v) => v.documento));
    const porDocumento = new Map(vendas.map((v) => [v.documento, v]));
    const linhas = (DB.artigos || []).filter((l) => docs.has(l.documento)).map((l) => ({ ...l, venda: porDocumento.get(l.documento) }));
    const total = vendas.reduce((s, v) => s + (+v.total || 0), 0);
    const clientes = somar(
      vendas.map((v) => ({ ...v, quantidade: 0 })),
      (x) => x.cliente_codigo || x.nif || x.cliente,
      (x) => x.cliente,
    );
    const artigos = somar(
      linhas,
      (x) => x.codigo,
      (x) => x.artigo,
    );
    const dias = new Set(vendas.map((v) => v.data)).size;
    const atalhos = el('div', 'lig-atalhos');
    [
      ['#conciliacao-carrinha', t('Conciliação da carrinha')],
      ['#requisicoes-carrinha', t('Requisições (PO)')],
    ].forEach(([h, l]) => {
      const x = el('a', 'btn ghost sm', l);
      x.href = h;
      x.addEventListener('click', () => {
        const d = x.closest('dialog');
        if (d) d.close();
      });
      atalhos.append(x);
    });
    b.append(
      quadros([
        [t('Vendas'), kz(total)],
        [t('Documentos'), n0(vendas.length)],
        [t('Clientes'), n0(clientes.length)],
        [t('Dias com vendas'), n0(dias)],
        [t('Média por dia'), dias ? kz(total / dias) : '—'],
      ]),
      atalhos,
      tabelinha(
        t('Principais clientes'),
        [
          [
            t('Cliente'),
            (x) =>
              celulaLigada(
                'cliente',
                x.nome,
                vendas.find((v) => (v.cliente_codigo || v.nif || v.cliente) === x.k),
                x.nome || x.k,
              ),
          ],
          [t('Valor'), (x) => kz(x.v), true],
          [t('Documentos'), (x) => n0(x.n), true],
        ],
        clientes.slice(0, 15),
      ),
      tabelinha(
        t('Principais artigos'),
        [
          [t('Artigo'), (x) => celulaLigada('artigo', x.nome, { codigo: x.k, artigo: x.nome }, x.nome || x.k)],
          [t('Quantidade'), (x) => n0(x.q), true],
          [t('Valor'), (x) => kz(x.v), true],
        ],
        artigos.slice(0, 15),
      ),
      tabelinha(
        t('Últimos documentos'),
        [
          [t('Documento'), (x) => celulaLigada('documento', x.documento, x)],
          [t('Data'), (x) => dl(x.data) + (x.hora ? ' ' + String(x.hora).slice(0, 5) : '')],
          [t('Cliente'), (x) => celulaLigada('cliente', x.cliente, x)],
          [t('Valor'), (x) => kz(x.total), true],
        ],
        vendas
          .slice()
          .sort((p, s) => String(s.data + (s.hora || '')).localeCompare(String(p.data + (p.hora || ''))))
          .slice(0, 20),
      ),
    );
  });
}
