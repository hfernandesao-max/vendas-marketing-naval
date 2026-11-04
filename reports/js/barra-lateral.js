// Menu lateral à medida de cada pessoa (boas práticas de navegação: WAI-ARIA, Fluent «navigation pane» e Material
// «navigation rail»):
//  - modo: Fixo (sempre visível), Compacto (só os ícones; abre ao passar o rato ou com o teclado) ou Oculto (abre com
//    o botão Menu, por cima da página — mais espaço para mapas e tabelas largas);
//  - posição: à esquerda ou à direita.
// A escolha segue a conta (v1/preferencias: igual em qualquer computador ou telemóvel) e fica também no navegador
// (vm-menu) para o arranque já sair certo, sem saltos (aplicada em tema-arranque.js antes do primeiro desenho).
import { postJson } from './servidor.js';
import { t } from './i18n/i18n.js';
import { el, svg } from './util.js';

const MODOS = ['fixa', 'compacta', 'oculta'];
const LADOS = ['esquerda', 'direita'];
const K = 'vm-menu';
const NOMES_MODO = { fixa: 'Fixo', compacta: 'Compacto', oculta: 'Oculto' };
const DICA_MODO = { fixa: 'Sempre visível', compacta: 'Só os ícones; abre ao passar o rato', oculta: 'Abre com o botão Menu' };
const NOMES_LADO = { esquerda: 'Esquerda', direita: 'Direita' };

function normalizar(p) {
  p = p || {};
  return { barra: MODOS.includes(p.barra) ? p.barra : 'fixa', lado: LADOS.includes(p.lado) ? p.lado : 'esquerda' };
}
function lerLocal() {
  try {
    return normalizar(JSON.parse(localStorage.getItem(K) || '{}'));
  } catch (e) {
    return normalizar({});
  }
}
let actual = lerLocal();

function aplicar() {
  const h = document.documentElement;
  h.setAttribute('data-barra', actual.barra);
  h.setAttribute('data-lado', actual.lado);
  try {
    localStorage.setItem(K, JSON.stringify(actual));
  } catch (e) {
    /* sem armazenamento: vale até fechar a página */
  }
  const app = document.getElementById('app');
  if (app) app.classList.remove('open');
  actualizarPainel();
}

/** Preferências vindas da conta (v1/sessao): mandam sobre as do navegador. */
export function aplicarPreferencias(p) {
  if (!p) return;
  actual = normalizar(p);
  aplicar();
}

function escolher(alt) {
  actual = normalizar({ ...actual, ...alt });
  aplicar();
  postJson('preferencias', alt).catch(() => {});
}

let painel = null;
let botao = null;
function actualizarPainel() {
  if (!painel) return;
  painel.querySelectorAll('button[data-barra]').forEach((b) => {
    const on = b.dataset.barra === actual.barra;
    b.classList.toggle('on', on);
    b.setAttribute('aria-pressed', on ? 'true' : 'false');
  });
  painel.querySelectorAll('button[data-lado]').forEach((b) => {
    const on = b.dataset.lado === actual.lado;
    b.classList.toggle('on', on);
    b.setAttribute('aria-pressed', on ? 'true' : 'false');
  });
}
function fechar() {
  if (!painel || painel.hidden) return;
  painel.hidden = true;
  botao.setAttribute('aria-expanded', 'false');
}

/** Botão «Menu lateral» no rodapé do menu (junto ao tema) com as escolhas de modo e posição. */
export function montarAjusteMenu() {
  const side = document.getElementById('side');
  if (!side) return;
  let caixa = document.getElementById('menu-cfg');
  if (!caixa) {
    caixa = el('div', 'menu-cfg');
    caixa.id = 'menu-cfg';
    const tema = document.getElementById('tema');
    if (tema && tema.parentElement === side) tema.after(caixa);
    else side.append(caixa);
  }
  caixa.replaceChildren();
  botao = el('button', 'menu-cfg-b');
  botao.type = 'button';
  botao.id = 'menu-cfg-b';
  botao.innerHTML = svg('menu'); // nosemgrep: insecure-innerhtml, insecure-document-method -- SVG estático do próprio código (sem dados)
  botao.append(el('span', null, t('Menu lateral')));
  botao.setAttribute('aria-expanded', 'false');
  botao.setAttribute('aria-controls', 'menu-cfg-p');
  botao.title = t('Ajustar o menu lateral');
  painel = el('div', 'menu-cfg-p');
  painel.id = 'menu-cfg-p';
  painel.setAttribute('role', 'group');
  painel.setAttribute('aria-label', t('Ajustar o menu lateral'));
  painel.hidden = true;
  const grupo = (titulo, chave, valores, nomes, dicas) => {
    const g = el('div', 'menu-cfg-g');
    g.append(el('div', 'menu-cfg-t', t(titulo)));
    const seg = el('div', 'seg');
    seg.setAttribute('role', 'group');
    seg.setAttribute('aria-label', t(titulo));
    valores.forEach((v) => {
      const b = el('button', null, t(nomes[v]));
      b.type = 'button';
      b.dataset[chave] = v;
      if (dicas) b.title = t(dicas[v]);
      b.addEventListener('click', () => escolher({ [chave]: v }));
      seg.append(b);
    });
    g.append(seg);
    return g;
  };
  painel.append(grupo('Modo', 'barra', MODOS, NOMES_MODO, DICA_MODO), grupo('Posição', 'lado', LADOS, NOMES_LADO));
  botao.addEventListener('click', (ev) => {
    ev.stopPropagation();
    const abre = painel.hidden;
    painel.hidden = !abre;
    botao.setAttribute('aria-expanded', abre ? 'true' : 'false');
  });
  caixa.append(botao, painel);
  actualizarPainel();
  if (!montarAjusteMenu.ligado) {
    montarAjusteMenu.ligado = true;
    document.addEventListener('click', (ev) => {
      if (painel && !ev.target.closest('#menu-cfg')) fechar();
    });
    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape') fechar();
    });
  }
}
