// Ajuda viva do portal (base de conhecimento gerada a cada versão a partir do próprio portal: páginas, novidades,
// regras provadas pelos testes e funcionamento; servidor: nucleo/servicos/conhecimento.php, v1/conhecimento).
// Painel lateral à direita, como as fichas: «Esta página» (o que faz, as novidades e as regras), a pesquisa e, para
// quem tem o assistente, a pergunta em linguagem natural respondida com os dados reais da pessoa.
// «Porquê?» (botaoPorque): em cada estado importante, abre o painel com o motivo, os dados que o produziram, as
// regras dessa página e «Explicar com os meus dados».
import { apiJson } from '../api.js';
import { textoRico } from '../gestao-textos.js';
import { dl } from '../formatos.js';
import { LANG, t } from '../i18n/i18n.js';
import { PAGES } from '../paginas/catalogo.js';
import { podeVer } from '../perfil.js';
import { postJson } from '../servidor.js';
import { el } from '../util.js';
import { botao, janela } from './janelas.js';

const TIPO = { pagina: 'Página', novidade: 'Novidade', regra: 'Regra', funcionamento: 'Como funciona' };
const nomePagina = (id) => {
  const p = PAGES.find((x) => x.id === id);
  return p ? t(p.nome) : id;
};
// a data do CHANGELOG vem como dd-mm-aaaa
const dataNov = (d) => (/^\d{2}-\d{2}-\d{4}$/.test(d || '') ? d : d ? dl(d) : '');

function irPara(id, fechar) {
  fechar();
  if (location.hash !== '#' + id) location.hash = '#' + id;
}

function ligacoesPaginas(ids, fechar) {
  const w = el('span', 'aj-pags');
  (ids || [])
    .filter((id) => id === 'inicio' || podeVer(id))
    .forEach((id) => {
      const b = el('button', 'linkbtn', nomePagina(id));
      b.type = 'button';
      b.addEventListener('click', () => irPara(id, fechar));
      w.append(b);
    });
  return w;
}

/** Um artigo: título (com o tipo e a data) e, ao abrir, o texto e as páginas a que diz respeito. */
function artigo(x, fechar) {
  const d = el('details', 'aj-art aj-' + x.tipo);
  const s = el('summary');
  s.append(el('span', 'aj-tipo', t(TIPO[x.tipo] || x.tipo)), el('b', null, x.titulo));
  if (x.data) s.append(el('small', null, dataNov(x.data)));
  d.append(s);
  if (x.texto) d.append(textoRico(x.texto));
  if (x.paginas && x.paginas.length) d.append(ligacoesPaginas(x.paginas, fechar));
  return d;
}

function lista(titulo, artigos, fechar, max) {
  const s = el('section', 'aj-sec');
  s.append(el('h4', null, titulo));
  const vis = max ? artigos.slice(0, max) : artigos;
  vis.forEach((x) => s.append(artigo(x, fechar)));
  if (max && artigos.length > max) {
    const b = el('button', 'linkbtn', t('Ver todas ({n})', { n: artigos.length }));
    b.type = 'button';
    b.addEventListener('click', () => {
      b.remove();
      artigos.slice(max).forEach((x) => s.append(artigo(x, fechar)));
    });
    s.append(b);
  }
  return s;
}

/** Caixa da pergunta ao assistente (só para quem o pode usar). */
function perguntaIA(inicial, automatica) {
  const s = el('section', 'aj-sec aj-ia');
  s.append(el('h4', null, t('Perguntar ao assistente')));
  const ta = el('textarea');
  ta.rows = 2;
  ta.maxLength = 1500;
  ta.value = inicial || '';
  ta.placeholder = t('Ex.: porque é que o fecho de ontem está pendente?');
  ta.setAttribute('aria-label', t('Pergunta ao assistente'));
  const resp = el('div', 'aj-resp');
  resp.setAttribute('aria-live', 'polite');
  const enviar = botao(t('Perguntar'), null, () => {
    const q = ta.value.trim();
    if (!q) return;
    enviar.disabled = true;
    resp.replaceChildren(el('p', 'note', t('A analisar os dados…')));
    postJson('assistente', { pergunta: q, historico: [], idioma: LANG }).then((r) => {
      enviar.disabled = false;
      resp.replaceChildren(r.ok ? textoRico(r.resposta) : el('p', 'erro', t(r.erro || 'Sem resposta do servidor.')));
    });
  });
  ta.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      enviar.click();
    }
  });
  const f = el('div', 'aj-ia-f');
  f.append(ta, enviar);
  s.append(f, resp);
  if (automatica) setTimeout(() => enviar.click(), 0);
  return s;
}

