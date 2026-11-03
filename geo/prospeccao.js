// GeoMobilidade › Prospecção: território de cada rota, clientes e potenciais clientes (OpenStreetMap e Google
// Places, sem os que já são clientes), por rota e dia de visita, com a ordem de prioridade e o caminho até lá.
// Dados: API do portal (v1/prospeccao), com o token pedido pela sessão desta página.
/* global google */
(function () {
  'use strict';
  const API = '../api/index.php/v1/';
  const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const DIAS = { 1: 'segunda', 2: 'terça', 3: 'quarta', 4: 'quinta', 5: 'sexta', 6: 'sábado', 7: 'domingo' };
  const TIPOS = {
    supermarket: 'Supermercado',
    grocery_store: 'Mercearia',
    grocery: 'Mercearia',
    convenience: 'Loja de conveniência',
    convenience_store: 'Loja de conveniência',
    general: 'Loja de bairro',
    variety_store: 'Loja de variedades',
    wholesale: 'Armazém grossista',
    market: 'Mercado',
    marketplace: 'Mercado',
    kiosk: 'Quiosque',
    chemist: 'Drogaria',
    cosmetics: 'Cosmética',
    pharmacy: 'Farmácia',
    drugstore: 'Drogaria',
    beverages: 'Bebidas',
    liquor_store: 'Bebidas',
    alcohol: 'Bebidas',
    bakery: 'Padaria',
    deli: 'Charcutaria',
    houseware: 'Utilidades domésticas',
    hardware: 'Ferragens',
    greengrocer: 'Frutaria',
    butcher: 'Talho',
    frozen_food: 'Congelados',
    bar: 'Bar',
    cafe: 'Café',
    restaurant: 'Restaurante',
    fast_food: 'Comida rápida',
  };
  // azuis Naval por rota (sem verde); potenciais em âmbar; zonas sem rota em vermelho
  const CORES = ['#0057b8', '#1a73c9', '#4f8fd6', '#0a2a52', '#2b6cb0', '#6aa6e8', '#123f7a', '#3d7fd0', '#8bb8ef', '#1d4f91', '#5b95db'];
  let V = null;
  let token = null;
  let mapa = null;
  let camadas = [];
  let info = null;
  let vista = 'potenciais'; // potenciais | esquecidos | coordenadas | funil
  // dias sem compras: âmbar, laranja e vermelho, sempre com o texto do escalão (nunca só a cor)
  const FAIXAS = { 14: ['14 a 29 dias', '#f5b547'], 30: ['30 a 59 dias', '#e0661c'], 60: ['60 dias ou mais', '#c0262d'] };
  const kz = (v) => Math.round(v || 0).toLocaleString('pt-PT') + ' Kz';
  const dl = (d) => (d ? d.slice(8, 10) + '-' + d.slice(5, 7) + '-' + d.slice(0, 4) : '—');
  const daRota = (x) => {
    const r = rotaEscolhida();
    return r === '' || (r === '_sem' ? !x.r : x.r === r);
  };
  const $ = (id) => document.getElementById(id);
  const el = (tg, cls, txt) => {
    const e = document.createElement(tg);
    if (cls) e.className = cls;
    if (txt != null) e.textContent = txt;
    return e;
  };
  const pct = (v) => Math.round(v * 100) + ' %';

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
  async function api(rota, init = {}) {
    if (!token) await pedirToken();
    const ir = () => fetch(API + rota, { ...init, credentials: 'same-origin', headers: { ...(init.headers || {}), Authorization: 'Bearer ' + token } });
    let r = await ir();
    if (r.status === 401) {
      await pedirToken();
      r = await ir();
    }
    return r.json().catch(() => ({ ok: false, erro: 'Resposta inválida do servidor.' }));
  }

  function diaEscolhido() {
    const v = $('ppDia').value;
    if (v === 'hoje') return new Date().getDay() || 7;
    return v === 'todos' ? null : +v;
  }
  function rotaEscolhida() {
    return $('ppRota').value;
  }
  function filtrados() {
    const r = rotaEscolhida();
    const d = diaEscolhido();
    return V.potenciais.filter(
      (p) => (r === '' ? true : r === '_sem' ? p.r === '' : p.r === r) && (d == null || p.d === d || p.r === '') && p.e !== 'nao_interessado',
    );
  }

  function indicadores() {
    const box = $('ppIndicadores');
    box.replaceChildren();
    const av = $('ppAviso');
    av.hidden = true;
    const r = rotaEscolhida();
    const rs = r === '' || r === '_sem' ? V.rotas : V.rotas.filter((x) => x.rota === r);
    const soma = (k) => rs.reduce((s, x) => s + x[k], 0);
    const cap = soma('capacidade');
    const linhas = [
      ['Clientes', soma('clientes').toLocaleString('pt-PT')],
      ['Potenciais clientes no território', soma('potenciais').toLocaleString('pt-PT')],
      ['Cobertura (clientes ÷ estabelecimentos)', pct(soma('clientes') / Math.max(1, soma('clientes') + soma('potenciais')))],
      ['Ocupação da rota (visitas por semana)', cap ? pct(soma('visitas_semana') / cap) + ' de ' + cap : '—'],
      ['Convertidos nos últimos 30 dias', soma('convertidos_30d').toLocaleString('pt-PT')],
    ];
    if (r === '' && V.zonas_sem_rota.length) linhas.push(['Zonas sem rota com potencial', V.zonas_sem_rota.length.toLocaleString('pt-PT')]);
    linhas.forEach(([l, v]) => {
      const d = el('div', 'pp-i');
      d.append(el('span', null, l), el('b', null, v));
      box.append(d);
    });
    const cheias = rs.filter((x) => x.cheia);
    if (cheias.length) {
      av.hidden = false;
      av.textContent =
        cheias.length === 1
          ? `Rota ${cheias[0].rota} cheia (${pct(cheias[0].ocupacao)} da capacidade): é preciso uma nova rota (vendedor + viatura). A proposta de divisão está no mapa.`
          : `${cheias.length} rotas cheias: é preciso criar novas rotas (vendedor + viatura).`;
    }
  }

  // Clientes regulares que deixaram de comprar: os mais valiosos primeiro (recuperar custa menos do que conquistar)
  function listaEsquecidos() {
    const box = $('ppLista');
    box.replaceChildren();
    const xs = ((V.carteira || {}).esquecidos || []).filter(daRota);
    $('ppTituloLista').textContent = `Clientes regulares sem compras (${xs.length})`;
    box.append(el('p', 'pp-nota', 'Compravam em 3 ou mais dias nos últimos 4 meses e não compram há 14 dias ou mais. Os de maior valor primeiro.'));
    if (!xs.length) box.append(el('p', 'pp-nota', 'Nenhum cliente regular parado nesta escolha.'));
    xs.slice(0, 120).forEach((x) => {
      const c = el('article', 'pp-item pp-f' + x.faixa);
      const h = el('div', 'pp-cab');
      h.append(el('b', null, x.n || x.c), el('span', 'pp-faixa f' + x.faixa, x.dias + ' dias'));
      const det = ['Rota ' + (x.r || '—'), 'última compra ' + dl(x.ult), x.compras + ' compras', kz(x.media) + ' por compra'];
      c.append(h, el('p', 'pp-nota', det.join(' · ')));
      if (x.lat != null) {
        const acc = el('div', 'pp-acc');
        const ir = el('a', 'pp-ir', 'Ir');
        ir.href = `https://www.google.com/maps/dir/?api=1&destination=${x.lat},${x.lng}`;
        ir.target = '_blank';
        ir.rel = 'noopener noreferrer';
        acc.append(ir);
        c.append(acc);
        c.addEventListener('click', (e) => {
          if (mapa && e.target === c) mapa.panTo({ lat: x.lat, lng: x.lng });
        });
      } else c.append(el('p', 'pp-nota', 'Sem coordenadas no Pinguim BO: ver «Coordenadas».'));
      box.append(c);
    });
  }
  // Qualidade das coordenadas dos clientes (corrigir no Pinguim BO, no próprio cliente)
  function listaCoordenadas() {
    const box = $('ppLista');
    box.replaceChildren();
    const k = V.carteira || {};
    const sem = (k.sem_gps || []).filter(daRota);
    const dup = (k.duplicados || []).filter(daRota);
    $('ppTituloLista').textContent = `Coordenadas a corrigir (${sem.length + dup.length})`;
    box.append(
      el(
        'p',
        'pp-nota',
        'Corrigir no Pinguim BO, de preferência com o telemóvel junto ao cliente. Sem coordenadas certas, o cliente fica fora do território, da rota do dia e da prospecção.',
      ),
    );
    box.append(el('h3', 'pp-sub', `Clientes com compras sem coordenadas válidas (${sem.length})`));
    if (!sem.length) box.append(el('p', 'pp-nota', 'Nenhum.'));
    sem.slice(0, 100).forEach((x) => {
      const c = el('article', 'pp-item pp-sem');
      c.append(
        el('b', null, x.n || x.c),
        el('p', 'pp-nota', ['Código ' + x.c, 'Rota ' + (x.r || '—'), x.compras + ' compras', 'última ' + dl(x.ult)].join(' · ')),
      );
      box.append(c);
    });
    box.append(el('h3', 'pp-sub', `Clientes diferentes no mesmo ponto (${dup.length})`));
    if (!dup.length) box.append(el('p', 'pp-nota', 'Nenhum.'));
    dup.slice(0, 60).forEach((g) => {
      const c = el('article', 'pp-item pp-sem');
      c.append(el('b', null, g.clientes.length + ' clientes no mesmo ponto'), el('p', 'pp-nota', g.clientes.map((x) => x.n + ' (' + x.c + ')').join(' · ')));
      c.addEventListener('click', () => mapa && mapa.panTo({ lat: g.lat, lng: g.lng }));
      box.append(c);
    });
  }
  // Funil da prospecção por rota: por visitar → visitado → interessado → cliente
  function listaFunil() {
    const box = $('ppLista');
    box.replaceChildren();
    const F = V.funil || {};
    const r = rotaEscolhida();
    const rotas = Object.keys(F).filter((k) => (r === '' ? true : r === '_sem' ? k === '' : k === r));
    $('ppTituloLista').textContent = 'Resultados da prospecção';
    box.append(
      el(
        'p',
        'pp-nota',
        'Estado de cada potencial cliente registado pelos vendedores; clientes conquistados nos últimos 90 dias. Taxa de conversão = clientes ÷ estabelecimentos já trabalhados.',
      ),
    );
    if (!rotas.length) {
      box.append(el('p', 'pp-nota', 'Ainda sem resultados.'));
      return;
    }
    const t = el('table', 'pp-funil');
    const cab = el('tr');
    ['Rota', 'Por visitar', 'Visitados', 'Interessados', 'Clientes', 'Taxa'].forEach((x, i) => cab.append(el('th', i ? 'n' : null, x)));
    t.append(cab);
    const tot = { por_visitar: 0, visitado: 0, interessado: 0, nao_interessado: 0, outro_fornecedor: 0, convertidos: 0 };
    rotas.forEach((k) => {
      const x = F[k];
      Object.keys(tot).forEach((c) => (tot[c] += x[c] || 0));
      const tr = el('tr');
      tr.append(
        el('td', null, k || 'Fora das rotas'),
        el('td', 'n', String(x.por_visitar)),
        el('td', 'n', String(x.visitado + x.nao_interessado + x.outro_fornecedor)),
        el('td', 'n', String(x.interessado)),
        el('td', 'n', String(x.convertidos)),
        el('td', 'n', x.taxa == null ? '—' : pct(x.taxa)),
      );
      t.append(tr);
    });
    if (rotas.length > 1) {
      const trab = tot.visitado + tot.interessado + tot.nao_interessado + tot.outro_fornecedor + tot.convertidos;
      const tr = el('tr', 'pp-total');
      tr.append(
        el('td', null, 'Total'),
        el('td', 'n', String(tot.por_visitar)),
        el('td', 'n', String(tot.visitado + tot.nao_interessado + tot.outro_fornecedor)),
        el('td', 'n', String(tot.interessado)),
        el('td', 'n', String(tot.convertidos)),
        el('td', 'n', trab ? pct(tot.convertidos / trab) : '—'),
      );
      t.append(tr);
    }
    const w = el('div', 'pp-funil-w');
    w.append(t);
    box.append(w);
  }

  function lista() {
    if (vista === 'esquecidos') return listaEsquecidos();
    if (vista === 'coordenadas') return listaCoordenadas();
    if (vista === 'funil') return listaFunil();
    const box = $('ppLista');
    box.replaceChildren();
    const ps = filtrados();
    const d = diaEscolhido();
    $('ppTituloLista').textContent = `Potenciais clientes${d ? ' · ' + DIAS[d] : ''} (${ps.length})`;
    if (!ps.length) {
      box.append(el('p', 'pp-nota', 'Sem potenciais clientes para esta escolha.'));
      return;
    }
    ps.slice(0, 80).forEach((p) => {
      const c = el('article', 'pp-item' + (p.e ? ' pp-' + p.e : ''));
      c.dataset.id = p.id;
      const h = el('div', 'pp-cab');
      h.append(el('b', null, p.n || 'Estabelecimento sem nome'), el('span', 'pp-pont', String(p.s)));
      const det = [TIPOS[p.t] || p.t || 'Comércio'];
      if (p.dc != null) det.push(`a ${p.dc} m de um cliente`);
      if (p.r === '') det.push('fora das rotas');
      else if (p.d) det.push('rota ' + p.r + ' · ' + DIAS[p.d]);
      det.push(p.f.length > 1 ? 'confirmado em 2 fontes' : p.f[0] === 'google' ? 'Google' : 'OpenStreetMap');
      const acc = el('div', 'pp-acc');
      const ir = el('a', 'pp-ir', 'Ir');
      ir.href = `https://www.google.com/maps/dir/?api=1&destination=${p.lat},${p.lng}`;
      ir.target = '_blank';
      ir.rel = 'noopener noreferrer';
      const sel = el('select', 'premium-select');
      sel.setAttribute('aria-label', 'Resultado da visita a ' + (p.n || 'estabelecimento'));
      sel.append(new Option('Resultado da visita…', ''));
      Object.entries(V.estados).forEach(([k, v]) => sel.append(new Option(v, k, false, p.e === k)));
      sel.addEventListener('change', async () => {
        const r = await api('prospeccao', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ accao: 'marcar', id: p.id, estado: sel.value, csrf }),
        });
        if (!r.ok) {
          alerta(r.erro || 'Não foi possível gravar.');
          return;
        }
        p.e = sel.value;
        if (p.e === 'nao_existe') V.potenciais = V.potenciais.filter((x) => x.id !== p.id);
        desenhar();
      });
      acc.append(ir, sel);
      c.append(h, el('p', 'pp-nota', det.join(' · ')), acc);
      c.addEventListener('click', (e) => {
        if (mapa && e.target === c) mapa.panTo({ lat: p.lat, lng: p.lng });
      });
      box.append(c);
    });
  }
  function alerta(t) {
    const av = $('ppAviso');
    av.hidden = false;
    av.textContent = t;
  }

  function limparMapa() {
    camadas.forEach((x) => x.setMap(null));
    camadas = [];
  }
  function desenharMapa() {
    if (!mapa) return;
    limparMapa();
    const r = rotaEscolhida();
    const limites = new google.maps.LatLngBounds();
    V.rotas.forEach((x, i) => {
      if (r !== '' && r !== '_sem' && x.rota !== r) return;
      if (x.territorio.length >= 3) {
        const pol = new google.maps.Polygon({
          paths: x.territorio.map(([lat, lng]) => ({ lat, lng })),
          strokeColor: CORES[i % CORES.length],
          strokeWeight: 2,
          fillColor: CORES[i % CORES.length],
          fillOpacity: 0.08,
          map: mapa,
        });
        pol.addListener('click', (e) =>
          abrir(e.latLng, `<b>Rota ${x.rota}</b> · ${x.promotor}<br>${x.clientes} clientes · ${x.potenciais} potenciais · ocupação ${pct(x.ocupacao)}`),
        );
        camadas.push(pol);
        x.territorio.forEach(([lat, lng]) => limites.extend({ lat, lng }));
      }
      (x.divisao || []).forEach((dv, k) => {
        if (dv.territorio.length < 3) return;
        camadas.push(
          new google.maps.Polygon({
            paths: dv.territorio.map(([lat, lng]) => ({ lat, lng })),
            strokeColor: k ? '#c0262d' : '#a15c07',
            strokeWeight: 2,
            strokeOpacity: 0.9,
            fillOpacity: 0,
            map: mapa,
          }),
        );
      });
    });
    V.clientes.forEach((c) => {
      if (r !== '' && r !== '_sem' && c.r !== r) return;
      camadas.push(
        new google.maps.Marker({
          position: { lat: c.lat, lng: c.lng },
          map: mapa,
          title: c.n,
          icon: { path: google.maps.SymbolPath.CIRCLE, scale: 3, fillColor: '#1a73c9', fillOpacity: 0.9, strokeWeight: 0 },
        }),
      );
    });
    if (vista === 'esquecidos')
      ((V.carteira || {}).esquecidos || [])
        .filter((x) => daRota(x) && x.lat != null)
        .forEach((x) => {
          const [txt, cor] = FAIXAS[x.faixa];
          const m = new google.maps.Marker({
            position: { lat: x.lat, lng: x.lng },
            map: mapa,
            title: x.n,
            icon: { path: google.maps.SymbolPath.CIRCLE, scale: 7, fillColor: cor, fillOpacity: 0.95, strokeColor: '#1c2340', strokeWeight: 1 },
          });
          m.addListener('click', () =>
            abrir(m.getPosition(), `<b>${String(x.n || x.c).replace(/</g, '&lt;')}</b><br>Sem compras: ${txt} (${x.dias} dias) · ${kz(x.media)} por compra`),
          );
          camadas.push(m);
          limites.extend({ lat: x.lat, lng: x.lng });
        });
    if (vista === 'coordenadas')
      ((V.carteira || {}).duplicados || []).filter(daRota).forEach((g) => {
        const m = new google.maps.Marker({
          position: { lat: g.lat, lng: g.lng },
          map: mapa,
          title: g.clientes.length + ' clientes no mesmo ponto',
          icon: { path: google.maps.SymbolPath.CIRCLE, scale: 8, fillColor: '#c0262d', fillOpacity: 0.9, strokeColor: '#fff', strokeWeight: 1 },
        });
        m.addListener('click', () =>
          abrir(
            m.getPosition(),
            '<b>Clientes no mesmo ponto</b><br>' + g.clientes.map((x) => String(x.n).replace(/</g, '&lt;') + ' (' + x.c + ')').join('<br>'),
          ),
        );
        camadas.push(m);
        limites.extend({ lat: g.lat, lng: g.lng });
      });
    (vista === 'potenciais' ? filtrados() : []).forEach((p) => {
      const m = new google.maps.Marker({
        position: { lat: p.lat, lng: p.lng },
        map: mapa,
        title: p.n || 'Estabelecimento sem nome',
        icon: { path: google.maps.SymbolPath.CIRCLE, scale: 4 + p.s / 20, fillColor: '#f5b547', fillOpacity: 0.95, strokeColor: '#7a4a00', strokeWeight: 1 },
      });
      m.addListener('click', () =>
        abrir(m.getPosition(), `<b>${(p.n || 'Estabelecimento sem nome').replace(/</g, '&lt;')}</b><br>${TIPOS[p.t] || p.t || ''} · prioridade ${p.s}`),
      );
      camadas.push(m);
      limites.extend({ lat: p.lat, lng: p.lng });
    });
    if (r === '' || r === '_sem')
      V.zonas_sem_rota.forEach((z) => {
        const c = new google.maps.Circle({
          center: { lat: z.lat, lng: z.lng },
          radius: 900,
          strokeColor: '#c0262d',
          strokeWeight: 2,
          fillColor: '#c0262d',
          fillOpacity: 0.12,
          map: mapa,
        });
        c.addListener('click', () => abrir(c.getCenter(), `<b>Zona sem rota</b><br>${z.potenciais} potenciais clientes: candidata a uma rota nova`));
        camadas.push(c);
      });
    if (!limites.isEmpty()) mapa.fitBounds(limites);
  }
  function abrir(pos, html) {
    if (!info) info = new google.maps.InfoWindow();
    info.setContent(html);
    info.setPosition(pos);
    info.open(mapa);
  }

  function desenhar() {
    indicadores();
    lista();
    desenharMapa();
  }

  async function carregar(actualizar) {
    const d = await api('prospeccao' + (actualizar ? '?actualizar=1' : ''));
    if (!d.ok) {
      $('ppLista').replaceChildren(el('p', 'pp-nota', d.erro || 'Não foi possível ler os dados.'));
      return;
    }
    V = d;
    const sel = $('ppRota');
    const antes = sel.value;
    sel.replaceChildren();
    if (V.rotas.length !== 1) sel.append(new Option('Todas as rotas', ''));
    V.rotas.forEach((x) => sel.append(new Option(`${x.rota} · ${x.promotor}`, x.rota)));
    if (V.zonas_sem_rota.length || V.potenciais.some((p) => p.r === '')) sel.append(new Option('Fora das rotas', '_sem'));
    if ([...sel.options].some((o) => o.value === antes)) sel.value = antes;
    $('ppRecalcular').hidden = !V.gere;
    const f = V.fontes || {};
    $('ppFontes').textContent =
      `Clientes: Pinguim BO${V.origem_clientes ? ' (' + V.origem_clientes + ')' : ''}. Estabelecimentos: © contribuidores do OpenStreetMap (ODbL)` +
      (f.google_activo ? `, Google (${f.google_mes} de ${f.google_limite} pedidos este mês)` : '') +
      `. Calculado em ${String(V.gerado || '').slice(8, 10)}-${String(V.gerado || '').slice(5, 7)}-${String(V.gerado || '').slice(0, 4)} ${String(V.gerado || '').slice(11, 16)}.`;
    if (V.erro_clientes) alerta(V.erro_clientes);
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
    if (V) desenharMapa();
  };
  document.addEventListener('DOMContentLoaded', () => {
    $('ppRota').addEventListener('change', desenhar);
    $('ppDia').addEventListener('change', desenhar);
    $('ppRecalcular').addEventListener('click', () => carregar(true));
    document.querySelectorAll('.pp-tab').forEach((b) =>
      b.addEventListener('click', () => {
        vista = b.dataset.vista;
        document.querySelectorAll('.pp-tab').forEach((x) => {
          x.classList.toggle('on', x === b);
          x.setAttribute('aria-selected', x === b ? 'true' : 'false');
        });
        if (V) desenhar();
      }),
    );
    carregar(false).catch((e) => $('ppLista').replaceChildren(el('p', 'pp-nota', e.message)));
  });
})();
