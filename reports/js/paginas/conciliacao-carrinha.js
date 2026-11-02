// Logística › Conciliação da carrinha: por rota e dia, o que foi carregado, vendido e devolvido (Pinguim BO), a contagem
// do técnico do armazém no regresso (cega: não vê o esperado antes de gravar) e a aprovação das diferenças, com motivo.
// Servidor: nucleo/api/v1/carrinha.php e nucleo/servicos/carrinha.php.
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { pill, secBox, tiles } from '../componentes/blocos.js';
import { botao, entrada, msgErro } from '../componentes/janelas.js';
import { dl, ds, kz, n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { atual } from '../perfil.js';
import { hoje } from '../periodo.js';
import { postJson } from '../servidor.js';
import { el, somaDias, toast } from '../util.js';
import { R } from './registo.js';

let CC = null;
let dia = null;
let aberta = null;
let aLer = false;
const ESTADOS = {
  por_contar: ['Por contar', 'p-warn'],
  por_aprovar: ['Por aprovar', 'p-serio'],
  aprovada: ['Aprovada', 'p-ok'],
};
const ALERTAS = {
  negativo: 'Stock negativo na carrinha',
  sem_carga: 'Vendido mais do que estava na carrinha',
  nao_explicado: 'Movimento sem documento',
};

function ler(actualizar) {
  if (aLer) return;
  aLer = true;
  dia = dia || hoje();
  apiJson('conciliacao-carrinha?data=' + dia + (actualizar ? '&actualizar=1' : '')).then((d) => {
    aLer = false;
    CC = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os dados.') };
    if (atual === 'conciliacao-carrinha') draw();
  });
}
function mudarDia(d) {
  if (!d || d > hoje()) return;
  dia = d;
  CC = null;
  aberta = null;
  draw();
}
const q = (v) => (v == null ? '—' : n0(v));
const difTxt = (v) => (v == null ? '—' : (v > 0 ? '+' : '') + n0(v));

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
  d.append(botao(t('Actualizar'), 'ghost sm', () => ((CC = null), ler(true), draw())));
  b.append(d);
  return b;
}

/** Contagem cega: só o artigo e o campo da quantidade que voltou na carrinha. */
function formContagem(r) {
  const f = el('div', 'cc-contagem');
  const tab = el('table', 'cc-tab');
  const th = el('tr');
  [t('Artigo'), t('Quantidade que voltou')].forEach((x) => th.append(el('th', null, x)));
  tab.append(th);
  const campos = {};
  r.linhas.forEach((l) => {
    const tr = el('tr');
    const i = entrada('number', l.contado == null ? '' : l.contado, { min: 0, step: 'any', inputMode: 'decimal' });
    i.setAttribute('aria-label', l.artigo);
    campos[l.codigo] = i;
    const td = el('td');
    td.append(i, el('span', 'cc-un', l.unidade || ''));
    tr.append(el('td', null, l.artigo + ' · ' + l.codigo), td);
    tab.append(tr);
  });
  const erro = msgErro();
  f.append(
    el('p', 'note', t('Conte o que voltou na carrinha (artigos por vender e devoluções). Os números do sistema só aparecem depois de gravar a contagem.')),
    tab,
    erro,
    botao(t('Gravar contagem'), null, () => {
      const contagem = {};
      for (const [c, i] of Object.entries(campos)) {
        if (i.value === '') {
          erro.hidden = false;
          erro.textContent = t('Preencha todos os artigos (0 se não voltou nada).');
          i.focus();
          return;
        }
        contagem[c] = Number(i.value);
      }
      postJson('conciliacao-carrinha', { accao: 'contar', data: dia, rota: r.rota, contagem }).then((x) => {
        if (!x.ok) {
          erro.hidden = false;
          erro.textContent = t(x.erro || 'Não foi possível gravar.');
          return;
        }
        toast(
          x.resumo.diferencas ? t('Contagem gravada: {n} diferença(s) a aprovar.', { n: x.resumo.diferencas }) : t('Contagem gravada, sem diferenças.'),
          4000,
        );
        CC = null;
        ler();
        draw();
      });
    }),
  );
  return f;
}

