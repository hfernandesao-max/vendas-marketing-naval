// GeoMobilidade › Terreno do dia: percurso de cada rota (localização registada pela aplicação de vendas, lida do
// servidor OLAP), paragens, prova de visita (venda a mais de 150 m do cliente assinalada), tempos, pontos fora do
// território e coordenadas dos clientes sugeridas pelas vendas. Repetição animada do dia no mapa.
// Dados: API do portal (v1/terreno), com o token pedido pela sessão desta página.
/* global google */
(function () {
  'use strict';
  const API = '../api/index.php/v1/';
  const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const CORES = ['#0057b8', '#1a73c9', '#4f8fd6', '#0a2a52', '#2b6cb0', '#6aa6e8', '#123f7a', '#3d7fd0', '#8bb8ef', '#1d4f91', '#5b95db'];
  const PROVA = { ok: 'No cliente', longe: 'Longe do cliente', sem_coordenadas: 'Cliente sem coordenadas' };
  let T = null;
  let token = null;
  let mapa = null;
  let camadas = [];
  let info = null;
  let vista = 'visitas';
  let marcador = null;
  let animar = null;
  const $ = (id) => document.getElementById(id);
  const el = (tg, cls, txt) => {
    const e = document.createElement(tg);
    if (cls) e.className = cls;
    if (txt != null) e.textContent = txt;
    return e;
  };
  const hm = (min) => (min >= 60 ? Math.floor(min / 60) + ' h ' + String(min % 60).padStart(2, '0') + ' min' : min + ' min');
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);

  async function pedirToken() {
    const r = await fetch(API + 'auth/token', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: '{}',
    });
    const d = await r.json().catch(() => ({}));
    if (!r.ok || !d.access_token) throw new Error(d.erro || 'Sessão terminada. Entre de novo.');
    token = d.access_token;
  }
  async function api(rota) {
    if (!token) await pedirToken();
    const ir = () => fetch(API + rota, { credentials: 'same-origin', headers: { Authorization: 'Bearer ' + token } });
    let r = await ir();
    if (r.status === 401) {
      await pedirToken();
      r = await ir();
    }
    return r.json().catch(() => ({ ok: false, erro: 'Resposta inválida do servidor.' }));
  }

  const rotaSel = () => $('tdRota').value;
  const rotasVis = () => (T ? T.rotas.filter((r) => !rotaSel() || r.rota === rotaSel()) : []);

  function indicadores() {
    const box = $('tdIndicadores');
    box.replaceChildren();
    const av = $('tdAviso');
    av.hidden = !T.erro && T.rotas.length > 0;
    av.textContent = T.erro || (T.rotas.length ? '' : 'Sem localização registada neste dia.');
    const rs = rotasVis();
    if (!rs.length) return;
    const soma = (k) => rs.reduce((s, r) => s + (r[k] || 0), 0);
    const linhas = [
      ['Clientes com venda', soma('clientes') + ' de ' + rs.length * (rs[0].objectivo || 30) + ' (objectivo)'],
      ['Tempo nos clientes', hm(soma('min_cliente'))],
      ['Tempo em deslocação', hm(soma('min_deslocacao'))],
      ['Parado sem vender', hm(soma('min_parado_sem_venda'))],
      ['Distância percorrida', soma('km').toLocaleString('pt-PT', { maximumFractionDigits: 1 }) + ' km'],
      ['Vendas longe do cliente', String(soma('vendas_longe'))],
      ['Pontos fora do território', String(soma('pontos_fora'))],
    ];
    if (rs.length === 1) linhas.unshift(['Início e fim', rs[0].inicio + ' – ' + rs[0].fim]);
    linhas.forEach(([l, v]) => {
      const d = el('div', 'pp-i');
      d.append(el('span', null, l), el('b', null, v));
      box.append(d);
    });
  }

  function lista() {
    const box = $('tdLista');
    box.replaceChildren();
    const rs = rotasVis();
    if (vista === 'visitas') {
      const vs = rs.flatMap((r) => r.visitas.map((v) => ({ ...v, r: r.rota })));
      $('tdTituloLista').textContent = `Visitas com venda (${vs.length})`;
      if (!vs.length) box.append(el('p', 'pp-nota', 'Sem vendas com localização.'));
      vs.forEach((v) => {
        const c = el('article', 'pp-item' + (v.prova === 'longe' ? ' pp-f60' : ''));
        const h = el('div', 'pp-cab');
        h.append(el('b', null, v.t.slice(11, 16) + ' · ' + (v.n || v.cli || 'Sem cliente')), el('span', 'pp-prova ' + v.prova, PROVA[v.prova] || v.prova));
        c.append(h, el('p', 'pp-nota', ['Rota ' + v.r, v.doc || '', v.dist != null ? v.dist + ' m do registo do cliente' : ''].filter(Boolean).join(' · ')));
        c.addEventListener('click', () => mapa && mapa.panTo({ lat: v.lat, lng: v.lng }));
        box.append(c);
      });
    } else if (vista === 'paragens') {
      const ps = rs.flatMap((r) => r.paragens.filter((p) => p.min >= 3).map((p) => ({ ...p, r: r.rota })));
      $('tdTituloLista').textContent = `Paragens de 3 minutos ou mais (${ps.length})`;
      ps.forEach((p) => {
        const c = el('article', 'pp-item' + (p.cliente ? '' : ' pp-f30'));
        c.append(
          el('b', null, p.de.slice(11, 16) + ' – ' + p.ate.slice(11, 16) + ' · ' + hm(p.min)),
          el('p', 'pp-nota', 'Rota ' + p.r + ' · ' + (p.cliente ? 'no cliente ' + p.cliente : 'sem venda')),
        );
        c.addEventListener('click', () => mapa && mapa.panTo({ lat: p.lat, lng: p.lng }));
        box.append(c);
      });
    } else {
      const cs = (T.coordenadas || []).filter((c) => !rotaSel() || c.r === rotaSel());
      $('tdTituloLista').textContent = `Coordenadas sugeridas pelas vendas (${cs.length})`;
      box.append(
        el('p', 'pp-nota', 'Clientes com 3 ou mais vendas no mesmo sítio, sem coordenadas ou a mais de 50 m do registo. Confirmar e corrigir no Pinguim BO.'),
      );
      cs.forEach((c) => {
        const a = el('article', 'pp-item pp-sem');
        a.append(
          el('b', null, c.n + ' (' + c.c + ')'),
          el(
            'p',
            'pp-nota',
            `Sugerido ${c.lat}, ${c.lng} · ${c.vendas} vendas` + (c.dist != null ? ` · ${c.dist} m do registo actual` : ' · sem coordenadas no registo'),
          ),
        );
        a.addEventListener('click', () => mapa && mapa.panTo({ lat: c.lat, lng: c.lng }));
        box.append(a);
      });
    }
  }

  function limpar() {
    camadas.forEach((x) => x.setMap(null));
    camadas = [];
  }
  function abrir(pos, html) {
    if (!info) info = new google.maps.InfoWindow();
    info.setContent(html);
    info.setPosition(pos);
    info.open(mapa);
  }
  function desenharMapa() {
    if (!mapa || !T) return;
    limpar();
    const lim = new google.maps.LatLngBounds();
    rotasVis().forEach((r) => {
      const cor = CORES[T.rotas.indexOf(r) % CORES.length];
      const pol = (T.territorios || {})[r.rota] || [];
      if (pol.length >= 3)
        camadas.push(
          new google.maps.Polygon({
            paths: pol.map(([lat, lng]) => ({ lat, lng })),
            strokeColor: cor,
            strokeWeight: 1,
            fillOpacity: 0.05,
            fillColor: cor,
            map: mapa,
          }),
        );
      const path = r.percurso.map(([lat, lng]) => ({ lat, lng }));
      path.forEach((p) => lim.extend(p));
      camadas.push(new google.maps.Polyline({ path, strokeColor: cor, strokeWeight: 3, strokeOpacity: 0.85, map: mapa }));
      r.visitas.forEach((v) => {
        const m = new google.maps.Marker({
          position: { lat: v.lat, lng: v.lng },
          map: mapa,
          title: v.n,
          icon: {
            path: google.maps.SymbolPath.CIRCLE,
            scale: 6,
            fillColor: v.prova === 'ok' ? '#1a73c9' : v.prova === 'longe' ? '#c0262d' : '#f5b547',
            fillOpacity: 1,
            strokeColor: '#fff',
            strokeWeight: 1,
          },
        });
        m.addListener('click', () =>
          abrir(m.getPosition(), `<b>${esc(v.n)}</b><br>${v.t.slice(11, 16)} · ${esc(PROVA[v.prova])}${v.dist != null ? ' · ' + v.dist + ' m' : ''}`),
        );
        camadas.push(m);
      });
    });
    if (vista === 'coordenadas')
      (T.coordenadas || [])
        .filter((c) => !rotaSel() || c.r === rotaSel())
        .forEach((c) => {
          const m = new google.maps.Marker({
            position: { lat: c.lat, lng: c.lng },
            map: mapa,
            title: c.n,
            icon: { path: google.maps.SymbolPath.BACKWARD_CLOSED_ARROW, scale: 5, fillColor: '#f5b547', fillOpacity: 1, strokeWeight: 1 },
          });
          camadas.push(m);
          if (c.actual)
            camadas.push(
              new google.maps.Polyline({
                path: [
                  { lat: c.lat, lng: c.lng },
                  { lat: c.actual[0], lng: c.actual[1] },
                ],
                strokeColor: '#f5b547',
                strokeWeight: 2,
                map: mapa,
              }),
            );
          lim.extend({ lat: c.lat, lng: c.lng });
        });
    if (!lim.isEmpty()) mapa.fitBounds(lim);
    repeticao();
  }

  // Repetição do dia: um marcador percorre o percurso da rota escolhida (com «reduzir movimento», só o cursor)
  function repeticao() {
    const r = rotasVis();
    const box = $('tdRepeticao');
    if (animar) clearInterval(animar);
    animar = null;
    if (marcador) marcador.setMap(null);
    marcador = null;
    box.hidden = r.length !== 1 || !r[0].percurso.length || !mapa;
    if (box.hidden) return;
    const p = r[0].percurso;
    const tempo = $('tdTempo');
    tempo.max = String(p.length - 1);
    tempo.value = '0';
    marcador = new google.maps.Marker({
      position: { lat: p[0][0], lng: p[0][1] },
      map: mapa,
      zIndex: 999,
      icon: { path: google.maps.SymbolPath.FORWARD_CLOSED_ARROW, scale: 6, fillColor: '#0a2a52', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 1 },
    });
    const ir = (i) => {
      marcador.setPosition({ lat: p[i][0], lng: p[i][1] });
      $('tdHora').textContent = p[i][2];
    };
    ir(0);
    tempo.oninput = () => ir(+tempo.value);
    $('tdPlay').onclick = () => {
      if (animar) {
        clearInterval(animar);
        animar = null;
        $('tdPlay').textContent = '▶ Repetir o dia';
        return;
      }
      $('tdPlay').textContent = '❚❚ Pausa';
      const passo = Math.max(1, Math.round(p.length / 300));
      animar = setInterval(() => {
        const i = Math.min(p.length - 1, +tempo.value + passo);
        tempo.value = String(i);
        ir(i);
        if (i >= p.length - 1) {
          clearInterval(animar);
          animar = null;
          $('tdPlay').textContent = '▶ Repetir o dia';
        }
      }, 60);
    };
  }

  function desenhar() {
    indicadores();
    lista();
    desenharMapa();
  }
  async function carregar() {
    const d = await api('terreno?data=' + encodeURIComponent($('tdDia').value));
    if (!d.ok) {
      $('tdLista').replaceChildren(el('p', 'pp-nota', d.erro || 'Não foi possível ler os dados.'));
      return;
    }
    T = d;
    const sel = $('tdRota');
    const antes = sel.value;
    sel.replaceChildren();
    if (T.rotas.length !== 1) sel.append(new Option('Todas as rotas', ''));
    T.rotas.forEach((r) => sel.append(new Option('Rota ' + r.rota, r.rota)));
    if ([...sel.options].some((o) => o.value === antes)) sel.value = antes;
    $('tdFonte').textContent = T.olap
      ? 'Localização: servidor OLAP' +
        (T.origem ? ' (' + T.origem + ')' : '') +
        '. Prova de visita: venda até 150 m do cliente. Dado pessoal dos vendedores: só para acompanhamento das rotas (Lei 22/11).'
      : 'O servidor OLAP ainda não está ligado: o ecrã fica pronto e mostra os dados assim que a ligação for configurada.';
    desenhar();
  }

  window.initMap = function () {
    mapa = new google.maps.Map($('map'), {
      center: { lat: -8.84, lng: 13.29 },
      zoom: 11,
      mapTypeControl: false,
      streetViewControl: false,
      clickableIcons: false,
    });
    if (T) desenharMapa();
  };
  document.addEventListener('DOMContentLoaded', () => {
    const d = new Date();
    $('tdDia').value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    $('tdDia').addEventListener('change', () => carregar());
    $('tdRota').addEventListener('change', desenhar);
    document.querySelectorAll('.pp-tab[data-vista]').forEach((b) =>
      b.addEventListener('click', () => {
        vista = b.dataset.vista;
        document.querySelectorAll('.pp-tab[data-vista]').forEach((x) => {
          x.classList.toggle('on', x === b);
          x.setAttribute('aria-selected', x === b ? 'true' : 'false');
        });
        if (T) desenhar();
      }),
    );
    carregar().catch((e) => $('tdLista').replaceChildren(el('p', 'pp-nota', e.message)));
  });
})();
