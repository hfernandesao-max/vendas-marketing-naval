// Menu em árvore e divisão das páginas em relatórios (separadores).
import { filtrosURL, normTxt } from './filtros.js';
import { t } from './i18n/i18n.js';
import { PAGES } from './paginas/catalogo.js';
import { atual, contarAlertas, paginasVisiveis } from './perfil.js';
import { $, el, svg } from './util.js';

// Cada secção de uma página passa a ser um relatório: mostra-se um de cada vez (separadores no topo e
// sub-itens no menu), com os indicadores e os filtros da página sempre visíveis.
const SEM_DIVISAO = ['plano-cargas', 'inicio', 'cadastros']; // painéis que se lêem de uma vez
export let RELS = [],
  REL_ATUAL = '',
  REL_PEDIDO = '';
const slugRel = (s) =>
  normTxt(s)
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '');
export function organizarRelatorios(pg, pagina) {
  RELS = [];
  REL_ATUAL = '';
  pg.classList.remove('um-relatorio');
  if (SEM_DIVISAO.includes(pagina)) return;
  const secs = [...pg.querySelectorAll('section.sec')].filter((x) => !x.classList.contains('fixo') && !x.parentElement.closest('section.sec'));
  const vistos = new Set();
  RELS = secs
    .map((x) => {
      const h = x.querySelector('.sec-h h2'),
        titulo = h ? h.textContent.trim() : '';
      let key = x.id ? x.id.replace(/^s-/, '') : slugRel(titulo);
      while (vistos.has(key)) key += '-2';
      vistos.add(key);
      return { key, titulo, el: x };
    })
    .filter((r) => r.titulo);
  if (RELS.length < 2) {
    RELS = [];
    return;
  }
  let memo = '';
  try {
    memo = localStorage.getItem('vm-rel-' + pagina) || '';
  } catch (e) {}
  const r = RELS.find((x) => x.key === REL_PEDIDO) || RELS.find((x) => x.key === memo) || RELS[0];
  REL_ATUAL = r.key;
  REL_PEDIDO = '';
  try {
    localStorage.setItem('vm-rel-' + pagina, r.key);
  } catch (e) {}
  RELS.forEach((x) => {
    x.el.hidden = x !== r;
  });
  pg.classList.add('um-relatorio');
  const q = filtrosURL(pagina);
  const tabs = el('nav', 'rel-tabs');
  tabs.setAttribute('aria-label', t('Relatórios desta página'));
  RELS.forEach((x) => {
    const a = el('a', 'rel-tab' + (x === r ? ' on' : ''), x.titulo);
    a.href = '#' + pagina + '/' + x.key + (q ? '?' + q : '');
    if (x === r) a.setAttribute('aria-current', 'page');
    tabs.append(a);
  });
  let anc = RELS[0].el;
  while (anc.parentElement && anc.parentElement !== pg) anc = anc.parentElement;
  pg.insertBefore(tabs, anc);
  requestAnimationFrame(() => {
    const on = tabs.querySelector('.on');
    if (on && tabs.scrollWidth > tabs.clientWidth) tabs.scrollLeft = on.offsetLeft - 16;
  });
}
// Menu principal em acordeão (padrão de divulgação da WAI-ARIA): as áreas começam fechadas e só uma
// fica aberta de cada vez; abrir outra fecha a anterior. A área da página aberta abre-se sozinha e
// fica destacada; com a área fechada, o número de alertas das suas páginas aparece no título.
const ICONE_GRUPO = {
  Vendas: 'chart',
  Recebimentos: 'card',
  Clientes: 'users',
  Terreno: 'route',
  Logística: 'truck',
  Integrações: 'upload',
  Administração: 'lock',
};
let grupoAberto = null,
  ultimaPagina = null;
