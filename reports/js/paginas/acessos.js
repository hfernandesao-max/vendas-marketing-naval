// Acessos: contas, matriz de acessos e registos (super administrador).
import { draw } from '../app.js';
import { LIM, pill, secBox, tabela, tiles } from '../componentes/blocos.js';
import { botao, campo, confirmarAccao, copiar, entrada, janela, msgErro } from '../componentes/janelas.js';
import { dl, n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { nomeRecurso } from './catalogo.js';
import { exportarEstrutura } from '../estrutura.js';
import { R } from './registo.js';
import { atual, podeExportar } from '../perfil.js';
import { carregarScript, postJson } from '../servidor.js';
import { el, toast } from '../util.js';
import { apiFetch } from '../api.js';

const NOMES_ACCAO = {
  entrada: 'Entrada',
  saida: 'Saída',
  entrada_falhada: 'Entrada falhada',
  entrada_bloqueada: 'Entrada com conta bloqueada',
  entrada_recusada: 'Entrada recusada',
  conta_bloqueada: 'Conta bloqueada',
  conta_desbloqueada: 'Conta desbloqueada',
  desbloqueio_recusado: 'Desbloqueio recusado',
  exportacao: 'Exportação para Excel',
  exportacao_recusada: 'Exportação recusada',
  acesso_negado: 'Acesso negado',
  sessao_terminada: 'Sessão terminada',
  dois_passos_em_falta: 'Entrada sem dois passos',
  conta_criada: 'Conta criada',
  conta_alterada: 'Conta alterada',
  senha_reposta: 'Palavra-passe reposta',
  senha_alterada: 'Palavra-passe alterada',
  dois_passos_configurado: 'Dois passos activados',
  dois_passos_retirado: 'Dois passos retirados',
  matriz_alterada: 'Matriz alterada',
  matriz_reposta: 'Matriz original reposta',
  alteracao_recusada: 'Alteração recusada',
  confirmacao_falhada: 'Confirmação falhada',
  importar_contas_antigas: 'Contas antigas importadas',
  gestao_activada: 'Gestão de contas activada',
  perfil_alterado: 'Perfil alterado',
  perfil_criado: 'Perfil criado',
  perfil_apagado: 'Perfil apagado',
  rotas_alteradas: 'Rotas alteradas',
  email_alterado: 'Email alterado',
  conta_activada: 'Conta activada',
  conta_desactivada: 'Conta desactivada',
};
let GESTAO = null,
  MPEND = {};
function carregarGestao() {
  return apiFetch('gestao')
    .then((r) => r.json())
    .then((d) => {
      GESTAO = d && d.utilizadores ? d : { erro: (d && d.erro) || t('Sem dados') };
    })
    .catch(() => {
      GESTAO = { erro: t('A página Acessos precisa do servidor da plataforma (PHP).') };
    });
}
const recarregarGestao = () => {
  GESTAO = null;
  MPEND = {};
  draw();
};
const NIVEL_SIG = { '': '—', V: 'V', Vr: 'V·r', E: 'E', Er: 'E·r', G: 'G', A: 'A' };

function confirmarIdentidade(metodo, erro) {
  return new Promise((ok) => {
    let feito = false;
    janela(
      t('Confirme a sua identidade'),
      metodo === 'codigo'
        ? t('Insira o código de 6 dígitos da aplicação de autenticação. Fica confirmado durante 10 minutos.')
        : t('Insira a sua palavra-passe. Fica confirmado durante 10 minutos.'),
      (b, ft, fechar, d) => {
        const i =
          metodo === 'codigo'
            ? entrada('text', '', { inputMode: 'numeric', maxLength: 7, autocomplete: 'one-time-code' })
            : entrada('password', '', { autocomplete: 'current-password' });
        b.append(campo(metodo === 'codigo' ? t('Código') : t('Palavra-passe'), i));
        if (erro) {
          const p = msgErro();
          p.textContent = t(erro);
          p.hidden = false;
          b.append(p);
        }
        const ir = () => {
          if (!i.value.trim()) {
            i.focus();
            return;
          }
          feito = true;
          ok(i.value.trim());
          fechar();
        };
        i.addEventListener('keydown', (e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            ir();
          }
        });
        ft.append(botao(t('Cancelar'), 'ghost', fechar), botao(t('Confirmar'), '', ir));
        d.addEventListener('close', () => {
          if (!feito) ok(null);
        });
        setTimeout(() => i.focus(), 50);
      },
    );
  });
}
// Pedido de alteração: pede a confirmação da identidade quando o servidor a exigir
function pedirGestao(corpo, confirmacao) {
  return postJson('gestao', { ...corpo, confirmacao }).then((d) => {
    if (d.sair) {
      toast(t(d.erro), 6000);
      setTimeout(() => {
        location.href = '../geo/login.php?redirect=../reports/';
      }, 1800);
      return { ok: false, cancelado: true };
    }
    if (d.confirmar) return confirmarIdentidade(d.confirmar, d.erro).then((c) => (c == null ? { ok: false, cancelado: true } : pedirGestao(corpo, c)));
    return d;
  });
}
function mostrarSenha(login, senha, mfa) {
  janela(
    t('Palavra-passe temporária'),
    t('Conta {c}. Entregue-a à pessoa por um canal seguro: só aparece agora. Na primeira entrada terá de escolher uma nova.', { c: login }),
    (b, ft, fechar) => {
      b.append(el('div', 'segredo', senha));
      if (mfa) b.append(el('p', 'aviso', t('Este perfil exige verificação em dois passos. Active-a em Gerir, junto da pessoa, com o telemóvel dela.')));
      ft.append(
        botao(t('Copiar'), 'ghost', () => copiar(senha)),
        botao(t('Fechar'), '', fechar),
      );
    },
  );
}
const carregarQr = () => carregarScript('qrcode.js');
function mostrarDoisPassos(login, segredo, uri) {
  janela(
    t('Verificação em dois passos'),
    t(
      'Conta {c}. Na aplicação de autenticação (Microsoft ou Google Authenticator), leia o código QR ou escreva a chave. A partir da próxima entrada é pedido o código de 6 dígitos.',
      { c: login },
    ),
    (b, ft, fechar) => {
      const qr = el('div', 'qr');
      qr.setAttribute('role', 'img');
      qr.setAttribute('aria-label', t('Código QR'));
      b.append(qr, el('div', 'segredo', segredo.replace(/(.{4})/g, '$1 ').trim()));
      carregarQr()
        .then(() => {
          const q = window.qrcode(0, 'M');
          q.addData(uri);
          q.make();
          qr.innerHTML = q.createSvgTag(4, 2); // nosemgrep: insecure-innerhtml, insecure-document-method -- SVG gerado pela biblioteca de QR
        })
        .catch(() => {
          qr.hidden = true;
        });
      ft.append(
        botao(t('Copiar chave'), 'ghost', () => copiar(segredo)),
        botao(t('Fechar'), '', fechar),
      );
    },
  );
}

