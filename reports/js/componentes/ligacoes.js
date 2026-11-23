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
import { apiJson } from '../api.js';
import { artigosProntos, fetchVendas, garantirArtigos } from '../fontes.js';
import { kz, n0, dl, pct } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { guardar, inR, st } from '../periodo.js';
import { DB, el, toast } from '../util.js';
import { botao, janela } from './janelas.js';
import { tArtigo } from '../i18n/artigos.js';

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

// rasto das fichas abertas umas sobre as outras (Documento › Artigo › Rota): «voltar» fecha a de cima
const RASTO = [];
const nomeDe = (tipo, a) =>
  ({ documento: a.documento, cliente: a.nome || a.codigo, artigo: a.artigo || a.codigo, rota: t('Rota {r}', { r: a.rota }) })[tipo] || '';
/** Ligação para partilhar a ficha: abre o portal na página actual com a ficha aberta (#página?ficha=tipo:valor). */
export function ligacaoFicha(tipo, a) {
  const v = { documento: a.documento, cliente: a.codigo || a.nif || a.nome, artigo: a.codigo || a.artigo, rota: a.rota }[tipo];
  const pag = (location.hash.slice(1).split('?')[0] || 'inicio').split('/')[0];
  return location.origin + location.pathname + '#' + pag + '?ficha=' + encodeURIComponent(tipo + ':' + v);
}

/** Abre a ficha do tipo pedido (fica no rasto até fechar). */
export function abrir(tipo, a) {
  RASTO.push({ tipo, nome: nomeDe(tipo, a) });
  const antes = new Set(document.querySelectorAll('dialog[open]'));
  const p =
    tipo === 'documento'
      ? import('./documento.js').then((m) => m.abrirDocumento(a.documento))
      : tipo === 'cliente'
        ? import('../paginas/cliente-ficha.js').then((m) => m.abrirFicha(a))
        : Promise.resolve(tipo === 'artigo' ? abrirArtigo(a) : tipo === 'rota' ? abrirRota(a) : null);
  return p.then(() => {
    const nova = [...document.querySelectorAll('dialog[open]')].find((d) => !antes.has(d));
    if (!nova) {
      RASTO.pop();
      return;
    }
    nova.addEventListener('close', () => RASTO.pop(), { once: true });
  });
}

/** Abre a ficha pedida no endereço (#página?ficha=tipo:valor), quando os dados do período já chegaram. */
export function abrirFichaDoEndereco() {
  const m = location.hash.match(/[?&]ficha=([^&]+)/);
  if (!m) return;
  const [tipo, ...resto] = decodeURIComponent(m[1]).split(':');
  const v = resto.join(':');
  let n = 0;
  const tentar = () => {
    if (!(DB.vendas || []).length && n++ < 40) return setTimeout(tentar, 250);
    const venda = (DB.vendas || []).find((x) => x.cliente_codigo === v || x.nif === v || x.cliente === v) || {};
    const a = {
      documento: { documento: v },
      cliente: { codigo: venda.cliente_codigo || v, nif: venda.nif || '', nome: venda.cliente || v, rota: venda.rota || '' },
      artigo: { codigo: v, artigo: ((DB.artigos || []).find((x) => String(x.codigo) === v) || {}).artigo || v },
      rota: { rota: v, promotor: '' },
    }[tipo];
    if (a) abrir(tipo, a);
  };
  tentar();
}

/**
 * Procura nos dados do período (pesquisa global, Ctrl+K): documentos, clientes, artigos, rotas e números de PO.
 * Devolve [{tipo, texto, rotulo, icon, alvo}] — até 5 de cada tipo.
 */
