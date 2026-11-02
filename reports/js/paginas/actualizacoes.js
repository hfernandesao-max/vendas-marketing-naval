// Administração › Actualizações (só o super administrador): aprovação das versões do portal publicadas no GitHub.
// Gestão de alterações: cada versão já passou nos testes automáticos; só as aprovadas aqui chegam ao servidor (à noite,
// ou em 5 minutos com «Aprovar e instalar já»). Reprovar exige motivo; todas as decisões pedem a confirmação da
// identidade e ficam registadas (servidor: nucleo/api/v1/actualizacoes.php).
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { pill, secBox, tiles } from '../componentes/blocos.js';
import { botao, campo, confirmarIdentidade, janela, msgErro } from '../componentes/janelas.js';
import { t } from '../i18n/i18n.js';
import { textoFormatado } from '../novidades.js';
import { atual } from '../perfil.js';
import { postJson } from '../servidor.js';
import { el, toast } from '../util.js';
import { R } from './registo.js';

let ESTADO = null;
let aCarregar = false;

function carregar() {
  if (aCarregar) return;
  aCarregar = true;
  apiJson('actualizacoes').then((d) => {
    aCarregar = false;
    ESTADO = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler as actualizações.') };
    if (atual === 'actualizacoes') draw();
  });
}
const recarregar = () => {
  ESTADO = null;
  draw();
};

const dataHora = (s) => {
  const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(s || ''));
  return m ? `${m[3]}-${m[2]}-${m[1]} ${m[4]}:${m[5]}` : '—';
};

const RESULTADOS = {
  actualizada: ['Instalada', 'p-ok'],
  sem_alteracoes: ['Instalada', 'p-ok'],
  revertida: ['Falhou a verificação: reposta a versão anterior', 'p-bad'],
  conflito: ['Parada: ficheiros alterados à mão no servidor', 'p-warn'],
  erro: ['Erro na instalação', 'p-bad'],
  reversao_manual: ['Reposta a versão anterior', 'p-warn'],
  reversao_portal: ['Reposta a versão anterior', 'p-warn'],
};
const resultado = (r) => {
  if (!r) return null;
  const [txt, cls] = RESULTADOS[r.accao] || [r.accao, 'p-mut'];
  return pill(t(txt) + ' · ' + dataHora(r.data), cls);
};

/** Pedido com confirmação da identidade quando o servidor a exigir. */
function pedir(corpo, confirmacao) {
  return postJson('actualizacoes', { ...corpo, confirmacao }).then((d) => {
    if (d.sair) {
      toast(t(d.erro), 6000);
      setTimeout(() => {
        location.href = '../geo/login.php?redirect=../reports/';
      }, 1800);
      return { ok: false, cancelado: true };
    }
    if (d.confirmar) return confirmarIdentidade(d.confirmar, d.erro && t(d.erro)).then((c) => (c == null ? { ok: false, cancelado: true } : pedir(corpo, c)));
    return d;
  });
}

/** Janela de decisão: aprovar (com ou sem instalar já), reprovar (motivo obrigatório) ou anular. */
function decidir(v, accao, urgente) {
  const titulos = {
    aprovar: urgente ? t('Aprovar e instalar já a versão {v}', { v: v.versao }) : t('Aprovar a versão {v}', { v: v.versao }),
    reprovar: t('Reprovar a versão {v}', { v: v.versao }),
    cancelar: t('Anular a decisão sobre a versão {v}', { v: v.versao }),
  };
  const notas = {
    aprovar: urgente
      ? t('A versão é instalada nos próximos 5 minutos, com cópia de segurança e verificação; se falhar, a anterior é reposta sozinha.')
      : t('A versão é instalada esta noite às 02:15, com cópia de segurança e verificação; se falhar, a anterior é reposta sozinha.'),
    reprovar: t('A versão nunca é instalada. O motivo fica registado.'),
    cancelar: t('A versão volta a ficar à espera de decisão.'),
  };
  janela(titulos[accao], notas[accao], (b, ft, fechar) => {
    const motivo = el('textarea');
    motivo.rows = 3;
    motivo.maxLength = 500;
    if (accao !== 'cancelar') b.append(campo(accao === 'reprovar' ? t('Motivo (obrigatório)') : t('Observações (facultativo)'), motivo));
    const erro = msgErro();
    b.append(erro);
    const go = botao(
      accao === 'reprovar' ? t('Reprovar') : accao === 'cancelar' ? t('Anular decisão') : t('Aprovar'),
      accao === 'reprovar' ? 'perigo' : '',
      () => {
        if (accao === 'reprovar' && motivo.value.trim().length < 5) {
          erro.textContent = t('Indique o motivo da reprovação.');
          erro.hidden = false;
          motivo.focus();
          return;
        }
        go.disabled = true;
        pedir({ accao, versao: v.versao, urgente: !!urgente, motivo: motivo.value.trim() }).then((d) => {
          go.disabled = false;
          if (d.cancelado) return;
          if (!d.ok) {
            erro.textContent = t(d.erro || 'Não foi possível concluir.');
            erro.hidden = false;
            return;
          }
          fechar();
          toast(accao === 'reprovar' ? t('Versão reprovada.') : accao === 'cancelar' ? t('Decisão anulada.') : t('Versão aprovada.'), 3500);
          recarregar();
        });
      },
    );
    ft.append(botao(t('Cancelar'), 'ghost', fechar), go);
  });
}