// ---- conta: criar e alterar ----
function janelaConta(u) {
  const G = GESTAO,
    novo = !u;
  u = u || { login: '', nome: '', email: '', perfil: 'COM', rotas: [], ligada: true, valido_ate: null };
  janela(
    novo ? t('Nova conta') : u.nome,
    novo ? t('A palavra-passe temporária aparece no fim. A pessoa escolhe a sua na primeira entrada.') : u.login + ' · ' + u.perfil + ' · ' + t(u.perfil_nome),
    (b, ft, fechar) => {
      const iNome = entrada('text', u.nome, { autocomplete: 'off' });
      const iLogin = entrada('text', u.login, { autocomplete: 'off', autocapitalize: 'off', spellcheck: false, placeholder: t('ex.: joao.silva') });
      if (!novo) iLogin.disabled = true;
      const iEmail = entrada('email', u.email, { autocomplete: 'off', placeholder: 'nome@naval.co.ao' });
      const sPerfil = el('select');
      Object.entries(G.perfis).forEach(([k, v]) => {
        const o = el('option', null, k + ' · ' + t(v));
        o.value = k;
        sPerfil.append(o);
      });
      sPerfil.value = u.perfil;
      const iAte = entrada('date', u.valido_ate || '');
      const cAct = entrada('checkbox');
      cAct.checked = !!u.ligada;
      const lAct = el('label', 'interruptor');
      lAct.append(cAct, document.createTextNode(t('Conta activa')));
      // rotas (supervisores e promotores)
      const caixaRotas = el('div', 'campo');
      caixaRotas.append(el('span', null, t('Rotas')));
      const chips = el('div', 'chips-rotas'),
        marcadas = new Set((u.rotas || []).map(String));
      const lista = [...G.rotas, ...[...marcadas].filter((r) => !G.rotas.some((x) => x.rota === r)).map((r) => ({ rota: r, promotor: '' }))];
      lista.forEach((r) => {
        const l = el('label');
        const c = entrada('checkbox');
        c.value = r.rota;
        c.checked = marcadas.has(r.rota);
        l.title = r.promotor;
        l.append(c, document.createTextNode(r.rota + (r.promotor ? ' · ' + r.promotor : '')));
        chips.append(l);
      });
      const iOutras = entrada('text', '', { placeholder: t('Outras rotas, separadas por vírgulas') });
      caixaRotas.append(chips, iOutras);
      // aprovações (super administrador)
      const iA1 = entrada('text', '', { placeholder: t('Nome') }),
        iA2 = entrada('text', '', { placeholder: t('Nome') });
      const caixaAprov = el('div', 'campos2');
      caixaAprov.append(campo(t('Aprovado por (1)'), iA1), campo(t('Aprovado por (2)'), iA2));
      const notaAprov = el('p', 'aviso', t('Contas de super administrador: indique as duas pessoas da Administração que aprovaram.'));
      const erro = msgErro();
      const actualizar = () => {
        const p = sPerfil.value;
        caixaRotas.hidden = !G.por_rota.includes(p);
        const precisa = (p === 'SADM' && (novo || u.perfil !== 'SADM' || (cAct.checked && !u.ligada))) || (!novo && u.perfil === 'SADM' && p !== 'SADM');
        caixaAprov.hidden = notaAprov.hidden = !precisa;
      };
      sPerfil.addEventListener('change', actualizar);
      cAct.addEventListener('change', actualizar);
      const l2 = el('div', 'campos2');
      l2.append(campo(t('Utilizador'), iLogin), campo(t('Email (obrigatório)'), iEmail));
      const l3 = el('div', 'campos2');
      l3.append(campo(t('Perfil'), sPerfil), campo(t('Válida até (opcional)'), iAte));
      b.append(campo(t('Nome'), iNome), l2, l3, caixaRotas, lAct, notaAprov, caixaAprov);
      if (!novo) {
        const acc = el('div', 'dlg-accoes');
        acc.append(
          botao(t('Repor palavra-passe'), 'ghost sm', () => {
            fechar();
            confirmarAccao(
              t('Repor palavra-passe'),
              t('É criada uma palavra-passe temporária para {c}. A actual deixa de funcionar.', { c: u.nome }),
              t('Repor'),
              () =>
                pedirGestao({ accao: 'senha', conta: u.login }).then((d) => {
                  if (d.ok) {
                    recarregarGestao();
                    mostrarSenha(u.login, d.senha, false);
                  }
                  return d;
                }),
            );
          }),
        );
        acc.append(
          botao(u.dois_passos ? t('Gerar novo código QR') : t('Activar dois passos'), 'ghost sm', () => {
            fechar();
            confirmarAccao(
              t('Verificação em dois passos'),
              u.dois_passos
                ? t('É criada uma chave nova. A aplicação de autenticação de {c} tem de ser configurada outra vez.', { c: u.nome })
                : t('Faça isto junto da pessoa: ela lê o código QR com o telemóvel.'),
              t('Continuar'),
              () =>
                pedirGestao({ accao: 'dois_passos', conta: u.login, activar: true }).then((d) => {
                  if (d.ok) {
                    recarregarGestao();
                    mostrarDoisPassos(u.login, d.segredo, d.uri);
                  }
                  return d;
                }),
            );
          }),
        );
        if (u.dois_passos && u.login !== G.eu)
          acc.append(
            botao(t('Retirar dois passos'), 'ghost sm', () => {
              fechar();
              confirmarAccao(
                t('Retirar dois passos'),
                t('A conta de {c} passa a entrar só com a palavra-passe.', { c: u.nome }) +
                  (G.perfis_dois_passos.includes(u.perfil) ? ' ' + t('Este perfil exige verificação em dois passos.') : ''),
                t('Retirar'),
                () =>
                  pedirGestao({ accao: 'dois_passos', conta: u.login, activar: false }).then((d) => {
                    if (d.ok) {
                      recarregarGestao();
                      toast(t('Verificação em dois passos retirada.'), 4000);
                    }
                    return d;
                  }),
              );
            }),
          );
        if (u.bloqueado)
          acc.append(
            botao(t('Desbloquear'), 'ghost sm', () =>
              pedirGestao({ accao: 'desbloquear', conta: u.login }).then((d) => {
                if (d.ok) {
                  fechar();
                  recarregarGestao();
                  toast(t('Conta desbloqueada.'), 4000);
                }
              }),
            ),
          );
        b.append(acc);
      }
      b.append(erro);
      actualizar();
      const guardar = botao(novo ? t('Criar conta') : t('Guardar'), '', () => {
        const rotas = [...chips.querySelectorAll('input:checked')]
          .map((c) => c.value)
          .concat(
            iOutras.value
              .split(',')
              .map((s) => s.trim())
              .filter(Boolean),
          );
        const login = novo ? iLogin.value.trim() : u.login;
        guardar.disabled = true;
        erro.hidden = true;
        pedirGestao({
          accao: 'guardar_conta',
          novo,
          conta: login,
          nome: iNome.value.trim(),
          email: iEmail.value.trim(),
          perfil: sPerfil.value,
          rotas,
          valido_ate: iAte.value,
          activo: cAct.checked,
          aprovado_por: caixaAprov.hidden ? [] : [iA1.value, iA2.value],
        }).then((d) => {
          guardar.disabled = false;
          if (d.cancelado) return;
          if (!d.ok) {
            erro.textContent = t(d.erro || 'Não foi possível guardar.');
            erro.hidden = false;
            return;
          }
          fechar();
          recarregarGestao();
          if (d.senha) mostrarSenha(login, d.senha, d.dois_passos_obrigatorio);
          else toast(t('Conta guardada.'), 4000);
        });
      });
      ft.append(botao(t('Cancelar'), 'ghost', fechar), guardar);
      setTimeout(() => (novo ? iNome : iEmail).focus(), 50);
    },
  );
}

