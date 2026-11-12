// Logística › Requisições de reposição da carrinha ao armazém. A conciliação da carrinha cria sozinha a requisição
// com o que foi vendido (rascunho); o promotor revê as quantidades, junta outros artigos e grava quantas vezes
// precisar. Ao emitir (PDF ou impressora) o documento fica fechado; para mais mercadoria, uma nova requisição.
// Servidor: nucleo/api/v1/requisicoes.php e nucleo/servicos/requisicoes.php.
import { apiJson } from '../api.js';
import { draw } from '../app.js';
import { pill, tabela, tiles } from '../componentes/blocos.js';
import { botao, confirmarAccao, entrada, msgErro } from '../componentes/janelas.js';
import { marcarPorGravar } from '../componentes/rascunho.js';
import { requisicaoPdf } from '../componentes/requisicao-pdf.js';
import { dl, n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { atual } from '../perfil.js';
import { hoje, st } from '../periodo.js';
import { postJson } from '../servidor.js';
import { el, toast } from '../util.js';
import { R } from './registo.js';

let L = null; // lista do período
let chaveLista = '';
let aLer = false;
let aberta = null; // requisição aberta no editor
let edicao = null; // cópia em edição: {linhas, nota}
let CAT = null; // catálogo de artigos
export const ESTADOS_REQ = {
  rascunho: ['Rascunho', 'p-warn'],
  emitida: ['Emitida · a carregar', 'p-serio'],
  carregada: ['Carregada', 'p-ok'],
  anulada: ['Anulada', 'p-mut'],
};
const PEDIDO_ABRIR = 'vm-requisicao-abrir';

function ler() {
  const k = st.de + '|' + st.ate;
  if (aLer) return;
  aLer = true;
  apiJson('requisicoes?de=' + st.de + '&ate=' + st.ate).then((d) => {
    aLer = false;
    L = d && d.ok ? d : { falhou: (d && d.erro) || t('Não foi possível ler os dados.') };
    chaveLista = k;
    if (atual === 'requisicoes-carrinha') draw();
  });
}
function catalogo() {
  if (CAT) return;
  CAT = { a_ler: true, artigos: [] };
  apiJson('requisicoes?catalogo=1').then((d) => {
    CAT = { artigos: (d && d.artigos) || [] };
    if (atual === 'requisicoes-carrinha' && aberta) draw();
  });
}
function abrir(numero) {
  apiJson('requisicoes?numero=' + encodeURIComponent(numero)).then((d) => {
    if (!d || !d.ok) return toast(t((d && d.erro) || 'Não foi possível ler os dados.'), 5000);
    aberta = d.requisicao;
    edicao = { linhas: aberta.linhas.map((l) => ({ ...l })), nota: aberta.nota || '', mudou: false };
    if (aberta.estado === 'rascunho') catalogo();
    draw();
    setTimeout(() => {
      const s = document.getElementById('req-editor');
      if (s) s.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 50);
  });
}
function fecharEditor() {
  aberta = null;
  edicao = null;
  marcarPorGravar('requisicao', false);
  draw();
}
const actualizarLista = () => {
  L = null;
  ler();
};

/** Envia uma acção; devolve a requisição actualizada (ou null, com a mensagem no erro). */
function enviar(corpo, erro) {
  return postJson('requisicoes', corpo).then((x) => {
    if (!x.ok) {
      erro.hidden = false;
      erro.textContent = t(x.erro || 'Não foi possível gravar.');
      return null;
    }
    erro.hidden = true;
    return x.requisicao;
  });
}

function editor() {
  const r = aberta,
    rasc = r.estado === 'rascunho' && L && L.gere;
  const s = el('section', 'glass sec req-editor');
  s.id = 'req-editor';
  const h = el('div', 'sec-h');
  const hl = el('div');
  const [txt, cls] = ESTADOS_REQ[r.estado] || [r.estado, 'p-mut'];
  const tit = el('h2', null, t('Requisição {n}', { n: r.numero }) + ' ');
  tit.append(pill(t(txt), cls));
  hl.append(tit);
  const origem =
    r.origem && r.origem.tipo === 'conciliacao'
      ? t('criada pela conciliação da carrinha de {d}', { d: dl(r.origem.data) })
      : t('criada por {p}', { p: r.criado_por });
  hl.append(el('p', null, [r.rota + ' · ' + r.promotor, t('dia {d}', { d: dl(r.data) }), origem].join(' · ')));
  h.append(hl);
  const tools = el('div', 'sec-tools');
  tools.append(botao(t('Fechar'), 'ghost sm', fecharEditor));
  h.append(tools);
  s.append(h);
  const erro = msgErro();
  const mudou = () => {
    edicao.mudou = true;
    marcarPorGravar('requisicao', true);
    estadoGravacao.textContent = t('Alterações por gravar.');
  };
  const estadoGravacao = el('p', 'note req-estado');
  estadoGravacao.setAttribute('aria-live', 'polite');
  if (edicao.mudou) {
    marcarPorGravar('requisicao', true);
    estadoGravacao.textContent = t('Alterações por gravar.');
  }

  // Linhas
  const tab = el('table', 'cc-tab req-tab');
  const cab = el('tr');
  const comEntregue = r.estado === 'carregada';
  [t('Código'), t('Artigo'), t('Un.'), t('Vendido'), t('Quantidade'), ...(comEntregue ? [t('Entregue')] : []), ''].forEach((x, i) =>
    cab.append(el('th', i >= 3 && x ? 'n' : null, x)),
  );
  tab.append(cab);
  edicao.linhas.forEach((l, i) => {
    const tr = el('tr', l.origem === 'adicionado' ? 'req-adicionado' : null);
    tr.append(el('td', null, l.codigo), el('td', null, l.artigo), el('td', null, l.unidade || ''), el('td', 'n', l.sugerida == null ? '—' : n0(l.sugerida)));
    const tq = el('td', 'n');
    if (rasc) {
      const q = entrada('number', l.quantidade, { min: 0, step: 'any', inputMode: 'decimal' });
      q.setAttribute('aria-label', t('Quantidade') + ' · ' + l.artigo);
      q.addEventListener('input', () => {
        l.quantidade = q.value === '' ? 0 : Number(q.value);
        mudou();
      });
      tq.append(q);
    } else tq.textContent = n0(l.quantidade);
    tr.append(tq);
    if (comEntregue) tr.append(el('td', 'n' + (l.entregue !== l.quantidade ? ' cc-neg' : ''), n0(l.entregue)));
    const tx = el('td');
    if (rasc) {
      const b = botao('×', 'ghost sm', () => {
        edicao.linhas.splice(i, 1);
        mudou();
        draw();
      });
      b.setAttribute('aria-label', t('Retirar {a}', { a: l.artigo }));
      tx.append(b);
    }
    tr.append(tx);
    tab.append(tr);
  });
  if (!edicao.linhas.length) {
    const tr = el('tr');
    const td = el('td', 'note', t('Sem artigos. Junte os artigos a requisitar.'));
    td.colSpan = 6;
    tr.append(td);
    tab.append(tr);
  }
  const wrap = el('div', 'cc-scroll');
  wrap.append(tab);
  s.append(wrap);
  const total = edicao.linhas.reduce((a, l) => a + (+l.quantidade || 0), 0);
  s.append(el('p', 'note', t('{n} artigos · quantidade total {q}', { n: n0(edicao.linhas.length), q: n0(total) })));

  if (rasc) {
    // Juntar artigo (do catálogo dos armazéns, com o disponível)
    const junta = el('div', 'req-juntar');
    const dlId = 'req-cat';
    const lista = el('datalist');
    lista.id = dlId;
    ((CAT && CAT.artigos) || []).forEach((a) => {
      const o = el('option');
      o.value = a.codigo + ' · ' + a.artigo;
      if (a.disponivel != null) o.label = t('disponível no armazém: {q}', { q: n0(a.disponivel) });
      lista.append(o);
    });
    const art = entrada('text', '', { placeholder: t('Artigo (nome ou código)') });
    art.setAttribute('list', dlId);
    art.setAttribute('aria-label', t('Artigo a juntar'));
    const qt = entrada('number', '', { min: 0, step: 'any', inputMode: 'decimal', placeholder: t('Qtd.') });
    qt.setAttribute('aria-label', t('Quantidade a juntar'));
    const juntar = () => {
      const v = art.value.trim();
      const a = ((CAT && CAT.artigos) || []).find((x) => v === x.codigo + ' · ' + x.artigo || v === x.codigo);
      if (!a) {
        erro.hidden = false;
        erro.textContent = t('Escolha um artigo da lista.');
        return art.focus();
      }
      if (!(Number(qt.value) > 0)) {
        erro.hidden = false;
        erro.textContent = t('Indique a quantidade.');
        return qt.focus();
      }
      erro.hidden = true;
      const ja = edicao.linhas.find((l) => l.codigo === a.codigo);
      if (ja) ja.quantidade = (+ja.quantidade || 0) + Number(qt.value);
      else
        edicao.linhas.push({
          codigo: a.codigo,
          artigo: a.artigo,
          unidade: a.unidade || '',
          sugerida: null,
          quantidade: Number(qt.value),
          origem: 'adicionado',
        });
      mudou();
      draw();
    };
    qt.addEventListener('keydown', (e) => e.key === 'Enter' && juntar());
    junta.append(lista, art, qt, botao(t('Juntar artigo'), 'ghost sm', juntar));
    if (CAT && CAT.a_ler) junta.append(el('span', 'note', t('A carregar…')));
    s.append(junta);
    const nota = el('textarea', 'req-nota');
    nota.maxLength = 500;
    nota.rows = 2;
    nota.placeholder = t('Nota para o armazém (opcional)');
    nota.setAttribute('aria-label', t('Nota para o armazém'));
    nota.value = edicao.nota;
    nota.addEventListener('input', () => {
      edicao.nota = nota.value;
      mudou();
    });
    s.append(nota);
  } else if (r.nota) s.append(el('p', 'note', t('Nota') + ': ' + r.nota));

  s.append(estadoGravacao, erro);
  const acc = el('div', 'cc-acc');
  const corpoGravar = () => ({ accao: 'gravar', numero: r.numero, versao: r.versao, linhas: edicao.linhas, nota: edicao.nota });
  const depois = (x, msg) => {
    if (!x) return null;
    aberta = x;
    edicao = { linhas: x.linhas.map((l) => ({ ...l })), nota: x.nota || '', mudou: false };
    marcarPorGravar('requisicao', false);
    if (msg) toast(t(msg), 4000);
    actualizarLista();
    draw();
    return x;
  };
  if (rasc) {
    const gravar = botao(t('Gravar'), 'ghost', () => {
      gravar.disabled = true;
      enviar(corpoGravar(), erro).then((x) => {
        gravar.disabled = false;
        depois(x, 'Requisição gravada. Pode voltar a editar até a emitir.');
      });
    });
    const emitir = botao(t('Gravar, emitir e imprimir'), null, () =>
      confirmarAccao(
        t('Emitir a requisição {n}?', { n: r.numero }),
        t('Depois de emitida (PDF ou impressora) a requisição fica fechada e não se pode alterar. Para mais mercadoria, faça uma nova requisição.'),
        t('Emitir e imprimir'),
        () =>
          enviar(corpoGravar(), erro).then((g) => {
            if (!g) return { ok: false, erro: erro.textContent };
            return enviar({ accao: 'emitir', numero: g.numero, versao: g.versao }, erro).then((x) => {
              if (!x) return { ok: false, erro: erro.textContent };
              depois(x, 'Requisição emitida e fechada.');
              return requisicaoPdf(x, true)
                .then(() => ({ ok: true }))
                .catch((e) => {
                  console.error(e);
                  toast(t('Não foi possível gerar o PDF.'), 5000);
                  return { ok: true };
                });
            });
          }),
      ),
    );
    const anular = botao(t('Anular'), 'ghost', () => pedirMotivo(r, depois));
    acc.append(anular, gravar, emitir);
  } else if (r.estado === 'emitida' || r.estado === 'carregada') {
    if (r.estado === 'carregada')
      s.append(
        el(
          'p',
          'note',
          t('Carga confirmada por {p} em {d}', {
            p: r.confirmado_por,
            d: dl(String(r.confirmado_em).slice(0, 10)) + ' ' + String(r.confirmado_em).slice(11, 16),
          }),
        ),
      );
    const quem = el(
      'p',
      'note',
      t('Emitida por {p} em {d}', { p: r.emitida_por, d: dl(String(r.emitida_em).slice(0, 10)) + ' ' + String(r.emitida_em).slice(11, 16) }),
    );
    s.append(quem);
    acc.append(
      botao(t('Reimprimir (2.ª via)'), 'ghost', () =>
        enviar({ accao: 'reimprimir', numero: r.numero }, erro).then((x) => {
          if (!x) return;
          depois(x);
          requisicaoPdf(x, true).catch(() => toast(t('Não foi possível gerar o PDF.'), 5000));
        }),
      ),
    );
    if (L && L.gere) acc.append(botao(t('Nova requisição para esta rota'), null, () => nova(r.rota, erro)));
  } else if (r.estado === 'anulada') s.append(el('p', 'note', t('Anulada por {p}: {m}', { p: r.anulada_por, m: r.motivo_anulacao })));
  s.append(acc);
  return s;
}

function pedirMotivo(r, depois) {
  import('../componentes/janelas.js').then(({ janela }) =>
    janela(t('Anular a requisição {n}?', { n: r.numero }), t('A requisição fica registada como anulada, com o motivo.'), (b, ft, fechar) => {
      const m = el('textarea');
      m.rows = 3;
      m.maxLength = 300;
      m.setAttribute('aria-label', t('Motivo da anulação'));
      m.placeholder = t('Motivo da anulação');
      const e2 = msgErro();
      b.append(m, e2);
      ft.append(
        botao(t('Cancelar'), 'ghost', fechar),
        botao(t('Anular'), null, () =>
          enviar({ accao: 'anular', numero: r.numero, versao: r.versao, motivo: m.value }, e2).then((x) => {
            if (x) {
              fechar();
              depois(x, 'Requisição anulada.');
            }
          }),
        ),
      );
    }),
  );
}

function nova(rota, erro) {
  return enviar({ accao: 'criar', rota, data: hoje() }, erro).then((x) => {
    if (!x) return;
    toast(t('Requisição {n} criada.', { n: x.numero }), 4000);
    actualizarLista();
    abrir(x.numero);
  });
}

R['requisicoes-carrinha'] = (pg) => {
  // pedido de abrir uma requisição (ligação da conciliação da carrinha)
  try {
    const p = sessionStorage.getItem(PEDIDO_ABRIR);
    if (p) {
      sessionStorage.removeItem(PEDIDO_ABRIR);
      abrir(p);
    }
  } catch (e) {
    /* sem armazenamento */
  }
  if (!L || chaveLista !== st.de + '|' + st.ate) {
    pg.append(el('div', 'glass empty', t('A carregar…')));
    ler();
    return;
  }
  if (L.falhou) {
    pg.append(el('div', 'glass empty', t(L.falhou)));
    return;
  }
  const rs = L.requisicoes;
  const n = (e) => rs.filter((r) => r.estado === e).length;
  pg.append(
    tiles([
      { l: t('Requisições'), v: n0(rs.length), s: t('no período escolhido') },
      { l: t('Em rascunho'), v: n0(n('rascunho')), tone: n('rascunho') ? 'warn' : null, s: t('por rever e emitir') },
      { l: t('Emitidas'), v: n0(n('emitida') + n('carregada')), s: t('fechadas · por carregar: {n}', { n: n0(n('emitida')) }) },
      {
        l: t('Quantidade requisitada'),
        v: n0(rs.filter((r) => r.estado !== 'anulada').reduce((s, r) => s + r.quantidade, 0)),
        s: t('{n} anuladas', { n: n0(n('anulada')) }),
      },
    ]),
  );
  // Nova requisição (fora da conciliação: para mais mercadoria depois de emitida a anterior)
  if (L.gere) {
    const rotas = Object.entries(L.rotas || {});
    const b = el('section', 'glass sec req-nova');
    const erro = msgErro();
    const sel = el('select');
    sel.setAttribute('aria-label', t('Rota'));
    rotas.forEach(([r, p]) => {
      const o = el('option', null, r + ' · ' + p);
      o.value = r;
      sel.append(o);
    });
    b.append(
      el('b', null, t('Nova requisição')),
      sel,
      botao(t('Criar'), 'sm', () => nova(sel.value, erro)),
      el('span', 'note', t('A conciliação da carrinha cria sozinha a requisição com o que foi vendido.')),
      erro,
    );
    if (rotas.length) pg.append(b);
  }
  if (aberta) pg.append(editor());
  const linhaBtn = (v, r) => {
    const b = el('button', 'linkbtn', v);
    b.type = 'button';
    b.addEventListener('click', () => abrir(r.numero));
    return b;
  };
  pg.append(
    tabela({
      id: 'req',
      titulo: t('Requisições de reposição'),
      nota: t('Uma requisição por documento; o número é sequencial por ano. Abra para rever, gravar, emitir ou reimprimir.'),
      rows: rs.map((r) => ({
        ...r,
        estado_txt: t((ESTADOS_REQ[r.estado] || [r.estado])[0]),
        origem_txt: r.origem && r.origem.tipo === 'conciliacao' ? t('Conciliação') : t('Pedido do promotor'),
        promotor_rota: r.rota + ' · ' + r.promotor,
      })),
      sort: 'numero',
      cols: [
        { key: 'numero', label: t('Número'), p: 0, render: linhaBtn },
        { key: 'data', label: t('Dia'), type: 'date' },
        { key: 'promotor_rota', label: t('Promotor'), p: 1 },
        { key: 'origem_txt', label: t('Origem'), p: 4 },
        {
          key: 'estado_txt',
          label: t('Estado'),
          p: 1,
          render: (v, r) => pill(v, (ESTADOS_REQ[r.estado] || [0, 'p-mut'])[1]),
        },
        { key: 'n_linhas', label: t('Artigos'), type: 'n', p: 3 },
        { key: 'quantidade', label: t('Quantidade'), type: 'n', p: 2 },
        { key: 'emitida_em', label: t('Emitida'), p: 5, get: (r) => (r.emitida_em ? dl(r.emitida_em.slice(0, 10)) + ' ' + r.emitida_em.slice(11, 16) : '—') },
      ],
    }),
  );
};

/** Pede à página das requisições que abra uma (ligação a partir da conciliação). */
export function pedirAbrirRequisicao(numero) {
  try {
    sessionStorage.setItem(PEDIDO_ABRIR, numero);
  } catch (e) {
    /* sem armazenamento: abre a lista */
  }
}
