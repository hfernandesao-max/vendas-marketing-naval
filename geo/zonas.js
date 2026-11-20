// GeoMobilidade › Zonas de entrega e Distribuidores: tudo automático, sem desenhar à mão.
//  - Zonas de entrega: a geofence de cada rota (território calculado todas as noites a partir dos clientes da rota,
//    v1/geo-clientes → territorios), os clientes na cor da sua rota e, com contorno vermelho, os que estão fora da
//    geofence da sua rota (coordenadas a confirmar ou cliente na rota errada).
//  - Distribuidores: os clientes com tipologia «Distribuidor» e a zona de cada um (os clientes indicados no Pinguim BO
//    ou, sem essa indicação, os mais próximos dele), com a distância média.
// A legenda e os números ficam na barra lateral (o mapa fica livre); as exportações saem em CSV para o Excel.
/* global google, NavalGeo, NavalCores */
(function () {
  'use strict';
  const MODO = document.body.dataset.modo === 'distribuidores' ? 'distribuidores' : 'zonas';
  const $ = (id) => document.getElementById(id);
  const el = (tg, cls, txt) => {
    const e = document.createElement(tg);
    if (cls) e.className = cls;
    if (txt != null) e.textContent = txt;
    return e;
  };
  const num = (n) => Number(n || 0).toLocaleString('pt-PT');
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
  let mapa = null;
  let info = null;
  let camadas = [];
  let camadaPontos = null; // camada rápida dos clientes (geo/pontos-canvas.js)
  let D = null; // resposta de v1/geo-clientes
  let grupos = []; // [{chave, nome, cor, clientes, poligono, fora, ...}]
  let escolhido = ''; // chave do grupo seleccionado na legenda ('' = todos)

  // ── geometria ──────────────────────────────────────────────────────────────────────────────────────────────
  function dist(a, b) {
    const r = Math.PI / 180;
    const x = (b.lng - a.lng) * r * Math.cos(((a.lat + b.lat) / 2) * r);
    const y = (b.lat - a.lat) * r;
    return Math.sqrt(x * x + y * y) * 6371000;
  }
  function dentro(p, pol) {
    let d = false;
    for (let i = 0, j = pol.length - 1; i < pol.length; j = i++) {
      const [yi, xi] = pol[i];
      const [yj, xj] = pol[j];
      if (yi > p.lat !== yj > p.lat && p.lng < ((xj - xi) * (p.lat - yi)) / (yj - yi || 1e-12) + xi) d = !d;
    }
    return d;
  }
  // invólucro convexo (cadeia monótona) com uma margem de ~300 m à volta de cada vértice
  function involucro(pts) {
    const p = pts.map((x) => [x.lat, x.lng]).sort((a, b) => a[1] - b[1] || a[0] - b[0]);
    if (p.length < 3) return p;
    const cr = (o, a, b) => (a[1] - o[1]) * (b[0] - o[0]) - (a[0] - o[0]) * (b[1] - o[1]);
    const lo = [];
    for (const x of p) {
      while (lo.length >= 2 && cr(lo[lo.length - 2], lo[lo.length - 1], x) <= 0) lo.pop();
      lo.push(x);
    }
    const up = [];
    for (const x of p.slice().reverse()) {
      while (up.length >= 2 && cr(up[up.length - 2], up[up.length - 1], x) <= 0) up.pop();
      up.push(x);
    }
    return lo.slice(0, -1).concat(up.slice(0, -1));
  }
  function comMargem(pts, m = 300) {
    if (!pts.length) return [];
    const buf = [];
    pts.forEach((x) => {
      const dLat = m / 111320;
      const dLng = m / (111320 * Math.cos((x.lat * Math.PI) / 180));
      for (let k = 0; k < 12; k++) buf.push({ lat: x.lat + dLat * Math.sin((k * Math.PI) / 6), lng: x.lng + dLng * Math.cos((k * Math.PI) / 6) });
    });
    return involucro(buf);
  }
  function centro(pol) {
    const n = pol.length || 1;
    return { lat: pol.reduce((s, p) => s + p[0], 0) / n, lng: pol.reduce((s, p) => s + p[1], 0) / n };
  }

  // ── dados ──────────────────────────────────────────────────────────────────────────────────────────────────
  function pontos() {
    return (D.clientes || [])
      .map((c) => ({ ...c, lat: parseFloat(c.Latitude), lng: parseFloat(c.Longitude) }))
      .filter((c) => isFinite(c.lat) && isFinite(c.lng));
  }
  function gruposZonas(cs) {
    const T = D.territorios || {};
    const P = D.promotores || {};
    const por = {};
    cs.forEach((c) => (por[c.Rota || ''] = por[c.Rota || ''] || []).push(c));
    const chaves = Object.keys(por).sort((a, b) => (a === '' ? 1 : b === '' ? -1 : a.localeCompare(b, 'pt', { numeric: true })));
    return chaves.map((r) => {
      const pol = r !== '' && T[r] && T[r].length >= 3 ? T[r] : r !== '' ? comMargem(por[r]) : [];
      const cl = por[r].map((c) => ({ ...c, fora: r !== '' && pol.length >= 3 && !dentro(c, pol) }));
      return {
        chave: r,
        nome: r === '' ? 'Sem rota' : r + (P[r] ? ' · ' + P[r] : ''),
        cor: NavalCores.rota(r),
        clientes: cl,
        poligono: pol,
        fora: cl.filter((c) => c.fora).length,
        automatica: !(T[r] && T[r].length >= 3),
      };
    });
  }
  function eDistribuidor(c) {
    return /DISTRIBUI/i.test(String(c['Tipo de estabelecimento'] || ''));
  }
  function gruposDistribuidores(cs) {
    const ds = cs.filter(eDistribuidor);
    const porNome = {};
    ds.forEach((d) => (porNome[String(d.Nome).toUpperCase()] = d));
    const g = ds.map((d, i) => ({ chave: String(d['Código']), nome: d.Nome, cor: NavalCores.posicao(i), base: d, clientes: [], poligono: [], fora: 0 }));
    const porChave = {};
    g.forEach((x) => (porChave[x.chave] = x));
    const sem = { chave: '_sem', nome: 'Sem distribuidor por perto', cor: '#9aa3b2', clientes: [], poligono: [], fora: 0 };
    cs.forEach((c) => {
      if (eDistribuidor(c)) return;
      // o distribuidor indicado no Pinguim BO; senão, o mais próximo
      let alvo = null;
      if (c.Distribuidor) {
        const d = porNome[String(c.Distribuidor).toUpperCase()];
        if (d) alvo = porChave[String(d['Código'])];
      }
      let km = null;
      if (!alvo && g.length) {
        let m = Infinity;
        g.forEach((x) => {
          const d = dist(c, { lat: parseFloat(x.base.Latitude), lng: parseFloat(x.base.Longitude) });
          if (d < m) {
            m = d;
            alvo = x;
          }
        });
        km = m / 1000;
      }
      if (alvo) alvo.clientes.push({ ...c, km: km != null ? km : dist(c, { lat: parseFloat(alvo.base.Latitude), lng: parseFloat(alvo.base.Longitude) }) / 1000 });
      else sem.clientes.push(c);
    });
    g.forEach((x) => {
      const base = { lat: parseFloat(x.base.Latitude), lng: parseFloat(x.base.Longitude) };
      x.poligono = comMargem(x.clientes.concat([base]));
      x.km = x.clientes.length ? x.clientes.reduce((s, c) => s + c.km, 0) / x.clientes.length : 0;
    });
    g.sort((a, b) => b.clientes.length - a.clientes.length);
    g.forEach((x, i) => (x.cor = NavalCores.posicao(i)));
    if (sem.clientes.length) g.push(sem);
    return g;
  }

  // ── barra lateral ──────────────────────────────────────────────────────────────────────────────────────────
  function indicadores(cs) {
    const box = $('zIndicadores');
    box.replaceChildren();
    const linhas =
      MODO === 'zonas'
        ? [
            ['Clientes no mapa', num(cs.length)],
            ['Rotas (geofences)', num(grupos.filter((g) => g.chave !== '').length)],
            ['Dentro da geofence da sua rota', num(cs.length - grupos.reduce((s, g) => s + g.fora, 0) - (grupos.find((g) => g.chave === '') || { clientes: [] }).clientes.length)],
            ['Fora da geofence da sua rota', num(grupos.reduce((s, g) => s + g.fora, 0))],
            ['Sem rota (sem compras há 56 dias)', num((grupos.find((g) => g.chave === '') || { clientes: [] }).clientes.length)],
          ]
        : [
            ['Clientes no mapa', num(cs.length)],
            ['Distribuidores', num(grupos.filter((g) => g.base).length)],
            ['Clientes sem distribuidor', num((grupos.find((g) => g.chave === '_sem') || { clientes: [] }).clientes.length)],
          ];
    linhas.forEach(([l, v]) => {
      const d = el('div', 'z-i');
      d.append(el('span', null, l), el('b', null, v));
      box.append(d);
    });
    const g = D.gerado ? String(D.gerado) : '';
    $('zFonte').textContent =
      'Clientes do Pinguim BO' +
      (D.origem ? ' (' + D.origem + ')' : '') +
      (g ? ', lidos em ' + g.slice(8, 10) + '-' + g.slice(5, 7) + '-' + g.slice(0, 4) + ' ' + g.slice(11, 16) : '') +
      (MODO === 'zonas' ? '. Geofences calculadas todas as noites a partir dos clientes de cada rota.' : '. Zona de cada distribuidor: os seus clientes no Pinguim BO ou, sem essa indicação, os mais próximos.');
  }
  function legenda() {
    const box = $('zLegenda');
    box.replaceChildren();
    if (MODO === 'distribuidores' && !grupos.some((g) => g.base)) {
      box.append(
        el(
          'p',
          'z-nota',
          'Nenhum cliente com a tipologia «Distribuidor» no Pinguim BO. Quando um cliente for registado com essa tipologia, a sua zona aparece aqui sozinha.',
        ),
      );
    }
    const todos = el('button', 'z-item' + (escolhido === '' ? ' on' : ''));
    todos.type = 'button';
    todos.append(el('span', 'z-cor z-todas'), el('span', 'z-nome', 'Todas'), el('span', 'z-num', num(grupos.reduce((s, g) => s + g.clientes.length, 0))));
    todos.addEventListener('click', () => escolher(''));
    box.append(todos);
    grupos.forEach((g) => {
      const b = el('button', 'z-item' + (escolhido === g.chave ? ' on' : ''));
      b.type = 'button';
      b.setAttribute('aria-pressed', escolhido === g.chave ? 'true' : 'false');
      const cor = el('span', 'z-cor');
      cor.style.background = g.cor;
      const det = MODO === 'zonas' ? (g.fora ? g.fora + ' fora' : '') : g.base ? g.km.toFixed(1).replace('.', ',') + ' km em média' : '';
      b.append(cor, el('span', 'z-nome', g.nome), el('span', 'z-num', num(g.clientes.length)));
      if (det) b.append(el('span', 'z-det' + (MODO === 'zonas' && g.fora ? ' aviso' : ''), det));
      b.addEventListener('click', () => escolher(escolhido === g.chave ? '' : g.chave));
      box.append(b);
    });
    const s = el('div', 'z-simbolos');
    s.append(
      el('p', null, MODO === 'zonas' ? '● cliente na cor da sua rota · ◯ contorno vermelho: fora da geofence da sua rota' : '◆ distribuidor · ● cliente na cor do seu distribuidor'),
      el('p', null, MODO === 'zonas' ? 'Área sombreada: geofence da rota. Toque numa rota para a ver sozinha.' : 'Área sombreada: zona do distribuidor. Toque num distribuidor para o ver sozinho.'),
    );
    box.append(s);
  }
  function detalhe(c, g) {
    const box = $('zDetalhe');
    box.replaceChildren();
    if (!c) {
      box.append(el('p', 'z-nota', 'Toque num cliente no mapa para ver os dados.'));
      return;
    }
    box.append(el('b', null, c.Nome || c['Código']));
    const linhas = [
      ['Código', c['Código']],
      ['Rota', c.Vendedor || c.Rota || '—'],
      ['Dia de visita', c.Dia || '—'],
      ['Tipologia', c['Tipo de estabelecimento'] || '—'],
      ['Estado', c.Estado],
    ];
    if (MODO === 'zonas' && c.fora) linhas.push(['Geofence', 'Fora da geofence da sua rota: confirmar as coordenadas ou a rota']);
    if (MODO === 'distribuidores' && g && g.base) linhas.push(['Distribuidor', g.nome + (c.km != null ? ' · ' + c.km.toFixed(1).replace('.', ',') + ' km' : '')]);
    linhas.forEach(([k, v]) => {
      const d = el('div', 'z-i');
      d.append(el('span', null, k), el('b', null, String(v)));
      box.append(d);
    });
    const ir = el('a', 'z-ir', 'Abrir no Google Maps');
    ir.href = `https://www.google.com/maps?q=${c.lat},${c.lng}`;
    ir.target = '_blank';
    ir.rel = 'noopener noreferrer';
    box.append(ir);
  }

  // ── mapa ───────────────────────────────────────────────────────────────────────────────────────────────────
  function limpar() {
    camadas.forEach((x) => x.setMap(null));
    camadas = [];
    if (camadaPontos) camadaPontos.limpar();
  }
  function desenharMapa() {
    if (!mapa || !D) return;
    limpar();
    const lim = new google.maps.LatLngBounds();
    const vis = grupos.filter((g) => escolhido === '' || g.chave === escolhido);
    const pts = [];
    vis.forEach((g) => {
      if (g.poligono.length >= 3) {
        const pol = new google.maps.Polygon({
          paths: g.poligono.map(([lat, lng]) => ({ lat, lng })),
          strokeColor: g.cor,
          strokeWeight: escolhido ? 3 : 2,
          strokeOpacity: 0.9,
          fillColor: g.cor,
          fillOpacity: escolhido ? 0.18 : 0.1,
          clickable: false,
          map: mapa,
        });
        camadas.push(pol);
        // rótulo da rota no centro da geofence (só com todas: identifica cada área sem legenda em cima do mapa)
        const c = centro(g.poligono);
        camadas.push(
          new google.maps.Marker({
            position: c,
            map: mapa,
            clickable: false,
            zIndex: 5,
            icon: { path: google.maps.SymbolPath.CIRCLE, scale: 0 },
            label: { text: MODO === 'zonas' ? g.chave : String(g.nome).slice(0, 14), color: g.cor, fontSize: '13px', fontWeight: '800', className: 'z-rotulo' },
          }),
        );
      }
      if (g.base) {
        const b = { lat: parseFloat(g.base.Latitude), lng: parseFloat(g.base.Longitude) };
        const m = new google.maps.Marker({
          position: b,
          map: mapa,
          zIndex: 1000,
          title: g.nome + ' (distribuidor)',
          icon: { path: 'M 0,-9 9,0 0,9 -9,0 Z', fillColor: g.cor, fillOpacity: 1, strokeColor: '#0a0f1a', strokeWeight: 2, scale: 1.3 },
        });
        m.addListener('click', () => {
          detalhe({ ...g.base, lat: b.lat, lng: b.lng }, g);
          abrir(m.getPosition(), `<b>${esc(g.nome)}</b><br>Distribuidor · ${num(g.clientes.length)} clientes`);
        });
        camadas.push(m);
        lim.extend(b);
      }
      g.clientes.forEach((c) => {
        pts.push({ lat: c.lat, lng: c.lng, r: c.fora ? 7 : 5.5, cor: g.cor, borda: c.fora ? NavalCores.AVISO : '#ffffff', bordaL: c.fora ? 3 : 1.5, z: c.fora ? 2 : 1, c, g });
        lim.extend({ lat: c.lat, lng: c.lng });
      });
    });
    if (!camadaPontos)
      camadaPontos = window.NavalPontos.criar(mapa, {
        titulo: (p) => p.c.Nome,
        aoClicar: (p, pos) => {
          const c = p.c;
          detalhe(c, p.g);
          abrir(
            pos,
            `<b>${esc(c.Nome)}</b><br>${esc(c.Vendedor || 'Sem rota')}${c.Dia ? ' · ' + esc(c.Dia.toLowerCase()) : ''}` +
              (c.fora ? '<br><span style="color:#c0262d">Fora da geofence da sua rota</span>' : ''),
          );
        },
      });
    camadaPontos.definir(pts);
    if (!lim.isEmpty()) mapa.fitBounds(lim, 40);
  }
  function abrir(pos, html) {
    if (!info) info = new google.maps.InfoWindow();
    info.setContent(`<div class="z-info">${html}</div>`);
    info.setPosition(pos);
    info.open(mapa);
  }
  function escolher(k) {
    escolhido = k;
    legenda();
    desenharMapa();
  }

  // ── exportações (CSV para o Excel: «;» e BOM) ──────────────────────────────────────────────────────────────
  function csv(nome, cab, linhas) {
    const q = (v) => {
      const s = String(v == null ? '' : v);
      return /[";\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
    };
    const txt = '﻿' + [cab].concat(linhas).map((l) => l.map(q).join(';')).join('\r\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([txt], { type: 'text/csv;charset=utf-8' }));
    a.download = nome + '-' + new Date().toISOString().slice(0, 10) + '.csv';
    document.body.append(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 2000);
  }
  function linhasClientes(filtro) {
    const out = [];
    grupos.forEach((g) =>
      g.clientes.filter(filtro).forEach((c) => {
        const base = [c['Código'], c.Nome, c.Rota || '', c.Vendedor || '', c.Dia || '', c['Tipo de estabelecimento'] || '', c.Estado, c.lat, c.lng];
        out.push(
          MODO === 'zonas'
            ? base.concat([g.chave === '' ? 'sem rota' : c.fora ? 'fora' : 'dentro'])
            : base.concat([g.base ? g.nome : '', c.km != null ? c.km.toFixed(2).replace('.', ',') : '']),
        );
      }),
    );
    return out;
  }
  const CAB = ['Código', 'Nome', 'Rota', 'Vendedor', 'Dia de visita', 'Tipologia', 'Estado', 'Latitude', 'Longitude'];
  const EXPORTA = {
    todos: () => csv(MODO + '-clientes', CAB.concat(MODO === 'zonas' ? ['Geofence da rota'] : ['Distribuidor', 'Distância (km)']), linhasClientes(() => true)),
    dentro: () => csv('clientes-dentro-das-geofences', CAB.concat(['Geofence da rota']), linhasClientes((c) => c.Rota && !c.fora)),
    fora: () => csv('clientes-fora-das-geofences', CAB.concat(['Geofence da rota']), linhasClientes((c) => c.fora)),
    resumo: () =>
      csv(
        MODO + '-resumo',
        MODO === 'zonas' ? ['Rota', 'Vendedor', 'Clientes', 'Dentro', 'Fora', 'Vértices da geofence'] : ['Distribuidor', 'Código', 'Clientes', 'Distância média (km)'],
        grupos.map((g) =>
          MODO === 'zonas'
            ? [g.chave || 'sem rota', (D.promotores || {})[g.chave] || '', g.clientes.length, g.clientes.length - g.fora, g.fora, g.poligono.length]
            : [g.nome, g.base ? g.base['Código'] : '', g.clientes.length, g.base ? g.km.toFixed(2).replace('.', ',') : ''],
        ),
      ),
    geojson: () => {
      const fc = {
        type: 'FeatureCollection',
        features: grupos
          .filter((g) => g.poligono.length >= 3)
          .map((g) => ({
            type: 'Feature',
            properties: { zona: g.chave, nome: g.nome, clientes: g.clientes.length, cor: g.cor },
            geometry: { type: 'Polygon', coordinates: [g.poligono.map(([la, lo]) => [lo, la]).concat([[g.poligono[0][1], g.poligono[0][0]]])] },
          })),
      };
      const a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([JSON.stringify(fc)], { type: 'application/geo+json' }));
      a.download = (MODO === 'zonas' ? 'geofences-rotas-' : 'zonas-distribuidores-') + new Date().toISOString().slice(0, 10) + '.geojson';
      document.body.append(a);
      a.click();
      a.remove();
    },
  };

  // ── arranque ───────────────────────────────────────────────────────────────────────────────────────────────
  function preparar() {
    const cs = pontos();
    grupos = MODO === 'zonas' ? gruposZonas(cs) : gruposDistribuidores(cs);
    if (escolhido && !grupos.some((g) => g.chave === escolhido)) escolhido = '';
    indicadores(cs);
    legenda();
    detalhe(null);
    desenharMapa();
  }
  async function carregar() {
    const est = $('zEstado').value;
    $('zFonte').textContent = 'A ler os clientes do Pinguim BO…';
    try {
      const lista = await NavalGeo.clientes(est);
      const d = NavalGeo.ultima ? NavalGeo.ultima() : {};
      D = { ...d, clientes: lista };
      preparar();
    } catch (e) {
      $('zFonte').textContent = e.message;
    }
  }
  window.initMap = function () {
    mapa = new google.maps.Map($('map'), {
      center: { lat: -8.84, lng: 13.29 },
      zoom: 11,
      mapTypeControl: true,
      mapTypeControlOptions: { position: google.maps.ControlPosition.LEFT_BOTTOM },
      streetViewControl: false,
      clickableIcons: false,
      gestureHandling: 'greedy',
    });
    if (D) desenharMapa();
  };
  document.addEventListener('DOMContentLoaded', () => {
    $('zEstado').addEventListener('change', carregar);
    document.querySelectorAll('[data-exporta]').forEach((b) =>
      b.addEventListener('click', () => {
        if (!D) return;
        EXPORTA[b.dataset.exporta]();
      }),
    );
    carregar();
  });
})();