function pedirReversao() {
  janela(
    t('Repor a versão anterior'),
    t('O servidor repõe, nos próximos 5 minutos, os ficheiros da versão anterior a partir da última cópia de segurança. Os dados não são tocados.'),
    (b, ft, fechar) => {
      const erro = msgErro();
      b.append(erro);
      const go = botao(t('Repor'), 'perigo', () => {
        go.disabled = true;
        pedir({ accao: 'reverter' }).then((d) => {
          go.disabled = false;
          if (d.cancelado) return;
          if (!d.ok) {
            erro.textContent = t(d.erro || 'Não foi possível concluir.');
            erro.hidden = false;
            return;
          }
          fechar();
          toast(t('Pedido registado: a versão anterior é reposta nos próximos 5 minutos.'), 4500);
          recarregar();
        });
      });
      ft.append(botao(t('Cancelar'), 'ghost', fechar), go);
    },
  );
}

/** Resumo das alterações de uma versão (secções do registo de alterações; senão, as notas da versão). */
function resumo(v) {
  const box = el('div', 'act-resumo');
  if (v.seccoes && v.seccoes.length) {
    for (const s of v.seccoes) {
      box.append(el('h4', null, s.data + ' · ' + s.titulo));
      const ul = el('ul');
      for (const i of s.itens) ul.append(textoFormatado(el('li'), i));
      box.append(ul);
    }
  } else {
    const linhas = String(v.notas || '')
      .split('\n')
      .map((l) => l.trim())
      .filter((l) => l.startsWith('- '));
    const ul = el('ul');
    for (const l of linhas.length ? linhas : ['- ' + t('Sem notas publicadas.')]) ul.append(textoFormatado(el('li'), l.slice(2)));
    box.append(ul);
  }
  return box;
}

function cartaoPendente(v, maisRecente) {
  const c = el('article', 'glass act-cartao');
  const cab = el('div', 'act-cab');
  const tit = el('div');
  tit.append(el('h3', null, t('Versão {v}', { v: v.versao })));
  tit.append(el('p', null, t('Publicada em {d} · commit {c} · passou em todos os testes automáticos', { d: dataHora(v.publicada_em), c: v.commit })));
  cab.append(tit);
  const dec = v.decisao;
  if (dec && dec.decisao === 'aprovada')
    cab.append(pill((dec.urgente ? t('Aprovada para instalar já') : t('Aprovada: instala esta noite')) + ' · ' + dec.por + ' · ' + dataHora(dec.em), 'p-ok'));
  else if (dec && dec.decisao === 'reprovada') cab.append(pill(t('Reprovada') + ' · ' + dec.por + ' · ' + dataHora(dec.em), 'p-bad'));
  else cab.append(pill(t('À espera de decisão'), 'p-warn'));
  c.append(cab);
  if (dec && dec.motivo) c.append(el('p', 'act-motivo', (dec.decisao === 'reprovada' ? t('Motivo') : t('Observações')) + ': ' + dec.motivo));
  if (v.resultado) c.append(resultado(v.resultado));
  const det = el('details', 'act-det');
  det.open = maisRecente;
  det.append(el('summary', null, t('O que muda nesta versão')));
  det.append(resumo(v));
  c.append(det);
  const acc = el('div', 'act-accoes');
  if (!dec) {
    acc.append(
      botao(t('Aprovar'), '', () => decidir(v, 'aprovar', false)),
      botao(t('Aprovar e instalar já'), 'ghost', () => decidir(v, 'aprovar', true)),
      botao(t('Reprovar'), 'ghost perigo', () => decidir(v, 'reprovar', false)),
    );
  } else {
    acc.append(botao(t('Anular decisão'), 'ghost', () => decidir(v, 'cancelar', false)));
  }
  c.append(acc);
  return c;
}

