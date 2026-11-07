// Barra superior (convenção das plataformas de gestão — SAP Fiori «shell bar», Microsoft 365, Salesforce):
//  - Procurar (Ctrl+K ou /): vai directo a qualquer função pelo nome, sem navegar no menu (ISO 9241-110);
//  - estado do sistema (fontes de dados e telemóveis), em texto e não só por cor (WCAG 1.4.1);
//  - sino dos alertas com o número por tratar (centro de notificações);
//  - conta: nome, perfil e Terminar sessão.
// A estrela ao lado do título fixa a página nos favoritos do menu.
import { apiJson } from './api.js';
import { alternarFavorito, aoMudarFavoritos, eFavorito, MAX_FAVORITOS } from './favoritos.js';
import { normTxt } from './filtros.js';
import { t, tv } from './i18n/i18n.js';
import { seccaoDe } from './paginas/catalogo.js';
import { paginaAtual, paginasVisiveis, podeVer, SESSAO } from './perfil.js';
import { $, el, svg, toast } from './util.js';

// ───────────── Procurar uma função ─────────────
let dlg = null,
  campo = null,
  lista = null,
  marcado = 0,
  resultados = [];

const caminho = (p) =>
  [seccaoDe(p), p.grupo]
    .filter(Boolean)
    .map((x) => t(x))
    .join(' › ');

function filtrar() {
  const q = normTxt(campo.value.trim());
  const todas = paginasVisiveis();
  resultados = !q ? todas : todas.filter((p) => normTxt(t(p.nome) + ' ' + caminho(p) + ' ' + p.nome + ' ' + (p.grupo || '')).includes(q));
  marcado = 0;
  desenharLista();
}
function desenharLista() {
  lista.replaceChildren();
  if (!resultados.length) {
    lista.append(el('li', 'proc-vazio', t('Nenhuma função com esse nome.')));
    campo.removeAttribute('aria-activedescendant');
    return;
  }
  resultados.forEach((p, i) => {
    const li = el('li', 'proc-item' + (i === marcado ? ' on' : ''));
    li.id = 'proc-' + p.id;
    li.setAttribute('role', 'option');
    li.setAttribute('aria-selected', i === marcado ? 'true' : 'false');
    const ic = el('span', 'proc-ic');
    ic.innerHTML = svg(p.icon); // nosemgrep: insecure-innerhtml, insecure-document-method -- SVG estático do próprio código (sem dados)
    const txt = el('span', 'proc-txt');
    txt.append(el('b', null, t(p.nome)), el('small', null, caminho(p) || t('Portal RTM')));
    const est = el('button', 'proc-fav' + (eFavorito(p.id) ? ' on' : ''));
    est.type = 'button';
    est.innerHTML = svg('estrela'); // nosemgrep: insecure-innerhtml, insecure-document-method -- SVG estático do próprio código (sem dados)
    est.setAttribute('aria-pressed', eFavorito(p.id) ? 'true' : 'false');
    est.setAttribute('aria-label', t(eFavorito(p.id) ? 'Retirar dos favoritos' : 'Fixar nos favoritos') + ': ' + t(p.nome));
    est.tabIndex = -1;
    est.addEventListener('click', (ev) => {
      ev.stopPropagation();
      favorito(p.id);
      desenharLista();
    });
    li.append(ic, txt, est);
    li.addEventListener('click', () => abrir(p));
    li.addEventListener('mousemove', () => {
      if (marcado !== i) {
        marcado = i;
        realcar();
      }
    });
    lista.append(li);
  });
  realcar();
}
function realcar() {
  [...lista.children].forEach((li, i) => {
    li.classList.toggle('on', i === marcado);
    li.setAttribute('aria-selected', i === marcado ? 'true' : 'false');
  });
  const on = lista.children[marcado];
  if (on && on.id) {
    campo.setAttribute('aria-activedescendant', on.id);
    on.scrollIntoView({ block: 'nearest' });
  }
}
function abrir(p) {
  fecharProcura();
  $('#app').classList.remove('open');
  location.hash = '#' + p.id;
}
function montarProcura() {
  dlg = el('dialog', 'procura');
  dlg.id = 'procura';
  dlg.setAttribute('aria-label', t('Procurar uma função'));
  const cab = el('div', 'proc-cab');
  const lupa = el('span', 'proc-lupa');
  lupa.innerHTML = svg('lupa'); // nosemgrep: insecure-innerhtml, insecure-document-method -- SVG estático do próprio código (sem dados)
  campo = el('input', 'proc-campo');
  campo.type = 'search';
  campo.id = 'procura-campo';
  campo.autocomplete = 'off';
  campo.setAttribute('role', 'combobox');
  campo.setAttribute('aria-expanded', 'true');
  campo.setAttribute('aria-controls', 'procura-lista');
  campo.setAttribute('aria-autocomplete', 'list');
  lista = el('ul', 'proc-lista');
  lista.id = 'procura-lista';
  lista.setAttribute('role', 'listbox');
  const dica = el('div', 'proc-dica');
  cab.append(lupa, campo);
  dlg.append(cab, lista, dica);
  campo.addEventListener('input', filtrar);
  campo.addEventListener('keydown', (ev) => {
    if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
      ev.preventDefault();
      if (!resultados.length) return;
      marcado = (marcado + (ev.key === 'ArrowDown' ? 1 : -1) + resultados.length) % resultados.length;
      realcar();
    } else if (ev.key === 'Enter') {
      ev.preventDefault();
      if (resultados[marcado]) abrir(resultados[marcado]);
    } else if (ev.key === 'Escape') {
      ev.preventDefault();
      fecharProcura(); // fecha logo (o campo de pesquisa só limparia o texto)
    }
  });
  dlg.addEventListener('click', (ev) => {
    if (ev.target === dlg) fecharProcura(); // clique fora da caixa
  });
  dlg.addEventListener('close', () => $('#procurar').focus());
  document.body.append(dlg);
}
export function abrirProcura() {
  if (!dlg) montarProcura();
  campo.placeholder = t('Escreva o nome de uma função…');
  campo.setAttribute('aria-label', t('Procurar uma função'));
  dlg.querySelector('.proc-dica').textContent = t('↑ ↓ para escolher · Enter para abrir · Esc para fechar · ☆ fixa nos favoritos');
  campo.value = '';
  filtrar();
  if (!dlg.open) dlg.showModal();
  campo.focus();
}
function fecharProcura() {
  if (dlg && dlg.open) dlg.close();
}

