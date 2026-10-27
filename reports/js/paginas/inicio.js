// Início: indicadores e painel de gráficos.
import { META_ROTA, isCF, pendenteDe, porPromotor, recebidoDe, temCad, temMeio, valorCat, vendasR } from '../calculos.js';
import { FITS, tiles } from '../componentes/blocos.js';
import { COR, cartaoGrafico, grafBarras, grafColunas, grafParte, kzc } from '../componentes/graficos.js';
import { diaCurto, dl, ds, kz, kz2, n0, pct } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { R } from './registo.js';
import { atual, podeVer } from '../perfil.js';
import { draw } from '../app.js';
import { FV, filtroAtivo } from '../filtros.js';
import { addD, hoje, inR, st } from '../periodo.js';
import { DB, el, group, sum } from '../util.js';

function painelInicio(pg) {
  const V = vendasR(),
    tot = sum(V, 'total'),
    liq = sum(V, recebidoDe),
    cards = [];
  const pode = (id) => podeVer(id);
  const nomeCli = (x) => (isCF(x.nif) ? t('Consumidor final') : x.cliente);
  // 1. Vendas por dia
  if (pode('vendas-dia') && V.length) {
    const dias = Object.values(group(V, 'data'))
      .sort((a, b) => (a[0].data < b[0].data ? -1 : 1))
      .map((rs) => ({ d: rs[0].data, v: sum(rs, 'total'), r: sum(rs, recebidoDe), n: rs.length }));
    cards.push(
      cartaoGrafico({
        titulo: t('Vendas por dia'),
        sub: t('{v} em {n} dias · média {m} por dia', { v: kzc(tot) + ' Kz', n: dias.length, m: kzc(tot / dias.length) + ' Kz' }),
        destino: 'vendas-dia',
        desenhar: (w, h) =>
          grafColunas(w, {
            altura: h,
            itens: dias.map((x) => ({
              valor: x.v,
              rot: diaCurto(x.d),
              titulo: ds(x.d),
              linhas: [
                [kz(x.v), t('vendido')],
                [kz(x.r), t('recebido')],
                [n0(x.n), t('documentos')],
              ],
            })),
          }),
        colunas: [[t('Dia')], [t('Vendido'), 1], [t('Recebido'), 1], [t('Documentos'), 1]],
        linhas: dias.map((x) => [dl(x.d), kz(x.v), kz(x.r), n0(x.n)]),
      }),
    );
  }
  // 2. Vendas por promotor (clicar abre os documentos da rota)
  if (pode('vendas-promotor') && V.length) {
    const P = porPromotor(V)
      .filter((x) => x.total > 0)
      .sort((a, b) => b.total - a.total);
    const top = P[0];
    cards.push(
      cartaoGrafico({
        titulo: t('Vendas por promotor'),
        sub: top ? t('Maior venda: {p} ({q} do total)', { p: top.promotor, q: pct(tot ? top.total / tot : 0) }) : '',
        destino: 'vendas-promotor',
        desenhar: (w, h) =>
          grafBarras(w, {
            altura: h,
            itens: P.map((x) => ({
              valor: x.total,
              rot: x.promotor,
              linhas: [
                [kz(x.total), t('vendido')],
                [kz(x.recebido), t('recebido')],
                [n0(x.docs), t('documentos')],
              ],
              href: podeVer('documentos') ? `documentos/dc?rota=${x.rota}` : null,
            })),
          }),
        colunas: [[t('Promotor')], [t('Vendido'), 1], [t('Recebido'), 1], [t('Documentos'), 1]],
        linhas: P.map((x) => [x.promotor, kz(x.total), kz(x.recebido), n0(x.docs)]),
      }),
    );
  }
  // 3. Como foi cobrado (parte do todo)
  if (pode('pagamentos') && V.length && temMeio()) {
    const din = valorCat(V, 'dinheiro'),
      mc = valorCat(V, 'multicaixa'),
      outros = Math.max(0, liq - din - mc),
      pend = Math.max(0, tot - liq);
    const partes = [
      { nome: t('Numerário'), valor: din, cor: COR.s1 },
      { nome: t('Multicaixa'), valor: mc, cor: COR.s2 },
      { nome: t('Outros meios'), valor: outros, cor: COR.s3 },
      { nome: t('Por receber'), valor: pend, cor: COR.neutro, href: podeVer('pendentes') ? 'pendentes' : null },
    ].filter((p) => p.valor > 0.005 || p.cor === COR.s1);
    cards.push(
      cartaoGrafico({
        titulo: t('Como foi cobrado'),
        sub: t('{p} das vendas já recebido', { p: pct(tot ? liq / tot : 0) }),
        destino: 'pagamentos',
        desenhar: (w) => grafParte(w, { partes }),
        colunas: [[t('Meio')], [t('Valor'), 1], [t('Parte'), 1]],
        linhas: partes.map((p) => [p.nome, kz(p.valor), pct(tot ? p.valor / tot : 0)]),
      }),
    );
  }
  // 4. Antiguidade da dívida (cores de risco, sempre com o rótulo)
  if (pode('pendentes')) {
    const hj = Date.parse(hoje()),
      P = V.filter((v) => pendenteDe(v) > 0.005).map((v) => ({ v: pendenteDe(v), d: Math.round((hj - Date.parse(v.data)) / 864e5) }));
    if (P.length) {
      const fx = [
        [t('0–7 dias'), (d) => d <= 7, COR.a1],
        [t('8–30 dias'), (d) => d > 7 && d <= 30, COR.a2],
        [t('Mais de 30 dias'), (d) => d > 30, COR.a3],
      ].map(([n, f, cor]) => {
        const rs = P.filter((x) => f(x.d));
        return { rot: n, valor: sum(rs, 'v'), n: rs.length, cor };
      });
      cards.push(
        cartaoGrafico({
          titulo: t('Antiguidade da dívida'),
          sub: t('{v} por receber em {n} facturas', { v: kzc(sum(P, 'v')) + ' Kz', n: n0(P.length) }),
          destino: 'pendentes',
          desenhar: (w, h) =>
            grafBarras(w, {
              altura: h,
              itens: fx.map((x) => ({
                ...x,
                linhas: [
                  [kz(x.valor), t('por receber')],
                  [n0(x.n), t('facturas')],
                ],
                href: 'pendentes/pd-d',
              })),
            }),
          colunas: [[t('Antiguidade')], [t('Por receber'), 1], [t('Facturas'), 1]],
          linhas: fx.map((x) => [x.rot, kz(x.valor), n0(x.n)]),
        }),
      );
    }
  }
  // 5. Melhores clientes
  if (pode('vendas-cliente') && V.length) {
    const C = Object.values(
      group(
        V.filter((x) => !isCF(x.nif)),
        'nif',
      ),
    )
      .map((rs) => ({ nome: nomeCli(rs[0]), v: sum(rs, 'total'), n: rs.length, nif: rs[0].nif }))
      .sort((a, b) => b.v - a.v)
      .slice(0, 8);
    if (C.length)
      cards.push(
        cartaoGrafico({
          titulo: t('Melhores clientes'),
          sub: t('Clientes com mais compras no período (sem consumidor final)'),
          destino: 'vendas-cliente',
          desenhar: (w, h) =>
            grafBarras(w, {
              altura: h,
              itens: C.map((x) => ({
                valor: x.v,
                rot: x.nome,
                linhas: [
                  [kz(x.v), t('comprado')],
                  [n0(x.n), t('documentos')],
                ],
                href: podeVer('documentos') ? 'documentos/dc?cliente=' + encodeURIComponent(x.nif || x.nome) : null,
              })),
            }),
          colunas: [[t('Cliente')], [t('Comprado'), 1], [t('Documentos'), 1]],
          linhas: C.map((x) => [x.nome, kz(x.v), n0(x.n)]),
        }),
      );
  }
  // 6. Artigos mais vendidos (valor; quantidades para quem não vê valores)
  if (pode('vendas-artigo') && (DB.artigos || []).length) {
    const A = (DB.artigos || []).filter((x) => inR(x.data)),
      comValor = A.some((x) => x.total != null);
    const rows = Object.values(group(A, (x) => x.codigo + '|' + x.artigo))
      .map((ls) => ({ nome: ls[0].artigo, cod: ls[0].codigo, q: sum(ls, 'quantidade'), v: sum(ls, 'total') }))
      .sort((a, b) => (comValor ? b.v - a.v : b.q - a.q))
      .slice(0, 8);
    if (rows.length)
      cards.push(
        cartaoGrafico({
          titulo: t('Artigos mais vendidos'),
          sub: comValor ? t('Por valor vendido, com IVA') : t('Por quantidade vendida'),
          destino: 'vendas-artigo',
          desenhar: (w, h) =>
            grafBarras(w, {
              altura: h,
              fmt: comValor ? kzc : n0,
              itens: rows.map((x) => ({
                valor: comValor ? x.v : x.q,
                rot: x.nome,
                linhas: [
                  [comValor ? kz(x.v) : n0(x.q), comValor ? t('vendido') : t('quantidade')],
                  [n0(x.q), t('quantidade')],
                  [x.cod, t('código')],
                ],
              })),
            }),
          colunas: [[t('Artigo')], [t('Código')], [t('Quantidade'), 1], ...(comValor ? [[t('Vendido'), 1]] : [])],
          linhas: rows.map((x) => [x.nome, x.cod, n0(x.q), ...(comValor ? [kz(x.v)] : [])]),
        }),
      );
  }
  // 7. Caixas carregadas por dia (plano de cargas)
  if (pode('registo-cargas') && (DB.cargas || []).some((x) => inR(x.data))) {
    const dias = Object.values(
      group(
        DB.cargas.filter((x) => inR(x.data)),
        'data',
      ),
    )
      .sort((a, b) => (a[0].data < b[0].data ? -1 : 1))
      .map((rs) => ({ d: rs[0].data, c: sum(rs, 'carga'), n: rs.length }));
    cards.push(
      cartaoGrafico({
        titulo: t('Caixas carregadas por dia'),
        sub: t('{c} caixas em {n} dias, segundo o plano de cargas', { c: n0(sum(dias, 'c')), n: dias.length }),
        destino: 'registo-cargas',
        desenhar: (w, h) =>
          grafColunas(w, {
            altura: h,
            fmt: n0,
            itens: dias.map((x) => ({
              valor: x.c,
              rot: diaCurto(x.d),
              titulo: ds(x.d),
              linhas: [
                [n0(x.c), t('caixas')],
                [n0(x.n), t('carrinhas')],
              ],
            })),
          }),
        colunas: [[t('Dia')], [t('Caixas'), 1], [t('Carrinhas'), 1]],
        linhas: dias.map((x) => [dl(x.d), n0(x.c), n0(x.n)]),
      }),
    );
  }
  // 8. Novos clientes por dia (cadastros) com a meta da rede
  if (pode('cadastros-analise') && temCad()) {
    const D = Object.values(
      group(
        (DB.cad.cadastros_diarios || []).filter((x) => inR(x.dia)),
        'dia',
      ),
    )
      .sort((a, b) => (a[0].dia < b[0].dia ? -1 : 1))
      .map((rs) => ({ d: rs[0].dia, n: sum(rs, 'novos'), r: rs.length }));
    if (D.length) {
      const rotasN = new Set((DB.cad.cadastros_diarios || []).map((x) => x.rota)).size,
        meta = META_ROTA() * rotasN;
      cards.push(
        cartaoGrafico({
          titulo: t('Novos clientes por dia'),
          sub: t('{n} cadastros no período · meta diária da rede {m}', { n: n0(sum(D, 'n')), m: n0(meta) }),
          destino: 'cadastros-analise',
          desenhar: (w, h) =>
            grafColunas(w, {
              altura: h,
              fmt: n0,
              ref: { valor: meta, rot: t('Meta {m}', { m: n0(meta) }) },
              itens: D.map((x) => ({
                valor: x.n,
                rot: diaCurto(x.d),
                titulo: ds(x.d),
                linhas: [
                  [n0(x.n), t('novos clientes')],
                  [n0(x.r), t('rotas com cadastros')],
                ],
              })),
            }),
          colunas: [[t('Dia')], [t('Novos clientes'), 1]],
          linhas: D.map((x) => [dl(x.d), n0(x.n)]),
        }),
      );
    }
  }
  if (!cards.length) {
    pg.append(el('div', 'glass empty', t('Sem dados para mostrar no período escolhido.')));
    return;
  }
  const grelha = el('div', 'viz-grid');
  grelha.append(...cards);
  pg.append(grelha);
  ecraUnico(pg, grelha);
}

