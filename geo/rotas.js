// GeoMobilidade › Optimização de rotas: o percurso de cada rota, optimizado sozinho todas as noites pelo servidor
// (sem ficheiros nem botões: os clientes, as coordenadas e as visitas do dia vêm do Pinguim BO, pelas mesmas regras
// das «Visitas do dia»). Mostra a ordem de visita, a hora prevista de chegada a cada cliente, os quilómetros, a hora de
// regresso e os clientes sem coordenadas. Dados: API do portal (v1/rotas-optimizadas).
/* global google */
(function () {
  'use strict';
  const API = '../api/index.php/v1/';
  const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const CORES = ['#0057b8', '#1a73c9', '#4f8fd6', '#0a2a52', '#2b6cb0', '#6aa6e8', '#123f7a', '#3d7fd0', '#8bb8ef', '#1d4f91', '#5b95db'];
  const ESTADO = { parado: 'Parado', a_abrandar: 'A abrandar', em_dia: 'Em dia' };
  const DIAS = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
  let P = null;
  let token = null;
  let mapa = null;
  let camadas = [];
  let info = null;
  const $ = (id) => document.getElementById(id);
  const el = (tg, cls, txt) => {
    const e = document.createElement(tg);
    if (cls) e.className = cls;
    if (txt != null) e.textContent = txt;
    return e;
  };
  const dl = (iso) => (iso ? iso.slice(8, 10) + '-' + iso.slice(5, 7) + '-' + iso.slice(0, 4) : '');
  const hm = (min) => (min >= 60 ? Math.floor(min / 60) + ' h ' + String(min % 60).padStart(2, '0') + ' min' : min + ' min');
  const km = (v) => Number(v || 0).toLocaleString('pt-PT', { maximumFractionDigits: 1 }) + ' km';
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

  const rotas = () => Object.keys((P && P.percursos) || {});
  const nomeRota = (r) => r + (P.promotores && P.promotores[r] ? ' · ' + P.promotores[r] : '');
  const actual = () => (P && P.percursos[$('rtRota').value]) || null;

  function indicadores() {
    const box = $('rtIndicadores');
    box.replaceChildren();
    const x = actual();
    const av = $('rtAviso');
    av.hidden = !!x;
    av.textContent = P.erro || (rotas().length ? '' : 'O primeiro cálculo automático fica pronto na próxima noite.');
    $('rtExportar').hidden = !x;
    if (!x) return;
    const linhas = [
      ['Clientes a visitar', String(x.paragens.length + x.sem_coordenadas.length)],
      ['Distância prevista', km(x.km)],
      ['Saída e regresso', x.saida + ' – ' + x.regresso],
      ['Tempo total', hm(x.minutos)],
      ['Ponto de partida', x.partida ? (x.partida.origem === 'olap' ? 'Saída habitual da carrinha' : 'Armazém configurado') : 'Primeiro cliente'],
    ];
    linhas.forEach(([l, v]) => {
      const d = el('div', 'pp-i');
      d.append(el('span', null, l), el('b', null, v));
      box.append(d);
    });
    if (x.acima_jornada) {
      const d = el('div', 'pp-aviso', 'O percurso passa a jornada de trabalho: rever a carteira desta rota.');
      box.append(d);
    }
  }

  function lista() {
    const box = $('rtLista');
    box.replaceChildren();
    const x = actual();
    if (!x) return;
    $('rtTituloLista').textContent = 'Ordem de visita (' + x.paragens.length + ')';
    x.paragens.forEach((p) => {
      const c = el('article', 'pp-item' + (p.estado === 'parado' ? ' pp-f60' : p.estado === 'a_abrandar' ? ' pp-f30' : ''));
      const h = el('div', 'pp-cab');
      h.append(el('b', null, p.ordem + '. ' + (p.n || p.c)), el('span', 'pp-prova ok', p.chegada));
      c.append(h, el('p', 'pp-nota', [p.c, ESTADO[p.estado] || '', km(p.km) + ' desde a partida'].filter(Boolean).join(' · ')));
      c.addEventListener('click', () => {
        if (!mapa) return;
        mapa.panTo({ lat: p.lat, lng: p.lng });
        mapa.setZoom(Math.max(mapa.getZoom(), 15));
      });
      box.append(c);
    });
    if (x.sem_coordenadas.length) {
      box.append(el('h2', null, 'Sem coordenadas (' + x.sem_coordenadas.length + ')'));
      box.append(el('p', 'pp-nota', 'Visitas do dia sem localização no Pinguim BO: entram no fim do percurso. Corrigir em Qualidade dos dados.'));
      x.sem_coordenadas.forEach((s) => box.append(el('article', 'pp-item pp-sem', (s.n || s.c) + ' (' + s.c + ')')));
    }
  }

  function limpar() {
    camadas.forEach((c) => c.setMap(null));
    camadas = [];
  }
  function desenharMapa() {
    if (!mapa || !P) return;
    limpar();
    const x = actual();
    if (!x || !x.paragens.length) return;
    const cor = CORES[rotas().indexOf($('rtRota').value) % CORES.length];
    const lim = new google.maps.LatLngBounds();
    const path = x.paragens.map((p) => ({ lat: p.lat, lng: p.lng }));
    if (x.partida) {
      path.unshift({ lat: x.partida.lat, lng: x.partida.lng });
      if (P.cfg && P.cfg.regressa) path.push({ lat: x.partida.lat, lng: x.partida.lng });
      const m = new google.maps.Marker({
        position: { lat: x.partida.lat, lng: x.partida.lng },
        map: mapa,
        title: 'Partida',
        icon: { path: google.maps.SymbolPath.BACKWARD_CLOSED_ARROW, scale: 6, fillColor: '#f5b547', fillOpacity: 1, strokeWeight: 1 },
      });
      camadas.push(m);
    }
    camadas.push(
      new google.maps.Polyline({
        path,
        geodesic: true,
        strokeColor: cor,
        strokeWeight: 3,
        strokeOpacity: 0.85,
        icons: [{ icon: { path: google.maps.SymbolPath.FORWARD_OPEN_ARROW, scale: 2.5 }, offset: '50%', repeat: '120px' }],
        map: mapa,
      }),
    );
    x.paragens.forEach((p) => {
      lim.extend({ lat: p.lat, lng: p.lng });
      const m = new google.maps.Marker({
        position: { lat: p.lat, lng: p.lng },
        map: mapa,
        label: { text: String(p.ordem), color: '#fff', fontSize: '11px', fontWeight: '700' },
        icon: { path: google.maps.SymbolPath.CIRCLE, scale: 11, fillColor: cor, fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2 },
        title: p.ordem + '. ' + (p.n || p.c),
      });
      m.addListener('click', () => {
        if (!info) info = new google.maps.InfoWindow();
        info.setContent('<b>' + esc(p.ordem + '. ' + (p.n || p.c)) + '</b><br>' + esc(p.c) + '<br>Chegada prevista ' + esc(p.chegada));
        info.open({ map: mapa, anchor: m });
      });
      camadas.push(m);
    });
    if (x.partida) lim.extend({ lat: x.partida.lat, lng: x.partida.lng });
    mapa.fitBounds(lim, 48);
  }

  function desenhar() {
    indicadores();
    lista();
    desenharMapa();
  }

  async function carregar(dia) {
    P = await api('rotas-optimizadas' + (dia ? '?dia=' + encodeURIComponent(dia) : ''));
    if (!P || !P.ok) {
      P = { percursos: {}, dias: [], erro: (P && P.erro) || 'Não foi possível ler os percursos.' };
    }
    const sd = $('rtDia');
    sd.replaceChildren();
    (P.dias || []).forEach((d) => {
      const o = el('option', null, DIAS[new Date(d + 'T12:00:00').getDay()] + ', ' + dl(d));
      o.value = d;
      sd.append(o);
    });
    if (P.dia) sd.value = P.dia;
    const sr = $('rtRota');
    const antes = sr.value;
    sr.replaceChildren();
    rotas().forEach((r) => {
      const o = el('option', null, nomeRota(r));
      o.value = r;
      sr.append(o);
    });
    if (rotas().includes(antes)) sr.value = antes;
    $('rtFonte').textContent = P.gerado
      ? 'Optimizado automaticamente em ' + dl(P.gerado) + ' às ' + P.gerado.slice(11, 16) + '. Clientes, coordenadas e visitas do Pinguim BO.'
      : '';
    desenhar();
  }

  async function exportar() {
    const x = actual();
    if (!x) return;
    if (!window.ExcelJS) {
      await new Promise((ok, ko) => {
        const s = document.createElement('script');
        s.src = '../reports/vendor/exceljs.min.js';
        s.onload = ok;
        s.onerror = () => ko(new Error('Não foi possível preparar o Excel.'));
        document.head.append(s);
      });
    }
    const wb = new window.ExcelJS.Workbook();
    const ws = wb.addWorksheet('Percurso');
    ws.addRow(['Rota', nomeRota($('rtRota').value)]);
    ws.addRow(['Dia', dl(P.dia)]);
    ws.addRow(['Distância prevista (km)', x.km]);
    ws.addRow(['Saída e regresso', x.saida + ' – ' + x.regresso]);
    ws.addRow([]);
    const cab = ws.addRow(['Ordem', 'Código', 'Cliente', 'Chegada prevista', 'Km desde a partida', 'Latitude', 'Longitude']);
    cab.font = { bold: true, color: { argb: 'FFFFFFFF' } };
    cab.eachCell((c) => (c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF0A2A5C' } }));
    x.paragens.forEach((p) => ws.addRow([p.ordem, p.c, p.n, p.chegada, p.km, p.lat, p.lng]));
    x.sem_coordenadas.forEach((s) => ws.addRow(['—', s.c, s.n, 'sem coordenadas', '', '', '']));
    ws.columns.forEach((c, i) => (c.width = [8, 14, 40, 16, 18, 12, 12][i]));
    const buf = await wb.xlsx.writeBuffer();
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }));
    a.download = 'percurso_rota_' + $('rtRota').value + '_' + P.dia + '.xlsx';
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 2000);
  }

  window.initMap = function () {
    mapa = new google.maps.Map($('map'), {
      center: { lat: -8.84, lng: 13.29 },
      zoom: 11,
      mapTypeControl: false,
      streetViewControl: false,
      clickableIcons: false,
    });
    desenharMapa();
  };
  document.addEventListener('DOMContentLoaded', () => {
    $('rtDia').addEventListener('change', () => carregar($('rtDia').value).catch((e) => $('rtLista').replaceChildren(el('p', 'pp-nota', e.message))));
    $('rtRota').addEventListener('change', desenhar);
    $('rtExportar').addEventListener('click', () =>
      exportar().catch((e) => {
        $('rtAviso').hidden = false;
        $('rtAviso').textContent = e.message;
      }),
    );
    carregar().catch((e) => $('rtLista').replaceChildren(el('p', 'pp-nota', e.message)));
  });
})();