// ───────────── Favoritos (estrela ao lado do título) ─────────────
function favorito(id) {
  if (!alternarFavorito(id)) toast(t('Já tem {n} favoritos: retire um antes de fixar outro.', { n: MAX_FAVORITOS }), 5000);
}
function actualizarEstrela() {
  const b = $('#fav');
  if (!b) return;
  const p = paginaAtual();
  const pode = p && p.id !== 'inicio' && p.menu !== false && podeVer(p.id);
  b.hidden = !pode;
  if (!pode) return;
  const on = eFavorito(p.id);
  b.classList.toggle('on', on);
  b.setAttribute('aria-pressed', on ? 'true' : 'false');
  const r = t(on ? 'Retirar dos favoritos' : 'Fixar nos favoritos');
  b.setAttribute('aria-label', r);
  b.title = r;
}

// ───────────── Sino dos alertas ─────────────
let alertasPorTratar = null,
  alertasLidosEm = 0;
function lerAlertas() {
  if (!podeVer('alertas') || Date.now() - alertasLidosEm < 9 * 60000) return;
  alertasLidosEm = Date.now();
  apiJson('alertas')
    .then((d) => {
      alertasPorTratar = d && d.ok && Array.isArray(d.alertas) ? d.alertas.filter((a) => a.gravidade === 'critico' || a.gravidade === 'aviso').length : null;
      mostrarSino();
    })
    .catch(() => {});
}
function mostrarSino() {
  const s = $('#sino');
  if (!s) return;
  s.hidden = !podeVer('alertas');
  const n = $('#sino-n');
  const r = alertasPorTratar ? t('{n} alertas por tratar', { n: alertasPorTratar }) : t('Alertas');
  s.setAttribute('aria-label', r);
  s.title = r;
  n.hidden = !alertasPorTratar;
  n.textContent = alertasPorTratar > 99 ? '99+' : String(alertasPorTratar || '');
  if (paginaAtual().id === 'alertas') s.setAttribute('aria-current', 'page');
  else s.removeAttribute('aria-current');
}