// Painel num só ecrã (boas práticas de dashboards: visão geral sem deslocação, «regra dos 5 segundos»):
// em ecrãs de computador os gráficos ficam numa grelha de 4 × 2 com a altura que sobra no ecrã; cada
// gráfico mostra o essencial (as maiores parcelas e «Outros») e o detalhe fica no relatório.
const ECRA_MIN = { w: 1200, h: 700 };
function ecraUnico(pg, grelha) {
  const ajustar = () => {
    if (!grelha.isConnected) return;
    const ok = innerWidth >= ECRA_MIN.w && innerHeight >= ECRA_MIN.h && !matchMedia('print').matches;
    grelha.classList.toggle('ecra-unico', ok);
    pg.classList.toggle('inicio-ecra', ok);
    if (!ok) return;
    const filas = Math.ceil(grelha.children.length / 4),
      topo = grelha.getBoundingClientRect().top + scrollY,
      gap = 12,
      fundo = 16;
    let h = Math.max(170, Math.floor((innerHeight - topo - fundo - gap * (filas - 1)) / filas));
    grelha.style.setProperty('--viz-h', h + 'px');
    // o que ainda sobrar (margens da página, rodapé) sai da altura dos quadros: nada de barra de deslocação
    const excesso = document.documentElement.scrollHeight - innerHeight;
    if (excesso > 0 && h > 170) {
      h = Math.max(170, h - Math.ceil(excesso / filas));
      grelha.style.setProperty('--viz-h', h + 'px');
    }
  };
  ajustar();
  FITS.unshift(ajustar); // antes dos gráficos, que se desenham à altura do quadro
}

