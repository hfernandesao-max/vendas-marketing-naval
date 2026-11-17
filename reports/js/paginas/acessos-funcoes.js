// Acessos › Funções: atribuição automática das permissões pelas funções de cada pessoa (RBAC com separação de
// funções; ISO/IEC 27001 A.5.15, A.5.3, A.5.18). O super administrador escolhe as funções da conta e o portal
// calcula as permissões (a maior de cada página entre as funções), recusa combinações proibidas e mantém as contas
// actualizadas quando uma função muda. Ver nucleo/seguranca/funcoes.php.
import { pill, tabela } from '../componentes/blocos.js';
import { botao, campo, entrada, janela, msgErro } from '../componentes/janelas.js';
import { n0 } from '../formatos.js';
import { siglaNivel, t } from '../i18n/i18n.js';
import { el, toast } from '../util.js';
import { nomeRecurso } from './catalogo.js';

/** Conflitos de separação de funções num conjunto (pares de nomes). */
export function conflitosDe(F, codigos) {
  const out = [];
  codigos.forEach((a) =>
    ((F[a] && F[a].conflitos) || []).forEach((b) => {
      if (codigos.includes(b) && a < b) out.push([F[a].nome, F[b].nome]);
    }),
  );
  return out;
}

/** Permissões efectivas de um conjunto de funções (a maior de cada página), como no servidor. */
export function permissoesDe(F, codigos, ordem) {
  const out = {};
  codigos.forEach((c) =>
    Object.entries((F[c] && F[c].permissoes) || {}).forEach(([rec, n]) => {
      if (!out[rec] || (ordem[n] || 0) > (ordem[out[rec]] || 0)) out[rec] = n;
    }),
  );
  return out;
}

/**
 * Bloco «Funções» da janela da conta: caixas por função (base e complementares), com as permissões calculadas e os
 * conflitos ao vivo. mudou(codigos) avisa a janela (para esconder o perfil manual e mostrar as rotas).
 */
export function blocoFuncoes(G, inicial, mudou, fimInicial) {
  const F = G.funcoes || {};
  // funções temporárias: último dia de cada função (substituições, férias); no dia seguinte saem sozinhas
  const fins = {};
  const caixa = el('div', 'campo ac-funcoes');
  caixa.append(el('span', null, t('Funções da pessoa (atribuem as permissões sozinhas)')));
  const marcadas = new Set(inicial || []);
  const resumo = el('p', 'note');
  const aviso = el('p', 'aviso');
  aviso.hidden = true;
  const ler = () => [...marcadas].sort();
  const actualizar = () => {
    const c = ler();
    const perm = permissoesDe(F, c, G.ordem_niveis || {});
    const conf = conflitosDe(F, c);
    resumo.textContent = c.length
      ? t('{n} funções · {p} páginas com acesso · âmbito: {a} · dois passos: {m}', {
          n: n0(c.length),
          p: n0(Object.keys(perm).length),
          a: c.some((x) => F[x] && F[x].por_rota) ? t('só as suas rotas') : t('todas as rotas'),
          m: c.some((x) => F[x] && F[x].dois_passos) ? t('obrigatória') : t('opcional'),
        })
      : t('Sem funções: use o perfil manual.');
    aviso.hidden = !conf.length;
    aviso.textContent = conf.length
      ? t('Separação de funções: a mesma pessoa não pode ter {l}.', { l: conf.map(([a, b]) => '«' + t(a) + '» e «' + t(b) + '»').join('; ') })
      : '';
    mudou(c, conf);
  };
  [
    ['base', t('Funções principais')],
    ['complementar', t('Funções de aprovação e controlo')],
  ].forEach(([tipo, titulo]) => {
    const lista = Object.entries(F).filter(([, f]) => f.activo && (f.tipo || 'complementar') === tipo);
    if (!lista.length) return;
    caixa.append(el('b', 'ac-funcoes-t', titulo));
    const g = el('div', 'chips-rotas');
    lista.forEach(([c, f]) => {
      const l = el('label');
      const i = entrada('checkbox');
      i.value = c;
      i.checked = marcadas.has(c);
      const fim = entrada('date', (fimInicial || {})[c] || '');
      fim.className = 'inp ac-fim';
      fim.setAttribute('aria-label', t('Último dia') + ' · ' + t(f.nome));
      fim.title = t('Último dia (opcional, para acessos temporários)');
      fim.hidden = !i.checked;
      fins[c] = fim;
      i.addEventListener('change', () => {
        if (i.checked) marcadas.add(c);
        else marcadas.delete(c);
        fim.hidden = !i.checked;
        actualizar();
      });
      l.title = t(f.descricao || '');
      l.append(i, document.createTextNode(t(f.nome)), fim);
      g.append(l);
    });
    caixa.append(g);
  });
  caixa.append(el('p', 'note', t('Para um acesso temporário, indique o último dia junto da função: no dia seguinte sai da conta sozinha.')), resumo, aviso);
  setTimeout(actualizar, 0);
  const lerFim = () => {
    const out = {};
    ler().forEach((c) => fins[c] && fins[c].value && (out[c] = fins[c].value));
    return out;
  };
  return { el: caixa, ler, lerFim };
}

