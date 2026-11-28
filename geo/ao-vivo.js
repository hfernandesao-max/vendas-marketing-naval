// GeoMobilidade › Mapa de clientes ao vivo: um ponto por cliente com o estado do dia (com venda, com encomenda, em
// curso, recusa, visitado sem venda), das visitas da aplicação móvel e das vendas do Pinguim BO (v1/clientes-ao-vivo).
// Actualiza de 30 em 30 s com a versão (só recebe dados quando mudaram); os clientes que mudaram ficam com um halo.
// A cor nunca vem sozinha: forma própria em cada estado (anel no «em curso»), legenda com nomes e contagens, e detalhe.
/* global google */
(function () {
  'use strict';
  const API = '../api/index.php/v1/';
  const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const CICLO = 30000;
  // estado → [nome, cor, forma]
  const ESTADOS = {
    1: ['Com venda', '#1e8e4e', 'circulo'],
    3: ['Com encomenda', '#1a73c9', 'quadrado'],
    2: ['Em curso', '#d99a00', 'anel'],
    0: ['Recusa', '#c0262d', 'losango'],
    4: ['Visitado sem venda', '#6b7a90', 'circulo'],
  };
  const ORDEM = [1, 3, 2, 0, 4];
  const PRESENCA = { p: 'Presencial', r: 'Remota', g: 'Sem GPS' };
  let D = null;
  let token = null;
  let mapa = null;
  let info = null;
  let marcas = new Map();
  let anteriores = null; // chave → assinatura do ponto na leitura anterior (para o halo)
  const novos = new Map(); // chave → até quando fica o halo
  const visiveis = new Set(ORDEM);
  let enquadrado = false;
  let temporizador = null;
  const $ = (id) => document.getElementById(id);
  const el = (tg, cls, txt) => {
    const e = document.createElement(tg);
    if (cls) e.className = cls;
    if (txt != null) e.textContent = txt;
    return e;
  };
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
  const kz = (v) => Number(v).toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' Kz';
  const num = (v) => Number(v).toLocaleString('pt-PT', { maximumFractionDigits: 1 });
  const hojeLocal = () => {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  };

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

  const rotaSel = () => $('avRota').value;
  const procura = () => $('avProcura').value.trim().toLowerCase();
  const doFiltro = (p) =>
    (!rotaSel() || p.r === rotaSel()) && visiveis.has(p.e) && (!procura() || (p.n + ' ' + p.c + ' ' + p.k).toLowerCase().includes(procura()));
  const assinatura = (p) => [p.e, p.vis, p.h, p.vv, p.lat, p.lng].join('|');

  function legenda() {
    const box = $('avLegenda');
    box.replaceChildren();
    const pts = D.pontos.filter((p) => !rotaSel() || p.r === rotaSel());
    ORDEM.forEach((e) => {
      const [nome, cor, forma] = ESTADOS[e];
      const b = el('button');
      b.type = 'button';
      b.setAttribute('aria-pressed', visiveis.has(e) ? 'true' : 'false');
      const sw = el('span', 'av-sw' + (forma === 'anel' ? ' anel' : forma === 'quadrado' ? ' quad' : forma === 'losango' ? ' losango' : ''));
      sw.style.background = cor;
      sw.style.borderColor = cor;
      b.append(sw, el('span', null, nome), el('b', null, String(pts.filter((p) => p.e === e).length)));
      b.title = visiveis.has(e) ? 'Esconder «' + nome + '»' : 'Mostrar «' + nome + '»';
      b.addEventListener('click', () => {
        if (visiveis.has(e)) visiveis.delete(e);
        else visiveis.add(e);
        desenhar();
      });
      box.append(b);
    });
  }

  function indicadores() {
    const box = $('avIndicadores');
    box.replaceChildren();
    const pts = D.pontos.filter((p) => !rotaSel() || p.r === rotaSel());
    const soma = (k) => pts.reduce((s, p) => s + (p[k] || 0), 0);
    const linhas = [
      ['Clientes no mapa', String(pts.filter((p) => p.lat != null).length) + ' de ' + pts.length],
      ['Visitas registadas', String(soma('vis'))],
    ];
    if (D.ve_valores) linhas.push(['Vendido (c/ IVA)', kz(soma('vv'))]);
    if (D.ve_valores && soma('ve')) linhas.push(['Encomendado', kz(soma('ve'))]);
    linhas.push(['Caixas vendidas (aplicação)', num(soma('cx'))]);
    linhas.push(['Visitas remotas (≥ ' + (D.raio_m || 50) + ' m do cliente)', String(pts.filter((p) => p.p === 'r').length)]);
    const sv = pts.filter((p) => p.sv).length;
    if (sv) linhas.push(['Vendas sem visita na aplicação', String(sv)]);
    const sg = pts.filter((p) => p.lat == null).length;
    if (sg) linhas.push(['Sem GPS (não desenhados)', String(sg)]);
    const t = D.totais || {};
    if (!rotaSel() && t.sem_pingwin) linhas.push(['Clientes da aplicação sem ligação ao Pinguim BO', String(t.sem_pingwin)]);
    if (!rotaSel() && t.eventuais) linhas.push(['Vendas a consumidor final (fora do mapa)', String(t.eventuais)]);
    linhas.forEach(([l, v]) => {
      const d = el('div', 'pp-i');
      d.append(el('span', null, l), el('b', null, v));
      box.append(d);
    });
  }

  function etiqueta(p) {
    const [nome, cor] = ESTADOS[p.e];
    const s = el('span', 'av-estado', nome);
    s.style.background = cor;
    return s;
  }

  function lista() {
    const box = $('avLista');
    box.replaceChildren();
    const pts = D.pontos.filter(doFiltro);
    $('avTituloLista').textContent = 'Clientes (' + pts.length + ')';
    if (!pts.length) box.append(el('p', 'pp-nota', D.pontos.length ? 'Nenhum cliente com estes filtros.' : 'Ainda sem visitas nem vendas neste dia.'));
    pts.slice(0, 400).forEach((p) => {
      const a = el('article', 'pp-item' + (novos.has(p.k) ? ' av-novo' : ''));
      const h = el('div', 'pp-cab');
      h.append(el('b', null, (p.h ? p.h + ' · ' : '') + p.n), etiqueta(p));
      const det = [
        p.r ? 'Rota ' + p.r : '',
        p.c ? 'Cód. ' + p.c : '',
        p.vis ? p.vis + (p.vis === 1 ? ' visita' : ' visitas') : 'Sem visita na aplicação',
        D.ve_valores && p.vv ? kz(p.vv) : '',
        p.e === 0 && p.m ? p.m : '',
        p.lat == null ? 'sem GPS' : '',
      ].filter(Boolean);
      a.append(h, el('p', 'pp-nota', det.join(' · ')));
      a.addEventListener('click', () => ver(p));
      box.append(a);
    });
    if (pts.length > 400) box.append(el('p', 'pp-nota', 'Mostram-se os 400 mais recentes. Use a procura ou a rota para ver os outros.'));
  }

  function simbolo(p) {
    const [, cor, forma] = ESTADOS[p.e];
    const halo = novos.has(p.k);
    const base = { fillColor: cor, fillOpacity: 1, strokeColor: halo ? '#f5b547' : '#ffffff', strokeWeight: halo ? 3 : 1.5, scale: 1 };
    if (forma === 'anel')
      return { ...base, path: google.maps.SymbolPath.CIRCLE, scale: 7, fillOpacity: 0.15, strokeColor: halo ? '#f5b547' : cor, strokeWeight: 4 };
    if (forma === 'quadrado') return { ...base, path: 'M -6,-6 6,-6 6,6 -6,6 z' };
    if (forma === 'losango') return { ...base, path: 'M 0,-8 8,0 0,8 -8,0 z' };
    return { ...base, path: google.maps.SymbolPath.CIRCLE, scale: p.e === 4 ? 5 : 7 };
  }

  function detalhe(p) {
    const [nome] = ESTADOS[p.e];
    const l = [`<b>${esc(p.n)}</b>`, esc([p.c ? 'Cód. ' + p.c : '', p.r ? 'Rota ' + p.r : ''].filter(Boolean).join(' · ')), `<b>${esc(nome)}</b>`];
    l.push(p.vis ? `${p.vis} ${p.vis === 1 ? 'visita' : 'visitas'}${p.h ? ' · última às ' + esc(p.h) : ''}` : 'Venda no Pinguim BO sem visita na aplicação');
    if (D.ve_valores && p.vv) l.push('Vendido: <b>' + esc(kz(p.vv)) + '</b>' + (p.pend ? ' (documento ainda por ler do Pinguim BO)' : ''));
    if (D.ve_valores && p.ve) l.push('Encomendado: ' + esc(kz(p.ve)));
    if (p.cx) l.push('Caixas: ' + esc(num(p.cx)));
    if (p.e === 0 && p.m) l.push('Motivo: ' + esc(p.m));
    if (p.vis) l.push(esc(PRESENCA[p.p] || '') + (p.dm != null ? ' · ' + p.dm + ' m do cliente' : ''));
    l.push(p.og === 'v' ? 'Posição: GPS da visita' : 'Posição: GPS do cliente');
    if (p.lat != null)
      l.push(`<a href="https://www.google.com/maps/search/?api=1&query=${p.lat},${p.lng}" target="_blank" rel="noopener noreferrer">Abrir no Google Maps</a>`);
    return l.join('<br>');
  }

  function ver(p) {
    if (!mapa || p.lat == null) return;
    mapa.panTo({ lat: p.lat, lng: p.lng });
    if (mapa.getZoom() < 16) mapa.setZoom(17);
    if (!info) info = new google.maps.InfoWindow();
    info.setContent(detalhe(p));
    info.setPosition({ lat: p.lat, lng: p.lng });
    info.open(mapa);
  }

  function desenharMapa() {
    if (!mapa || !D) return;
    const ficam = new Set();
    const lim = new google.maps.LatLngBounds();
    D.pontos.filter(doFiltro).forEach((p) => {
      if (p.lat == null) return;
      ficam.add(p.k);
      const pos = { lat: p.lat, lng: p.lng };
      lim.extend(pos);
      let m = marcas.get(p.k);
      const titulo = p.n + ' · ' + ESTADOS[p.e][0];
      if (!m) {
        m = new google.maps.Marker({ position: pos, map: mapa, title: titulo, icon: simbolo(p), zIndex: p.e === 2 ? 30 : p.e === 1 ? 20 : 10 });
        marcas.set(p.k, m);
      } else {
        m.setPosition(pos);
        m.setIcon(simbolo(p));
        m.setTitle(titulo);
        if (!m.getMap()) m.setMap(mapa);
      }
      google.maps.event.clearListeners(m, 'click');
      m.addListener('click', () => ver(p));
    });
    marcas.forEach((m, k) => {
      if (!ficam.has(k)) m.setMap(null);
    });
    if (!enquadrado && !lim.isEmpty()) {
      mapa.fitBounds(lim);
      enquadrado = true;
    }
  }

  function desenhar() {
    if (!D) return;
    legenda();
    indicadores();
    lista();
    desenharMapa();
  }

  function marcarNovos() {
    const agora = Date.now();
    const sig = new Map(D.pontos.map((p) => [p.k, assinatura(p)]));
    if (anteriores) sig.forEach((s, k) => anteriores.get(k) !== s && novos.set(k, agora + 90000));
    novos.forEach((ate, k) => ate < agora && novos.delete(k));
    anteriores = sig;
  }

  function rotulo() {
    const hoje = $('avDia').value === hojeLocal();
    const t = D.atualizado_em ? D.atualizado_em.slice(11, 19) : '—';
    $('avEstado').textContent = hoje
      ? 'Ao vivo · dados de ' + t + ' · a página volta a pedir de 30 em 30 s'
      : 'Dia ' + $('avDia').value + ' · como ficou às ' + t;
  }

  async function carregar(manter) {
    const dia = $('avDia').value;
    const d = await api('clientes-ao-vivo?dia=' + encodeURIComponent(dia) + (manter && D ? '&versao=' + encodeURIComponent(D.versao || '') : ''));
    if (!d.ok) {
      $('avAviso').hidden = false;
      $('avAviso').textContent = d.erro || 'Não foi possível ler os dados.';
      return;
    }
    if (d.inalterado) {
      if (D && d.atualizado_em) D.atualizado_em = d.atualizado_em;
      novos.forEach((ate, k) => ate < Date.now() && novos.delete(k));
      if (D) {
        rotulo();
        desenharMapa();
      }
      return;
    }
    D = d;
    if (!manter) {
      anteriores = null;
      enquadrado = false;
      novos.clear();
    }
    marcarNovos();
    const av = $('avAviso');
    av.hidden = !D.erro;
    av.textContent = D.erro || '';
    const sel = $('avRota');
    const antes = sel.value;
    sel.replaceChildren(new Option('Todas as rotas', ''));
    (D.rotas || []).forEach((r) => sel.append(new Option('Rota ' + r, r)));
    if ([...sel.options].some((o) => o.value === antes)) sel.value = antes;
    $('avFonte').textContent =
      (D.configurado
        ? 'Visitas: aplicação móvel (tempo real). Tipo de cada venda e vendas sem visita: Pinguim BO. Presencial: visita a menos de ' +
          (D.raio_m || 50) +
          ' m do cliente.'
        : 'A ligação à aplicação móvel ainda não está configurada: o mapa mostra só o que já ficou guardado.') +
      ' Dado pessoal dos vendedores: só para acompanhamento das rotas (Lei 22/11).';
    rotulo();
    desenhar();
  }

  function ciclo() {
    clearTimeout(temporizador);
    temporizador = setTimeout(async () => {
      if (!document.hidden && $('avDia').value === hojeLocal()) await carregar(true).catch(() => {});
      ciclo();
    }, CICLO);
  }

  window.initMap = function () {
    mapa = new google.maps.Map($('map'), {
      center: { lat: -8.84, lng: 13.29 },
      zoom: 11,
      maxZoom: 21,
      gestureHandling: 'greedy',
      mapTypeControl: true,
      mapTypeControlOptions: { mapTypeIds: ['roadmap', 'hybrid'], position: google.maps.ControlPosition.TOP_LEFT },
      streetViewControl: false,
      clickableIcons: false,
    });
    if (D) desenharMapa();
  };
  document.addEventListener('DOMContentLoaded', () => {
    const hoje = hojeLocal();
    const pedido = new URLSearchParams(location.search);
    const dia = pedido.get('data') || '';
    $('avDia').value = /^\d{4}-\d{2}-\d{2}$/.test(dia) && dia <= hoje ? dia : hoje;
    $('avDia').max = hoje;
    const rota = /^[A-Za-z0-9_-]{1,20}$/.test(pedido.get('rota') || '') ? pedido.get('rota') : '';
    if (rota) $('avRota').append(new Option('Rota ' + rota, rota, true, true));
    $('avDia').addEventListener('change', () => carregar(false).catch((e) => ($('avLista').textContent = e.message)));
    $('avRota').addEventListener('change', () => {
      enquadrado = false;
      desenhar();
    });
    $('avProcura').addEventListener('input', () => {
      lista();
      desenharMapa();
    });
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden && D && $('avDia').value === hojeLocal()) carregar(true).catch(() => {});
    });
    carregar(false)
      .catch((e) => $('avLista').replaceChildren(el('p', 'pp-nota', e.message)))
      .finally(ciclo);
  });
})();
