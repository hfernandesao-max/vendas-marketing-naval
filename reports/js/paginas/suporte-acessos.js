// Suporte › Pedidos de acesso e revisão de acessos da área (ISO/IEC 27001 A.5.15, A.5.18 e A.5.3): pede-se a função
// com o motivo; o dono da função autoriza (sem dono, o super administrador); o super administrador aplica em Acessos.
// Ninguém autoriza o que pediu nem um acesso para si. Por trimestre, cada dono confirma quem deve manter a sua função.
// Servidor: nucleo/api/v1/pedidos-acesso.php.
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { pill, tabela } from '../componentes/blocos.js';
import { botao, campo, entrada, janela, msgErro } from '../componentes/janelas.js';
import { n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { atual } from '../perfil.js';
import { postJson } from '../servidor.js';
import { el, toast } from '../util.js';

let PA = null;
let aLer = false;
const ESTADOS = {
  pendente: ['Por autorizar', 'p-warn'],
  aprovado: ['Autorizado, por aplicar', 'p-serio'],
  aplicado: ['Aplicado', 'p-ok'],
  recusado: ['Recusado', 'p-bad'],
  anulado: ['Anulado', 'p-mut'],
};
const ACCOES = { pedido: 'Pedido', aprovar: 'Autorizado', recusar: 'Recusado', anular: 'Anulado', aplicar: 'Aplicado' };
const dh = (s) => {
  const x = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(s || ''));
  return x ? `${x[3]}-${x[2]}-${x[1]}` + (x[4] ? ` ${x[4]}:${x[5]}` : '') : '—';
};

function ler() {
  if (aLer) return;
  aLer = true;
  apiJson('pedidos-acesso').then((d) => {
    aLer = false;
    PA = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os dados.') };
    if (atual === 'suporte') draw();
  });
}

function enviar(corpo, erro, fechar, msg) {
  return postJson('pedidos-acesso', corpo).then((d) => {
    if (!d || !d.ok) {
      erro.textContent = t((d && d.erro) || 'Sem resposta do servidor.');
      erro.hidden = false;
      return;
    }
    PA = d;
    fechar();
    if (msg) toast(t(msg, { id: d.id }), 5000);
    draw();
  });
}

const nomeFuncao = (c) => {
  const f = PA.funcoes.find((x) => x.codigo === c);
  return f ? t(f.nome) : c;
};

function novoPedido() {
  janela(
    t('Pedir acesso'),
    t('Escolha a conta e as funções. O dono de cada função autoriza; depois o super administrador aplica. Fica tudo registado.'),
    (b, ft, fechar) => {
      const sConta = el('select', 'inp');
      PA.contas.forEach((c) => sConta.append(new Option(c.nome + ' (' + c.login + ')', c.login)));
      sConta.value = PA.eu;
      const lista = el('div', 'chips-rotas pa-funcoes');
      const aviso = el('p', 'aviso');
      aviso.hidden = true;
      const marcadas = new Set();
      const desenhar = () => {
        lista.textContent = '';
        marcadas.clear();
        const tem = (PA.funcoes_contas || {})[sConta.value] || [];
        PA.funcoes
          .filter((f) => !tem.includes(f.codigo))
          .forEach((f) => {
            const l = el('label');
            const i = entrada('checkbox');
            i.value = f.codigo;
            i.addEventListener('change', () => {
              if (i.checked) marcadas.add(f.codigo);
              else marcadas.delete(f.codigo);
              verificar();
            });
            l.title = t(f.descricao || '');
            l.append(i, document.createTextNode(t(f.nome)));
            lista.append(l);
          });
        if (!lista.childNodes.length) lista.append(el('p', 'note', t('Esta conta já tem todas as funções.')));
        verificar();
      };
      // separação de funções ao vivo: o que a conta já tem mais o que se pede
      const verificar = () => {
        const tem = (PA.funcoes_contas || {})[sConta.value] || [];
        const todas = [...tem, ...marcadas];
        const pares = [];
        PA.funcoes.forEach((f) => {
          if (!todas.includes(f.codigo)) return;
          f.conflitos.forEach((c) => todas.includes(c) && f.codigo < c && pares.push('«' + t(f.nome) + '» e «' + nomeFuncao(c) + '»'));
        });
        aviso.hidden = !pares.length;
        aviso.textContent = pares.length ? t('Separação de funções: a mesma pessoa não pode ter {l}.', { l: pares.join('; ') }) : '';
        if (sConta.value === PA.eu && !tem.length) {
          aviso.hidden = false;
          aviso.textContent = t('Esta conta ainda tem um perfil manual: o super administrador tem de a passar primeiro para funções.');
        }
      };
      sConta.addEventListener('change', desenhar);
      const fim = entrada('date', '');
      const motivo = el('textarea', 'inp');
      motivo.rows = 3;
      motivo.maxLength = 500;
      motivo.placeholder = t('Ex.: substitui a coordenadora da logística durante as férias');
      const erro = msgErro();
      b.append(
        campo(t('Conta'), sConta),
        el('b', 'ac-funcoes-t', t('Funções')),
        lista,
        aviso,
        campo(t('Último dia (opcional, para acessos temporários)'), fim),
        campo(t('Motivo'), motivo),
        erro,
      );
      desenhar();
      ft.append(botao(t('Cancelar'), 'ghost', fechar));
      const go = botao(t('Enviar o pedido'), '', () => {
        erro.hidden = true;
        go.disabled = true;
        enviar(
          { accao: 'criar', conta: sConta.value, funcoes: [...marcadas], fim: fim.value, motivo: motivo.value.trim() },
          erro,
          fechar,
          'Pedido {id} enviado para autorização.',
        ).finally(() => (go.disabled = false));
      });
      ft.append(go);
    },
  );
}

