// Administração › Suporte (ISO/IEC 20000-1 e ITIL; incidentes de segurança pela ISO/IEC 27035): cada pessoa abre e
// segue os seus pedidos; o administrador da plataforma gere todos. Prioridade com prazo de resolução, histórico,
// causa e lições aprendidas nos incidentes graves e avaliação de 1 a 5 no fim. Servidor: nucleo/api/v1/suporte.php.
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { pill, tabela, tiles } from '../componentes/blocos.js';
import { botao, campo, entrada, janela, msgErro } from '../componentes/janelas.js';
import { n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { atual } from '../perfil.js';
import { postJson } from '../servidor.js';
import { el, toast } from '../util.js';
import { R } from './registo.js';

let SUP = null;
let aLer = false;
const ESTADOS = { novo: ['Novo', 'p-warn'], em_curso: ['Em curso', 'p-serio'], resolvido: ['Resolvido', 'p-ok'], fechado: ['Fechado', 'p-mut'] };
const ACCOES = {
  criado: 'Aberto',
  comentar: 'Comentário',
  atender: 'Atendido',
  prioridade: 'Prioridade',
  resolver: 'Resolvido',
  reabrir: 'Reaberto',
  avaliar: 'Avaliado',
  fechado: 'Fechado',
};
const PRIO = { P1: 'P1 · crítica', P2: 'P2 · alta', P3: 'P3 · média', P4: 'P4 · baixa' };
/** AAAA-MM-DD hh:mm:ss → dd-mm-aaaa hh:mm */
const dh = (s) => {
  const x = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(s || ''));
  return x ? `${x[3]}-${x[2]}-${x[1]} ${x[4]}:${x[5]}` : '—';
};

function ler() {
  if (aLer) return;
  aLer = true;
  apiJson('suporte').then((d) => {
    aLer = false;
    SUP = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os dados.') };
    if (atual === 'suporte') draw();
  });
}

function enviar(corpo, erro, fechar, msg) {
  return postJson('suporte', corpo).then((d) => {
    if (!d || !d.ok) {
      erro.textContent = t((d && d.erro) || 'Sem resposta do servidor.');
      erro.hidden = false;
      return;
    }
    SUP = d;
    fechar();
    if (msg) toast(t(msg, { id: d.pedido.id }), 5000);
    draw();
  });
}

const seletor = (opcoes, valor) => {
  const s = el('select', 'inp');
  Object.entries(opcoes).forEach(([k, v]) => {
    const o = el('option', null, t(v));
    o.value = k;
    if (k === valor) o.selected = true;
    s.append(o);
  });
  return s;
};

function novo() {
  janela(
    t('Novo pedido de suporte'),
    t('Descreva o que precisa ou o que não funciona. Um incidente de segurança vai logo ao super administrador.'),
    (b, ft, fechar) => {
      const tipo = seletor(SUP.tipos, 'incidente');
      const prio = seletor(PRIO, 'P3');
      const titulo = entrada('text', '', { maxLength: 120, placeholder: t('Ex.: o telemóvel da rota 1005 não sincroniza') });
      const desc = el('textarea', 'inp');
      desc.rows = 5;
      desc.maxLength = 5000;
      const dp = el('input');
      dp.type = 'checkbox';
      const dpl = el('label', 'sup-check');
      dpl.append(dp, el('span', null, t('Envolve dados pessoais (clientes, vendedores, imagens)')));
      const erro = msgErro();
      b.append(campo(t('Tipo'), tipo), campo(t('Prioridade'), prio), campo(t('Título'), titulo), campo(t('Descrição'), desc), dpl, erro);
      ft.append(botao(t('Cancelar'), 'ghost', fechar));
      ft.append(
        botao(t('Abrir o pedido'), '', () =>
          enviar(
            { accao: 'criar', tipo: tipo.value, prioridade: prio.value, titulo: titulo.value.trim(), descricao: desc.value.trim(), dados_pessoais: dp.checked },
            erro,
            fechar,
            'Pedido {id} aberto.',
          ),
        ),
      );
    },
  );
}