/** Tabela completa (inicial, carregado, vendido, devolvido, esperado, contado, diferença) e, a aprovar, os motivos. */
function tabelaRota(r, gere) {
  const aprovar = gere && r.resumo.estado === 'por_aprovar';
  const box = el('div', 'cc-detalhe');
  const tab = el('table', 'cc-tab');
  const th = el('tr');
  [
    'Artigo',
    'Inicial',
    'Carregado',
    'Vendido',
    'Devolvido',
    'Tem de voltar',
    'Contado',
    'Diferença',
    'Valor',
    r.resumo.estado === 'por_contar' ? 'Alertas' : 'Motivo',
  ].forEach((x) => th.append(el('th', null, t(x))));
  tab.append(th);
  const escolhas = {};
  r.linhas.forEach((l) => {
    const tr = el('tr', l.diferenca ? (l.diferenca < 0 ? 'cc-falta' : 'cc-sobra') : l.alertas.length ? 'cc-alerta' : null);
    tr.append(
      el('td', null, l.artigo),
      el('td', 'n', q(l.inicial)),
      el('td', 'n', q(l.carregado)),
      el('td', 'n', q(l.vendido)),
      el('td', 'n', q(l.devolvido)),
      el('td', 'n', q(l.esperado)),
      el('td', 'n', q(l.contado)),
      el('td', 'n', difTxt(l.diferenca)),
      el('td', 'n', l.valor == null ? '—' : kz(l.valor)),
    );
    const ult = el('td');
    if (r.resumo.estado === 'por_contar') l.alertas.forEach((a) => ult.append(pill(t(ALERTAS[a] || a), 'p-warn')));
    else if (l.diferenca && aprovar) {
      const s = el('select');
      s.append(el('option', null, t('Escolha o motivo')));
      s.firstChild.value = '';
      Object.entries(CC.motivos).forEach(([k, v]) => {
        const o = el('option', null, t(v));
        o.value = k;
        s.append(o);
      });
      const nota = entrada('text', '', { maxLength: 300, placeholder: t('Nota') });
      s.setAttribute('aria-label', t('Motivo') + ' · ' + l.artigo);
      escolhas[l.codigo] = { s, nota };
      ult.append(s, nota);
    } else if (l.diferenca) {
      const m = (r.motivos || {})[l.codigo];
      ult.textContent = m ? t(CC.motivos[m.motivo] || m.motivo) + (m.nota ? ' · ' + m.nota : '') : '—';
    }
    tr.append(ult);
    tab.append(tr);
  });
  const wrap = el('div', 'cc-scroll');
  wrap.append(tab);
  box.append(wrap);
  if (aprovar) {
    const erro = msgErro();
    const acc = el('div', 'cc-acc');
    acc.append(
      botao(t('Recontar'), 'ghost', () => {
        r.linhas = r.linhas.map((l) => ({ ...l, contado: null }));
        r.resumo.estado = 'por_contar';
        r.cega = true;
        draw();
      }),
      botao(r.resumo.diferencas ? t('Aprovar com as diferenças') : t('Aprovar'), null, () => {
        const motivos = {};
        Object.entries(escolhas).forEach(([c, x]) => (motivos[c] = { motivo: x.s.value, nota: x.nota.value }));
        postJson('conciliacao-carrinha', { accao: 'aprovar', data: dia, rota: r.rota, motivos }).then((x) => {
          if (!x.ok) {
            erro.hidden = false;
            erro.textContent = t(x.erro || 'Não foi possível gravar.') + (x.erros ? ' ' + x.erros.join(', ') : '');
            return;
          }
          toast(t('Conciliação aprovada.'), 4000);
          CC = null;
          ler();
          draw();
        });
      }),
    );
    box.append(erro, acc);
  }
  const quem = [];
  if (r.contado_por) quem.push(t('Contada por {p} em {d}', { p: r.contado_por, d: dl(r.contado_em) + ' ' + String(r.contado_em).slice(11, 16) }));
  if (r.aprovado_por) quem.push(t('Aprovada por {p} em {d}', { p: r.aprovado_por, d: dl(r.aprovado_em) + ' ' + String(r.aprovado_em).slice(11, 16) }));
  if (quem.length) box.append(el('p', 'note', quem.join(' · ')));
  return box;
}

