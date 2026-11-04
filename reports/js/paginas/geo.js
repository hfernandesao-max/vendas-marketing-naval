// GeoMobilidade dentro do portal: as páginas de mapas (rotas, prospecção, terreno, zonas e distribuidores) abrem
// na área principal, com o mesmo menu, o mesmo tema e a mesma sessão (sem nova entrada). Cada página GEO corre
// num quadro da própria origem (mesma sessão e mesmas protecções do servidor) e ocupa a altura do ecrã.
import { t } from '../i18n/i18n.js';
import { SESSAO } from '../perfil.js';
import { el } from '../util.js';
import { R } from './registo.js';

/** Endereço de cada página GEO (relativo a reports/). */
export const GEO_PAGINAS = {
  'geo-rotas': '../geo/geo.php',
  'geo-prospeccao': '../geo/prospeccao.php',
  'geo-terreno': '../geo/terreno.php',
  'geo-zonas': '../geo/view/index.php',
  'geo-distribuidores': '../geo/view/viewDist/index.php',
};

let ajuste = null;
function ajustarAltura(q) {
  if (!q.isConnected) {
    removeEventListener('resize', ajuste);
    ajuste = null;
    return;
  }
  const topo = q.getBoundingClientRect().top + scrollY;
  q.style.height = Math.max(420, innerHeight - topo - 16) + 'px';
}

Object.entries(GEO_PAGINAS).forEach(([id, src]) => {
  R[id] = (pg) => {
    // sem servidor do portal (ficheiros estáticos, testes locais) não há páginas GEO para mostrar
    if (!SESSAO) {
      pg.append(el('div', 'glass empty', t('Disponível no servidor do portal.')));
      return;
    }
    document.body.classList.add('pagina-mapa');
    const q = el('iframe', 'geo-quadro');
    q.src = src;
    q.title = document.getElementById('titulo') ? document.getElementById('titulo').textContent : id;
    q.setAttribute('allow', 'geolocation; fullscreen');
    pg.append(q);
    if (ajuste) removeEventListener('resize', ajuste);
    ajuste = () => ajustarAltura(q);
    addEventListener('resize', ajuste);
    requestAnimationFrame(ajuste);
  };
});
