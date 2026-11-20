// Camada rápida de pontos para os mapas da GeoMobilidade: milhares de clientes e potenciais clientes desenhados num só
// <canvas> por cima do Google Maps (google.maps.OverlayView), em vez de um marcador do Google (um elemento da página)
// por ponto. Desenha só o que está à vista, numa só passagem, com densidade de píxeis do ecrã (nítido em retina), e
// redesenha uma vez por fotograma (requestAnimationFrame) ao mover ou aproximar. Clique e cursor: o ponto mais perto
// do rato (até ao raio do ponto + 4 px), procurado numa grelha espacial (não percorre todos os pontos).
//
// Uso: const c = NavalPontos.criar(mapa, { aoClicar: (p, latLng) => …, titulo: (p) => '…' });
//      c.definir([{ lat, lng, r: 4, cor: '#1f77b4', borda: '#fff', bordaL: 1, rotulo?: 'R', z?: 0, … }]);
/* global google */
(function () {
  'use strict';
  window.NavalPontos = {
    criar: function (mapa, opcoes) {
      const o = opcoes || {};
      let pontos = [];
      let tela = null;
      let ctx = null;
      let pedido = 0;
      let grelha = new Map();
      let projectados = [];
      const camada = new google.maps.OverlayView();
      camada.onAdd = function () {
        tela = document.createElement('canvas');
        tela.style.cssText = 'position:absolute;left:0;top:0;pointer-events:none';
        ctx = tela.getContext('2d');
        // por cima das áreas e linhas, por baixo das janelas de informação
        this.getPanes().overlayLayer.appendChild(tela);
      };
      camada.onRemove = function () {
        if (tela) tela.remove();
        tela = null;
      };
      camada.draw = function () {
        if (!pedido) pedido = requestAnimationFrame(desenhar);
      };
      function desenhar() {
        pedido = 0;
        const proj = camada.getProjection();
        if (!tela || !proj) return;
        const div = mapa.getDiv();
        const w = div.clientWidth;
        const h = div.clientHeight;
        const dpr = Math.min(3, window.devicePixelRatio || 1);
        // o canvas cobre a área visível do mapa: canto superior esquerdo em coordenadas da camada
        const b = mapa.getBounds();
        if (!b) return;
        const ne = proj.fromLatLngToDivPixel(b.getNorthEast());
        const sw = proj.fromLatLngToDivPixel(b.getSouthWest());
        const x0 = Math.min(sw.x, ne.x);
        const y0 = Math.min(ne.y, sw.y);
        tela.style.left = x0 + 'px';
        tela.style.top = y0 + 'px';
        tela.style.width = w + 'px';
        tela.style.height = h + 'px';
        if (tela.width !== Math.round(w * dpr) || tela.height !== Math.round(h * dpr)) {
          tela.width = Math.round(w * dpr);
          tela.height = Math.round(h * dpr);
        }
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, w, h);
        projectados = [];
        grelha = new Map();
        for (let i = 0; i < pontos.length; i++) {
          const p = pontos[i];
          if (!p._ll) p._ll = new google.maps.LatLng(p.lat, p.lng);
          const px = proj.fromLatLngToDivPixel(p._ll);
          const x = px.x - x0;
          const y = px.y - y0;
          const r = p.r || 4;
          if (x < -r || y < -r || x > w + r || y > h + r) continue;
          projectados.push([x, y, r, p]);
        }
        // os de maior z por cima
        projectados.sort((a, c) => (a[3].z || 0) - (c[3].z || 0));
        for (let k = 0; k < projectados.length; k++) {
          const [x, y, r, p] = projectados[k];
          ctx.beginPath();
          ctx.arc(x, y, r, 0, 6.2832);
          ctx.fillStyle = p.cor || '#1f77b4';
          ctx.globalAlpha = p.alfa == null ? 0.95 : p.alfa;
          ctx.fill();
          ctx.globalAlpha = 1;
          if (p.bordaL !== 0) {
            ctx.lineWidth = p.bordaL || 1;
            ctx.strokeStyle = p.borda || '#ffffff';
            ctx.stroke();
          }
          if (p.rotulo) {
            ctx.fillStyle = p.corRotulo || '#ffffff';
            ctx.font = '700 ' + Math.max(9, Math.round(r * 1.1)) + 'px system-ui, sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(p.rotulo, x, y + 0.5);
          }
          const g = Math.floor(x / 24) + ':' + Math.floor(y / 24);
          if (!grelha.has(g)) grelha.set(g, []);
          grelha.get(g).push(k);
        }
      }
      /** Ponto mais perto de um píxel do mapa (no máximo raio + 4 px), ou null. */
      function perto(x, y) {
        let melhor = null;
        let dm = Infinity;
        const gx = Math.floor(x / 24);
        const gy = Math.floor(y / 24);
        for (let a = -1; a <= 1; a++)
          for (let c = -1; c <= 1; c++) {
            for (const k of grelha.get(gx + a + ':' + (gy + c)) || []) {
              const [px, py, r] = projectados[k];
              const d = Math.hypot(px - x, py - y);
              // empate: o desenhado por cima (maior índice) ganha
              if (d <= r + 4 && d <= dm) {
                dm = d;
                melhor = projectados[k][3];
              }
            }
          }
        return melhor;
      }
      const pixel = (e) => {
        const r = mapa.getDiv().getBoundingClientRect();
        return [e.domEvent.clientX - r.left, e.domEvent.clientY - r.top];
      };
      mapa.addListener('click', (e) => {
        if (!o.aoClicar || !e.domEvent) return;
        const p = perto(...pixel(e));
        if (p) o.aoClicar(p, new google.maps.LatLng(p.lat, p.lng));
      });
      let sobre = null;
      mapa.addListener('mousemove', (e) => {
        if (!e.domEvent) return;
        const p = perto(...pixel(e));
        if (p === sobre) return;
        sobre = p;
        mapa.setOptions({ draggableCursor: p ? 'pointer' : null });
        mapa.getDiv().title = p && o.titulo ? o.titulo(p) : '';
      });
      // ao arrastar, a camada move-se com o mapa; a parte que aparece desenha-se no fotograma seguinte
      mapa.addListener('bounds_changed', () => camada.draw());
      camada.setMap(mapa);
      return {
        definir(lista) {
          pontos = lista || [];
          camada.draw();
        },
        limpar() {
          pontos = [];
          camada.draw();
        },
        get n() {
          return pontos.length;
        },
      };
    },
  };
})();
