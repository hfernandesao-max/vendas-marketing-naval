// Administração › Módulos (só o super administrador): as peças do portal e o estado de cada uma. Monólito modular: cada
// módulo é uma área de negócio com ficha própria (nucleo/modulos/*.json): páginas, rotas da API, conectores, eventos e
// dependências. Estados (lançamento por anéis): ligado para todos (conforme a matriz de acessos), piloto só para os
// administradores de sistema (SADM e TI) e desligado para ninguém. Cada mudança pede a confirmação da identidade e fica
// no registo de auditoria (servidor: nucleo/api/v1/modulos.php).
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { pill, secBox, tabela, tiles } from '../componentes/blocos.js';
import { botao, campo, confirmarIdentidade, entrada, janela, msgErro } from '../componentes/janelas.js';
import { n0 } from '../formatos.js';
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

// ───────────── Lista dos módulos (padrão das consolas de administração: Microsoft 365, Atlassian, Google Workspace) ─────
// Uma linha por módulo, com o essencial à vista (nome, resumo numa linha, versão, estado, páginas, conectores e
// interruptor). A ficha completa (descrição, páginas, conectores, dependências, eventos, última alteração e piloto)
// abre num painel flutuante ao passar o rato ou ao focar a linha pelo teclado, e fica fixa com um clique em «Detalhes»
// (ecrãs tácteis). Esc ou um clique fora fecham-na (WAI-ARIA, padrão «disclosure» não modal).
const FILTRO = { q: '', estado: '' };
let pop = null,
  popDe = '',
  fixo = false,
  tAbrir = 0,
  tFechar = 0;

function detalhes(m) {
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
  return det;
}

function fecharFicha() {
  clearTimeout(tAbrir);
  if (pop) pop.remove();
  pop = null;
  popDe = '';
  fixo = false;
  document.querySelectorAll('.mod-linha.aberta').forEach((x) => {
    x.classList.remove('aberta');
    const b = x.querySelector('.mod-info');
    if (b) b.setAttribute('aria-expanded', 'false');
  });
}

function abrirFicha(m, linha, fixar) {
  clearTimeout(tFechar);
  if (popDe === m.id && pop) {
    if (fixar) fixo = true;
    return;
  }
  fecharFicha();
  const [rot, cls, expl] = ESTADOS[m.estado] || ESTADOS.ligado;
  pop = el('div', 'glass mod-pop');
  pop.id = 'mod-ficha';
  pop.setAttribute('role', 'dialog');
  pop.setAttribute('aria-label', t('Ficha do módulo «{m}»', { m: t(m.nome) }));
  const cab = el('div', 'mod-pop-cab');
  cab.append(el('b', null, t(m.nome)), el('span', 'mod-versao mono', 'v' + m.versao), pill(t(rot), cls));
  if (m.nucleo) cab.append(pill(t('Núcleo'), 'p-mut'));
  pop.append(cab, el('p', 'mod-pop-desc', t(m.descricao)), el('p', 'mod-pop-estado', t(expl)), detalhes(m));
  if (!m.nucleo && m.estado !== 'desligado') {
    const acc = el('div', 'mod-acc');
    if (m.estado === 'piloto') acc.append(botao(t('Ligar para todos'), 'sm', () => (fecharFicha(), mudar(m, 'ligado'))));
    else acc.append(botao(t('Pôr em piloto'), 'sm ghost', () => (fecharFicha(), mudar(m, 'piloto'))));
    pop.append(acc);
  }
  pop.addEventListener('mouseenter', () => clearTimeout(tFechar));
  pop.addEventListener('mouseleave', () => {
    if (!fixo) tFechar = setTimeout(fecharFicha, 200);
  });
  document.body.append(pop);
  popDe = m.id;
  fixo = !!fixar;
  linha.classList.add('aberta');
  const info = linha.querySelector('.mod-info');
  if (info) info.setAttribute('aria-expanded', 'true');
  // por baixo da linha, alinhada à direita do nome; sobe se não couber no ecrã
  const r = linha.getBoundingClientRect();
  const w = Math.min(460, window.innerWidth - 24);
  pop.style.width = w + 'px';
  const left = Math.max(12, Math.min(r.left + 56, window.innerWidth - w - 12));
  pop.style.left = left + 'px';
  const h = pop.offsetHeight;
  const top = r.bottom + 6 + h > window.innerHeight - 12 ? Math.max(12, r.top - h - 6) : r.bottom + 6;
  pop.style.top = top + 'px';
}

