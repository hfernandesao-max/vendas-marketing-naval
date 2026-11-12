// Logística › Painel da carrinha: um ecrã por carrinha e dia com o fecho de rota (prática da distribuição directa à loja,
// «route settlement»): carregado, vendido e devolvido (Pinguim BO), contagem e aprovação, peso da carga e o estado das
// ligações às contagens da solução do Pinguim BO, à Quatenus e ao CCTV. Gestão por excepção: as carrinhas cuja contagem
// bate certo fecham sozinhas; só as diferenças pedem atenção. Contar e aprovar fazem-se na Conciliação da carrinha.
// Servidor: nucleo/api/v1/painel-carrinha.php (naval_carr_painel).
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { pill, secBox, tiles } from '../componentes/blocos.js';
import { botao, entrada } from '../componentes/janelas.js';
import { dl, ds, kz, n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { atual } from '../perfil.js';
import { hoje } from '../periodo.js';
import { el, somaDias } from '../util.js';
import { R } from './registo.js';

let PC = null;
let dia = null;
let aLer = false;
const FECHO = {
  por_contar: ['Por contar', 'p-warn'],
  por_validar: ['Contada, por validar', 'p-warn'],
  por_aprovar: ['Diferenças por aprovar', 'p-serio'],
  por_fechar: ['Por aprovação final', 'p-serio'],
  aprovada: ['Aprovada com motivos', 'p-ok'],
  fechada_auto: ['Fechada sozinha (sem diferenças)', 'p-ok'],
};

function ler(actualizar) {
  if (aLer) return;
  aLer = true;
  dia = dia || hoje();
  apiJson('painel-carrinha?data=' + dia + (actualizar ? '&actualizar=1' : '')).then((d) => {
    aLer = false;
    PC = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os dados.') };
    if (atual === 'painel-carrinha') draw();
  });
}
function mudarDia(d) {
  if (!d || d > hoje()) return;
  dia = d;
  PC = null;
  draw();
}
const q = (v) => (v == null ? '—' : n0(v));

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
  const dc = el('span', 'pc-dcampo');
  dc.append(di, el('span', 'dv', dl(dia)));
  d.append(ant, el('b', 'pc-dia-t', ds(dia)), dc, seg);
  if (dia !== hoje()) d.append(botao(t('Hoje'), 'ghost sm', () => mudarDia(hoje())));
  d.append(botao(t('Actualizar'), 'ghost sm', () => ((PC = null), ler(true), draw())));
  b.append(d);
  return b;
}

function cartao(r) {
  const c = el('div', 'glass pcar-cartao');
  c.dataset.rota = r.rota;
  const [rot, cls] = FECHO[r.fecho] || [r.fecho, 'p-mut'];
  const h = el('div', 'pcar-cab');
  h.append(el('b', null, r.rota + ' · ' + r.promotor), pill(t(rot), cls));
  c.append(h);
  const det = el('dl', 'pcar-det');
  const linha = (k, v) => det.append(el('dt', null, k), el('dd', null, v));
  linha(t('Artigos'), q(r.artigos));
  if (r.carregado != null) {
    linha(t('Carregado'), q(r.carregado));
    linha(t('Vendido'), q(r.vendido));
  }
  if (r.diferencas != null) linha(t('Diferenças'), q(r.diferencas) + (r.falta_kz ? ' · ' + t('falta {v}', { v: kz(r.falta_kz) }) : ''));
  if (r.alertas) linha(t('Alertas'), q(r.alertas));
  if (r.peso_kg != null) linha(t('Peso na carrinha'), n0(r.peso_kg) + ' kg');
  if (r.contado_em) linha(t('Contado'), String(r.contado_em).slice(11, 16) + ' · ' + r.contado_por);
  if (r.aprovado_em) linha(t('Fechada'), String(r.aprovado_em).slice(11, 16) + ' · ' + (r.aprovado_por === 'automatico' ? t('automático') : r.aprovado_por));
  c.append(det);
  if (['por_contar', 'por_validar', 'por_aprovar', 'por_fechar'].includes(r.fecho)) {
    const a = el('a', 'btn sm', t(r.fecho === 'por_contar' ? 'Contar na conciliação' : 'Ver as diferenças'));
    a.href = '#conciliacao-carrinha';
    c.append(a);
  }
  return c;
}

R['painel-carrinha'] = (pg) => {
  dia = dia || hoje();
  pg.append(barra());
  if (!PC) {
    pg.append(el('div', 'glass empty', t('A carregar…')));
    ler(false);
    return;
  }
  if (PC.falhou) {
    pg.append(el('div', 'glass empty', t(PC.falhou)));
    return;
  }
  const L = PC.rotas;
  const n = (f) => L.filter((r) => r.fecho === f).length;
  const fechadas = n('fechada_auto') + n('aprovada');
  pg.append(
    tiles([
      { l: t('Carrinhas com movimento'), v: n0(L.length) },
      { l: t('Fechadas'), v: n0(fechadas), s: t('sem diferenças, fechadas sozinhas: {n}', { n: n0(n('fechada_auto')) }) },
      { l: t('Por contar'), v: n0(n('por_contar')), tone: n('por_contar') ? 'warn' : null },
      {
        l: t('Diferenças por aprovar'),
        v: n0(n('por_validar') + n('por_aprovar') + n('por_fechar')),
        tone: n('por_validar') + n('por_aprovar') + n('por_fechar') ? 'bad' : null,
      },
    ]),
  );
  const ordem = ['por_fechar', 'por_aprovar', 'por_validar', 'por_contar', 'aprovada', 'fechada_auto'];
  const grelha = el('div', 'pcar-grelha');
  L.slice()
    .sort((a, b) => ordem.indexOf(a.fecho) - ordem.indexOf(b.fecho) || String(a.rota).localeCompare(String(b.rota)))
    .forEach((r) => grelha.append(cartao(r)));
  pg.append(
    secBox(
      t('Carrinhas do dia'),
      t('Primeiro as que pedem atenção. As carrinhas cuja contagem bate certo em todos os artigos fecham sozinhas.'),
      L.length ? grelha : el('p', 'empty', t('Sem movimentos nas carrinhas neste dia.')),
    ),
  );
  const fontes = el('ul', 'pcar-fontes');
  PC.fontes.forEach((f) => {
    const li = el('li');
    li.append(el('span', null, t(f.bloco)), pill(t(f.estado === 'ligado' ? 'Ligado' : 'Por ligar'), f.estado === 'ligado' ? 'p-ok' : 'p-mut'));
    fontes.append(li);
  });
  pg.append(secBox(t('Origem dos dados'), t('Cada bloco passa a ter dados quando a ligação ao sistema de origem existir. O portal só lê.'), fontes));
};