/** Secção «Funções» da página Acessos: o catálogo, com as contas de cada função; gerir só o super administrador. */
export function seccaoFuncoes(G, gerir, pedirGestao, recarregar) {
  const F = G.funcoes || {};
  const contas = {};
  (G.utilizadores || []).forEach((u) => (u.funcoes || []).forEach((c) => (contas[c] = (contas[c] || 0) + 1)));
  const sec = tabela({
    id: 'ac-f',
    titulo: t('Funções'),
    nota: t(
      'Diga as funções de cada pessoa (em Utilizadores › Gerir) e o portal atribui as permissões sozinho: a maior de cada página entre as funções, só o necessário (menor privilégio). Combinações proibidas (ex.: lançar e aprovar a mesma caixa) são recusadas. Ao alterar uma função, todas as contas com ela ficam actualizadas. O dono de cada função autoriza os pedidos de acesso e revê, por trimestre, quem a tem.',
    ),
    sort: 'ordem',
    dir: 1,
    total: false,
    search: false,
    rows: Object.entries(F).map(([c, f], i) => ({
      chave: c,
      ordem: i,
      codigo: c,
      nome: t(f.nome),
      descricao: t(f.descricao || ''),
      tipo: (f.tipo || 'complementar') === 'base' ? t('Principal') : t('Aprovação e controlo'),
      paginas: Object.keys(f.permissoes || {}).length,
      conflitos: (f.conflitos || []).map((x) => (F[x] ? t(F[x].nome) : x)).join(' · ') || '—',
      dono: f.dono ? nomeConta(G, f.dono) : '—',
      contas: contas[c] || 0,
      activo: f.activo,
    })),
    cols: [
      { key: 'nome', label: t('Função'), p: 0, wrap: true },
      { key: 'descricao', label: t('O que faz'), wrap: true, p: 3 },
      { key: 'tipo', label: t('Tipo'), p: 4 },
      { key: 'paginas', label: t('Páginas'), type: 'n', p: 4 },
      { key: 'conflitos', label: t('Não pode acumular com'), wrap: true, p: 2 },
      { key: 'dono', label: t('Dono (autoriza e revê)'), wrap: true, p: 2 },
      { key: 'contas', label: t('Contas'), type: 'n' },
      {
        key: 'activo',
        label: t('Estado'),
        render: (v, r) => {
          const w = el('span');
          w.append(pill(v ? t('Activa') : t('Inactiva'), v ? 'p-ok' : 'p-warn'));
          if (gerir) {
            const b = el('button', 'linkbtn', t('Gerir'));
            b.type = 'button';
            b.setAttribute('aria-label', t('Gerir') + ' ' + r.nome);
            b.addEventListener('click', () => janelaFuncao(G, r.codigo, pedirGestao, recarregar));
            w.append(' ', b);
          }
          return w;
        },
        xl: (r) => (r.activo ? t('Activa') : t('Inactiva')),
      },
    ],
  });
  if (gerir) sec.querySelector('.sec-tools').prepend(botao(t('Nova função'), 'sm', () => janelaFuncao(G, null, pedirGestao, recarregar)));
  return sec;
}