R.acessos = (pg) => {
  if (!GESTAO) {
    pg.append(el('div', 'glass empty', t('A carregar…')));
    carregarGestao().then(() => {
      if (atual === 'acessos') draw();
    });
    return;
  }
  if (GESTAO.erro) {
    pg.append(secBox(t('Acessos'), '', el('p', null, GESTAO.erro)));
    return;
  }
  const U = GESTAO.utilizadores,
    obrig = GESTAO.perfis_dois_passos || ['SADM', 'TI', 'DIR'],
    gerir = GESTAO.pode_gerir;
  if (GESTAO.compatibilidade) pg.append(cartaoActivar());
  // Documentação técnica (TI e auditoria): estrutura da base de dados, sem dados de negócio
  if (podeExportar('estrutura-dados')) {
    const acc = el('div', 'ac-accoes');
    const b = botao(t('Dicionário técnico da base de dados'), 'ghost sm', () => exportarEstrutura(b));
    b.id = 'ac-dicionario';
    b.title = t('Excel com as tabelas, colunas, chaves, relações e índices da base de dados do Pinguim BO, o modelo do portal e a matriz de acessos');
    acc.append(b);
    pg.append(acc);
  }
  const estado = (u) => (u.bloqueado ? 'Bloqueada' : !u.email_ok && !u.compatibilidade ? 'Sem email' : u.activo ? 'Activa' : 'Inactiva');
  pg.append(
    tiles([
      { l: t('Contas activas'), v: n0(U.filter((u) => u.activo && !u.bloqueado).length), s: t('{n} contas no total', { n: U.length }) },
      {
        l: t('Contas bloqueadas'),
        v: n0(U.filter((u) => u.bloqueado).length),
        s: t('desbloqueio pelo super administrador'),
        tone: U.some((u) => u.bloqueado) ? 'bad' : null,
      },
      { l: t('Sem email associado'), v: n0(U.filter((u) => !u.email_ok).length), tone: U.some((u) => !u.email_ok) ? 'warn' : null },
      {
        l: t('Sem verificação em dois passos'),
        v: n0(U.filter((u) => obrig.includes(u.perfil) && !u.dois_passos).length),
        s: t('perfis {p}', { p: obrig.join(', ') }),
        tone: U.some((u) => obrig.includes(u.perfil) && !u.dois_passos) ? 'warn' : null,
      },
    ]),
  );
  const cols = [
    { key: 'nome', label: t('Nome'), p: 0 },
    { key: 'login', label: t('Utilizador') },
    { key: 'email', label: t('Email') },
    { key: 'perfil', label: t('Perfil'), render: (v, r) => document.createTextNode(v + ' · ' + t(r.perfil_nome)) },
    { key: 'rotas_txt', label: t('Rotas') },
    { key: 'mfa', label: t('Dois passos') },
    {
      key: 'estado',
      label: t('Estado'),
      render: (v, r) => {
        const w = el('span');
        w.append(pill(t(v), v === 'Activa' ? 'p-ok' : v === 'Bloqueada' ? 'p-bad' : 'p-warn'));
        if (r.senha_temporaria && r.activo) {
          w.append(document.createTextNode(' '));
          w.append(pill(t('Palavra-passe temporária'), 'p-warn'));
        }
        if (r.bloqueado && GESTAO.pode_desbloquear) {
          const b = el('button', 'linkbtn', t('Desbloquear'));
          b.type = 'button';
          b.style.marginLeft = '8px';
          b.addEventListener('click', () =>
            pedirGestao({ accao: 'desbloquear', conta: r.login }).then((d) => {
              if (d.cancelado) return;
              toast(d.ok ? t('Conta desbloqueada.') : d.erro ? t(d.erro) : t('Não foi possível desbloquear.'), 5000);
              recarregarGestao();
            }),
          );
          w.append(b);
        }
        return w;
      },
      xl: (r) => t(r.estado),
    },
    { key: 'ultima_entrada', label: t('Última entrada'), render: (v) => document.createTextNode(v ? dl(v) + ' ' + v.slice(11, 16) : '—') },
  ];
  if (gerir)
    cols.push({
      key: 'gerir',
      label: '',
      p: 0,
      render: (v, r) => {
        const b = el('button', 'linkbtn', t('Gerir'));
        b.type = 'button';
        b.setAttribute('aria-label', t('Gerir') + ' ' + r.nome);
        b.addEventListener('click', () => janelaConta(U.find((x) => x.login === r.login)));
        return b;
      },
      xl: () => '',
    });
  const secU = tabela({
    id: 'ac-u',
    titulo: t('Utilizadores'),
    nota: gerir
      ? t('Contas e perfis. Em Gerir altera o perfil, as rotas, o email e a palavra-passe de cada conta.')
      : t('Contas e perfis. Só o super administrador cria e altera contas.'),
    sort: 'nome',
    dir: 1,
    total: false,
    rows: U.map((u) => ({
      ...u,
      chave: u.login,
      estado: estado(u),
      rotas_txt: u.rotas ? u.rotas.join(', ') || t('nenhuma') : t('Todas'),
      mfa: u.dois_passos ? t('Sim') : t('Não'),
      gerir: '',
    })),
    cols,
  });
  if (gerir) secU.querySelector('.sec-tools').prepend(botao(t('Nova conta'), 'sm', () => janelaConta(null)));
  pg.append(secU);

  // ---- perfis ----
  pg.append(seccaoPerfis(gerir));

  // ---- matriz ----
  const perfis = Object.keys(GESTAO.perfis),
    M = GESTAO.matriz,
    BASE = GESTAO.matriz_base;
  const difBase = Object.keys(M).some((rec) => perfis.some((p) => (M[rec][p] || '') !== (BASE[rec][p] || '')));
  const nPend = () => Object.values(MPEND).reduce((s, x) => s + Object.keys(x).length, 0);
  let bGuardar, bAnular;
  const actualizarBotoes = () => {
    if (!bGuardar) return;
    const n = nPend();
    bGuardar.disabled = !n;
    bGuardar.textContent = n === 1 ? t('Guardar 1 alteração') : n ? t('Guardar {n} alterações', { n }) : t('Guardar alterações');
    bAnular.hidden = !n;
  };
  const celula = (rec, p) => {
    const atual = M[rec][p] || '',
      opts = GESTAO.niveis[rec] || [];
    if (!gerir || p === 'SADM' || !opts.length) return document.createTextNode(NIVEL_SIG[atual]);
    const s = el('select', 'nivel');
    opts.forEach((n) => {
      const o = el('option', null, NIVEL_SIG[n]);
      o.value = n;
      s.append(o);
    });
    const pend = MPEND[rec] && MPEND[rec][p];
    s.value = pend != null ? pend : atual;
    s.setAttribute('aria-label', nomeRecurso(rec) + ' · ' + p);
    const marca = () => {
      s.classList.toggle('pendente', s.value !== atual);
      s.classList.toggle('mudou', s.value === atual && atual !== (BASE[rec][p] || ''));
      s.title =
        s.value !== atual
          ? t('Por guardar')
          : atual !== (BASE[rec][p] || '')
            ? t('Diferente da matriz original ({v})', { v: NIVEL_SIG[BASE[rec][p] || ''] })
            : '';
    };
    s.addEventListener('change', () => {
      if (s.value === atual) {
        if (MPEND[rec]) delete MPEND[rec][p];
      } else (MPEND[rec] = MPEND[rec] || {})[p] = s.value;
      marca();
      actualizarBotoes();
    });
    marca();
    return s;
  };
  LIM['ac-m'] = Math.max(LIM['ac-m'] || 0, 200);
  const secM = tabela({
    id: 'ac-m',
    titulo: t('Matriz de acessos'),
    sort: 'ordem',
    dir: 1,
    total: false,
    search: false,
    nota:
      (gerir
        ? t(
            'Escolha o nível de cada perfil em cada página ou função e guarde. Contorno laranja: por guardar; azul: diferente da matriz original. A coluna SADM e as funções de administração não se alteram.',
          ) + ' '
        : '') + t('V ver · V·r ver só as suas rotas · E exportar · E·r exportar só as suas rotas · G gerir · A administrar.'),
    rows: Object.keys(M).map((rec, i) => {
      const o = { chave: rec, ordem: i, recurso: nomeRecurso(rec) };
      perfis.forEach((p) => {
        o[p] = NIVEL_SIG[M[rec][p] || ''];
      });
      return o;
    }),
    cols: [
      {
        key: 'recurso',
        label: t('Página ou função'),
        p: 0,
        wrap: true,
        get: (r) => r.ordem,
        render: (v, r) => document.createTextNode(r.recurso),
        xl: (r) => r.recurso,
      },
      ...perfis.map((p) => ({
        key: p,
        label: p,
        title: GESTAO.perfis[p],
        p: 0,
        render: (v, r) => celula(r.chave, p),
        xl: (r) => NIVEL_SIG[M[r.chave][p] || ''],
      })),
    ],
  });
  if (gerir) {
    const tools = secM.querySelector('.sec-tools');
    bAnular = botao(t('Anular'), 'ghost sm', () => {
      MPEND = {};
      draw();
    });
    bGuardar = botao(t('Guardar alterações'), 'sm', () => {
      const n = nPend();
      if (!n) return;
      bGuardar.disabled = true;
      pedirGestao({ accao: 'matriz', matriz: MPEND }).then((d) => {
        if (d.cancelado) {
          actualizarBotoes();
          return;
        }
        if (!d.ok) {
          toast(t(d.erro || 'Não foi possível guardar.'), 6000);
          actualizarBotoes();
          return;
        }
        toast(
          (n === 1 ? t('Matriz guardada: 1 alteração.') : t('Matriz guardada: {n} alterações.', { n })) +
            ' ' +
            t('Aplica-se já nos dados; cada pessoa vê o menu novo quando actualizar a página.'),
          7000,
        );
        recarregarGestao();
      });
    });
    tools.prepend(bAnular, bGuardar);
    if (difBase)
      tools.append(
        botao(t('Repor matriz original'), 'ghost sm', () =>
          confirmarAccao(
            t('Repor matriz original'),
            t('Todas as alterações feitas à matriz no portal são apagadas e volta a matriz aprovada.'),
            t('Repor'),
            () =>
              pedirGestao({ accao: 'repor_matriz' }).then((d) => {
                if (d.ok) {
                  recarregarGestao();
                  toast(t('Matriz original reposta.'), 4000);
                }
                return d;
              }),
          ),
        ),
      );
    actualizarBotoes();
  }
  pg.append(secM);
  if ((GESTAO.registos || []).length) {
    pg.append(
      tabela({
        id: 'ac-r',
        titulo: t('Registos de actividade'),
        nota: t('Últimos 300 registos: entradas, saídas, bloqueios, alterações de contas e da matriz, exportações.'),
        sort: 'data',
        total: false,
        rows: GESTAO.registos.map((r, i) => ({
          chave: i + '|' + r.data,
          data: r.data,
          utilizador: r.utilizador,
          perfil: r.perfil,
          accao: t(NOMES_ACCAO[r.accao] || r.accao),
          detalhe: Object.entries(r.detalhe || {})
            .map(([k, v]) => k + ': ' + (Array.isArray(v) ? v.join(', ') : typeof v === 'boolean' ? (v ? t('Sim') : t('Não')) : v))
            .join(' · '),
          ip: r.ip,
        })),
        cols: [
          { key: 'data', label: t('Data'), render: (v) => document.createTextNode(dl(v) + ' ' + String(v).slice(11, 19)) },
          { key: 'utilizador', label: t('Utilizador'), p: 0 },
          { key: 'perfil', label: t('Perfil') },
          { key: 'accao', label: t('Acção') },
          { key: 'detalhe', label: t('Detalhe'), wrap: true },
          { key: 'ip', label: 'IP' },
        ],
      }),
    );
  } else pg.append(secBox(t('Registos de actividade'), '', el('p', 'note', t('Sem registos para mostrar.'))));
};