/**
 * Abre o painel de ajuda. o.pagina: página actual; o.porque: {titulo, estado, detalhe, pergunta} para «Porquê?».
 */
export function abrirAjuda(o = {}) {
  const pagina = o.pagina && (o.pagina === 'inicio' || podeVer(o.pagina)) ? o.pagina : null;
  janela(t('Ajuda'), pagina ? nomePagina(pagina) : null, (b, ft, fechar, d) => {
    d.classList.add('lig-dlg', 'aj-dlg');
    // pesquisa
    const procura = el('input');
    procura.type = 'search';
    procura.placeholder = t('Procurar na ajuda: páginas, regras, novidades…');
    procura.setAttribute('aria-label', t('Procurar na ajuda'));
    const res = el('div', 'aj-res');
    const base = el('div', 'aj-base');
    let tempo = 0;
    let pedido = 0;
    procura.addEventListener('input', () => {
      clearTimeout(tempo);
      const q = procura.value.trim();
      if (q.length < 2) {
        res.replaceChildren();
        base.hidden = false;
        return;
      }
      tempo = setTimeout(() => {
        const n = ++pedido;
        apiJson('conhecimento?q=' + encodeURIComponent(q) + (pagina ? '&pagina=' + pagina : '')).then((r) => {
          if (n !== pedido) return;
          base.hidden = true;
          const arts = (r && r.artigos) || [];
          res.replaceChildren(
            arts.length ? lista(t('Resultados'), arts, fechar) : el('p', 'note', t('Nada encontrado. Tente outras palavras ou pergunte ao assistente.')),
          );
        });
      }, 250);
    });
    b.append(procura, res);
    // porquê
    if (o.porque) {
      const p = el('section', 'aj-sec aj-porque');
      p.append(el('h4', null, t('Porquê?')));
      const c = el('div', 'aj-porque-c');
      c.append(el('b', null, o.porque.titulo));
      if (o.porque.estado) c.append(el('span', 'aj-estado', o.porque.estado));
      if (o.porque.detalhe) c.append(el('p', null, o.porque.detalhe));
      p.append(c);
      b.append(p);
    }
    b.append(base);
    if (podeVer('assistente') && (o.porque || o.perguntar)) base.before(perguntaIA(o.porque ? o.porque.pergunta : '', !!o.porque && !!o.porque.explicar));
    // esta página
    if (pagina) {
      base.append(el('p', 'note', t('A carregar…')));
      apiJson('conhecimento?pagina=' + pagina).then((r) => {
        base.replaceChildren();
        if (!r || !r.ok) {
          base.append(el('p', 'note', t('Não foi possível ler os dados.')));
          return;
        }
        if (r.pagina && r.pagina.texto) {
          const s = el('section', 'aj-sec aj-pag');
          s.append(el('h4', null, t('Esta página')), textoRico(r.pagina.texto));
          base.append(s);
        }
        let regras = r.regras || [];
        if (o.porque && o.porque.tema) {
          // primeiro as regras do tema do «Porquê?»
          const tema = o.porque.tema.toLowerCase();
          regras = regras.slice().sort((a, c) => (c.titulo.toLowerCase().includes(tema) ? 1 : 0) - (a.titulo.toLowerCase().includes(tema) ? 1 : 0));
        }
        if (regras.length) base.append(lista(t('Regras desta página (provadas a cada versão)'), regras, fechar, 8));
        if ((r.novidades || []).length) base.append(lista(t('Novidades'), r.novidades, fechar));
        if ((r.funcionamento || []).length) base.append(lista(t('Como funciona'), r.funcionamento, fechar));
        if (!base.children.length) base.append(el('p', 'note', t('Ainda não há ajuda para esta página.')));
      });
    }
    if (podeVer('assistente') && !o.porque && !o.perguntar) b.append(perguntaIA('', false));
    ft.append(botao(t('Fechar'), 'ghost', fechar));
    setTimeout(() => procura.focus(), 0);
  });
}

/**
 * Botão «Porquê?» para um estado: abre a ajuda com o motivo e os dados, as regras da página e, para quem tem o
 * assistente, «Explicar com os meus dados» já perguntado.
 */
export function botaoPorque(o) {
  const b = el('button', 'linkbtn aj-porque-b', t('Porquê?'));
  b.type = 'button';
  b.setAttribute('aria-label', t('Porquê?') + ' ' + (o.titulo || ''));
  b.addEventListener('click', (ev) => {
    ev.stopPropagation();
    abrirAjuda({ pagina: o.pagina, porque: { ...o, explicar: false } });
  });
  return b;
}