function abrirPedido(p) {
  janela(p.id + ' · ' + p.conta_nome, t('Pedido por {p} em {d}', { p: p.criado_nome, d: dh(p.criado_em) }), (b, ft, fechar) => {
    const [rot, cls] = ESTADOS[p.estado] || [p.estado, 'p-mut'];
    b.append(pill(t(rot), cls));
    b.append(el('p', 'sup-desc', p.motivo));
    if (p.fim) b.append(el('p', 'note', t('Acesso temporário: até {d} (no dia seguinte sai sozinho).', { d: dh(p.fim) })));
    const tb = el('table', 'cc-tab');
    const cab = el('tr');
    [t('Função'), t('Autoriza'), t('Autorizado')].forEach((x) => cab.append(el('th', null, x)));
    tb.append(cab);
    p.funcoes.forEach((f) => {
      const tr = el('tr');
      tr.append(
        el('td', null, t(f.nome)),
        el('td', null, f.sem_dono ? t('Super administrador (função sem dono)') : f.aprovador_nome),
        el('td', null, f.aprovado_por ? f.aprovado_por + ' · ' + dh(f.aprovado_em) : '—'),
      );
      tb.append(tr);
    });
    b.append(tb);
    if (p.recusa) b.append(el('p', 'aviso', t('Recusado por {p}: {n}', { p: p.recusa.por, n: p.recusa.nota })));
    const h = el('ol', 'sup-hist');
    p.historico.forEach((x) => h.append(el('li', null, dh(x.em) + ' · ' + x.por + ' · ' + t(ACCOES[x.accao] || x.accao) + (x.texto ? ': ' + x.texto : ''))));
    b.append(el('h3', null, t('Histórico')), h);
    const nota = el('textarea', 'inp');
    nota.rows = 2;
    nota.maxLength = 500;
    const erro = msgErro();
    if (p.posso_recusar || p.posso_anular) b.append(campo(t('Nota (obrigatória para recusar)'), nota));
    b.append(erro);
    ft.append(botao(t('Fechar'), 'ghost', fechar));
    const corpo = (accao) => ({ accao, id: p.id, nota: nota.value.trim() });
    if (p.posso_anular) ft.append(botao(t('Anular o pedido'), 'ghost', () => enviar(corpo('anular'), erro, fechar, 'Pedido {id} anulado.')));
    if (p.posso_recusar) {
      ft.append(botao(t('Recusar'), 'ghost', () => enviar(corpo('recusar'), erro, fechar, 'Pedido {id} recusado.')));
      ft.append(botao(t('Autorizar'), '', () => enviar(corpo('aprovar'), erro, fechar, 'Pedido {id} autorizado.')));
    }
  });
}