function abrirPedido(p) {
  janela(p.id + ' · ' + p.titulo, t(SUP.tipos[p.tipo]) + ' · ' + t(PRIO[p.prioridade]) + ' · ' + t('resolver até {d}', { d: dh(p.prazo) }), (b, ft, fechar) => {
    const [rot, cls] = ESTADOS[p.estado] || [p.estado, 'p-mut'];
    const cab = el('div', 'sup-cab');
    cab.append(pill(t(rot), cls));
    if (p.fora_prazo) cab.append(pill(t('Fora do prazo'), 'p-bad'));
    if (p.dados_pessoais) cab.append(pill(t('Dados pessoais'), 'p-warn'));
    b.append(cab);
    if (p.descricao) b.append(el('p', 'sup-desc', p.descricao));
    if (p.resolucao) b.append(el('p', 'sup-res', t('Resolução') + ': ' + p.resolucao));
    if (p.causa) b.append(el('p', 'sup-res', t('Causa') + ': ' + p.causa));
    if (p.licoes) b.append(el('p', 'sup-res', t('Lições aprendidas') + ': ' + p.licoes));
    const h = el('ol', 'sup-hist');
    p.historico.forEach((x) => h.append(el('li', null, dh(x.em) + ' · ' + x.por + ' · ' + t(ACCOES[x.accao] || x.accao) + (x.texto ? ': ' + x.texto : ''))));
    b.append(el('h3', null, t('Histórico')), h);
    const texto = el('textarea', 'inp');
    texto.rows = 3;
    texto.maxLength = 5000;
    texto.setAttribute('aria-label', t('Texto'));
    const erro = msgErro();
    b.append(campo(t('Comentário ou resolução'), texto), erro);
    const corpo = (accao, extra) => ({ accao, id: p.id, texto: texto.value.trim(), ...(extra || {}) });
    ft.append(botao(t('Fechar'), 'ghost', fechar));
    if (p.estado !== 'fechado') ft.append(botao(t('Comentar'), 'ghost', () => enviar(corpo('comentar'), erro, fechar, 'Comentário registado em {id}.')));
    if (SUP.gere && p.estado === 'novo') ft.append(botao(t('Atender'), 'ghost', () => enviar(corpo('atender'), erro, fechar, '{id} em curso.')));
    if (SUP.gere && (p.estado === 'novo' || p.estado === 'em_curso')) {
      const grave = p.tipo === 'seguranca' || (p.tipo === 'incidente' && (p.prioridade === 'P1' || p.prioridade === 'P2'));
      const causa = entrada('text', '', { maxLength: 2000 });
      const licoes = entrada('text', '', { maxLength: 2000 });
      if (grave) b.append(campo(t('Causa'), causa), campo(t('Lições aprendidas'), licoes));
      ft.append(
        botao(t('Resolver'), '', () => enviar(corpo('resolver', { causa: causa.value.trim(), licoes: licoes.value.trim() }), erro, fechar, '{id} resolvido.')),
      );
    }
    if (p.estado === 'resolvido' && p.criado_por === SUP.eu) {
      const nota = seletor({ 5: '5 · excelente', 4: '4 · bom', 3: '3 · razoável', 2: '2 · fraco', 1: '1 · mau' }, '5');
      b.append(campo(t('Avaliação do serviço'), nota));
      ft.append(botao(t('Reabrir'), 'ghost', () => enviar(corpo('reabrir'), erro, fechar, '{id} reaberto.')));
      ft.append(botao(t('Avaliar e fechar'), '', () => enviar(corpo('avaliar', { satisfacao: +nota.value }), erro, fechar, '{id} fechado. Obrigado.')));
    }
  });
}

R.suporte = (pg) => {
  if (!SUP) {
    pg.append(el('div', 'glass empty', t('A carregar…')));
    ler();
    return;
  }
  if (SUP.falhou) {
    pg.append(el('div', 'glass empty', t(SUP.falhou)));
    return;
  }
  const k = SUP.indicadores;
  const barra = el('section', 'glass sec sup-barra');
  barra.append(botao(t('Novo pedido'), '', novo));
  pg.append(barra);
  pg.append(
    tiles([
      { l: t('Abertos'), v: n0(k.abertos), tone: k.abertos ? 'warn' : null },
      { l: t('Fora do prazo'), v: n0(k.fora_prazo), tone: k.fora_prazo ? 'bad' : null },
      { l: t('Resolvidos no prazo (30 dias)'), v: k.no_prazo_pct == null ? '—' : k.no_prazo_pct + '%' },
      { l: t('Tempo médio de resolução'), v: k.horas_medias == null ? '—' : String(k.horas_medias).replace('.', ',') + ' h' },
      { l: t('Satisfação'), v: k.satisfacao == null ? '—' : String(k.satisfacao).replace('.', ',') + ' / 5' },
      { l: t('Incidentes de segurança abertos'), v: n0(k.seguranca_abertos), tone: k.seguranca_abertos ? 'bad' : null },
    ]),
  );
  pg.append(
    tabela({
      id: 'sup-l',
      titulo: t(SUP.gere ? 'Pedidos de suporte' : 'Os meus pedidos'),
      nota: t('Clique num pedido para ver o histórico, comentar ou avaliar.'),
      rows: SUP.pedidos,
      sort: 'criado_em',
      dir: -1,
      cols: [
        {
          key: 'id',
          label: t('Pedido'),
          p: 0,
          render: (v, r) => {
            const a = el('button', 'linkbtn', v);
            a.type = 'button';
            a.addEventListener('click', () => abrirPedido(r));
            return a;
          },
        },
        { key: 'titulo', label: t('Título'), wrap: true },
        { key: 'tipo', label: t('Tipo'), get: (r) => t(SUP.tipos[r.tipo]) },
        { key: 'prioridade', label: t('Prioridade') },
        {
          key: 'estado',
          label: t('Estado'),
          render: (v, r) =>
            pill(
              t((ESTADOS[v] || [v])[0]) + (r.fora_prazo && v !== 'fechado' && v !== 'resolvido' ? ' · ' + t('fora do prazo') : ''),
              (ESTADOS[v] || [0, 'p-mut'])[1],
            ),
        },
        { key: 'criado_em', label: t('Aberto em'), get: (r) => r.criado_em, render: (v) => document.createTextNode(dh(v)) },
        ...(SUP.gere ? [{ key: 'criado_nome', label: t('Pedido por') }] : []),
      ],
    }),
  );
};