document.addEventListener('keydown', (ev) => {
  if (ev.key === 'Escape' && pop) {
    const de = document.querySelector('.mod-linha.aberta .mod-info');
    fecharFicha();
    if (de) de.focus();
  }
});
document.addEventListener('click', (ev) => {
  if (pop && fixo && !pop.contains(ev.target) && !ev.target.closest('.mod-linha')) fecharFicha();
});
window.addEventListener('hashchange', fecharFicha);
window.addEventListener('scroll', () => pop && !fixo && fecharFicha(), { passive: true });

const iniciais = (nome) =>
  nome
    .split(/\s+/)
    .filter((x) => x.length > 2 || /^[A-Z]{2,}$/.test(x))
    .slice(0, 2)
    .map((x) => x[0])
    .join('')
    .toUpperCase();

function linhaModulo(m) {
  const l = el('div', 'mod-linha mod-' + m.estado);
  l.dataset.id = m.id;
  const [rot, cls] = ESTADOS[m.estado] || ESTADOS.ligado;
  const ic = el('span', 'mod-ic', iniciais(t(m.nome)));
  ic.setAttribute('aria-hidden', 'true');
  const nome = el('div', 'mod-nome');
  nome.append(el('b', null, t(m.nome)), el('span', 'mod-resumo', t(m.descricao)));
  const porLigar = m.conectores.filter((x) => x.estado !== 'ligado').length;
  const nc = m.conectores.length;
  const con = el('span', 'mod-con', nc ? t(nc === 1 ? '{n} conector' : '{n} conectores', { n: nc }) : t('sem conectores'));
  if (porLigar) con.append(el('small', null, ' · ' + t('{n} por ligar', { n: porLigar })));
  const estado = el('span', 'mod-est');
  estado.append(pill(t(rot), cls));
  if (m.nucleo) estado.append(pill(t('Núcleo'), 'p-mut'));
  const info = el('button', 'btn ghost sm mod-info', t('Detalhes'));
  info.type = 'button';
  info.setAttribute('aria-expanded', 'false');
  info.setAttribute('aria-controls', 'mod-ficha');
  info.addEventListener('click', (ev) => {
    ev.stopPropagation();
    if (popDe === m.id && fixo) fecharFicha();
    else abrirFicha(m, l, true);
  });
  info.addEventListener('focus', () => abrirFicha(m, l, false));
  l.append(
    ic,
    nome,
    el('span', 'mod-versao mono', 'v' + m.versao),
    estado,
    el('span', 'mod-pags', t(m.paginas.length === 1 ? '{n} página' : '{n} páginas', { n: m.paginas.length })),
    con,
    info,
    interruptor(m),
  );
  l.addEventListener('mouseenter', () => {
    clearTimeout(tFechar);
    clearTimeout(tAbrir);
    if (!fixo) tAbrir = setTimeout(() => abrirFicha(m, l, false), 350);
  });
  l.addEventListener('mouseleave', () => {
    clearTimeout(tAbrir);
    if (!fixo) tFechar = setTimeout(fecharFicha, 200);
  });
  return l;
}

function barraFiltros(M, redesenhar) {
  const b = el('div', 'mod-barra');
  const q = el('input', 'search mod-procurar');
  q.type = 'search';
  q.placeholder = t('Procurar módulo, página ou conector…');
  q.setAttribute('aria-label', t('Procurar módulo, página ou conector'));
  q.value = FILTRO.q;
  q.addEventListener('input', () => {
    FILTRO.q = q.value;
    redesenhar();
  });
  const seg = el('div', 'seg mod-seg');
  seg.setAttribute('role', 'group');
  seg.setAttribute('aria-label', t('Estado'));
  [
    ['', t('Todos'), M.length],
    ['ligado', t('Ligados'), M.filter((m) => m.estado === 'ligado').length],
    ['piloto', t('Em piloto'), M.filter((m) => m.estado === 'piloto').length],
    ['desligado', t('Desligados'), M.filter((m) => m.estado === 'desligado').length],
  ].forEach(([v, rot, n]) => {
    const x = el('button', FILTRO.estado === v ? 'on' : null, rot + ' ' + n);
    x.type = 'button';
    x.setAttribute('aria-pressed', String(FILTRO.estado === v));
    x.addEventListener('click', () => {
      FILTRO.estado = v;
      seg.querySelectorAll('button').forEach((y) => {
        y.classList.toggle('on', y === x);
        y.setAttribute('aria-pressed', String(y === x));
      });
      redesenhar();
    });
    seg.append(x);
  });
  b.append(q, seg);
  return b;
}