/** O dono confirma a sua parte da revisão trimestral: mantém, ou marca a quem retirar a função. */
function seccaoRevisao(r) {
  const sec = el('section', 'glass sec pa-revisao');
  sec.append(el('h2', null, t('Revisão de acessos da sua área · {t}', { t: r.trimestre })));
  if (r.confirmada) {
    sec.append(
      el(
        'p',
        'note',
        t('Confirmou a sua parte em {d}: {n} acesso(s) revistos, {r} a retirar.', {
          d: dh(r.confirmada.em),
          n: n0(r.confirmada.contas),
          r: n0(r.confirmada.retirar.length),
        }),
      ),
    );
    return sec;
  }
  sec.append(
    el('p', 'note', t('É dono destas funções. Confirme quem deve manter cada uma; marque «Retirar» no que já não é preciso (o super administrador retira).')),
  );
  const tb = el('table', 'cc-tab');
  const cab = el('tr');
  [t('Pessoa'), t('Função'), t('Até'), t('Retirar')].forEach((x) => cab.append(el('th', null, x)));
  tb.append(cab);
  const caixas = [];
  r.contas.forEach((x) => {
    const tr = el('tr');
    const c = entrada('checkbox');
    c.setAttribute('aria-label', t('Retirar') + ' · ' + x.nome + ' · ' + t(x.funcao_nome));
    caixas.push([c, x]);
    const td = el('td');
    td.append(c);
    tr.append(el('td', null, x.nome), el('td', null, t(x.funcao_nome)), el('td', null, x.ate ? dh(x.ate) : '—'), td);
    tb.append(tr);
  });
  const w = el('div', 'cc-scroll');
  w.append(tb);
  const notas = entrada('text', '', { maxLength: 1000, placeholder: t('Notas (obrigatórias se retirar algum acesso)') });
  notas.setAttribute('aria-label', t('Notas'));
  const erro = msgErro();
  const go = botao(t('Confirmar a minha parte'), '', () => {
    go.disabled = true;
    const retirar = caixas.filter(([c]) => c.checked).map(([, x]) => ({ conta: x.conta, funcao: x.funcao }));
    enviar({ accao: 'revisao', retirar, notas: notas.value.trim() }, erro, () => {}, 'Revisão confirmada.').finally(() => (go.disabled = false));
  });
  sec.append(w, notas, erro, go);
  return sec;
}

/** Secções da página Suporte: pedidos de acesso e, para os donos de funções, a revisão do trimestre. */
export function seccoesAcessos(pg) {
  if (!PA) {
    ler();
    return;
  }
  if (PA.falhou) return;
  if (PA.revisao) pg.append(seccaoRevisao(PA.revisao));
  const sec = tabela({
    id: 'pa-l',
    titulo: t('Pedidos de acesso'),
    nota: t(
      'Peça aqui uma função para si ou para outra pessoa. Autoriza o dono da função (sem dono, o super administrador) e o super administrador aplica. Ninguém autoriza o que pediu nem um acesso para si.',
    ),
    rows: PA.pedidos,
    sort: 'criado_em',
    dir: -1,
    total: false,
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
      { key: 'conta_nome', label: t('Para') },
      { key: 'funcoes', label: t('Funções'), wrap: true, get: (r) => r.funcoes.map((f) => t(f.nome)).join(', ') },
      {
        key: 'estado',
        label: t('Estado'),
        get: (r) => t((ESTADOS[r.estado] || [r.estado])[0]),
        render: (v, r) => {
          const w = el('span');
          w.append(pill(t((ESTADOS[r.estado] || [r.estado])[0]), (ESTADOS[r.estado] || [0, 'p-mut'])[1]));
          if (r.posso_recusar) w.append(' ', pill(t('Para si decidir'), 'p-warn'));
          return w;
        },
      },
      { key: 'criado_em', label: t('Pedido em'), render: (v) => document.createTextNode(dh(v)) },
      { key: 'criado_nome', label: t('Pedido por') },
    ],
  });
  sec.querySelector('.sec-tools').prepend(botao(t('Pedir acesso'), 'sm', novoPedido));
  pg.append(sec);
}
