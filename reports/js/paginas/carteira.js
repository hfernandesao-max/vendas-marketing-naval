// Terreno › Visitas do dia (ficha de preparação de cada cliente: ritmo de compra, encomenda sugerida, o que deixou de
// comprar, o que propor e risco de crédito) e Gestão › Qualidade dos dados e anomalias. Calculado todas as noites no
// servidor a partir do Pinguim BO (nucleo/servicos/carteira.php, API v1/carteira).
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { promotorRota } from '../calculos.js';
import { pill, secBox, tabela, tiles } from '../componentes/blocos.js';
import { botao, entrada } from '../componentes/janelas.js';
import { dl, kz, n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { atual } from '../perfil.js';
import { hoje } from '../periodo.js';
import { el, somaDias } from '../util.js';
import { R } from './registo.js';

const cache = {};
let dia = null;
let rotaSel = '';
const ESTADO = {
  parado: ['Parado', 'p-bad'],
  a_abrandar: ['A abrandar', 'p-warn'],
  em_dia: ['Em dia', 'p-ok'],
};
const RISCO = { alto: ['Risco alto', 'p-bad'], medio: ['Risco médio', 'p-warn'], baixo: ['Risco baixo', 'p-mut'] };
const TIPOS = {
  sem_meio: 'Sem meio de cobrança',
  notas_credito: 'Notas de crédito altas',
  repetidos: 'Documentos repetidos',
  desconto: 'Desconto fora do habitual',
  cliente_sem_gps: 'Cliente sem coordenadas',
  clientes_mesmo_ponto: 'Clientes no mesmo ponto',
  artigo_sem_peso: 'Artigo sem peso',
  documento_sem_meio: 'Documento sem meio de cobrança',
  agt_por_comunicar: 'Por comunicar à AGT',
  agt_erro: 'Recusado pela AGT',
};

function ler(chave, url, pagina) {
  if (cache[chave]) return cache[chave];
  cache[chave] = { aLer: true };
  apiJson(url).then((d) => {
    cache[chave] = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os dados.') };
    if (atual === pagina) draw();
  });
  return cache[chave];
}
const qtd = (q) => (Number.isInteger(+q) ? n0(q) : String(q).replace('.', ','));

function barraDia() {
  const b = el('section', 'glass sec pc-barra');
  const d = el('div', 'pc-dia');
  const mudar = (x) => {
    dia = x;
    draw();
  };
  const ant = botao('‹', 'ghost sm', () => mudar(somaDias(dia, -1)));
  ant.setAttribute('aria-label', t('Dia anterior'));
  const seg = botao('›', 'ghost sm', () => mudar(somaDias(dia, 1)));
  seg.setAttribute('aria-label', t('Dia seguinte'));
  const di = entrada('date', dia, {});
  di.setAttribute('aria-label', t('Dia'));
  di.addEventListener('change', () => di.value && mudar(di.value));
  d.append(ant, di, seg, el('span', 'note', dia === hoje() ? t('Hoje') : dia === somaDias(hoje(), 1) ? t('Amanhã') : ''));
  b.append(d);
  return b;
}

/** Ficha de preparação da visita a um cliente. */
function ficha(c) {
  const a = el('article', 'gst-alerta vd-ficha g-' + (c.estado === 'parado' ? 'critico' : c.estado === 'a_abrandar' ? 'aviso' : 'info'));
  const h = el('div', 'cc-cab');
  const [et, ec] = ESTADO[c.estado] || [c.estado, 'p-mut'];
  const tit = el('div');
  // posição no percurso optimizado da rota (optimização automática da noite)
  if (c.ordem) tit.append(el('span', 'vd-ordem', String(c.ordem)));
  tit.append(el('b', null, c.n || c.c), el('span', 'note', ' · ' + c.c));
  const pills = el('div', 'vd-pills');
  pills.append(pill(t(et), ec));
  if (c.risco) {
    const [rt, rc] = RISCO[c.risco.risco] || [c.risco.risco, 'p-mut'];
    pills.append(pill(t(rt) + (c.risco.aberto > 0 ? ' · ' + kz(c.risco.aberto) : ''), rc));
  }
  h.append(tit, pills);
  a.append(h);
  a.append(
    el(
      'p',
      'note',
      [
        t(c.motivo),
        t('compra a cada {n} dias', { n: String(c.intervalo).replace('.', ',') }),
        t('última compra {d} (há {n} dias)', { d: dl(c.ult), n: n0(c.dias) }),
        t('{v} por compra', { v: kz(c.media) }),
      ].join(' · '),
    ),
  );
  const g = el('div', 'vd-grelha');
  const bloco = (titulo, itens, vazio) => {
    const s = el('div', 'vd-bloco');
    s.append(el('h4', null, titulo));
    const ul = el('ul');
    if (!itens.length) ul.append(el('li', 'note', vazio));
    itens.forEach((x) => ul.append(el('li', null, x)));
    s.append(ul);
    return s;
  };
  g.append(
    bloco(
      t('Encomenda sugerida'),
      (c.encomenda || []).map((x) => qtd(x.q) + ' × ' + x.n),
      t('Sem compras habituais suficientes.'),
    ),
    bloco(
      t('Deixou de comprar'),
      (c.deixou || []).map((x) => x.n),
      t('Nada a assinalar.'),
    ),
    bloco(
      t('Propor'),
      (c.propor || []).map((x) => t('{a} (compram {p} dos clientes da rota)', { a: x.n, p: Math.round(x.pen * 100) + ' %' })),
      t('Já compra o habitual da rota.'),
    ),
  );
  a.append(g);
  return a;
}

R['visitas-dia'] = (pg) => {
  dia = dia || hoje();
  pg.append(barraDia());
  const d = ler('v|' + dia, 'carteira?vista=visitas&dia=' + dia, 'visitas-dia');
  if (d.aLer) {
    pg.append(el('div', 'glass empty', t('A carregar…')));
    return;
  }
  if (d.falhou) {
    pg.append(el('div', 'glass empty', t(d.falhou)));
    return;
  }
  const rotas = Object.keys(d.visitas || {});
  if (!d.gerado) {
    pg.append(el('div', 'glass empty', t('A carteira é calculada todas as noites, entre as 04:00 e as 07:00. Ainda não há cálculo.')));
    return;
  }
  const todos = rotas.flatMap((r) => d.visitas[r]);
  const n = (e) => todos.filter((c) => c.estado === e).length;
  pg.append(
    tiles([
      { l: t('Clientes a visitar'), v: n0(todos.length), s: t('{n} rotas', { n: n0(rotas.length) }) },
      { l: t('Parados'), v: n0(n('parado')), tone: n('parado') ? 'bad' : null, s: t('mais de 2 vezes o seu intervalo habitual') },
      { l: t('A abrandar'), v: n0(n('a_abrandar')), tone: n('a_abrandar') ? 'warn' : null, s: t('compra em atraso face ao seu ritmo') },
      { l: t('Em dia'), v: n0(n('em_dia')), s: t('dia habitual de compra') },
    ]),
  );
  if (!rotas.length) {
    pg.append(el('div', 'glass empty', t('Nenhum cliente previsto para este dia.')));
    return;
  }
  if (rotas.length > 1) {
    const s = el('select', 'vd-rota');
    s.setAttribute('aria-label', t('Rota'));
    s.append(new Option(t('Todas as rotas'), ''));
    rotas.forEach((r) => s.append(new Option(promotorRota(r), r, false, r === rotaSel)));
    s.addEventListener('change', () => ((rotaSel = s.value), draw()));
    const w = el('div', 'glass sec');
    w.append(s);
    pg.append(w);
  }
  rotas
    .filter((r) => !rotaSel || r === rotaSel)
    .forEach((r) => {
      const l = el('div', 'gst-lista');
      d.visitas[r].forEach((c) => l.append(ficha(c)));
      pg.append(
        secBox(
          promotorRota(r) + ' · ' + t('{n} clientes', { n: n0(d.visitas[r].length) }),
          t('Os parados primeiro e, dentro de cada estado, os de maior valor por compra. Encomenda sugerida pela quantidade habitual de cada cliente.'),
          l,
        ),
      );
    });
  pg.append(
    el(
      'p',
      'note',
      t('Calculado em {d} a partir das vendas do Pinguim BO dos últimos 120 dias.', {
        d: dl(String(d.gerado).slice(0, 10)) + ' ' + String(d.gerado).slice(11, 16),
      }),
    ),
  );
};

R['qualidade-dados'] = (pg) => {
  const d = ler('q', 'carteira?vista=qualidade', 'qualidade-dados');
  if (d.aLer) {
    pg.append(el('div', 'glass empty', t('A carregar…')));
    return;
  }
  if (d.falhou) {
    pg.append(el('div', 'glass empty', t(d.falhou)));
    return;
  }
  const an = d.anomalias || [],
    q = d.qualidade || [];
  pg.append(
    tiles([
      { l: t('Anomalias de vendas'), v: n0(an.length), tone: an.length ? 'warn' : null, s: t('últimos 7 dias') },
      { l: t('Dados a corrigir'), v: n0(q.length), tone: q.length ? 'warn' : null, s: t('uma tarefa por problema') },
    ]),
  );
  pg.append(
    tabela({
      id: 'qd-anomalias',
      titulo: t('Anomalias de vendas'),
      nota: t('Auditoria contínua: verificar cada caso no Pinguim BO. Uma anomalia não é uma falha: é um caso fora do padrão que merece ser confirmado.'),
      rows: an.map((x, i) => ({ chave: 'a' + i, ...x, tipo_txt: t(TIPOS[x.tipo] || x.tipo), rota_txt: x.rota ? promotorRota(x.rota) : '—' })),
      sort: 'data',
      cols: [
        { key: 'data', label: t('Dia'), type: 'date' },
        { key: 'tipo_txt', label: t('Tipo') },
        { key: 'rota_txt', label: t('Rota') },
        { key: 'cliente', label: t('Cliente') },
        { key: 'documento', label: t('Documento') },
        { key: 'texto', label: t('Detalhe') },
        { key: 'valor', label: t('Valor'), type: 'kz' },
      ],
    }),
  );
  pg.append(
    tabela({
      id: 'qd-qualidade',
      titulo: t('Qualidade dos dados'),
      nota: t('Corrigir na origem (Pinguim BO). A lista é refeita todas as noites: o que for corrigido sai sozinho.'),
      rows: q.map((x, i) => ({ chave: 'q' + i, ...x, tipo_txt: t(TIPOS[x.tipo] || x.tipo), rota_txt: x.rota ? promotorRota(x.rota) : '—' })),
      cols: [
        { key: 'tipo_txt', label: t('Tipo') },
        { key: 'rota_txt', label: t('Rota') },
        { key: 'texto', label: t('Detalhe') },
      ],
    }),
  );
  if (d.gerado) pg.append(el('p', 'note', t('Calculado em {d}.', { d: dl(String(d.gerado).slice(0, 10)) + ' ' + String(d.gerado).slice(11, 16) })));
};