export function procurarDados(q, D) {
  const n = (s) =>
    String(s || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase();
  const x = n(q);
  if (x.length < 2) return [];
  const out = [];
  const vendas = D.vendas || [];
  const po = q.toUpperCase().match(/^(PO|RQ)-\d{4}-\d{6}$/);
  if (po) out.push({ tipo: 'po', texto: po[0], rotulo: 'PO · abrir em Cargas (PO)', icon: 'conferir', alvo: { numero: po[0] } });
  vendas
    .filter((v) => n(v.documento).includes(x))
    .slice(0, 5)
    .forEach((v) =>
      out.push({ tipo: 'documento', texto: v.documento, rotulo: 'Documento · ' + (v.cliente || ''), icon: 'file', alvo: { documento: v.documento } }),
    );
  const cli = new Map();
  vendas.forEach((v) => {
    const k = v.cliente_codigo || v.nif || v.cliente;
    if (k && !cli.has(k) && (n(v.cliente).includes(x) || n(v.nif) === x || n(v.cliente_codigo) === x))
      cli.set(k, { codigo: v.cliente_codigo || '', nif: v.nif || '', nome: v.cliente || '', rota: String(v.rota || '') });
  });
  [...cli.values()].slice(0, 5).forEach((c) => out.push({ tipo: 'cliente', texto: c.nome || c.codigo, rotulo: 'Cliente', icon: 'users', alvo: c }));
  const art = new Map();
  (D.artigos || []).forEach((l) => {
    if (!art.has(l.codigo) && (n(l.artigo).includes(x) || n(l.codigo) === x)) art.set(l.codigo, { codigo: String(l.codigo), artigo: l.artigo });
  });
  [...art.values()].slice(0, 5).forEach((a) => out.push({ tipo: 'artigo', texto: tArtigo(a.artigo), rotulo: 'Artigo · ' + a.codigo, icon: 'box', alvo: a }));
  const rot = new Map();
  vendas.forEach((v) => {
    const r = String(v.rota || '');
    if (r && !rot.has(r) && (r.includes(x) || n(v.promotor).includes(x))) rot.set(r, { rota: r, promotor: v.promotor || '' });
  });
  [...rot.values()]
    .slice(0, 5)
    .forEach((r) => out.push({ tipo: 'rota', texto: r.rota + (r.promotor ? ' · ' + r.promotor : ''), rotulo: 'Rota', icon: 'truck', alvo: r }));
  return out;
}

/** Valor de uma célula com a ligação (botão com o texto) ou só o texto, se não houver ligação. */
export function celulaLigada(tipo, v, r, texto) {
  const a = alvo(tipo, v, r);
  const txt = texto != null ? texto : v == null || v === '' ? '—' : tipo === 'artigo' ? String(tArtigo(String(v))) : String(v);
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
function fichaComLinhas(titulo, nota, montar, tipo, a) {
  janela(titulo, nota, (b, ft, fechar, d) => {
    d.classList.add('dlg-largo', 'lig-dlg');
    // rasto (de onde se veio), voltar e partilhar
    const rasto = RASTO.slice(0, -1).map((x) => x.nome);
    const desenhar = () => {
      b.replaceChildren();
      if (rasto.length) b.append(el('p', 'lig-rasto', rasto.concat(titulo).join(' › ')));
      if (!artigosProntos()) {
        b.append(el('p', 'note', t('A carregar as linhas de artigos…')));
        return;
      }
      montar(b);
    };
    desenhar();
    if (!artigosProntos()) garantirArtigos().then(() => d.open && desenhar());
    if (rasto.length) ft.append(botao('‹ ' + t('Voltar'), 'ghost', fechar));
    if (tipo)
      ft.append(
        botao(t('Copiar ligação'), 'ghost', () => {
          const u = ligacaoFicha(tipo, a);
          try {
            navigator.clipboard.writeText(u).then(
              () => toast(t('Ligação copiada: quem a abrir vê esta ficha (com as suas permissões).'), 4000),
              () => toast(u, 8000),
            );
          } catch (e) {
            toast(u, 8000);
          }
        }),
      );
    ft.append(botao(t('Fechar'), 'ghost', fechar));
  });
}

export function abrirArtigo(a) {
  const nome = tArtigo(a.artigo) || a.codigo;
  fichaComLinhas(
    nome,
    [a.codigo, t('no período escolhido')].filter(Boolean).join(' · '),
    (b) => {
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
    },
    'artigo',
    a,
  );
}
const clienteDe = (comDoc, k) => {
  const x = comDoc.find((y) => (y.venda.cliente_codigo || y.venda.nif || y.venda.cliente) === k);
  return x ? x.venda : null;
};

export function abrirRota(a) {
  fichaComLinhas(
    t('Rota {r}', { r: a.rota }) + (a.promotor ? ' · ' + a.promotor : ''),
    t('no período escolhido'),
    (b) => {
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
      // desempenho do vendedor no período, do servidor (Pinguim BO, conciliação, entrega do dinheiro e OLAP)
      const desempenho = el('section', 'lig-sec lig-desempenho');
      desempenho.append(el('h3', null, t('Perdas e tempo parado')), el('p', 'note', t('A ler…')));
      const nDias = st.de && st.ate ? Math.round((Date.parse(st.ate) - Date.parse(st.de)) / 864e5) + 1 : 99;
      if (nDias > 31) desempenho.lastChild.textContent = t('Escolha um período até {n} dias para ver as perdas e o tempo parado.', { n: 31 });
      else
        apiJson('perdas?de=' + st.de + '&ate=' + st.ate).then((d) => {
          const x = d && d.ok ? (d.rotas || []).find((y) => String(y.rota) === a.rota) : null;
          desempenho.lastChild.remove();
          if (!x) {
            desempenho.append(el('p', 'note', d && d.ok ? t('Sem perdas registadas no período.') : t((d && d.erro) || 'Não foi possível ler os dados.')));
            return;
          }
          const h = (m) => n0(Math.floor(m / 60)) + ' h ' + String(m % 60).padStart(2, '0');
          desempenho.append(
            quadros([
              [t('Perdas efectivas'), kz(x.perdas_kz) + (x.perdas_pct != null ? ' · ' + pct(x.perdas_pct) : '')],
              [t('Faltas na carrinha'), kz(x.falta_kz)],
              [t('Entrega do dinheiro'), kz(x.entrega_dif_kz)],
              [t('Tempo parado sem vender'), h(x.parado_min) + (x.parado_kz != null ? ' · ' + kz(x.parado_kz) : '')],
              [t('Km'), n0(x.km)],
              [t('Notas de crédito'), kz(x.nc_kz)],
              [t('Descontos'), kz(x.desconto_kz)],
              [t('Anulados'), kz(x.anulados_kz) + ' · ' + n0(x.anulados_n)],
            ]),
          );
          const ver = el('a', 'btn ghost sm', t('Ver todas as perdas'));
          ver.href = '#perdas';
          ver.addEventListener('click', () => ver.closest('dialog') && ver.closest('dialog').close());
          desempenho.append(ver);
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
        desempenho,
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
    },
    'rota',
    a,
  );
}

/**
 * Detalhe de um dia ou de um mês de um gráfico: põe o período (de … até) e abre a página (por omissão as vendas por
 * dia). Volta-se ao período anterior pelos botões de período, como sempre.
 */
export function abrirPeriodo(de, ate, pagina = 'vendas-dia') {
  st.preset = null;
  st.de = de;
  st.ate = ate || de;
  guardar();
  return fetchVendas(st.de, st.ate).then(() => {
    const alvo = '#' + pagina;
    if (location.hash.split('?')[0] === alvo) window.dispatchEvent(new HashChangeEvent('hashchange'));
    else location.hash = alvo;
  });
}