try {
  grupoAberto = localStorage.getItem('vm-grupo-aberto') || null;
} catch (e) {}
const guardarGrupo = () => {
  try {
    if (grupoAberto) localStorage.setItem('vm-grupo-aberto', grupoAberto);
    else localStorage.removeItem('vm-grupo-aberto');
  } catch (e) {}
};
function abrirGrupo(n, alvo) {
  n.querySelectorAll('.navg').forEach((b) => {
    const abre = b === alvo;
    const lista = document.getElementById(b.getAttribute('aria-controls'));
    b.setAttribute('aria-expanded', abre ? 'true' : 'false');
    lista.classList.toggle('aberta', abre);
    lista.inert = !abre;
  });
}
export function nav() {
  const n = $('#nav');
  n.replaceChildren();
  const al = contarAlertas(),
    q = filtrosURL(atual),
    grupoAtual = (PAGES.find((x) => x.id === atual) || {}).grupo;
  // Ao mudar de página, abre a área dessa página; ao voltar ao Início o menu volta ao estado inicial
  // (todas as áreas fechadas, como uma «casa» limpa)
  if (atual !== ultimaPagina) {
    ultimaPagina = atual;
    if (grupoAtual) grupoAberto = grupoAtual;
    else if (atual === 'inicio') grupoAberto = null;
    guardarGrupo();
  }
  const visiveis = paginasVisiveis();
  let caixa = n;
  visiveis.forEach((p, i, arr) => {
    if (p.grupo && (i === 0 || arr[i - 1].grupo !== p.grupo)) {
      const aberto = p.grupo === grupoAberto,
        id = 'navl-' + slugRel(p.grupo);
      const b = el('button', 'navg' + (p.grupo === grupoAtual ? ' atual' : ''));
      b.type = 'button';
      b.innerHTML = svg(ICONE_GRUPO[p.grupo] || 'file');
      b.append(el('span', 'navg-t', t(p.grupo)));
      const alertas = visiveis.filter((x) => x.grupo === p.grupo && x.id !== 'pagamentos').reduce((s, x) => s + (al[x.id] || 0), 0);
      if (alertas) b.append(el('span', 'n navg-n', String(alertas)));
      b.setAttribute('aria-expanded', aberto ? 'true' : 'false');
      b.setAttribute('aria-controls', id);
      const lista = el('div', 'navl' + (aberto ? ' aberta' : ''));
      lista.id = id;
      lista.inert = !aberto;
      const dentro = el('div', 'navl-in');
      lista.append(dentro);
      b.addEventListener('click', () => {
        const abre = b.getAttribute('aria-expanded') !== 'true';
        abrirGrupo(n, abre ? b : null);
        grupoAberto = abre ? p.grupo : null;
        guardarGrupo();
      });
      n.append(b, lista);
      caixa = dentro;
    } else if (!p.grupo) caixa = n;
    const a = el('a');
    a.href = '#' + p.id;
    a.innerHTML = svg(p.icon);
    a.append(el('span', null, t(p.nome)));
    if (al[p.id] && p.id !== 'pagamentos') a.append(el('span', 'n', String(al[p.id])));
    if (p.id === atual) {
      a.className = 'on';
      a.setAttribute('aria-current', 'page');
    }
    a.addEventListener('click', () => $('#app').classList.remove('open'));
    // Início: fecha todas as áreas do menu (mesmo já estando no Início) e volta ao topo
    if (p.id === 'inicio')
      a.addEventListener('click', () => {
        grupoAberto = null;
        guardarGrupo();
        abrirGrupo(n, null);
        window.scrollTo(0, 0);
      });
    caixa.append(a);
    // relatórios da página aberta
    if (p.id === atual && RELS.length) {
      const sub = el('div', 'nav-sub');
      RELS.forEach((r) => {
        const x = el('a', r.key === REL_ATUAL ? 'on' : null, r.titulo);
        x.href = '#' + p.id + '/' + r.key + (q ? '?' + q : '');
        if (r.key === REL_ATUAL) x.setAttribute('aria-current', 'true');
        x.addEventListener('click', () => $('#app').classList.remove('open'));
        sub.append(x);
      });
      caixa.append(sub);
    }
  });
}

// Alteração do estado partilhado: só este módulo altera estas variáveis; os outros lêem-nas.
export function definirRelPedido(valor) {
  REL_PEDIDO = valor;
}