/** Janela para criar ou alterar uma função: nome, descrição, permissões por página, âmbito, dois passos e conflitos. */
function janelaFuncao(G, codigo, pedirGestao, recarregar) {
  const F = G.funcoes || {};
  const f = codigo ? F[codigo] : { nome: '', descricao: '', permissoes: {}, por_rota: false, dois_passos: false, conflitos: [], activo: true };
  janela(codigo ? t(f.nome) : t('Nova função'), t('Só as permissões que a pessoa precisa para esta função (menor privilégio).'), (b, ft, fechar) => {
    const iCod = entrada('text', codigo || '', { placeholder: t('ex.: TESOURARIA'), autocapitalize: 'characters' });
    if (codigo) iCod.disabled = true;
    const iNome = entrada('text', t(f.nome), { maxLength: 80 });
    const iDesc = entrada('text', t(f.descricao || ''), { maxLength: 200 });
    const cRota = entrada('checkbox');
    cRota.checked = !!f.por_rota;
    const cMfa = entrada('checkbox');
    cMfa.checked = !!f.dois_passos;
    const cAct = entrada('checkbox');
    cAct.checked = f.activo !== false;
    const lRota = el('label', 'interruptor');
    lRota.append(cRota, document.createTextNode(t('Só as suas rotas')));
    const lMfa = el('label', 'interruptor');
    lMfa.append(cMfa, document.createTextNode(t('Dois passos obrigatórios')));
    const lAct = el('label', 'interruptor');
    lAct.append(cAct, document.createTextNode(t('Função activa')));
    // permissões: uma linha por página com os níveis que essa página admite
    const tab = el('table', 'cc-tab ac-perm');
    const cab = el('tr');
    cab.append(el('th', null, t('Página ou função')), el('th', null, t('Nível')));
    tab.append(cab);
    const sel = {};
    Object.keys(G.matriz || {}).forEach((rec) => {
      const tr = el('tr');
      const s = el('select');
      s.setAttribute('aria-label', t('Nível') + ' · ' + t(nomeRecurso(rec)));
      s.append(new Option('—', ''));
      (G.niveis[rec] || []).filter((n) => n && n !== 'A').forEach((n) => s.append(new Option(siglaNivel(n), n)));
      s.value = (f.permissoes || {})[rec] || '';
      sel[rec] = s;
      tr.append(el('td', null, t(nomeRecurso(rec))), el('td', null));
      tr.lastChild.append(s);
      tab.append(tr);
    });
    const w = el('div', 'cc-scroll ac-perm-w');
    w.append(tab);
    // conflitos de separação de funções
    const conf = el('div', 'chips-rotas');
    const sistema = f.conflitos_sistema || [];
    Object.entries(F)
      .filter(([c]) => c !== codigo)
      .forEach(([c, x]) => {
        const l = el('label');
        const i = entrada('checkbox');
        i.value = c;
        i.checked = (f.conflitos || []).includes(c) || sistema.includes(c);
        // regra do sistema: não se retira
        if (sistema.includes(c)) {
          i.disabled = true;
          l.title = t('Regra do sistema (separação de funções): não se retira.');
        }
        l.append(i, document.createTextNode(t(x.nome)));
        conf.append(l);
      });
    const sDono = el('select', 'inp');
    sDono.append(new Option(t('Sem dono (autoriza o super administrador)'), ''));
    (G.utilizadores || [])
      .filter((u) => u.activo)
      .sort((a, b) => String(a.nome).localeCompare(String(b.nome)))
      .forEach((u) => sDono.append(new Option(u.nome + ' (' + u.login + ')', u.login)));
    sDono.value = f.dono || '';
    const erro = msgErro();
    b.append(
      campo(t('Código'), iCod),
      campo(t('Nome'), iNome),
      campo(t('O que faz'), iDesc),
      campo(t('Dono da função (autoriza os pedidos e revê quem a tem)'), sDono),
      lRota,
      lMfa,
      lAct,
      el('b', 'ac-funcoes-t', t('Permissões')),
      w,
      el('b', 'ac-funcoes-t', t('Não pode acumular com (separação de funções)')),
      conf,
      erro,
    );
    ft.append(
      botao(t('Cancelar'), 'ghost', fechar),
      botao(t('Guardar'), null, () => {
        const permissoes = {};
        Object.entries(sel).forEach(([rec, s]) => s.value && (permissoes[rec] = s.value));
        pedirGestao({
          accao: 'guardar_funcao',
          codigo: codigo || iCod.value.trim().toUpperCase(),
          nome: iNome.value,
          descricao: iDesc.value,
          permissoes,
          por_rota: cRota.checked,
          dois_passos: cMfa.checked,
          activo: cAct.checked,
          conflitos: [...conf.querySelectorAll('input:checked')].map((i) => i.value),
          dono: sDono.value,
        }).then((d) => {
          if (d && d.cancelado) return;
          if (!d || !d.ok) {
            erro.hidden = false;
            erro.textContent = t((d && d.erro) || 'Não foi possível gravar.');
            return;
          }
          fechar();
          toast(t('Função gravada; {n} conta(s) actualizada(s).', { n: n0(d.contas || 0) }), 5000);
          recarregar();
        });
      }),
    );
  });
}

const nomeConta = (G, login) => {
  const u = (G.utilizadores || []).find((x) => x.login === login);
  return u ? u.nome : login;
};

/**
 * Secção «Governação dos acessos» (só o super administrador): contas com combinações que deixaram de ser permitidas,
 * contas ainda com perfil manual (passar para funções sem mudar acessos) e pedidos de acesso autorizados por aplicar.
 */