// ---- activação da gestão de contas (contas ainda na lista antiga) ----
function cartaoActivar() {
  const G = GESTAO,
    c = el('div', 'glass ac-activar');
  const txt = el('div');
  txt.append(
    el('h3', null, t('Activar a gestão de contas no portal')),
    el(
      'p',
      null,
      G.pode_activar
        ? t(
            'As contas vêm ainda da lista antiga e não podem ser criadas nem alteradas aqui. Ao activar, cada conta passa para a lista nova com email obrigatório, perfil e rotas, e a sua conta fica super administrador. As palavras-passe actuais mantêm-se. Faz-se uma só vez.',
          )
        : t(
            'As contas vêm ainda da lista antiga e não podem ser criadas nem alteradas aqui. Peça ao administrador da plataforma (conta admin) para activar a gestão de contas.',
          ),
    ),
  );
  c.append(txt);
  if (G.pode_activar) c.append(botao(t('Activar a gestão'), '', () => janelaActivar()));
  return c;
}
function janelaActivar() {
  const G = GESTAO;
  janela(
    t('Activar a gestão de contas'),
    t(
      'Reveja cada conta. O email é obrigatório para as contas activas (serve para recuperar a palavra-passe e para os avisos). Contas que já não são usadas: desactive-as.',
    ),
    (b, ft, fechar) => {
      const linhas = G.utilizadores.map((u) => {
        const eu = u.login === G.eu;
        const iEmail = entrada('email', u.email || '', { autocomplete: 'off', placeholder: 'nome@naval.co.ao' });
        iEmail.setAttribute('aria-label', t('Email') + ' · ' + u.login);
        const sPerfil = el('select');
        Object.entries(G.perfis).forEach(([k, v]) => {
          const o = el('option', null, k + ' · ' + t(v));
          o.value = k;
          sPerfil.append(o);
        });
        sPerfil.value = eu ? 'SADM' : u.perfil;
        sPerfil.disabled = eu;
        sPerfil.setAttribute('aria-label', t('Perfil') + ' · ' + u.login);
        const iRotas = entrada('text', (u.rotas || []).join(', '), { placeholder: t('ex.: 1001, 1002') });
        iRotas.setAttribute('aria-label', t('Rotas') + ' · ' + u.login);
        const cAct = entrada('checkbox');
        cAct.checked = true;
        cAct.disabled = eu;
        cAct.setAttribute('aria-label', t('Conta activa') + ' · ' + u.login);
        const tr = el('tr');
        const id = el('td');
        id.append(el('strong', null, u.nome), el('div', 'dim', u.login + (eu ? ' · ' + t('a sua conta') : '')));
        const tdR = el('td');
        tdR.append(iRotas);
        const ver = () => {
          iRotas.hidden = !G.por_rota.includes(sPerfil.value);
          tr.classList.toggle('ac-off', !cAct.checked);
        };
        sPerfil.addEventListener('change', ver);
        cAct.addEventListener('change', ver);
        ver();
        [id, iEmail, sPerfil, tdR, cAct].forEach((x, i) => {
          if (x.tagName === 'TD') tr.append(x);
          else {
            const td = el('td', i === 4 ? 'c' : null);
            td.append(x);
            tr.append(td);
          }
        });
        return { u, tr, iEmail, sPerfil, iRotas, cAct };
      });
      const tb = el('table', 'ac-mig'),
        th = el('thead'),
        hr = el('tr');
      [t('Conta'), t('Email (obrigatório)'), t('Perfil'), t('Rotas'), t('Activa')].forEach((x) => hr.append(el('th', null, x)));
      th.append(hr);
      const tbd = el('tbody');
      linhas.forEach((l) => tbd.append(l.tr));
      tb.append(th, tbd);
      const wrap = el('div', 'ac-mig-w');
      wrap.append(tb);
      const iA1 = entrada('text', '', { placeholder: t('Nome') }),
        iA2 = entrada('text', '', { placeholder: t('Nome') });
      const aprov = el('div', 'campos2');
      aprov.append(campo(t('Aprovado por (1)'), iA1), campo(t('Aprovado por (2)'), iA2));
      const erro = msgErro();
      b.append(
        wrap,
        el('p', 'aviso', t('A sua conta fica super administrador. Indique as duas pessoas da Administração que aprovaram (controlo dual).')),
        aprov,
        erro,
      );
      const ir = botao(t('Activar a gestão'), '', () => {
        erro.hidden = true;
        const contas = {};
        linhas.forEach((l) => {
          contas[l.u.login] = {
            email: l.iEmail.value.trim(),
            perfil: l.sPerfil.value,
            rotas: l.iRotas.value.split(/[,;\s]+/).filter(Boolean),
            activo: l.cAct.checked,
          };
        });
        ir.disabled = true;
        pedirGestao({ accao: 'activar_gestao', contas, aprovado_por: [iA1.value, iA2.value] }).then((d) => {
          ir.disabled = false;
          if (d.cancelado) return;
          if (!d.ok) {
            erro.textContent = t(d.erro || 'Não foi possível activar.');
            erro.hidden = false;
            return;
          }
          fechar();
          toast(t('Gestão de contas activada: {n} contas na lista nova. Active já a verificação em dois passos da sua conta.', { n: d.contas }), 8000);
          recarregarGestao();
        });
      });
      ft.append(botao(t('Cancelar'), 'ghost', fechar), ir);
      setTimeout(() => linhas[0] && linhas[0].iEmail.focus(), 50);
    },
  );
}