// ── Comparação com o período anterior (boas práticas de KPI: valor, variação e tendência) ──
// Comparável «like-for-like»: Hoje → ontem; N dias → os N dias anteriores; Mês (até hoje) → o mesmo
// intervalo do mês anterior. Com o período aberto (inclui hoje), o último dia comparável conta só até
// à hora actual, para não comparar um dia a meio com um dia completo. «Tudo» não tem comparação.
const COMP = { chave: '', vendas: null, aLer: false };
function periodoComparavel() {
  if (!st.de || !st.ate || st.preset === 'tudo') return null;
  const hj = hoje();
  let de, ate, nome;
  if (st.preset === 'mes') {
    const [a, m, d] = st.ate.split('-').map(Number);
    const ini = new Date(Date.UTC(a, m - 2, 1)),
      ultimo = new Date(Date.UTC(a, m - 1, 0)).getUTCDate();
    de = ini.toISOString().slice(0, 10);
    ate = de.slice(0, 8) + String(Math.min(d, ultimo)).padStart(2, '0');
    nome = t('mesmo período do mês anterior');
  } else {
    const n = Math.round((Date.parse(st.ate) - Date.parse(st.de)) / 864e5) + 1;
    ate = addD(st.de, -1);
    de = addD(st.de, -n);
    nome = n === 1 ? (st.de === hj ? t('ontem') : t('dia anterior')) : t('{n} dias anteriores', { n });
  }
  const ateHora = st.ate === hj ? new Date().toTimeString().slice(0, 5) : null;
  return { de, ate, nome: ateHora ? t('{p} até às {h}', { p: nome, h: ateHora }) : nome, ateHora };
}
function lerComparavel(pc) {
  const chave = pc.de + '|' + pc.ate;
  if (COMP.chave === chave && COMP.vendas) return COMP.vendas;
  if (COMP.aLer === chave) return null;
  COMP.aLer = chave;
  fetch(`data/api.php?start_date=${pc.de.replace(/-/g, '')}&end_date=${pc.ate.replace(/-/g, '')}`, { cache: 'no-store' })
    .then((r) => (r.ok ? r.json() : null))
    .then((d) => {
      COMP.chave = chave;
      COMP.vendas = d ? (Array.isArray(d) ? d : d.vendas || []) : [];
    })
    .catch(() => {
      COMP.chave = chave;
      COMP.vendas = [];
    })
    .finally(() => {
      COMP.aLer = false;
      if (atual === 'inicio' && !document.querySelector('dialog[open]')) {
        const y = scrollY;
        draw();
        scrollTo(0, y);
      }
    });
  return null;
}
const metricas = (V) => {
  const tot = sum(V, 'total'),
    liq = sum(V, recebidoDe);
  return {
    tot,
    liq,
    din: valorCat(V, 'dinheiro'),
    mc: valorCat(V, 'multicaixa'),
    pend: tot - liq,
    cli: new Set(V.map((x) => (isCF(x.nif) ? x.cliente : x.nif))).size,
  };
};
/** Variação para o quadro: ▲/▼ e a percentagem; azul quando favorável, vermelho quando desfavorável. */
function variacao(actual, anterior, nome, fmt, menosEMelhor) {
  if (anterior == null) return null;
  const titulo = t('Período comparável ({p}): {v}', { p: nome, v: fmt(anterior) });
  if (!anterior) return actual ? { txt: t('novo'), cls: 'neutro', title: titulo } : null;
  const d = (actual - anterior) / Math.abs(anterior);
  if (Math.abs(d) < 0.0005) return { txt: '= 0%', cls: 'neutro', title: titulo };
  const sobe = d > 0,
    bom = menosEMelhor ? !sobe : sobe;
  return { txt: (sobe ? '▲ ' : '▼ ') + pct(Math.abs(d)), cls: bom ? 'bom' : 'mau', title: titulo + ' · ' + t('vs {p}', { p: nome }) };
}
/** Série para a mini-linha: por dia no período; num só dia, acumulado por hora até agora. */
function serie(V, f) {
  if (st.de === st.ate) {
    const ate = st.ate === hoje() ? new Date().getHours() : 23;
    const porHora = Array.from({ length: ate + 1 }, () => []);
    V.forEach((x) => {
      const h = parseInt(String(x.hora || '0').slice(0, 2), 10) || 0;
      if (h <= ate) porHora[h].push(x);
    });
    let acum = [];
    return porHora.map((rs) => f((acum = acum.concat(rs))));
  }
  const dias = [];
  for (let d = st.de; d <= st.ate && dias.length < 400; d = addD(d, 1)) dias.push(d);
  const por = group(V, 'data');
  return dias.map((d) => f(por[d] || []));
}

