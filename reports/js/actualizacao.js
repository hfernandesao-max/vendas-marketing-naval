// Informação sempre actual: com um período que inclui hoje, as vendas voltam a ser lidas a cada 5 minutos
// (e quando o separador volta a ficar visível); à meia-noite os períodos relativos (Hoje, 7 dias, Mês)
// passam para o novo dia. O topo mostra a hora da última actualização; o botão «Actualizar» recarrega o portal.
import { draw } from './app.js';
import { fecharAberto } from './componentes/barra-filtros.js';
import { haPorGravar } from './componentes/rascunho.js';
import { DADOS, fetchVendas, pedirFresco } from './fontes.js';
import { t } from './i18n/i18n.js';
import { aplicarPreset, periodoAberto, presets, st } from './periodo.js';
import { $, el, svg } from './util.js';

export const INTERVALO = 5 * 60000;
// dados de hoje com mais de 15 minutos: aviso a âmbar (actualidade, ISO/IEC 25012)
const ATRASO = 15 * 60000;
const FONTES = { pinguim: 'Pinguim BO', olap: 'histórico do OLAP', misto: 'histórico do OLAP e hoje do Pinguim BO' };
let actualizadoEm = 0,
  aActualizar = false;

export function marcarActualizado() {
  actualizadoEm = Date.now();
}

// Não interrompe quem está a trabalhar: janela aberta, painel de filtros aberto, a escrever num campo ou com dados
// escritos e ainda não gravados (contagem a meio, por exemplo); actualiza logo que se grave ou se limpe
const ocupado = () =>
  haPorGravar() ||
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
  // hora a que os dados foram lidos da fonte (cabeçalho da API; sem ele, a hora do pedido) e a fonte de cada parte
  const lido = DADOS.ate ? Date.parse(String(DADOS.ate).replace(' ', 'T')) || actualizadoEm : actualizadoEm;
  const atrasado = aberto && Date.now() - lido > ATRASO;
  box.classList.toggle('vivo', aberto && !atrasado);
  box.classList.toggle('atrasado', atrasado);
  const fonte = FONTES[DADOS.fonte] ? ' · ' + t(FONTES[DADOS.fonte]) : '';
  box.append(
    el('i', 'actual-dot'),
    el(
      'span',
      null,
      (aberto
        ? t('Informação actual · dados até {h} · actualiza sozinha a cada 5 min', { h: hora(lido) })
        : t('Período fechado · dados lidos às {h}', { h: hora(lido) })) + fonte,
    ),
  );
  box.title = atrasado
    ? t('Os dados têm mais de 15 minutos: carregue em «Actualizar» ou verifique a ligação ao Pinguim BO (indicador das fontes, no menu).')
    : '';
  const b = el('button', 'linkbtn actual-btn');
  b.type = 'button';
  b.innerHTML = svg('actualizar'); // nosemgrep: insecure-innerhtml, insecure-document-method -- SVG estático do próprio código (sem dados)
  b.append(el('span', null, t('Actualizar')));
  b.title = t('Recarrega o portal: volta a ler todos os dados (vendas, stock, sincronização), mantendo a página, o período e os filtros.');
  // Refresh completo do portal (como F5): a página, o período e os filtros mantêm-se (endereço e armazenamento da sessão)
  b.addEventListener('click', () => {
    b.disabled = true;
    location.reload();
  });
  box.append(b);
}

setInterval(verificar, 60000);
setInterval(mostrarActualizacao, 60000); // o aviso dos dados atrasados aparece sem esperar por outro desenho
document.addEventListener('visibilitychange', verificar);