// ---- perfis: os do sistema (fixos) e os criados no portal pelo super administrador ----
function seccaoPerfis(gerir) {
  const P = GESTAO.perfis_info || [];
  const sec = tabela({
    id: 'ac-p',
    titulo: t('Perfis'),
    nota: gerir
      ? t(
          'Os perfis do sistema são fixos. Um perfil novo começa com as permissões do perfil de origem e passa a ser uma coluna da matriz, onde as pode ajustar.',
        )
      : t('Perfis de acesso e respectivas características.'),
    sort: 'ordem',
    dir: 1,
    total: false,
    search: false,
    rows: P.map((p, i) => ({
      ...p,
      chave: p.codigo,
      ordem: i,
      tipo: p.sistema ? t('Sistema') : t('Criado no portal'),
      ambito: p.por_rota ? t('Só as suas rotas') : t('Todas as rotas'),
      mfa: p.dois_passos ? t('Obrigatória') : t('Opcional'),
      estado: p.activo ? t('Activo') : t('Inactivo'),
      origem: p.base ? p.base : '—',
    })),
    cols: [
      { key: 'codigo', label: t('Código'), p: 0, get: (r) => r.ordem, render: (v, r) => document.createTextNode(r.codigo), xl: (r) => r.codigo },
      { key: 'nome', label: t('Nome'), p: 0 },
      { key: 'tipo', label: t('Tipo') },
      { key: 'origem', label: t('Copiado de') },
      { key: 'ambito', label: t('Âmbito') },
      { key: 'mfa', label: t('Dois passos') },
      { key: 'contas', label: t('Contas'), type: 'n' },
      {
        key: 'estado',
        label: t('Estado'),
        render: (v, r) => {
          const w = el('span');
          w.append(pill(v, r.activo ? 'p-ok' : 'p-warn'));
          if (gerir && !r.sistema) {
            const b = el('button', 'linkbtn', t('Gerir'));
            b.type = 'button';
            b.style.marginLeft = '8px';
            b.setAttribute('aria-label', t('Gerir') + ' ' + r.codigo);
            b.addEventListener('click', () => janelaPerfil(P.find((x) => x.codigo === r.codigo)));
            w.append(b);
          }
          return w;
        },
        xl: (r) => r.estado,
      },
    ],
  });
  if (gerir) sec.querySelector('.sec-tools').prepend(botao(t('Novo perfil'), 'sm', () => janelaPerfil(null)));
  return sec;
}
function janelaPerfil(p) {
  const novo = !p;
  p = p || { codigo: '', nome: '', por_rota: false, dois_passos: false, activo: true, contas: 0 };
  janela(
    novo ? t('Novo perfil') : p.codigo + ' · ' + p.nome,
    novo
      ? t('O perfil novo copia as permissões do perfil de origem; depois ajuste-as na matriz de acessos.')
      : t('{n} contas com este perfil.', { n: p.contas }),
    (b, ft, fechar) => {
      const iCod = entrada('text', p.codigo, {
        maxLength: 8,
        autocomplete: 'off',
        autocapitalize: 'characters',
        spellcheck: false,
        placeholder: t('ex.: VENDAS2'),
      });
      if (!novo) iCod.disabled = true;
      iCod.addEventListener('input', () => {
        iCod.value = iCod.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
      });
      const iNome = entrada('text', p.nome, { maxLength: 60, autocomplete: 'off', placeholder: t('ex.: Vendedor de loja') });
      const l1 = el('div', 'campos2');
      l1.append(campo(t('Código'), iCod), campo(t('Nome'), iNome));
      b.append(l1);
      let sBase = null;
      if (novo) {
        sBase = el('select');
        const o0 = el('option', null, t('Nenhum (sem permissões)'));
        o0.value = '';
        sBase.append(o0);
        (GESTAO.perfis_info || [])
          .filter((x) => x.activo && x.codigo !== 'SADM')
          .forEach((x) => {
            const o = el('option', null, x.codigo + ' · ' + t(x.nome));
            o.value = x.codigo;
            sBase.append(o);
          });
        b.append(campo(t('Copiar as permissões de'), sBase));
      }
      const caixa = (txt, v) => {
        const c = entrada('checkbox');
        c.checked = !!v;
        const l = el('label', 'interruptor');
        l.append(c, document.createTextNode(txt));
        b.append(l);
        return c;
      };
      const cRota = caixa(t('Vê só as rotas atribuídas a cada conta (como supervisores e promotores)'), p.por_rota);
      const cMfa = caixa(t('Verificação em dois passos obrigatória'), p.dois_passos);
      const cAct = novo ? null : caixa(t('Perfil activo'), p.activo);
      const erro = msgErro();
      b.append(erro);
      if (!novo && !p.contas) {
        const acc = el('div', 'dlg-accoes');
        acc.append(
          botao(t('Apagar perfil'), 'ghost sm', () => {
            fechar();
            confirmarAccao(t('Apagar perfil'), t('O perfil {c} e as suas permissões na matriz são apagados.', { c: p.codigo }), t('Apagar'), () =>
              pedirGestao({ accao: 'apagar_perfil', codigo: p.codigo }).then((d) => {
                if (d.ok) {
                  recarregarGestao();
                  toast(t('Perfil apagado.'), 4000);
                }
                return d;
              }),
            );
          }),
        );
        b.append(acc);
      }
      const go = botao(novo ? t('Criar perfil') : t('Guardar'), '', () => {
        go.disabled = true;
        erro.hidden = true;
        pedirGestao({
          accao: 'guardar_perfil',
          novo,
          codigo: iCod.value.trim(),
          nome: iNome.value.trim(),
          base: sBase ? sBase.value : undefined,
          por_rota: cRota.checked,
          dois_passos: cMfa.checked,
          activo: cAct ? cAct.checked : true,
        }).then((d) => {
          go.disabled = false;
          if (d.cancelado) return;
          if (!d.ok) {
            erro.textContent = t(d.erro || 'Não foi possível guardar.');
            erro.hidden = false;
            return;
          }
          fechar();
          recarregarGestao();
          toast(novo ? t('Perfil {c} criado. Ajuste as permissões na matriz de acessos.', { c: d.perfil }) : t('Perfil guardado.'), 6000);
        });
      });
      ft.append(botao(t('Cancelar'), 'ghost', fechar), go);
    },
  );
}