R.inicio = (pg) => {
  const V = vendasR(),
    tot = sum(V, 'total'),
    liq = sum(V, recebidoDe);
  const din = valorCat(V, 'dinheiro'),
    mc = valorCat(V, 'multicaixa');
  // Período comparável (lido em segundo plano; os quadros actualizam-se quando chega)
  const pc = periodoComparavel();
  let ant = null;
  if (pc) {
    let P = lerComparavel(pc);
    if (P) {
      if (filtroAtivo('inicio', 'rota')) P = P.filter((x) => FV.rota.includes(String(x.rota)));
      if (pc.ateHora) P = P.filter((x) => x.data < pc.ate || String(x.hora || '00:00').slice(0, 5) <= pc.ateHora);
      ant = metricas(P);
    }
  }
  const cur = metricas(V);
  const vr = (k, fmt, menos) => (ant ? variacao(cur[k], ant[k], pc.nome, fmt, menos) : null);
  const sp = (f, nome) => ({ spark: serie(V, f), sparkTitulo: t('Tendência de {m} no período', { m: nome }) });
  if (['vendas-promotor', 'documentos', 'pagamentos'].some(podeVer))
    pg.append(
      tiles([
        {
          l: t('Vendas RTM'),
          v: kz2(tot),
          s: t('{n} documentos', { n: n0(V.length) }),
          cls: 'full-value',
          var: vr('tot', kz2),
          ...sp((rs) => sum(rs, 'total'), t('vendas')),
        },
        {
          l: t('Recebido'),
          v: kz2(liq),
          s: t('{p} do total', { p: pct(tot ? liq / tot : null) }),
          cls: 'full-value',
          var: vr('liq', kz2),
          ...sp((rs) => sum(rs, recebidoDe), t('recebido')),
        },
        {
          l: t('Em dinheiro'),
          v: temMeio() ? kz2(din) : '—',
          s: temMeio() ? '' : t('Falta o meio de cobrança'),
          cls: 'full-value',
          var: temMeio() ? vr('din', kz2) : null,
          ...(temMeio() ? sp((rs) => valorCat(rs, 'dinheiro'), t('dinheiro')) : {}),
        },
        {
          l: t('Em Multicaixa'),
          v: temMeio() ? kz2(mc) : '—',
          s: temMeio() ? '' : t('Falta o meio de cobrança'),
          cls: 'full-value',
          var: temMeio() ? vr('mc', kz2) : null,
          ...(temMeio() ? sp((rs) => valorCat(rs, 'multicaixa'), t('Multicaixa')) : {}),
        },
        {
          l: t('Facturas pendentes'),
          v: kz2(tot - liq),
          tone: tot - liq > 0 ? 'warn' : null,
          cls: 'full-value',
          var: vr('pend', kz2, true),
          ...sp((rs) => sum(rs, 'total') - sum(rs, recebidoDe), t('pendentes')),
        },
        {
          l: t('Clientes com compras'),
          v: n0(cur.cli),
          var: vr('cli', n0),
          ...sp((rs) => new Set(rs.map((x) => (isCF(x.nif) ? x.cliente : x.nif))).size, t('clientes')),
        },
      ]),
    );
  const kp = pg.querySelector('.tiles');
  if (kp) kp.classList.add('tiles-kpi');
  painelInicio(pg);
};