R['conciliacao-carrinha'] = (pg) => {
  dia = dia || hoje();
  pg.append(barra());
  if (!CC) {
    pg.append(el('div', 'glass empty', t('A carregar…')));
    ler();
    return;
  }
  if (CC.falhou) {
    pg.append(el('div', 'glass empty', t(CC.falhou)));
    return;
  }
  const rs = CC.rotas;
  const n = (e) => rs.filter((r) => r.resumo.estado === e).length;
  const soma = (k) => rs.reduce((s, r) => s + (r.resumo[k] || 0), 0);
  pg.append(
    tiles([
      { l: t('Carrinhas com movimento'), v: n0(rs.length), s: t('rotas no dia') },
      { l: t('Por contar'), v: n0(n('por_contar')), tone: n('por_contar') ? 'warn' : null, s: t('técnico do armazém') },
      { l: t('Por aprovar'), v: n0(n('por_aprovar')), tone: n('por_aprovar') ? 'serio' : null, s: t('contadas, com diferenças a justificar') },
      { l: t('Aprovadas'), v: n0(n('aprovada')), s: t('fechadas') },
      { l: t('Faltas'), v: kz(soma('falta_kz')), tone: soma('falta_kz') ? 'bad' : null, s: t('ao preço médio de venda do dia') },
      { l: t('Alertas automáticos'), v: n0(soma('alertas')), tone: soma('alertas') ? 'warn' : null, s: t('artigos assinalados pelo sistema') },
    ]),
  );
  if (CC.movimentos === 'saldo')
    pg.append(
      el(
        'p',
        'note',
        t(
          'Esta base de dados não separa as cargas das devoluções: carregado e devolvido vêm do saldo do dia e «tem de voltar» é o stock da carrinha no sistema.',
        ),
      ),
    );
  if (!rs.length) {
    pg.append(el('div', 'glass empty', t('Nenhuma carrinha com movimento neste dia.')));
    return;
  }
  const lista = el('div', 'gst-lista');
  rs.forEach((r) => {
    const [txt, cls] = ESTADOS[r.resumo.estado] || [r.resumo.estado, 'p-mut'];
    const c = el('article', 'gst-alerta cc-rota g-' + (r.resumo.estado === 'aprovada' ? 'info' : r.resumo.alertas || r.resumo.diferencas ? 'aviso' : 'info'));
    c.dataset.rota = r.rota;
    const h = el('div', 'cc-cab');
    h.append(el('b', null, r.rota + ' · ' + r.promotor), pill(t(txt), cls));
    const det = [t('{n} artigos', { n: n0(r.resumo.artigos) })];
    if (r.resumo.alertas) det.push(t('{n} com alerta automático', { n: n0(r.resumo.alertas) }));
    if (r.resumo.estado !== 'por_contar') det.push(t('{n} diferença(s)', { n: n0(r.resumo.diferencas) }));
    if (r.resumo.falta_kz) det.push(t('falta {v}', { v: kz(r.resumo.falta_kz) }));
    c.append(h, el('p', 'note', det.join(' · ')));
    const abrir = botao(
      aberta === r.rota ? t('Fechar') : r.cega ? t('Contar') : t('Ver'),
      'ghost sm',
      () => ((aberta = aberta === r.rota ? null : r.rota), draw()),
    );
    c.append(abrir);
    if (aberta === r.rota) c.append(r.cega ? formContagem(r) : tabelaRota(r, CC.gere));
    lista.append(c);
  });
  pg.append(secBox(t('Carrinhas do dia'), t('Carregado, vendido e devolvido por artigo; diferenças entre o que tinha de voltar e o que foi contado.'), lista));
};
