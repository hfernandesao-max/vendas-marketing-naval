// Administração › Módulos (só o super administrador): as peças do portal e o estado de cada uma. Monólito modular: cada
// módulo é uma área de negócio com ficha própria (nucleo/modulos/*.json): páginas, rotas da API, conectores, eventos e
// dependências. Estados (lançamento por anéis): ligado para todos (conforme a matriz de acessos), piloto só para os
// administradores de sistema (SADM e TI) e desligado para ninguém. Cada mudança pede a confirmação da identidade e fica
// no registo de auditoria (servidor: nucleo/api/v1/modulos.php).
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { pill, secBox, tiles } from '../componentes/blocos.js';
import { botao, campo, confirmarIdentidade, entrada, janela, msgErro } from '../componentes/janelas.js';
import { t } from '../i18n/i18n.js';
import { atual } from '../perfil.js';
import { postJson } from '../servidor.js';
import { el, toast } from '../util.js';
import { PAGES } from './catalogo.js';
import { R } from './registo.js';

let ESTADO = null;
let aCarregar = false;

function carregar() {
  if (aCarregar) return;
  aCarregar = true;
  apiJson('modulos').then((d) => {
    aCarregar = false;
    ESTADO = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os módulos.') };
    if (atual === 'modulos') draw();
  });
}

const ESTADOS = {
  ligado: ['Ligado', 'p-ok', 'Para todos os perfis, conforme a matriz de acessos.'],
  piloto: ['Piloto', 'p-warn', 'Só para o super administrador e o administrador da plataforma.'],
  desligado: ['Desligado', 'p-mut', 'Para ninguém: as páginas saem do menu e a API recusa os pedidos.'],
};
/** AAAA-MM-DD hh:mm → dd-mm-aaaa hh:mm */
const dataHora = (s) => {
  const x = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(s || ''));
  return x ? `${x[3]}-${x[2]}-${x[1]} ${x[4]}:${x[5]}` : '—';
};
/** Interruptor ligado/desligado (role="switch", como nas definições do iPhone e do Android). O núcleo não se desliga. */
function interruptor(m) {
  const ligado = m.estado !== 'desligado';
  const b = el('button', 'mod-int');
  b.type = 'button';
  b.setAttribute('role', 'switch');
  b.setAttribute('aria-checked', String(ligado));
  b.setAttribute('aria-label', t('Módulo «{m}» ligado', { m: t(m.nome) }));
  b.append(el('span', 'mod-int-bola'));
  if (m.nucleo) {
    b.disabled = true;
    b.title = t('O núcleo nunca se desliga.');
  } else {
    b.title = t(ligado ? 'Desligar o módulo' : 'Ligar o módulo para todos');
    b.addEventListener('click', () => mudar(m, ligado ? 'desligado' : 'ligado'));
  }
  return b;
}
const nomePagina = (id) => t((PAGES.find((p) => p.id === id) || {}).nome || id);

/** Pedido com confirmação da identidade quando o servidor a exigir. */
function pedir(corpo, confirmacao) {
  return postJson('modulos', { ...corpo, confirmacao }).then((d) => {
    if (d && d.confirmar)
      return confirmarIdentidade(d.confirmar, d.erro && t(d.erro)).then((c) => (c == null ? { ok: false, cancelado: true } : pedir(corpo, c)));
    return d;
  });
}

function mudar(m, estado) {
  janela(t('Módulo «{m}»: passar a {e}', { m: t(m.nome), e: t(ESTADOS[estado][0]) }), t(ESTADOS[estado][2]), (b, ft, fechar) => {
    const motivo = entrada('text', '', { maxLength: 300, placeholder: t('Ex.: piloto com 2 rotas') });
    const erro = msgErro();
    b.append(campo(t('Motivo'), motivo), erro);
    ft.append(botao(t('Cancelar'), 'ghost', fechar));
    ft.append(
      botao(t('Confirmar'), '', () => {
        pedir({ id: m.id, estado, motivo: motivo.value.trim() }).then((d) => {
          if (d && d.cancelado) return;
          if (!d || !d.ok) {
            erro.textContent = t((d && d.erro) || 'Sem resposta do servidor.');
            erro.hidden = false;
            return;
          }
          fechar();
          toast(t('Módulo actualizado. As pessoas vêem a mudança ao abrir de novo o portal.'), 5000);
          ESTADO = d;
          draw();
        });
      }),
    );
  });
}

