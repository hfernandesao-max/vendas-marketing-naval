// Informação sempre actual: com um período que inclui hoje, as vendas voltam a ser lidas a cada 5 minutos
// (e quando o separador volta a ficar visível); à meia-noite os períodos relativos (Hoje, 7 dias, Mês)
// passam para o novo dia. O topo mostra a hora da última actualização e permite actualizar à mão.
import { draw } from './app.js';
import { fecharAberto } from './componentes/barra-filtros.js';
import { fetchVendas, pedirFresco } from './fontes.js';
import { t } from './i18n/i18n.js';
import { aplicarPreset, periodoAberto, presets, st } from './periodo.js';
import { $, el, svg } from './util.js';

export const INTERVALO = 5 * 60000;
let actualizadoEm = 0,
  aActualizar = false;

export function marcarActualizado() {
  actualizadoEm = Date.now();
}

// Não interrompe quem está a trabalhar: janela aberta, painel de filtros aberto ou a escrever num campo
const ocupado = () =>
  !!document.querySelector('dialog[open]') ||
  !!fecharAberto ||
  (document.activeElement && /^(INPUT|SELECT|TEXTAREA)$/.test(document.activeElement.tagName) && document.activeElement.closest('#page'));

/** Volta a ler os dados do período (ou passa os períodos relativos para o novo dia) e redesenha. */
export function actualizar(forcar) {
  if (aActualizar || (!forcar && ocupado())) return Promise.resolve();
  aActualizar = true;
  const y = window.scrollY;
  if (forcar) pedirFresco(); // pedido da pessoa: sem a cache do servidor
  const p = st.preset ? presets()[st.preset] : null;
  const ler = p ? aplicarPreset(st.preset) : fetchVendas(st.de, st.ate);
  return ler
    .then(() => {
      draw();
      window.scrollTo(0, y);
    })
    .finally(() => {
      aActualizar = false;
    });
}

function verificar() {
  if (document.hidden) return;
  const p = st.preset ? presets()[st.preset] : null;
  if (p && (p[1] !== st.de || p[2] !== st.ate)) {
    actualizar(); // mudou o dia
    return;
  }
  if (periodoAberto() && Date.now() - actualizadoEm >= INTERVALO) actualizar();
}

const hora = (ms) => new Date(ms).toTimeString().slice(0, 5);
/** Linha do topo: estado da informação e botão «Actualizar». */
export function mostrarActualizacao() {
  const box = $('#actual');
  if (!box) return;
  box.replaceChildren();
  if (!actualizadoEm) return;
  const aberto = periodoAberto();
  box.classList.toggle('vivo', aberto);
  box.append(
    el('i', 'actual-dot'),
    el(
      'span',
      null,
      aberto
        ? t('Informação actual · actualizada às {h} · actualiza sozinha a cada 5 min', { h: hora(actualizadoEm) })
        : t('Período fechado · dados lidos às {h}', { h: hora(actualizadoEm) }),
    ),
  );
  const b = el('button', 'linkbtn actual-btn');
  b.type = 'button';
  b.innerHTML = svg('actualizar'); // nosemgrep: insecure-innerhtml, insecure-document-method -- SVG estático do próprio código (sem dados)
  b.append(el('span', null, t('Actualizar')));
  b.addEventListener('click', () => {
    b.disabled = true;
    actualizar(true);
  });
  box.append(b);
}

setInterval(verificar, 60000);
document.addEventListener('visibilitychange', verificar);
