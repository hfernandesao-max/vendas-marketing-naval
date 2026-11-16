// Administração › Suporte (ISO/IEC 20000-1 e ITIL; incidentes de segurança pela ISO/IEC 27035; não conformidades pela
// ISO/IEC 27001, 10.2, com causa e acção correctiva obrigatórias): cada pessoa abre e
// segue os seus pedidos; o administrador da plataforma gere todos. Prioridade com prazo de resolução, histórico,
// causa e lições aprendidas nos incidentes graves e avaliação de 1 a 5 no fim. Servidor: nucleo/api/v1/suporte.php.
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { pill, tabela, tiles } from '../componentes/blocos.js';
import { botao, campo, entrada, janela, msgErro } from '../componentes/janelas.js';
import { n0 } from '../formatos.js';
import { LANG, t } from '../i18n/i18n.js';
import { anterior, atual } from '../perfil.js';
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

/** Prioridade pela matriz impacto × urgência (a mesma regra do servidor). */
const prioridadeDe = (tipo, impacto, urgencia) => {
  let p = (SUP.matriz[impacto] || {})[urgencia] || 'P3';
  if (tipo === 'seguranca' && p > 'P2') p = 'P2';
  if (tipo === 'melhoria') p = 'P4';
  return p;
};
const horas = (h) => (h < 1 ? Math.round(h * 60) + ' min' : String(h).replace('.', ',') + ' h');

/** Lê a imagem escolhida como base64 (PNG, JPEG ou WebP, até 5 MB). */
const lerImagem = (f) =>
  new Promise((ok, falha) => {
    if (!f) return ok(null);
    if (!/^image\/(png|jpeg|webp)$/.test(f.type)) return falha(new Error(t('A captura tem de ser uma imagem PNG, JPEG ou WebP.')));
    if (f.size > 5242880) return falha(new Error(t('A imagem tem no máximo 5 MB.')));
    const r = new FileReader();
    r.onload = () => ok({ nome: f.name, dados: String(r.result).split(',')[1] || '' });
    r.onerror = () => falha(new Error(t('Não foi possível ler a imagem.')));
    r.readAsDataURL(f);
  });

function novo() {
  janela(
    t('Novo pedido de suporte'),
    t(
      'Descreva o que precisa ou o que não funciona. O pedido segue para a equipa de TI com a página, a versão do portal e o navegador; um incidente de segurança vai também ao super administrador.',
    ),
    (b, ft, fechar) => {
      const tipo = seletor(SUP.tipos, 'incidente');
      const servico = seletor(SUP.servicos, 'portal');
      const impacto = seletor(SUP.impactos, 'baixo');
      const urgencia = seletor(SUP.urgencias, 'media');
      const prio = el('p', 'sup-prio');
      prio.setAttribute('aria-live', 'polite');
      const mostrarPrio = () => {
        const p = prioridadeDe(tipo.value, impacto.value, urgencia.value);
        prio.textContent = t('Prioridade {p}: primeira resposta até {r} e resolução até {s}.', {
          p: t(PRIO[p]),
          r: horas(SUP.resposta[p]),
          s: horas(SUP.prioridades[p]),
        });
      };
      [tipo, impacto, urgencia].forEach((x) => x.addEventListener('change', mostrarPrio));
      mostrarPrio();
      const titulo = entrada('text', '', { maxLength: 120, placeholder: t('Ex.: o telemóvel da rota 1005 não sincroniza') });
      const desc = el('textarea', 'inp');
      desc.rows = 5;
      desc.maxLength = 5000;
      const img = el('input', 'inp');
      img.type = 'file';
      img.accept = 'image/png,image/jpeg,image/webp';
      const dp = el('input');
      dp.type = 'checkbox';
      const dpl = el('label', 'sup-check');
      dpl.append(dp, el('span', null, t('Envolve dados pessoais (clientes, vendedores, imagens)')));
      const erro = msgErro();
      b.append(
        campo(t('Tipo'), tipo),
        campo(t('Serviço afectado'), servico),
        campo(t('Quem é afectado (impacto)'), impacto),
        campo(t('Quanto pode esperar (urgência)'), urgencia),
        prio,
        campo(t('Título'), titulo),
        campo(t('Descrição'), desc),
        campo(t('Captura de ecrã (opcional, até 5 MB)'), img),
        dpl,
        erro,
      );
      ft.append(botao(t('Cancelar'), 'ghost', fechar));
      const go = botao(t('Abrir o pedido'), '', () => {
        erro.hidden = true;
        go.disabled = true;
        lerImagem(img.files && img.files[0])
          .then((anexo) =>
            enviar(
              {
                accao: 'criar',
                tipo: tipo.value,
                servico: servico.value,
                impacto: impacto.value,
                urgencia: urgencia.value,
                titulo: titulo.value.trim(),
                descricao: desc.value.trim(),
                dados_pessoais: dp.checked,
                contexto: { pagina: anterior && anterior !== 'suporte' ? anterior : '', ecra: screen.width + 'x' + screen.height, idioma: LANG },
                ...(anexo ? { anexo } : {}),
              },
              erro,
              fechar,
              'Pedido {id} aberto.',
            ),
          )
          .catch((e) => {
            erro.textContent = e.message;
            erro.hidden = false;
          })
          .finally(() => (go.disabled = false));
      });
      ft.append(go);
    },
  );
}