function cartao(m) {
  const c = el('div', 'glass mod-cartao');
  const h = el('div', 'mod-cab');
  const [rot, cls] = ESTADOS[m.estado] || ESTADOS.ligado;
  h.append(el('b', null, t(m.nome)), el('span', 'mod-versao mono', 'v' + m.versao), pill(t(rot), cls));
  if (m.nucleo) h.append(pill(t('Núcleo'), 'p-mut'));
  h.append(interruptor(m));
  c.append(h, el('p', 'mod-desc', t(m.descricao)));
  const det = el('dl', 'mod-det');
  const linha = (k, v) => {
    if (!v) return;
    det.append(el('dt', null, k), el('dd', null, v));
  };
  linha(t('Páginas'), m.paginas.map(nomePagina).join(', '));
  linha(t('Conectores'), m.conectores.map((x) => t(x.nome) + (x.estado === 'ligado' ? '' : ' (' + t('por ligar') + ')')).join(', '));
  linha(t('Depende de'), m.depende.map((d) => t((ESTADO.modulos.find((x) => x.id === d) || { nome: d }).nome)).join(', '));
  const ev = [...(m.eventos.publica || []).map((x) => '↑ ' + x), ...(m.eventos.escuta || []).map((x) => '↓ ' + x)];
  linha(t('Eventos'), ev.join(' · '));
  if (m.alterado_em) linha(t('Última alteração'), dataHora(m.alterado_em) + ' · ' + m.alterado_por);
  c.append(det);
  // o interruptor liga e desliga; o piloto (validar com os administradores antes de ligar para todos) fica ao lado
  if (!m.nucleo && m.estado !== 'desligado') {
    const acc = el('div', 'mod-acc');
    if (m.estado === 'piloto') acc.append(botao(t('Ligar para todos'), 'sm ghost', () => mudar(m, 'ligado')));
    else acc.append(botao(t('Pôr em piloto'), 'sm ghost', () => mudar(m, 'piloto')));
    c.append(acc);
  }
  return c;
}

R.modulos = (pg) => {
  if (!ESTADO) {
    pg.append(el('div', 'glass empty', t('A carregar…')));
    carregar();
    return;
  }
  if (ESTADO.falhou) {
    pg.append(el('div', 'glass empty', t(ESTADO.falhou)));
    return;
  }
  const M = ESTADO.modulos;
  const n = (e) => M.filter((m) => m.estado === e).length;
  const porLigar = ESTADO.conectores.filter((c) => c.estado !== 'ligado');
  pg.append(
    tiles([
      { l: t('Módulos ligados'), v: String(n('ligado')), s: t('{n} módulos no total', { n: M.length }) },
      { l: t('Em piloto'), v: String(n('piloto')), s: t('só SADM e TI'), tone: n('piloto') ? 'warn' : null },
      { l: t('Desligados'), v: String(n('desligado')) },
      { l: t('Conectores por ligar'), v: String(porLigar.length), s: porLigar.map((c) => t(c.nome)).join(', ') },
    ]),
  );
  const grelha = el('div', 'mod-grelha');
  M.forEach((m) => grelha.append(cartao(m)));
  pg.append(
    secBox(
      t('Módulos do portal'),
      t(
        'Cada módulo é uma peça independente, com ficha própria. Piloto: só o super administrador e o administrador da plataforma vêem o módulo, para o validar antes de o ligar para todos.',
      ),
      grelha,
    ),
  );
};