export function seccaoGovernanca(G, pedirGestao, recarregar) {
  const sec = el('section', 'glass sec ac-gov');
  sec.append(el('h2', null, t('Governação dos acessos')));
  const conflito = G.contas_conflito || [];
  const sem = G.sem_funcoes || [];
  const pedidos = G.pedidos_acesso || [];
  if (!conflito.length && !sem.length && !pedidos.length) {
    sec.append(el('p', 'note', t('Todas as contas usam funções, sem combinações proibidas, e não há pedidos de acesso por aplicar.')));
    return sec;
  }
  if (conflito.length) {
    sec.append(el('h3', null, t('Contas com funções que não podem acumular')));
    sec.append(
      el(
        'p',
        'aviso',
        t(
          'Regra de separação de funções em vigor: retire uma das funções em Utilizadores › Gerir. Até lá, cada operação continua a recusar a mesma pessoa em dois passos.',
        ),
      ),
    );
    const ul = el('ul', 'ac-gov-l');
    conflito.forEach((x) => ul.append(el('li', null, x.nome + ' (' + x.login + '): «' + t(x.nomes[0]) + '» e «' + t(x.nomes[1]) + '»')));
    sec.append(ul);
  }
  if (sem.length) {
    sec.append(el('h3', null, t('Contas sem funções (perfil manual)')));
    sec.append(
      el(
        'p',
        'note',
        t(
          'Passam para a função equivalente ao perfil. Só as que ficam com os mesmos acessos passam sozinhas; nas outras, ajuste primeiro a função ou atribua as funções à mão.',
        ),
      ),
    );
    const tb = el('table', 'cc-tab');
    const cab = el('tr');
    [t('Conta'), t('Perfil'), t('Função equivalente'), t('O que muda')].forEach((x) => cab.append(el('th', null, x)));
    tb.append(cab);
    const F = G.funcoes || {};
    sem.forEach((x) => {
      const tr = el('tr');
      const muda = !x.funcao
        ? t('Sem função equivalente: atribua à mão')
        : x.diferencas.length
          ? x.diferencas.map((d) => t(nomeRecurso(d.pagina)) + ': ' + (siglaNivel(d.actual) || '—') + ' → ' + (siglaNivel(d.funcao) || '—')).join(' · ')
          : t('Nada (mesmos acessos)');
      tr.append(
        el('td', null, x.nome + ' (' + x.login + ')'),
        el('td', null, t(x.perfil_nome)),
        el('td', null, x.funcao ? t((F[x.funcao] || {}).nome || x.funcao) : '—'),
        el('td', null, muda),
      );
      tb.append(tr);
    });
    const w = el('div', 'cc-scroll');
    w.append(tb);
    sec.append(w);
    const prontas = sem.filter((x) => x.funcao && !x.diferencas.length).map((x) => x.login);
    if (prontas.length) {
      const erro = msgErro();
      const b = botao(t('Passar {n} conta(s) para funções', { n: n0(prontas.length) }), 'sm', () => {
        b.disabled = true;
        pedirGestao({ accao: 'converter_funcoes', contas: prontas }).then((d) => {
          b.disabled = false;
          if (d && d.cancelado) return;
          if (!d || !d.ok) {
            erro.hidden = false;
            erro.textContent = t((d && d.erro) || 'Não foi possível gravar.');
            return;
          }
          toast(t('{n} conta(s) passaram para funções.', { n: n0(d.convertidas.length) }), 5000);
          recarregar();
        });
      });
      sec.append(b, erro);
    }
  }
  if (pedidos.length) {
    sec.append(el('h3', null, t('Pedidos de acesso autorizados por aplicar')));
    const ul = el('ul', 'ac-gov-l');
    pedidos.forEach((p) => {
      const li = el('li');
      li.append(
        el(
          'span',
          null,
          p.id +
            ' · ' +
            p.conta_nome +
            ': ' +
            p.funcoes.map((f) => t(f.nome)).join(', ') +
            (p.fim ? ' · ' + t('até {d}', { d: p.fim.split('-').reverse().join('-') }) : '') +
            ' · ' +
            t('autorizado por {p}', { p: [...new Set(p.funcoes.map((f) => f.aprovado_por))].join(', ') }),
        ),
      );
      const erro = msgErro();
      if (p.posso_aplicar) {
        const b = botao(t('Aplicar'), 'sm', () => {
          b.disabled = true;
          pedirGestao({ accao: 'aplicar_pedido_acesso', id: p.id }).then((d) => {
            b.disabled = false;
            if (d && d.cancelado) return;
            if (!d || !d.ok) {
              erro.hidden = false;
              erro.textContent = t((d && d.erro) || 'Não foi possível gravar.');
              return;
            }
            toast(t('Pedido {id} aplicado.', { id: p.id }), 5000);
            recarregar();
          });
        });
        b.setAttribute('aria-label', t('Aplicar') + ' ' + p.id);
        li.append(' ', b);
      } else li.append(' ', el('span', 'note', t('É para a sua conta: outro super administrador aplica.')));
      li.append(erro);
      ul.append(li);
    });
    sec.append(ul);
  }
  return sec;
}
