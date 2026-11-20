// Camada rápida de pontos dos mapas (geo/pontos-canvas.js), com um Google Maps simulado (projecção linear): desenha
// milhares de pontos num só canvas, depressa, só os que estão à vista, e o clique escolhe o ponto certo.
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

const CAMADA = readFileSync(new URL('../../geo/pontos-canvas.js', import.meta.url), 'utf8');

// Google Maps mínimo: mapa de 1000 × 600 px que mostra lat −9..−8 e lng 13..14 (1 grau = 1000 px em lng, 600 px em lat)
const SIMULADO = `
  window.google = { maps: {} };
  const g = google.maps;
  g.LatLng = function (a, b) { this._a = a; this._b = b; this.lat = () => a; this.lng = () => b; };
  g.event = {};
  const ouvintes = {};
  const div = document.getElementById('map');
  const proj = { fromLatLngToDivPixel: (ll) => ({ x: (ll.lng() - 13) * 1000, y: (-8 - ll.lat()) * 600 }) };
  window.mapa = {
    getDiv: () => div,
    getBounds: () => ({ getNorthEast: () => new g.LatLng(-8, 14), getSouthWest: () => new g.LatLng(-9, 13) }),
    addListener: (n, f) => ((ouvintes[n] = ouvintes[n] || []).push(f)),
    setOptions: (o) => (window.cursor = o.draggableCursor),
    disparar: (n, e) => (ouvintes[n] || []).forEach((f) => f(e)),
  };
  g.OverlayView = function () {};
  g.OverlayView.prototype.getPanes = () => ({ overlayLayer: div });
  g.OverlayView.prototype.getProjection = () => proj;
  g.OverlayView.prototype.setMap = function () { this.onAdd(); this.draw(); };
`;

test('camada de pontos: 5000 pontos num só canvas, depressa; clique e cursor no ponto mais perto', async ({ page }) => {
  await page.setContent('<div id="map" style="position:relative;width:1000px;height:600px"></div>');
  await page.addScriptTag({ content: SIMULADO });
  await page.addScriptTag({ content: CAMADA });
  const r = await page.evaluate(async () => {
    window.clicado = null;
    const c = window.NavalPontos.criar(window.mapa, { aoClicar: (p) => (window.clicado = p.id), titulo: (p) => 'P' + p.id });
    const pts = [];
    for (let i = 0; i < 5000; i++) pts.push({ id: i, lat: -8.05 - (i % 100) * 0.009, lng: 13.05 + Math.floor(i / 100) * 0.018, r: 4, cor: '#1f77b4' });
    pts.push({ id: 'fora', lat: -20, lng: 20, r: 4 }); // fora da vista: não se desenha
    pts.push({ id: 'alvo', lat: -8.5, lng: 13.5, r: 8, cor: '#c0262d', alfa: 1, z: 9 });
    const a = performance.now();
    c.definir(pts);
    await new Promise((ok) => requestAnimationFrame(() => requestAnimationFrame(ok)));
    const ms = performance.now() - a;
    const tela = document.querySelector('#map canvas');
    // o centro do alvo (500, 300) está pintado de vermelho
    const px = tela.getContext('2d').getImageData(500 * (tela.width / 1000), 300 * (tela.height / 600), 1, 1).data;
    // clique a 3 px do alvo
    const box = document.getElementById('map').getBoundingClientRect();
    window.mapa.disparar('click', { domEvent: { clientX: box.left + 503, clientY: box.top + 300 } });
    window.mapa.disparar('mousemove', { domEvent: { clientX: box.left + 503, clientY: box.top + 300 } });
    const sobre = [window.cursor, document.getElementById('map').title];
    // longe de tudo: nada
    const antes = window.clicado;
    window.clicado = null;
    window.mapa.disparar('click', { domEvent: { clientX: box.left + 998, clientY: box.top + 2 } });
    return { ms, canvases: document.querySelectorAll('#map canvas').length, px: [px[0], px[1], px[2]], antes, depois: window.clicado, sobre, n: c.n };
  });
  expect(r.canvases).toBe(1);
  expect(r.n).toBe(5002);
  expect(r.ms).toBeLessThan(300); // 5000 pontos num fotograma (em média ~30 ms)
  expect(r.px).toEqual([192, 38, 45]); // o alvo (por cima dos outros, z maior) está desenhado no sítio certo
  expect(r.antes).toBe('alvo');
  expect(r.sobre).toEqual(['pointer', 'Palvo']);
  expect(r.depois).toBe(null);
});
