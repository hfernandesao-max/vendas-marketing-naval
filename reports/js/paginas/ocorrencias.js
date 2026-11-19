// Terreno › Operação do dia › Ocorrências: as excepções do terreno que pedem uma acção (venda longe do cliente,
// parado sem vender, fora do território, visita do plano não feita, cliente regular parado, cliente sem GPS ou
// duplicado, coordenadas a corrigir), numa só lista, com o tratamento dos alertas (assumir, resolver, silenciar,
// reabrir). Cada ocorrência leva à página onde se vê o pormenor (o percurso do dia no mapa, a prospecção, as visitas).
// Servidor: nucleo/api/v1/ocorrencias.php e nucleo/servicos/ocorrencias.php.
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { pill, secBox, tiles } from '../componentes/blocos.js';
import { botao, campo, entrada, janela, msgErro } from '../componentes/janelas.js';
import { actualizarContagem } from '../contagens.js';
import { dl, ds, n0 } from '../formatos.js';
import { t, tv } from '../i18n/i18n.js';
import { atual, SESSAO } from '../perfil.js';
import { hoje } from '../periodo.js';
import { postJson } from '../servidor.js';
import { el, somaDias, toast } from '../util.js';
import { R } from './registo.js';

let OC = null;
let dia = null;
let aLer = false;
let vista = 'abertas';
let tipo = '';
const GRAV = { critico: ['Crítico', 'p-bad'], aviso: ['Aviso', 'p-warn'], info: ['Informação', 'p-mut'] };
const ESTADO = {
  novo: ['Novo', 'p-bad'],
  assumido: ['Em tratamento', 'p-warn'],
  reaberto: ['Reaberto', 'p-bad'],
  resolvido: ['Resolvido', 'p-ok'],
  silenciado: ['Silenciado', 'p-mut'],
};
const FEITO = {
  assumir: 'Ocorrência assumida.',
  resolver: 'Ocorrência resolvida.',
  silenciar: 'Ocorrência silenciada.',
  reabrir: 'Ocorrência reaberta.',
  largar: 'Ocorrência largada.',
};

function ler() {
  if (aLer) return;
  aLer = true;
  dia = dia || hoje();
  apiJson('ocorrencias?data=' + dia).then((d) => {
    aLer = false;
    OC = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os dados.') };
    if (OC.ocorrencias && dia === hoje()) actualizarContagem('ocorrencias', OC.abertas);
    if (atual === 'ocorrencias') draw();
  });
}
function mudarDia(d) {
  if (!d || d > hoje()) return;
  dia = d;
  OC = null;
  draw();
}

function tratar(o, accao, fechar, erro, extra = {}) {
  return postJson('ocorrencias', { accao, id: o.id, data: dia, ...extra }).then((x) => {
    if (!x || !x.ok) {
      const m = tv((x && x.erro) || 'Não foi possível gravar.');
      if (erro) {
        erro.hidden = false;
        erro.textContent = m;
      } else toast(m, 5000);
      return;
    }
    if (fechar) fechar();
    toast(t(FEITO[accao]), 3500);
    OC = null;
    ler();
    draw();
  });
}
function janelaTratar(o, accao) {
  const cfg = {
    resolver: [
      t('Resolver a ocorrência'),
      t('O que foi feito? Fica registado com o seu nome. Se a situação continuar, a ocorrência reabre sozinha em 24 horas.'),
      t('Resolver'),
    ],
    silenciar: [t('Silenciar a ocorrência'), t('Até quando e porquê? No máximo 30 dias; depois volta sozinha.'), t('Silenciar')],
    reabrir: [t('Reabrir a ocorrência'), t('Porque reabre esta ocorrência?'), t('Reabrir')],
  }[accao];
  janela(cfg[0], o.titulo, (b, ft, fechar) => {
    b.append(el('p', 'note', cfg[1]));
    const nota = el('textarea');
    nota.rows = 3;
    nota.maxLength = 500;
    nota.setAttribute('aria-label', accao === 'resolver' ? t('Acção tomada') : t('Motivo'));
    let ate = null;
    if (accao === 'silenciar') {
      const hojeIso = hoje();
      ate = entrada('date', somaDias(hojeIso, 7), { min: hojeIso, max: somaDias(hojeIso, 30) });
      ate.setAttribute('aria-label', t('Silenciado até'));
      b.append(campo(t('Silenciado até'), ate));
    }
    b.append(campo(accao === 'resolver' ? t('Acção tomada') : t('Motivo'), nota));
    const erro = msgErro();
    b.append(erro);
    ft.append(
      botao(t('Cancelar'), 'ghost', fechar),
      botao(cfg[2], null, () => tratar(o, accao, fechar, erro, { nota: nota.value, ...(ate ? { ate: ate.value } : {}) })),
    );
    setTimeout(() => nota.focus(), 0);
  });
}