// ───────────── Conta ─────────────
function mostrarConta() {
  const b = $('#conta-b');
  if (!b) return;
  $('#conta').hidden = !SESSAO;
  if (!SESSAO) return;
  const nome = tv(SESSAO.nome || SESSAO.login || '');
  const ini =
    String(nome)
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((x) => x[0])
      .join('')
      .toUpperCase() || '?';
  $('#conta-ini').textContent = ini;
  $('#conta-nome').textContent = nome;
  b.setAttribute('aria-label', t('Conta') + ': ' + nome);
  $('#sair-t').textContent = t('Terminar sessão');
  $('#conta-app-t').textContent = t('App no telemóvel');
  const tem = !!SESSAO.app_instalada;
  $('#conta-app').classList.toggle('instalada', tem);
  $('#conta-app').title = t(tem ? 'Já tem a app activa num telemóvel' : 'Ainda não tem a app no telemóvel');
  // ambiente de ensaio (só SADM e TI): a versão nova lado a lado, para testar antes de aprovar
  const ens = $('#conta-ensaio');
  if (ens) {
    const e = SESSAO.ensaio;
    ens.hidden = !e;
    if (e) {
      $('#conta-ensaio-t').textContent = t('Versão nova {v} (ensaio)', { v: e.versao });
      ens.title = t(e.resultado === 'ok' ? 'Passou nos testes de fumo: teste-a antes de a aprovar' : 'Falhou nos testes de fumo');
    }
  }
}
function fecharConta() {
  const p = $('#conta-p');
  if (!p || p.hidden) return;
  p.hidden = true;
  $('#conta-b').setAttribute('aria-expanded', 'false');
}
function sair() {
  // POST com o código anti-falsificação da sessão (geo/auth/logout.php)
  const f = el('form');
  f.method = 'post';
  f.action = '../geo/auth/logout.php';
  const i = el('input');
  i.type = 'hidden';
  i.name = 'csrf';
  i.value = (SESSAO && SESSAO.csrf) || '';
  f.append(i);
  document.body.append(f);
  f.submit();
}

/** Textos e estado da barra (a cada desenho de página e mudança de idioma). */
export function actualizarBarraSup() {
  const pr = $('#procurar');
  if (!pr) return;
  $('#procurar-t').textContent = t('Procurar');
  pr.setAttribute('aria-label', t('Procurar uma função') + ' (Ctrl+K)');
  pr.title = t('Procurar uma função') + ' (Ctrl+K)';
  $('#estado-sis').setAttribute('aria-label', t('Estado do sistema'));
  actualizarEstrela();
  mostrarSino();
  mostrarConta();
  lerAlertas();
}

let montada = false;
export function montarBarraSup() {
  if (montada) return;
  montada = true;
  $('#procurar').addEventListener('click', abrirProcura);
  document.addEventListener('keydown', (ev) => {
    const escrita = ev.target.closest && ev.target.closest('input, textarea, select, [contenteditable="true"]');
    if ((ev.ctrlKey || ev.metaKey) && (ev.key === 'k' || ev.key === 'K')) {
      ev.preventDefault();
      abrirProcura();
    } else if (ev.key === '/' && !escrita && !ev.ctrlKey && !ev.metaKey && !ev.altKey) {
      ev.preventDefault();
      abrirProcura();
    } else if (ev.key === 'Escape') fecharConta();
  });
  $('#fav').addEventListener('click', () => {
    const p = paginaAtual();
    if (p && p.id !== 'inicio') favorito(p.id);
  });
  $('#conta-b').addEventListener('click', (ev) => {
    ev.stopPropagation();
    const p = $('#conta-p');
    p.hidden = !p.hidden;
    $('#conta-b').setAttribute('aria-expanded', p.hidden ? 'false' : 'true');
  });
  document.addEventListener('click', (ev) => {
    if (!ev.target.closest('#conta')) fecharConta();
  });
  $('#sair').addEventListener('click', sair);
  $('#conta-app').addEventListener('click', () => {
    fecharConta();
    import('./app-telemovel.js').then((m) => m.abrirAppTelemovel());
  });
  aoMudarFavoritos(() => {
    actualizarEstrela();
    import('./navegacao.js').then((m) => m.nav());
  });
  setInterval(() => {
    if (!document.hidden) lerAlertas();
  }, 10 * 60000);
}