function filtrar(M) {
  const q = FILTRO.q.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  const norm = (x) =>
    String(x || '')
      .toLowerCase()
      .normalize('NFD')
      .replace(/[̀-ͯ]/g, '');
  return M.filter((m) => !FILTRO.estado || m.estado === FILTRO.estado).filter(
    (m) => !q || norm([t(m.nome), t(m.descricao), ...m.paginas.map(nomePagina), ...m.conectores.map((c) => t(c.nome))].join(' ')).includes(q),
  );
}

R.modulos = (pg) => {
  fecharFicha();
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
  const tile = tiles([
    { l: t('Módulos ligados'), v: String(n('ligado')), s: t('{n} módulos no total', { n: M.length }) },
    { l: t('Em piloto'), v: String(n('piloto')), s: t('só SADM e TI'), tone: n('piloto') ? 'warn' : null },
    { l: t('Desligados'), v: String(n('desligado')) },
    {
      l: t('Conectores ligados'),
      v: n0(ESTADO.conectores.length - porLigar.length) + ' / ' + n0(ESTADO.conectores.length),
      s: t('{n} por ligar', { n: porLigar.length }),
      tone: porLigar.length ? 'warn' : null,
    },
  ]);
  pg.append(tile);
  const corpo = el('div', 'mod-corpo');
  const desenhar = () => {
    fecharFicha();
    corpo.replaceChildren();
    const F = filtrar(M);
    if (!F.length) corpo.append(el('p', 'note', t('Nenhum módulo com estes filtros.')));
    [
      [t('Base da plataforma'), F.filter((m) => m.nucleo)],
      [t('Áreas de negócio'), F.filter((m) => !m.nucleo)],
    ].forEach(([titulo, lista]) => {
      if (!lista.length) return;
      corpo.append(el('h3', 'mod-grupo', titulo + ' · ' + lista.length));
      const ls = el('div', 'mod-lista');
      ls.setAttribute('role', 'list');
      lista
        .slice()
        .sort((a, b) => t(a.nome).localeCompare(t(b.nome), 'pt'))
        .forEach((m) => {
          const l = linhaModulo(m);
          l.setAttribute('role', 'listitem');
          ls.append(l);
        });
      corpo.append(ls);
    });
  };
  const caixa = el('div');
  caixa.append(barraFiltros(M, desenhar), corpo);
  desenhar();
  pg.append(
    secBox(
      t('Módulos do portal'),
      t('Cada módulo é uma peça independente. Passe o rato por um módulo, ou carregue em «Detalhes», para ver a ficha completa.'),
      caixa,
    ),
  );
  pg.append(tabelaConectores());
};

const SENTIDO = { entrada: 'Recebe dados', saida: 'Envia dados', saída: 'Envia dados', 'entrada e saída': 'Recebe e envia dados' };
/** Catálogo de integrações: estado, categoria, fornecedor, protocolo, sentido dos dados e módulos que o usam. */
function tabelaConectores() {
  const linhas = ESTADO.conectores.map((c) => ({
    ...c,
    estado_txt: c.estado === 'ligado' ? t('Ligado') : t('Por ligar'),
    ordem: (c.estado === 'ligado' ? '1' : '0') + (c.categoria || ''),
  }));
  return tabela({
    id: 'mod-conectores',
    titulo: t('Conectores'),
    nota: t('Integrações com outros sistemas, agrupadas por categoria. Um conector fica ligado quando a informática define a sua configuração no servidor.'),
    rows: linhas,
    sort: 'ordem',
    dir: 1,
    cols: [
      { key: 'estado_txt', label: t('Estado'), p: 0, render: (v, r) => pill(v, r.estado === 'ligado' ? 'p-ok' : 'p-warn') },
      {
        key: 'nome',
        label: t('Conector'),
        p: 0,
        wrap: true,
        get: (r) => t(r.nome),
        render: (v, r) => {
          const d = el('div', 'con-nome');
          d.append(el('b', null, t(r.nome)), el('span', null, t(r.descricao || '')));
          if (r.estado !== 'ligado' && r.definicao) d.append(el('small', 'mono', t('Por configurar no servidor: {k}', { k: r.definicao })));
          return d;
        },
      },
      { key: 'categoria', label: t('Categoria'), get: (r) => t(r.categoria || '') },
      { key: 'fornecedor', label: t('Fornecedor'), get: (r) => t(r.fornecedor || '') },
      { key: 'protocolo', label: t('Protocolo'), get: (r) => t(r.protocolo || '') },
      { key: 'sentido', label: t('Sentido dos dados'), get: (r) => t(SENTIDO[r.sentido] || r.sentido || '') },
      { key: 'modulos', label: t('Usado por'), wrap: true, get: (r) => (r.modulos || []).map((x) => t(x)).join(', ') },
    ],
  });
}
