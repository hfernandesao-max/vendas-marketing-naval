// Menu em árvore e divisão das páginas em relatórios (separadores).
import { filtrosURL, normTxt } from './filtros.js';
import { t } from './i18n/i18n.js';
import { favoritos } from './favoritos.js';
import { PAGES, SECCAO_GRUPO } from './paginas/catalogo.js';
import { carregarPagina } from './paginas/modulos.js';
import { atual, contarAlertas, paginasVisiveis } from './perfil.js';
import { $, el, svg } from './util.js';

// Cada secção de uma página passa a ser um relatório: mostra-se um de cada vez (separadores no topo e
// sub-itens no menu), com os indicadores e os filtros da página sempre visíveis.
const SEM_DIVISAO = ['plano-cargas', 'inicio', 'cadastros', 'stock-carrinha']; // painéis que se lêem de uma vez (o stock mostra todas as carrinhas seguidas)
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
// As áreas agrupam-se em secções (Comercial, Operações, Análise e, separada em baixo, Sistema) e os favoritos da
// pessoa ficam logo a seguir ao Início.
const ICONE_GRUPO = {
  Vendas: 'chart',
  Clientes: 'users',
  Cobranças: 'card',
  Terreno: 'route',
  Logística: 'truck',
  'Inteligência de negócio': 'negocio',
  Equipamentos: 'phone',
  Integrações: 'upload',
  Administração: 'lock',
};
/** O que significa o número ao lado de uma função (texto acessível e dica; nunca só a cor). */
export function rotuloAlerta(id, n) {
  if (id === 'clientes') return t('{n} clientes não activos', { n });
  if (id === 'pagamentos' || id === 'pendentes') return t('{n} facturas por receber', { n });
  return t('{n} itens a tratar', { n });
}
const contador = (cls, n, rotulo) => {
  const s = el('span', cls, String(n));
  s.title = rotulo;
  s.setAttribute('aria-label', rotulo);
  return s;
};
let grupoAberto = null,
  ultimaPagina = null;
try {
  grupoAberto = localStorage.getItem('vm-grupo-aberto') || null;
} catch (e) {}
// Ao escolher uma página no menu abre-se sempre o primeiro relatório (o que está logo a seguir); a memória do
// relatório só serve para manter o que está aberto quando a mesma página se volta a desenhar.
const esquecerRelatorio = (pagina) => {
  try {
    localStorage.removeItem('vm-rel-' + pagina);
  } catch (e) {}
};
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
  const visiveis = paginasVisiveis().filter((p) => p.menu !== false);
  let caixa = n,
    seccao = null;
  const ligacao = (p, comSub) => {
    const a = el('a');
    a.href = '#' + p.id;
    a.innerHTML = svg(p.icon); // nosemgrep: insecure-innerhtml, insecure-document-method -- SVG estático do próprio código (sem dados)
    a.append(el('span', null, t(p.nome)));
    if (al[p.id] && p.id !== 'pagamentos') a.append(contador('n', al[p.id], rotuloAlerta(p.id, al[p.id])));
    if (p.id === atual && comSub) {
      a.className = 'on';
      a.setAttribute('aria-current', 'page');
    }
    a.addEventListener('click', () => {
      $('#app').classList.remove('open');
      esquecerRelatorio(p.id);
    });
    // Pré-carregamento por intenção: com o rato, o dedo ou o foco na ligação, o módulo da página já vem a caminho
    const antecipar = () => carregarPagina(p.id).catch(() => {});
    ['pointerenter', 'touchstart', 'focus'].forEach((ev) => a.addEventListener(ev, antecipar, { once: true, passive: true }));
    return a;
  };
  // Favoritos da pessoa (só as funções que pode ver), logo a seguir ao Início
  const favs = favoritos()
    .map((id) => visiveis.find((x) => x.id === id))
    .filter(Boolean);
  visiveis.forEach((p, i, arr) => {
    const sec = SECCAO_GRUPO[p.grupo] || null;
    if (sec && sec !== seccao) {
      seccao = sec;
      const h = el('div', 'nav-sec' + (sec === 'Sistema' ? ' nav-sec-sis' : ''), t(sec));
      h.setAttribute('role', 'heading');
      h.setAttribute('aria-level', '2');
      n.append(h);
    }
    if (p.grupo && (i === 0 || arr[i - 1].grupo !== p.grupo)) {
      const aberto = p.grupo === grupoAberto,
        id = 'navl-' + slugRel(p.grupo);
      const b = el('button', 'navg' + (p.grupo === grupoAtual ? ' atual' : ''));
      b.type = 'button';
      b.innerHTML = svg(ICONE_GRUPO[p.grupo] || 'file'); // nosemgrep: insecure-innerhtml, insecure-document-method -- SVG estático do próprio código (sem dados)
      b.append(el('span', 'navg-t', t(p.grupo)));
      const comAlerta = visiveis.filter((x) => x.grupo === p.grupo && x.id !== 'pagamentos' && al[x.id]);
      const alertas = comAlerta.reduce((s, x) => s + al[x.id], 0);
      if (alertas) b.append(contador('n navg-n', alertas, comAlerta.map((x) => rotuloAlerta(x.id, al[x.id])).join(' · ')));
      b.setAttribute('aria-expanded', aberto ? 'true' : 'false');
      b.setAttribute('aria-controls', id);
      const lista = el('div', 'navl' + (aberto ? ' aberta' : ''));
      lista.id = id;
      lista.inert = !aberto;
      const dentro = el('div', 'navl-in');
      lista.append(dentro);
      // Abrir uma área selecciona logo a primeira página dessa área (a que está imediatamente a seguir)
      const primeira = visiveis.find((x) => x.grupo === p.grupo);
      b.addEventListener('click', () => {
        const abre = b.getAttribute('aria-expanded') !== 'true';
        abrirGrupo(n, abre ? b : null);
        grupoAberto = abre ? p.grupo : null;
        guardarGrupo();
        if (abre && primeira) {
          esquecerRelatorio(primeira.id);
          const qp = filtrosURL(primeira.id);
          location.hash = '#' + primeira.id + (qp ? '?' + qp : '');
        }
      });
      n.append(b, lista);
      caixa = dentro;
    } else if (!p.grupo) caixa = n;
    const a = ligacao(p, true);
    // Início: fecha todas as áreas do menu (mesmo já estando no Início) e volta ao topo
    if (p.id === 'inicio')
      a.addEventListener('click', () => {
        grupoAberto = null;
        guardarGrupo();
        abrirGrupo(n, null);
        window.scrollTo(0, 0);
      });
    caixa.append(a);
    if (p.id === 'inicio' && favs.length) {
      const h = el('div', 'nav-sec nav-sec-fav', t('Favoritos'));
      h.setAttribute('role', 'heading');
      h.setAttribute('aria-level', '2');
      const caixaFav = el('div', 'nav-fav');
      favs.forEach((f) => caixaFav.append(ligacao(f, false)));
      n.append(h, caixaFav);
    }
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