const ENC = {
  enviado: ['Enviado à equipa de TI', 'p-ok'],
  por_enviar: ['Por enviar à equipa de TI', 'p-warn'],
  falhou: ['Envio à equipa de TI falhou', 'p-bad'],
  desligado: ['Envio à equipa de TI desligado', 'p-mut'],
};

function abrirPedido(p) {
  const sub =
    t(SUP.tipos[p.tipo]) +
    (p.servico ? ' · ' + t(SUP.servicos[p.servico] || p.servico) : '') +
    ' · ' +
    t(PRIO[p.prioridade]) +
    (p.prazo_resposta && !p.respondido_em ? ' · ' + t('responder até {d}', { d: dh(p.prazo_resposta) }) : '') +
    ' · ' +
    t('resolver até {d}', { d: dh(p.prazo) });
  janela(p.id + ' · ' + p.titulo, sub, (b, ft, fechar) => {
    const [rot, cls] = ESTADOS[p.estado] || [p.estado, 'p-mut'];
    const cab = el('div', 'sup-cab');
    cab.append(pill(t(rot), cls));
    if (p.fora_prazo) cab.append(pill(t('Fora do prazo'), 'p-bad'));
    if (p.resposta_fora_prazo) cab.append(pill(t('Primeira resposta fora do prazo'), 'p-warn'));
    if (p.dados_pessoais) cab.append(pill(t('Dados pessoais'), 'p-warn'));
    const enc = p.encaminhamento && ENC[p.encaminhamento.estado];
    if (enc) cab.append(pill(t(enc[0]), enc[1]));
    b.append(cab);
    if (p.impacto && p.urgencia)
      b.append(el('p', 'sup-res', t('Impacto') + ': ' + t(SUP.impactos[p.impacto]) + ' · ' + t('Urgência') + ': ' + t(SUP.urgencias[p.urgencia])));
    if (SUP.gere && p.encaminhamento && p.encaminhamento.erro) b.append(el('p', 'aviso', t('Último erro do envio') + ': ' + p.encaminhamento.erro));
    if (SUP.gere && p.encaminhamento && p.encaminhamento.estado !== 'enviado')
      ft.append(botao(t('Enviar à equipa de TI'), 'ghost', () => enviar({ accao: 'reenviar', id: p.id }, erroEnc, fechar, '{id} enviado de novo.')));
    const erroEnc = msgErro();
    b.append(erroEnc);
    if (p.anexos && p.anexos.length) {
      const a = el('a', 'lnk', t('Ver a captura de ecrã'));
      a.href = '../api/index.php/v1/suporte?anexo=' + encodeURIComponent(p.id);
      a.target = '_blank';
      a.rel = 'noopener';
      b.append(el('p', null), a);
    }
    if (SUP.gere && p.contexto) {
      const c = p.contexto;
      const l = [c.pagina && t('Página') + ': ' + c.pagina, c.versao && t('Versão') + ': ' + c.versao, c.ecra && t('Ecrã') + ': ' + c.ecra, c.navegador].filter(
        Boolean,
      );
      if (l.length) b.append(el('p', 'sup-ctx', l.join(' · ')));
    }
    if (p.descricao) b.append(el('p', 'sup-desc', p.descricao));
    if (p.resolucao) b.append(el('p', 'sup-res', t('Resolução') + ': ' + p.resolucao));
    if (p.causa) b.append(el('p', 'sup-res', t('Causa') + ': ' + p.causa));
    if (p.licoes) b.append(el('p', 'sup-res', t('Lições aprendidas') + ': ' + p.licoes));
    if (p.correctiva) b.append(el('p', 'sup-res', t('Acção correctiva') + ': ' + p.correctiva));
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
      const correctiva = entrada('text', '', { maxLength: 2000 });
      if (grave) b.append(campo(t('Causa'), causa), campo(t('Lições aprendidas'), licoes));
      if (p.tipo === 'nao_conformidade') b.append(campo(t('Causa'), causa), campo(t('Acção correctiva'), correctiva));
      ft.append(
        botao(t('Resolver'), '', () =>
          enviar(
            corpo('resolver', { causa: causa.value.trim(), licoes: licoes.value.trim(), correctiva: correctiva.value.trim() }),
            erro,
            fechar,
            '{id} resolvido.',
          ),
        ),
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
  if (SUP.gere && SUP.encaminhamento) barra.append(botao(t('Encaminhamento para a equipa de TI'), 'ghost', configurarEncaminhamento));
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
        { key: 'servico', label: t('Serviço'), get: (r) => (r.servico ? t(SUP.servicos[r.servico] || r.servico) : '—') },
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

/**
 * Encaminhamento para a equipa de TI (quem gere o suporte): endereço, identificador do assunto e, com o «E-mail Command»
 * do ServiceDesk Plus, os nomes que a ferramenta usa para o tipo, a categoria, a prioridade, o impacto e a urgência.
 */
function configurarEncaminhamento() {
  const c = SUP.encaminhamento;
  janela(
    t('Encaminhamento para a equipa de TI'),
    t(
      'Cada pedido novo segue por correio para a ferramenta de pedidos da equipa de TI. Os nomes têm de ser exactamente os da ferramenta (ServiceDesk Plus › Administração › E-mail Command).',
    ),
    (b, ft, fechar) => {
      const caixa = (rot, v) => {
        const i = el('input');
        i.type = 'checkbox';
        i.checked = !!v;
        const l = el('label', 'sup-check');
        l.append(i, el('span', null, rot));
        return [l, i];
      };
      const [lAct, iAct] = caixa(t('Enviar cada pedido novo à equipa de TI'), c.activo);
      const dest = entrada('email', c.destinatario, { maxLength: 120 });
      const ident = entrada('text', c.identificador, { maxLength: 40 });
      const [lConf, iConf] = caixa(t('Enviar a confirmação a quem abriu o pedido'), c.confirmar_requerente);
      const [lCmd, iCmd] = caixa(t('Preencher os campos na ferramenta («E-mail Command» do ServiceDesk Plus)'), c.comando.activo);
      const delim = entrada('text', c.comando.delimitador, { maxLength: 2 });
      const [lReq, iReq] = caixa(t('Quem abriu o pedido fica como requerente'), c.comando.requerente);
      const grupo = entrada('text', c.comando.grupo, { maxLength: 80 });
      b.append(
        lAct,
        campo(t('Endereço da equipa de TI'), dest),
        campo(t('Identificador no assunto'), ident),
        lConf,
        lCmd,
        campo(t('Delimitador'), delim),
        lReq,
        campo(t('Grupo (opcional)'), grupo),
      );
      const mapas = {};
      const grupos = [
        ['tipos', t('Tipo do pedido na ferramenta'), SUP.tipos],
        ['categorias', t('Categoria na ferramenta (por serviço afectado)'), SUP.servicos],
        ['prioridades', t('Prioridade na ferramenta'), PRIO],
        ['impactos', t('Impacto na ferramenta'), SUP.impactos],
        ['urgencias', t('Urgência na ferramenta'), SUP.urgencias],
      ];
      for (const [k, titulo, nomes] of grupos) {
        const d = el('details', 'sup-mapa');
        d.append(el('summary', null, titulo));
        mapas[k] = {};
        Object.keys(c.comando[k]).forEach((x) => {
          mapas[k][x] = entrada('text', c.comando[k][x], { maxLength: 80 });
          d.append(campo(t(nomes[x] || x), mapas[k][x]));
        });
        b.append(d);
      }
      if (c.alterado_em) b.append(el('p', 'sup-ctx', t('Alterado em {d} por {p}', { d: dh(c.alterado_em), p: c.alterado_por })));
      const erro = msgErro();
      b.append(erro);
      ft.append(botao(t('Cancelar'), 'ghost', fechar));
      const go = botao(t('Gravar'), '', () => {
        const comando = { activo: iCmd.checked, delimitador: delim.value.trim(), requerente: iReq.checked, grupo: grupo.value.trim() };
        Object.keys(mapas).forEach((k) => {
          comando[k] = {};
          Object.keys(mapas[k]).forEach((x) => (comando[k][x] = mapas[k][x].value.trim()));
        });
        postJson('suporte', {
          accao: 'encaminhamento',
          activo: iAct.checked,
          destinatario: dest.value.trim(),
          identificador: ident.value.trim(),
          confirmar_requerente: iConf.checked,
          comando,
        }).then((d) => {
          if (!d || !d.ok) {
            erro.textContent = t((d && d.erro) || 'Sem resposta do servidor.');
            erro.hidden = false;
            return;
          }
          SUP = d;
          fechar();
          toast(t('Encaminhamento gravado.'), 4000);
          draw();
        });
      });
      ft.append(go);
    },
  );
}
