// Inactividade (OWASP ASVS 5.0 V7.3, NIST SP 800-63B-4 §2.2.3, PCI DSS 4.0 8.2.8, ISO/IEC 27001 A.7.7 e A.8.5):
//   - conta só o que a pessoa faz (rato, teclado, toque, roda), nunca as actualizações automáticas dos dados; a
//     actividade é partilhada entre os separadores abertos do portal (armazenamento do navegador);
//   - 5 minutos sem actividade: o portal volta ao painel principal (ecrã limpo), salvo com trabalho por gravar, uma
//     janela aberta, a escrever num campo ou no modo apresentação (modo-apresentacao.js; 60 minutos nesse modo);
//   - 1 minuto antes do fim (15 minutos, definidos pelo servidor): aviso com contagem decrescente e «Continuar ligado»;
//   - no fim: termina a sessão no servidor e abre a página de entrada com o motivo.
// Cada pedido à API leva há quantos segundos a pessoa não mexe (cabeçalho X-Inactivo-S): é assim que o servidor
// distingue a actividade real das leituras automáticas.
import { definirInactividade } from './api.js';
import { t } from './i18n/i18n.js';
import { haPorGravar, limparPorGravar } from './componentes/rascunho.js';
import { botao, janela } from './componentes/janelas.js';
import { SESSAO, paginaAtual, paginasVisiveis } from './perfil.js';
import { el } from './util.js';

export const VOLTAR_INICIO_S = 5 * 60;
const AVISO_S = 60;
const CHAVE = 'vm-actividade';
let ultima = Date.now();
let gravada = 0;
let aviso = null;
let terminada = false;

/** Última actividade da pessoa (a mais recente de todos os separadores). */
function ultimaActividade() {
  try {
    const v = +localStorage.getItem(CHAVE) || 0;
    if (v > ultima && v <= Date.now() + 5000) ultima = v;
  } catch (e) {
    /* sem armazenamento: só este separador */
  }
  return ultima;
}

/** Segundos desde a última actividade (para o cabeçalho X-Inactivo-S). */
export const segundosInactivo = () => Math.max(0, Math.floor((Date.now() - ultimaActividade()) / 1000));

function mexeu() {
  if (terminada) return;
  ultima = Date.now();
  if (ultima - gravada > 5000) {
    gravada = ultima;
    try {
      localStorage.setItem(CHAVE, String(ultima));
    } catch (e) {
      /* sem armazenamento */
    }
  }
  if (aviso) fecharAviso();
}

function fecharAviso() {
  const d = aviso;
  aviso = null;
  if (d && d.open) d.close();
}

const ocupado = () =>
  haPorGravar() ||
  !!document.querySelector('dialog[open]') ||
  (document.activeElement && /^(INPUT|SELECT|TEXTAREA)$/.test(document.activeElement.tagName) && document.activeElement.closest('#page'));

/** Modo apresentação activo (modo-apresentacao.js): o portal não volta sozinho ao painel. */
export const emApresentacao = () => !!(SESSAO && SESSAO.apresentacao && SESSAO.apresentacao * 1000 > Date.now());

function voltarInicio() {
  const inicio = (paginasVisiveis()[0] || {}).id;
  const p = paginaAtual();
  if (!inicio || !p || p.id === inicio || ocupado()) return;
  location.hash = '#' + inicio;
}

function terminar() {
  if (terminada) return;
  terminada = true;
  fecharAviso();
  limparPorGravar(); // a sessão acabou: o navegador não pergunta se quer sair (os rascunhos ficam guardados)
  document.body.classList.add('sessao-terminada'); // ecrã limpo enquanto a página de entrada abre
  const f = el('form');
  f.method = 'post';
  f.action = '../geo/auth/logout.php';
  [
    ['csrf', (SESSAO && SESSAO.csrf) || ''],
    ['motivo', 'inactividade'],
  ].forEach(([n, v]) => {
    const i = el('input');
    i.type = 'hidden';
    i.name = n;
    i.value = v;
    f.append(i);
  });
  document.body.append(f);
  f.submit();
}

function mostrarAviso(resta) {
  if (aviso) {
    const s = aviso.querySelector('.ina-s');
    if (s) s.textContent = t('A sessão termina em {s} s.', { s: resta });
    return;
  }
  aviso = janela(
    t('Sessão sem actividade'),
    t('Por segurança, a sessão termina após {m} minutos sem actividade.', { m: Math.round(limite() / 60) }),
    (b, ft) => {
      const s = el('p', 'ina-s', t('A sessão termina em {s} s.', { s: resta }));
      s.setAttribute('role', 'timer');
      s.setAttribute('aria-live', 'assertive');
      b.append(s);
      if (haPorGravar()) b.append(el('p', 'note', t('Tem alterações por gravar: continue ligado e grave-as.')));
      ft.append(
        botao(t('Terminar sessão'), 'ghost', terminar),
        botao(t('Continuar ligado'), null, () => mexeu()),
      );
    },
  );
  aviso.setAttribute('role', 'alertdialog');
  aviso.classList.add('ina-aviso');
}

/** Tempo de inactividade da sessão (segundos), definido pelo servidor. */
const limite = () => (SESSAO && SESSAO.sessao && +SESSAO.sessao.inactiva_s) || 0;

export function verificarInactividade() {
  if (terminada) return;
  const s = segundosInactivo();
  if (s >= VOLTAR_INICIO_S && !emApresentacao()) voltarInicio();
  const lim = limite();
  if (!lim) return; // sem servidor (ensaios locais): só o regresso ao painel
  if (s >= lim) terminar();
  else if (s >= lim - AVISO_S) mostrarAviso(lim - s);
  else if (aviso) fecharAviso();
}

/**
 * Gestos numa janela (o portal ou uma app mostrada dentro dele, da mesma origem, no modo apresentação): contam como
 * actividade da pessoa.
 */
export function escutarJanela(w) {
  let movido = 0;
  const o = { passive: true, capture: true };
  ['pointerdown', 'keydown', 'wheel', 'touchstart'].forEach((e) => w.addEventListener(e, mexeu, o));
  w.addEventListener(
    'pointermove',
    () => {
      const a = Date.now();
      if (a - movido > 2000) {
        movido = a;
        mexeu();
      }
    },
    o,
  );
}

let iniciada = false;
export function iniciarInactividade() {
  if (iniciada) return;
  iniciada = true;
  ultima = Date.now();
  definirInactividade(segundosInactivo);
  escutarJanela(window);
  addEventListener('storage', (e) => {
    if (e.key === CHAVE && aviso && segundosInactivo() < limite() - AVISO_S) fecharAviso();
  });
  document.addEventListener('visibilitychange', verificarInactividade);
  setInterval(verificarInactividade, 1000);
}