R.actualizacoes = (pg) => {
  if (!ESTADO) {
    pg.append(el('div', 'glass empty', t('A carregar…')));
    carregar();
    return;
  }
  if (ESTADO.falhou) {
    pg.append(el('div', 'glass empty', t(ESTADO.falhou)));
    return;
  }
  const E = ESTADO;
  const ult = E.ultima_execucao;
  const porDecidir = E.pendentes.filter((v) => !v.decisao).length;
  pg.append(
    tiles([
      {
        l: t('Versão instalada'),
        v: E.instalada.versao || '—',
        s: E.instalada.aplicada_em ? t('desde {d}', { d: dataHora(E.instalada.aplicada_em) }) : t('instalação manual'),
      },
      { l: t('À espera de decisão'), v: String(porDecidir), s: t('versões testadas e publicadas'), tone: porDecidir ? 'warn' : null },
      {
        l: t('Última execução'),
        v: ult ? t((RESULTADOS[ult.accao] || [ult.accao])[0]) : '—',
        s: ult ? dataHora(ult.data) + (ult.versao ? ' · ' + ult.versao : '') : t('ainda sem execuções'),
      },
      {
        l: t('Instalação automática'),
        v: E.automatica ? t('Ligada') : t('Desligada'),
        s: E.exige_aprovacao ? t('só versões aprovadas aqui') : t('sem aprovação (todas as versões)'),
        tone: E.automatica ? null : 'warn',
      },
    ]),
  );
  if (!E.configurada)
    pg.append(
      el(
        'p',
        'glass act-aviso',
        t('O servidor ainda não está ligado ao GitHub: correr «php ferramentas/actualizar.php configurar» no servidor (passo 9 do manual).'),
      ),
    );
  else if (E.erro) pg.append(el('p', 'glass act-aviso', t('Sem ligação ao GitHub: {e}', { e: E.erro })));
  if (E.instalada.recusada)
    pg.append(
      el(
        'p',
        'glass act-aviso',
        t('A versão {v} falhou a verificação e foi reposta a anterior; não volta a ser instalada sozinha.', { v: E.instalada.recusada }),
      ),
    );

  const lista = el('div', 'act-lista');
  if (!E.pendentes.length) lista.append(el('p', 'note', t('Não há versões novas: o portal está na versão mais recente publicada.')));
  E.pendentes.forEach((v, i) => lista.append(cartaoPendente(v, i === 0)));
  pg.append(secBox(t('Versões por instalar'), t('Cada versão inclui as anteriores: aprovar a mais recente instala também o que vinha antes dela.'), lista));

  const hist = el('div', 'act-hist');
  if (!E.historico.length) hist.append(el('p', 'note', t('Ainda sem histórico.')));
  for (const v of E.historico) {
    const l = el('div', 'act-hist-l');
    l.append(el('strong', null, v.versao), el('span', null, dataHora(v.publicada_em)));
    if (v.decisao)
      l.append(el('span', null, (v.decisao.decisao === 'reprovada' ? t('Reprovada') : t('Aprovada')) + ' · ' + v.decisao.por + ' · ' + dataHora(v.decisao.em)));
    else l.append(el('span', 'dim', t('sem decisão registada')));
    const r = resultado(v.resultado);
    if (r) l.append(r);
    hist.append(l);
  }
  const accoes = el('div', 'act-accoes');
  if (E.reversao_pedida) accoes.append(pill(t('Reposição da versão anterior pedida: executa nos próximos 5 minutos'), 'p-warn'));
  else if (E.pode_reverter) accoes.append(botao(t('Repor a versão anterior'), 'ghost perigo', pedirReversao));
  accoes.append(botao(t('Actualizar'), 'ghost', recarregar));
  hist.append(accoes);
  pg.append(secBox(t('Histórico'), t('Versões instaladas ou anteriores à instalada, com a decisão e o resultado.'), hist));
};