function cartao(o) {
  const tr = o.tratamento || { estado: 'novo' };
  const c = el('article', 'gst-alerta oc-item g-' + (o.gravidade === 'critico' ? 'critico' : o.gravidade === 'aviso' ? 'aviso' : 'info'));
  c.dataset.id = o.id;
  const h = el('div', 'cc-cab');
  const [gn, gc] = GRAV[o.gravidade] || GRAV.info;
  const [en, ec] = ESTADO[tr.estado] || ESTADO.novo;
  h.append(el('b', null, t(o.tipo_nome) + (o.cliente ? ' · ' + o.cliente : '')), el('span', 'oc-pills'));
  h.lastChild.append(pill(t(gn), gc), pill(t(en), ec));
  c.append(h);
  const onde = [o.rota ? t('rota {r}', { r: o.rota }) + (o.promotor ? ' · ' + o.promotor : '') : '', tv(o.titulo), tv(o.detalhe)].filter(Boolean).join(' · ');
  c.append(el('p', 'note', onde));
  if (tr.por && tr.estado !== 'novo') {
    const quem = tr.por + (tr.em ? ' · ' + dl(String(tr.em).slice(0, 10)) + ' ' + String(tr.em).slice(11, 16) : '');
    c.append(
      el('p', 'note oc-trat', (tr.estado === 'silenciado' && tr.ate ? t('até {d}', { d: dl(tr.ate) }) + ' · ' : '') + quem + (tr.nota ? ' · ' + tr.nota : '')),
    );
  }
  const acc = el('div', 'gst-trat-acc');
  const ver = el('a', 'btn ghost sm', o.pagina && o.pagina.startsWith('geo-terreno') ? t('Ver no mapa') : t('Ver o pormenor'));
  ver.href = '#' + o.pagina;
  acc.append(ver);
  if (OC.trata) {
    const eu = (SESSAO && SESSAO.login) || '';
    const activo = ['novo', 'assumido', 'reaberto'].includes(tr.estado);
    if (activo && !(tr.estado === 'assumido' && tr.por === eu)) acc.append(botao(t('Assumir'), 'sec sm', () => tratar(o, 'assumir')));
    if (tr.estado === 'assumido' && tr.por === eu) acc.append(botao(t('Largar'), 'ghost sm', () => tratar(o, 'largar')));
    if (activo)
      acc.append(
        botao(t('Resolver'), 'sec sm', () => janelaTratar(o, 'resolver')),
        botao(t('Silenciar'), 'ghost sm', () => janelaTratar(o, 'silenciar')),
      );
    else acc.append(botao(t('Reabrir'), 'ghost sm', () => janelaTratar(o, 'reabrir')));
  }
  c.append(acc);
  return c;
}

function barra() {
  const b = el('section', 'glass sec pc-barra');
  const d = el('div', 'pc-dia');
  const ant = botao('‹', 'ghost sm', () => mudarDia(somaDias(dia, -1)));
  ant.setAttribute('aria-label', t('Dia anterior'));
  const seg = botao('›', 'ghost sm', () => mudarDia(somaDias(dia, 1)));
  seg.setAttribute('aria-label', t('Dia seguinte'));
  seg.disabled = dia >= hoje();
  const di = entrada('date', dia, { max: hoje() });
  di.setAttribute('aria-label', t('Dia'));
  di.addEventListener('change', () => mudarDia(di.value));
  d.append(ant, el('b', 'pc-dia-t', ds(dia)), di, seg);
  if (dia !== hoje()) d.append(botao(t('Hoje'), 'ghost sm', () => mudarDia(hoje())));
  b.append(d);
  return b;
}

R.ocorrencias = (pg) => {
  dia = dia || hoje();
  pg.append(barra());
  if (!OC) {
    pg.append(el('div', 'glass empty', t('A carregar…')));
    ler();
    return;
  }
  if (OC.falhou) {
    pg.append(el('div', 'glass empty', tv(OC.falhou)));
    return;
  }
  const todas = OC.ocorrencias || [];
  const abertas = todas.filter((o) => o.activo);
  const n = (g) => abertas.filter((o) => o.gravidade === g).length;
  pg.append(
    tiles([
      { l: t('Por tratar'), v: n0(abertas.length), tone: abertas.length ? 'warn' : 'ok', s: t('novas, em tratamento ou reabertas') },
      { l: t('Críticas'), v: n0(n('critico')), tone: n('critico') ? 'bad' : null, s: t('clientes regulares parados há 60 dias ou mais') },
      { l: t('Avisos'), v: n0(n('aviso')), tone: n('aviso') ? 'warn' : null },
      { l: t('Em tratamento'), v: n0(abertas.filter((o) => o.tratamento && o.tratamento.estado === 'assumido').length) },
      { l: t('Resolvidas ou silenciadas'), v: n0(todas.length - abertas.length) },
    ]),
  );
  if (OC.erro) pg.append(el('p', 'aviso', tv(OC.erro)));
  // filtros: estado (abertas / todas) e tipo
  const fil = el('div', 'oc-filtros');
  const seg = el('div', 'rel-tabs rel-tabs-2');
  seg.setAttribute('role', 'tablist');
  [
    ['abertas', 'Por tratar'],
    ['todas', 'Todas'],
  ].forEach(([k, nome]) => {
    const b = el('button', 'rel-tab' + (vista === k ? ' on' : ''), t(nome));
    b.type = 'button';
    b.setAttribute('role', 'tab');
    b.setAttribute('aria-selected', vista === k ? 'true' : 'false');
    b.addEventListener('click', () => ((vista = k), draw()));
    seg.append(b);
  });
  const sel = el('select');
  sel.setAttribute('aria-label', t('Tipo de ocorrência'));
  sel.append(new Option(t('Todos os tipos'), ''));
  const base = vista === 'abertas' ? abertas : todas;
  Object.entries(OC.tipos || {}).forEach(([k, nome]) => {
    const q = base.filter((o) => o.tipo === k).length;
    if (q) sel.append(new Option(t(nome) + ' (' + n0(q) + ')', k));
  });
  sel.value = [...sel.options].some((o) => o.value === tipo) ? tipo : '';
  sel.addEventListener('change', () => ((tipo = sel.value), draw()));
  fil.append(seg, sel);
  const lista = el('div', 'gst-lista');
  const vis = base.filter((o) => !tipo || o.tipo === tipo);
  vis.forEach((o) => lista.append(cartao(o)));
  if (!vis.length)
    lista.append(
      el(
        'p',
        'note oc-vazio',
        vista === 'abertas'
          ? t('Sem ocorrências por tratar neste dia: as vendas foram feitas junto dos clientes, sem paragens longas nem saídas do território.')
          : t('Sem ocorrências neste dia.'),
      ),
    );
  const box = secBox(
    t('Ocorrências'),
    t(
      'O que pede acção no terreno, calculado com os mesmos dados do Percurso do dia, das Visitas do dia e da Prospecção. As visitas do plano não feitas só aparecem depois das 18:00 ou em dias passados. Assumir mostra a todos quem está a tratar; resolver e silenciar pedem uma nota e ficam registados.',
    ),
    lista,
  );
  box.querySelector('.sec-h').after(fil);
  if (!OC.trata)
    box.append(el('p', 'note', t('Pode consultar as ocorrências; tratá-las é da Direcção, do Comercial, da GeoMobilidade e dos supervisores nas suas rotas.')));
  pg.append(box);
};
