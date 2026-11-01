// Equipamentos: smartphones e impressoras móveis do terreno (lista, ficha, eventos, associações) e os documentos
// de entrega, devolução, substituição, perda e abate, com assinatura no ecrã (ver nucleo/servicos/equipamentos*.php).
// A ficha abre-se por #equipamentos?id=NAV-SMT-0001 (também a partir do código QR da etiqueta) e um documento por
// #equipamentos-documentos?doc=TER-2026-000001. Os dados vêm da base de dados do módulo, pela API (v1/equipamentos).
import { apiDescarregar, apiFetch } from '../api.js';
import { draw } from '../app.js';
import { AreaAssinatura } from '../componentes/assinatura.js';
import { pill, tabela, tiles } from '../componentes/blocos.js';
import { botao, campo, entrada, janela, msgErro } from '../componentes/janelas.js';
import { FV, filtroAtivo } from '../filtros.js';
import { dl, n0 } from '../formatos.js';
import { t } from '../i18n/i18n.js';
import { SESSAO } from '../perfil.js';
import { postJson } from '../servidor.js';
import { el, toast } from '../util.js';
import { R } from './registo.js';

const VALIDADE = 2 * 60000;
let lista = { estado: '', dados: null, erro: '', em: 0 };
let docs = { estado: '', dados: null, erro: '', em: 0 };
const fichas = {}; // id → {estado, dados, erro}
const documentos = {}; // número → {estado, dados, erro}
let fichaId = '',
  docNum = '';
// Área de assinatura em curso: o mesmo elemento (com o desenho, mesmo a meio de um traço) volta à página quando
// esta é desenhada de novo (ex.: actualização automática dos dados)
let emCurso = { chave: '', el: null };

export const ESTADOS = {
  EM_STOCK: ['Em stock', 'p-mut'],
  ATRIBUIDO: ['Atribuído', 'p-ok'],
  EM_REPARACAO: ['Em reparação', 'p-warn'],
  PERDIDO_ROUBADO: ['Perdido ou roubado', 'p-bad'],
  ABATIDO: ['Abatido', 'p-mut'],
};
const EVENTOS = {
  ENTRADA: 'Entrada no stock',
  ETIQUETAGEM: 'Etiquetagem',
  ATRIBUICAO: 'Atribuição',
  DEVOLUCAO: 'Devolução',
  ASSOCIACAO: 'Associação',
  DESASSOCIACAO: 'Desassociação',
  REPARACAO_ENVIO: 'Envio para reparação',
  REPARACAO_REGRESSO: 'Regresso da reparação',
  SUBSTITUICAO: 'Substituição',
  PERDA_ROUBO: 'Perda ou roubo',
  RECUPERACAO: 'Recuperação',
  REPOSICAO_MDM: 'Reposição no MDM',
  LEITURA_QR: 'Leitura do código QR',
  INVENTARIO: 'Inventário',
  ABATE: 'Abate',
  DOCUMENTO_ASSINADO: 'Documento assinado',
};
// Eventos que se registam à mão e estados de onde partem (igual a NAVAL_ATV_TRANSICOES no servidor)
const TRANSICOES = {
  ATRIBUICAO: ['EM_STOCK'],
  DEVOLUCAO: ['ATRIBUIDO'],
  REPARACAO_ENVIO: ['EM_STOCK', 'ATRIBUIDO'],
  REPARACAO_REGRESSO: ['EM_REPARACAO'],
  PERDA_ROUBO: ['EM_STOCK', 'ATRIBUIDO', 'EM_REPARACAO'],
  RECUPERACAO: ['PERDIDO_ROUBADO'],
  ABATE: ['EM_STOCK', 'EM_REPARACAO', 'PERDIDO_ROUBADO'],
  ETIQUETAGEM: null,
  INVENTARIO: null,
};
const DOCS = {
  TERMO_ENTREGA: 'Termo de Entrega e Responsabilidade',
  AUTO_DEVOLUCAO: 'Auto de Devolução',
  AUTO_SUBSTITUICAO: 'Auto de Substituição',
  PARTICIPACAO_PERDA: 'Participação de Perda ou Roubo',
  AUTO_ABATE: 'Auto de Abate',
  CONVENCAO_PROVA: 'Convenção de Prova Electrónica',
};
const ESTADOS_DOC = { A_ASSINAR: ['Por assinar', 'p-warn'], ASSINADO: ['Assinado', 'p-ok'], ANULADO: ['Anulado', 'p-mut'] };
const TIPOS = { SMT: 'Smartphone', IMP: 'Impressora móvel' };

const nomeEstado = (e) => t((ESTADOS[e] || [e])[0]);
const pillEstado = (e) => pill(nomeEstado(e), (ESTADOS[e] || [])[1] || 'p-mut');
const nomeTipo = (x) => t(TIPOS[x] || x);
const dataHora = (s) => (s ? dl(String(s).slice(0, 10)) + (String(s).length > 10 ? ' ' + String(s).slice(11, 16) : '') : '—');
const pode = () => (lista.dados && lista.dados.pode) || {};
const erroDe = (d) => t((d && d.erro) || 'Não foi possível concluir.');

/** Parâmetro do endereço (#página?chave=valor). */
const paramHash = (k) => new URLSearchParams(location.hash.split('?')[1] || '').get(k) || '';

/** Pedido com cache curta; ao chegar a resposta, volta a desenhar. */
function pedir(alvo, rota, aoChegar) {
  const fresco = Date.now() - alvo.em < VALIDADE;
  if (alvo.estado === 'a-carregar' || (alvo.estado && fresco)) return alvo.estado;
  alvo.estado = 'a-carregar';
  alvo.em = Date.now();
  apiFetch(rota)
    .then((r) => r.json())
    .catch(() => null)
    .then((d) => {
      alvo.dados = d;
      alvo.estado = d && d.disponivel !== false && !d.erro && d.ok !== false ? 'ok' : 'erro';
      alvo.erro = (d && d.erro) || 'O módulo Equipamentos precisa do servidor da plataforma e da sua base de dados.';
      if (aoChegar) aoChegar(d);
      draw();
    });
  return 'a-carregar';
}
const garantirLista = () => pedir(lista, 'equipamentos');
const garantirDocs = () => pedir(docs, 'equipamentos/documentos');
const garantirFicha = (id) =>
  pedir(
    (fichas[id] = fichas[id] || { estado: '', em: 0 }),
    'equipamentos/ficha?id=' + encodeURIComponent(id) + (paramHash('origem') === 'qr' ? '&origem=qr' : ''),
  );
const garantirDoc = (n) => pedir((documentos[n] = documentos[n] || { estado: '', em: 0 }), 'equipamentos/documentos?numero=' + encodeURIComponent(n));

/** Depois de uma alteração: esquece o que está em memória e volta a desenhar. */
function renovar() {
  emCurso = { chave: '', el: null };
  lista = { estado: '', dados: null, erro: '', em: 0 };
  docs = { estado: '', dados: null, erro: '', em: 0 };
  Object.keys(fichas).forEach((k) => delete fichas[k]);
  Object.keys(documentos).forEach((k) => delete documentos[k]);
  draw();
}

function estadoPedido(pg, estado, erro, titulo) {
  const s = el('section', 'glass sec fixo');
  if (estado === 'erro') {
    s.append(el('h2', null, t(titulo)), el('p', 'aviso', t(erro)));
    s.append(botao(t('Tentar de novo'), '', renovar));
  } else s.append(el('h2', null, t('A carregar…')));
  pg.append(s);
}

const ligacao = (txt, href, cls) => {
  const a = el('a', cls || 'lnk', txt);
  a.href = href;
  return a;
};
const caixa = (titulo, cls) => {
  const s = el('section', 'glass sec fixo atv-caixa' + (cls ? ' ' + cls : ''));
  if (titulo) s.append(el('h3', 'atv-rot', titulo));
  return s;
};
const dado = (rotulo, valor, mono) => {
  if (valor == null || valor === '') return document.createTextNode(''); // só os dados preenchidos (ecrã do telemóvel)
  const d = el('div', 'atv-dado');
  d.append(el('span', null, rotulo), el('b', mono ? 'mono' : null, valor == null || valor === '' ? '—' : String(valor)));
  return d;
};

// ───────────────────────────── Lista ─────────────────────────────

function desenharLista(pg) {
  const est = garantirLista();
  if (est !== 'ok') return estadoPedido(pg, est, lista.erro, 'Não foi possível ler os equipamentos');
  const d = lista.dados,
    p = d.pode || {};
  let L = d.equipamentos || [];
  if (filtroAtivo('equipamentos', 'rota')) L = L.filter((x) => FV.rota.includes(String(x.rota)));
  const conta = (e) => L.filter((x) => x.estado === e).length;
  const semTermo = L.filter((x) => x.sem_termo).length;
  pg.append(
    tiles([
      {
        l: t('Equipamentos'),
        v: n0(L.length),
        s: t('{a} smartphones · {b} impressoras', { a: n0(L.filter((x) => x.tipo === 'SMT').length), b: n0(L.filter((x) => x.tipo === 'IMP').length) }),
      },
      { l: t('Atribuídos'), v: n0(conta('ATRIBUIDO')) },
      { l: t('Em stock'), v: n0(conta('EM_STOCK')) },
      { l: t('Em reparação'), v: n0(conta('EM_REPARACAO')), tone: conta('EM_REPARACAO') ? 'warn' : null },
      { l: t('Sem termo assinado'), v: n0(semTermo), tone: semTermo ? 'warn' : null, s: t('atribuídos sem Termo de Entrega') },
    ]),
  );
  const accoes = el('div', 'atv-accoes');
  if (p.administrar) accoes.append(botao(t('Registar equipamento'), '', () => dialogoRegistar()));
  if (p.movimentar) accoes.append(botao(t('Emitir documento'), 'ghost', () => dialogoEmitir({})));
  accoes.append(ligacao(t('Documentos'), '#equipamentos-documentos', 'btn ghost'));
  if (accoes.children.length) pg.append(accoes);
  const rotaTxt = (r) => (r.rota ? t('Rota') + ' ' + r.rota : '—');
  pg.append(
    tabela({
      id: 'equipamentos',
      titulo: t('Equipamentos'),
      nota: t('Smartphones e impressoras móveis do terreno. Toque no ID para abrir a ficha.'),
      rows: L.map((x) => ({ ...x, tipo_txt: nomeTipo(x.tipo), modelo_txt: [x.marca, x.modelo].filter(Boolean).join(' ') })),
      sort: 'id',
      cols: [
        { key: 'id', label: t('ID'), p: 0, nowrap: true, render: (v) => ligacao(v, '#equipamentos?id=' + encodeURIComponent(v), 'lnk mono') },
        { key: 'tipo_txt', label: t('Tipo'), p: 3 },
        { key: 'modelo_txt', label: t('Marca e modelo'), wrap: true, p: 3 },
        {
          key: 'estado',
          label: t('Estado'),
          p: 0,
          get: (r) => nomeEstado(r.estado),
          render: (v, r) => pillEstado(r ? r.estado : v),
          xl: (r) => nomeEstado(r.estado),
        },
        { key: 'rota', label: t('Rota'), p: 1, get: rotaTxt },
        { key: 'responsavel', label: t('Responsável'), wrap: true, p: 1 },
        { key: 'associado', label: t('Associado a'), p: 2, nowrap: true },
        {
          key: 'sem_termo',
          label: t('Termo'),
          p: 2,
          get: (r) => (r.sem_termo ? t('Por assinar') : r.estado === 'ATRIBUIDO' ? t('Assinado') : '—'),
          render: (v, r) =>
            r && r.sem_termo ? pill(t('Por assinar'), 'p-warn') : document.createTextNode(r && r.estado === 'ATRIBUIDO' ? t('Assinado') : '—'),
          xl: (r) => (r.sem_termo ? t('Por assinar') : r.estado === 'ATRIBUIDO' ? t('Assinado') : ''),
        },
      ],
    }),
  );
  if (!L.length) pg.append(el('div', 'glass empty', t('Ainda não há equipamentos registados.')));
  const C = d.colaboradores || [];
  pg.append(
    tabela({
      id: 'equipamentos-colaboradores',
      titulo: t('Colaboradores'),
      nota: t('Pessoas a quem os equipamentos são entregues. A Convenção de Prova Electrónica assina-se uma vez, antes do primeiro termo.'),
      rows: C.map((c) => ({ ...c, estado_txt: c.activo ? t('Activo') : t('Inactivo') })),
      sort: 'nome',
      cols: [
        {
          key: 'nome',
          label: t('Nome'),
          p: 0,
          wrap: true,
          render: (v, r) => {
            if (!p.administrar || !r) return document.createTextNode(v);
            const b = botao(v, 'lnk', () => dialogoColaborador(r));
            b.className = 'lnk';
            return b;
          },
        },
        { key: 'funcao', label: t('Função'), p: 3 },
        { key: 'rota', label: t('Rota'), p: 1 },
        { key: 'utilizador', label: t('Conta do portal'), p: 3 },
        {
          key: 'convencao',
          label: t('Convenção de prova'),
          p: 1,
          get: (r) => r.convencao || t('Por assinar'),
          render: (v, r) =>
            r && r.convencao ? ligacao(r.convencao, '#equipamentos-documentos?doc=' + r.convencao, 'lnk mono') : pill(t('Por assinar'), 'p-warn'),
        },
        { key: 'estado_txt', label: t('Estado'), p: 2 },
      ],
    }),
  );
  if (p.administrar) {
    const tools = pg.querySelector('#s-equipamentos-colaboradores .sec-tools');
    if (tools) tools.prepend(botao(t('Novo colaborador'), 'sm', () => dialogoColaborador({})));
  }
}

// ───────────────────────────── Ficha ─────────────────────────────

function desenharFicha(pg, id) {
  const f = fichas[id] || {};
  const est = garantirFicha(id);
  garantirLista();
  if (est !== 'ok') {
    const s = caixa(null);
    s.append(ligacao('‹ ' + t('Equipamentos'), '#equipamentos', 'lnk'));
    if (est === 'erro' && f.dados && f.dados.codigo === 'atv_nao_registado') {
      s.append(el('h2', null, id), el('p', 'aviso', t('Este equipamento ainda não está registado.')));
      if (pode().administrar) s.append(botao(t('Registar equipamento'), '', () => dialogoRegistar()));
      pg.append(s);
      return;
    }
    pg.append(s);
    return estadoPedido(pg, est, f.erro, 'Não foi possível abrir a ficha');
  }
  const d = f.dados,
    e = d.equipamento,
    p = d.pode || pode();
  const cab = el('section', 'glass sec fixo atv-cab');
  cab.append(ligacao('‹ ' + t('Equipamentos'), '#equipamentos', 'lnk'));
  cab.append(el('div', 'atv-sobre', nomeTipo(e.tipo)), el('h2', 'mono atv-id', e.id));
  const pills = el('div', 'atv-pills');
  pills.append(pillEstado(e.estado));
  if (e.rota) pills.append(pill(t('Rota') + ' ' + e.rota, 'p-mut'));
  if (e.estado_mdm) pills.append(pill(t('MDM') + ': ' + e.estado_mdm, 'p-mut'));
  cab.append(pills);
  pg.append(cab);

  const grelha = el('div', 'atv-grelha');
  const resp = caixa(t('Responsável actual'));
  if (e.responsavel) {
    resp.append(el('div', 'atv-nome', e.responsavel));
    const desde = (d.eventos || []).find((v) => v.tipo === 'ATRIBUICAO');
    resp.append(
      el('p', 'note', [e.rota ? t('Rota') + ' ' + e.rota : '', desde ? t('desde {d}', { d: dl(desde.data.slice(0, 10)) }) : ''].filter(Boolean).join(' · ')),
    );
  } else resp.append(el('p', 'note', t('Sem responsável: o equipamento está em {e}.', { e: nomeEstado(e.estado).toLowerCase() })));
  grelha.append(resp);
  const assoc = caixa(e.tipo === 'SMT' ? t('Impressora associada') : t('Smartphone associado'));
  if (d.associado) {
    const a = ligacao(d.associado.id, '#equipamentos?id=' + encodeURIComponent(d.associado.id), 'lnk mono atv-nome');
    assoc.append(a, el('p', 'note', [d.associado.modelo, nomeEstado(d.associado.estado)].filter(Boolean).join(' · ')));
  } else assoc.append(el('p', 'note', t('Sem associação em vigor.')));
  grelha.append(assoc);
  pg.append(grelha);

  const acc = el('div', 'atv-accoes');
  if (p.movimentar && e.estado !== 'ABATIDO') {
    acc.append(botao(t('Registar evento'), '', () => dialogoEvento(d)));
    acc.append(
      botao(t('Emitir documento'), 'ghost', () =>
        dialogoEmitir({ equipamentos: [e.id].concat(d.associado ? [d.associado.id] : []), colaborador: e.id_colaborador }),
      ),
    );
  }
  if (d.mdm_url) {
    const a = ligacao(t('Abrir no MDM'), d.mdm_url, 'btn ghost');
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    acc.append(a);
  }
  if (acc.children.length) pg.append(acc);

  (d.documentos || [])
    .filter((x) => x.estado === 'A_ASSINAR')
    .forEach((x) => {
      const s = caixa(null, 'atv-pendente');
      s.append(el('h3', null, t('{d} por assinar', { d: t(DOCS[x.tipo] || x.tipo) })), el('p', 'mono', x.numero));
      s.append(ligacao(t('Assinar agora'), '#equipamentos-documentos?doc=' + x.numero, 'btn'));
      pg.append(s);
    });

  const tec = caixa(t('Identificação técnica'));
  const g = el('div', 'atv-dados');
  g.append(dado(t('Marca e modelo'), [e.marca, e.modelo].filter(Boolean).join(' ')), dado(t('N.º de série'), e.num_serie, true));
  if (e.tipo === 'SMT') g.append(dado(t('IMEI 1'), e.imei1, true), dado(t('IMEI 2'), e.imei2, true), dado(t('Telefone'), e.telefone));
  if (e.mac || e.tipo === 'IMP') g.append(dado(t('MAC Bluetooth'), e.mac, true));
  g.append(
    dado(t('ID no MDM'), e.mdm_id),
    dado(t('Fornecedor'), e.fornecedor),
    dado(t('Fim da garantia'), e.fim_garantia ? dl(e.fim_garantia.slice(0, 10)) : null),
  );
  g.append(dado(t('Último inventário'), e.ultimo_inventario ? dataHora(e.ultimo_inventario) : null), dado(t('Registado em'), dataHora(e.criado_em)));
  tec.append(g);
  pg.append(tec);

  const hist = caixa(t('Histórico'));
  const ol = el('ol', 'atv-hist');
  (d.eventos || []).forEach((v) => {
    const li = el('li', v.tipo === 'LEITURA_QR' ? 'atv-qr' : null);
    let tit = t(EVENTOS[v.tipo] || v.tipo);
    if (v.relacionado) tit += ' · ' + v.relacionado;
    if (v.estado_depois && v.estado_antes !== v.estado_depois) tit += ' → ' + nomeEstado(v.estado_depois);
    li.append(el('b', null, tit));
    const det = [dataHora(v.data), v.utilizador, v.colaborador_depois, v.rota_depois ? t('Rota') + ' ' + v.rota_depois : '', v.documento, v.observacoes].filter(
      Boolean,
    );
    li.append(el('span', null, det.join(' · ')));
    ol.append(li);
  });
  hist.append(ol);
  pg.append(hist);

  const ds = caixa(t('Documentos'));
  if (!(d.documentos || []).length) ds.append(el('p', 'note', t('Sem documentos.')));
  (d.documentos || []).forEach((x) => {
    const linha = el('div', 'atv-doc');
    linha.append(
      ligacao(x.numero, '#equipamentos-documentos?doc=' + x.numero, 'lnk mono'),
      el('span', null, t(DOCS[x.tipo] || x.tipo)),
      pill(t(ESTADOS_DOC[x.estado][0]), ESTADOS_DOC[x.estado][1]),
    );
    ds.append(linha);
  });
  pg.append(ds);
}

// ───────────────────────────── Diálogos ─────────────────────────────

const seleccao = (opcoes, valor) => {
  const s = el('select');
  opcoes.forEach(([v, txt]) => {
    const o = el('option', null, txt);
    o.value = v;
    if (String(v) === String(valor == null ? '' : valor)) o.selected = true;
    s.append(o);
  });
  return s;
};
const areaTexto = (valor) => {
  const a = el('textarea');
  a.rows = 3;
  a.maxLength = 1000;
  if (valor) a.value = valor;
  return a;
};

/** Envia uma acção e trata a resposta: erro no diálogo, ou fecha e volta a desenhar. */
function enviar(btn, erro, corpo, fechar, depois) {
  btn.disabled = true;
  erro.hidden = true;
  return postJson('equipamentos', corpo).then((r) => {
    btn.disabled = false;
    if (!r || !r.ok) {
      erro.textContent = erroDe(r);
      erro.hidden = false;
      return;
    }
    fechar();
    if (depois) depois(r);
    renovar();
  });
}

function dialogoRegistar() {
  janela(t('Registar equipamento'), t('O ID (NAV-SMT-0001, NAV-IMP-0001…) é atribuído automaticamente e nunca se reutiliza.'), (b, ft, fechar) => {
    const tipo = seleccao(
      [
        ['SMT', t('Smartphone')],
        ['IMP', t('Impressora móvel')],
      ],
      'SMT',
    );
    const f = {};
    const campos = [
      ['marca', 'Marca', 'text'],
      ['modelo', 'Modelo', 'text'],
      ['num_serie', 'N.º de série', 'text'],
      ['imei1', 'IMEI 1', 'text', 'SMT'],
      ['imei2', 'IMEI 2', 'text', 'SMT'],
      ['telefone', 'Telefone', 'tel', 'SMT'],
      ['mac', 'MAC Bluetooth', 'text', 'IMP'],
      ['mdm_id', 'ID no MDM', 'text'],
      ['fornecedor', 'Fornecedor', 'text'],
    ];
    b.append(campo(t('Tipo'), tipo));
    const linhas = campos.map(([k, rot, tp, so]) => {
      f[k] = entrada(tp, '', { autocomplete: 'off', inputMode: k.startsWith('imei') ? 'numeric' : undefined });
      const c = campo(t(rot), f[k]);
      c.dataset.so = so || '';
      b.append(c);
      return c;
    });
    const mostrar = () => linhas.forEach((c) => (c.hidden = !!c.dataset.so && c.dataset.so !== tipo.value));
    tipo.addEventListener('change', mostrar);
    mostrar();
    const obs = areaTexto();
    b.append(campo(t('Observações'), obs));
    const erro = msgErro();
    b.append(erro);
    const go = botao(t('Registar'), '', () => {
      const corpo = { accao: 'registar', tipo: tipo.value, observacoes: obs.value };
      Object.keys(f).forEach((k) => {
        if (!f[k].closest('label').hidden) corpo[k] = f[k].value.trim();
      });
      enviar(go, erro, corpo, fechar, (r) => {
        toast(t('Equipamento {id} registado.', { id: r.equipamento.id }), 5000);
        location.hash = '#equipamentos?id=' + r.equipamento.id;
      });
    });
    ft.append(botao(t('Cancelar'), 'ghost', fechar), go);
  });
}

function dialogoColaborador(c) {
  janela(c.id ? t('Colaborador') : t('Novo colaborador'), t('A conta do portal permite ao colaborador assinar com a sua própria conta.'), (b, ft, fechar) => {
    const f = {
      nome: entrada('text', c.nome, { required: true, maxLength: 120 }),
      funcao: entrada('text', c.funcao, { maxLength: 80 }),
      rota: entrada('text', c.rota, { maxLength: 10, inputMode: 'numeric' }),
      telemovel: entrada('tel', c.telemovel, { maxLength: 20 }),
      email: entrada('email', c.email, { maxLength: 120 }),
      utilizador: entrada('text', c.utilizador, { maxLength: 120, autocomplete: 'off' }),
    };
    const rot = { nome: 'Nome', funcao: 'Função', rota: 'Rota', telemovel: 'Telemóvel', email: 'Correio electrónico', utilizador: 'Conta do portal' };
    Object.keys(f).forEach((k) => b.append(campo(t(rot[k]), f[k])));
    const activo = seleccao(
      [
        ['1', t('Activo')],
        ['0', t('Inactivo')],
      ],
      c.id && !c.activo ? '0' : '1',
    );
    b.append(campo(t('Estado'), activo));
    const erro = msgErro();
    b.append(erro);
    const go = botao(t('Guardar'), '', () => {
      const corpo = { accao: 'colaborador', id: c.id || 0, activo: activo.value === '1' };
      Object.keys(f).forEach((k) => (corpo[k] = f[k].value.trim()));
      enviar(go, erro, corpo, fechar, () => toast(t('Colaborador guardado.'), 4000));
    });
    ft.append(botao(t('Cancelar'), 'ghost', fechar), go);
  });
}

function dialogoEvento(ficha) {
  const e = ficha.equipamento,
    p = ficha.pode || pode();
  const possiveis = Object.keys(TRANSICOES).filter((k) => (!TRANSICOES[k] || TRANSICOES[k].includes(e.estado)) && (k !== 'ABATE' || p.administrar));
  const opcoes = possiveis.map((k) => [k, t(EVENTOS[k])]);
  const outros = ((lista.dados && lista.dados.equipamentos) || []).filter(
    (x) => x.tipo !== e.tipo && x.estado !== 'ABATIDO' && ['SMT', 'IMP'].includes(x.tipo),
  );
  if (['SMT', 'IMP'].includes(e.tipo) && outros.length)
    opcoes.push(['ASSOCIACAO', t(e.tipo === 'SMT' ? 'Associar a uma impressora' : 'Associar a um smartphone')]);
  if (e.associado) opcoes.push(['DESASSOCIACAO', t('Desassociar de {id}', { id: e.associado })]);
  janela(t('Registar evento'), e.id + ' · ' + nomeEstado(e.estado), (b, ft, fechar) => {
    const tipo = seleccao(opcoes, opcoes[0] && opcoes[0][0]);
    b.append(campo(t('Evento'), tipo));
    const colabs = ((lista.dados && lista.dados.colaboradores) || []).filter((c) => c.activo);
    const colab = seleccao([['', t('Escolha…')]].concat(colabs.map((c) => [c.id, c.nome + (c.rota ? ' · ' + t('Rota') + ' ' + c.rota : '')])), '');
    const rota = entrada('text', '', { maxLength: 10, inputMode: 'numeric' });
    colab.addEventListener('change', () => {
      const c = colabs.find((x) => String(x.id) === colab.value);
      if (c && c.rota) rota.value = c.rota;
    });
    const cColab = campo(t('Colaborador'), colab),
      cRota = campo(t('Rota'), rota);
    const outro = seleccao(
      outros.map((x) => [x.id, x.id + ' · ' + [x.marca, x.modelo].filter(Boolean).join(' ') + ' · ' + nomeEstado(x.estado)]),
      '',
    );
    const cOutro = campo(t('Equipamento'), outro);
    const incl = entrada('checkbox', null, { checked: !!e.associado });
    const cIncl = el('label', 'atv-check');
    cIncl.append(incl, el('span', null, t('Aplicar também ao equipamento associado ({id})', { id: e.associado || '—' })));
    const obs = areaTexto();
    b.append(cColab, cRota, cOutro, cIncl, campo(t('Observações'), obs));
    const mostrar = () => {
      const v = tipo.value;
      cColab.hidden = cRota.hidden = v !== 'ATRIBUICAO';
      cOutro.hidden = v !== 'ASSOCIACAO';
      cIncl.hidden = !e.associado || !['ATRIBUICAO', 'DEVOLUCAO', 'REPARACAO_ENVIO', 'PERDA_ROUBO'].includes(v);
    };
    tipo.addEventListener('change', mostrar);
    mostrar();
    const erro = msgErro();
    b.append(erro);
    if (!opcoes.length) b.append(el('p', 'note', t('Não há eventos para registar neste estado.')));
    const go = botao(t('Registar'), '', () => {
      const corpo = { accao: 'evento', id: e.id, tipo: tipo.value, observacoes: obs.value };
      if (tipo.value === 'ATRIBUICAO') Object.assign(corpo, { id_colaborador: +colab.value || 0, rota: rota.value.trim() });
      if (tipo.value === 'ASSOCIACAO') corpo.outro = outro.value;
      if (!cIncl.hidden) corpo.incluir_associado = incl.checked;
      enviar(go, erro, corpo, fechar, () => {
        if (tipo.value === 'ATRIBUICAO') toast(t('Atribuído. Emita e assine o Termo de Entrega.'), 7000);
      });
    });
    go.disabled = !opcoes.length;
    ft.append(botao(t('Cancelar'), 'ghost', fechar), go);
  });
}

function dialogoEmitir(pre) {
  const L = (lista.dados && lista.dados.equipamentos) || [];
  const colabs = ((lista.dados && lista.dados.colaboradores) || []).filter((c) => c.activo);
  janela(t('Emitir documento'), t('O documento fica bloqueado na emissão (impressão digital SHA-256) e segue para assinatura.'), (b, ft, fechar) => {
    const tipo = seleccao(
      Object.keys(DOCS).map((k) => [k, t(DOCS[k])]),
      pre.tipo || 'TERMO_ENTREGA',
    );
    const colab = seleccao(
      [['', t('Escolha…')]].concat(
        colabs.map((c) => [c.id, c.nome + (c.rota ? ' · ' + t('Rota') + ' ' + c.rota : '') + (c.convencao ? '' : ' · ' + t('sem convenção'))]),
      ),
      pre.colaborador || '',
    );
    const cColab = campo(t('Colaborador'), colab);
    b.append(campo(t('Documento'), tipo), cColab);
    const aviso = el('p', 'aviso');
    aviso.hidden = true;
    b.append(aviso);
    const caixaEq = el('div', 'atv-escolha');
    const cEq = el('div', 'campo');
    cEq.append(el('span', null, t('Equipamentos')), caixaEq);
    b.append(cEq);
    const linhas = L.filter((x) => x.estado !== 'ABATIDO' || (pre.equipamentos || []).includes(x.id)).map((x) => {
      const l = el('label', 'atv-check');
      const c = entrada('checkbox', null, { checked: (pre.equipamentos || []).includes(x.id), value: x.id });
      const papel = seleccao(
        [
          ['ENTREGUE', t('Entregue')],
          ['DEVOLVIDO', t('Devolvido')],
        ],
        x.estado === 'ATRIBUIDO' ? 'DEVOLVIDO' : 'ENTREGUE',
      );
      papel.className = 'atv-papel';
      const ace = entrada('text', '', { placeholder: t('Acessórios (carregador, cabo…)'), maxLength: 200 });
      const fis = entrada('text', '', { placeholder: t('Estado físico'), maxLength: 200 });
      ace.className = fis.className = 'atv-extra';
      l.append(
        c,
        el('span', 'mono', x.id),
        el('span', 'note', [nomeTipo(x.tipo), x.modelo, nomeEstado(x.estado), x.responsavel].filter(Boolean).join(' · ')),
        papel,
        ace,
        fis,
      );
      caixaEq.append(l);
      return { x, c, papel, ace, fis, l };
    });
    const obs = areaTexto();
    b.append(campo(t('Observações'), obs));
    const mostrar = () => {
      const v = tipo.value;
      cColab.hidden = v === 'AUTO_ABATE';
      cEq.hidden = v === 'CONVENCAO_PROVA';
      linhas.forEach((r) => {
        r.papel.hidden = v !== 'AUTO_SUBSTITUICAO';
        r.ace.hidden = r.fis.hidden = !['TERMO_ENTREGA', 'AUTO_DEVOLUCAO', 'AUTO_SUBSTITUICAO'].includes(v) || !r.c.checked;
      });
      const c = colabs.find((x) => String(x.id) === colab.value);
      const precisa = ['TERMO_ENTREGA', 'AUTO_SUBSTITUICAO'].includes(v) && c && !c.convencao;
      aviso.hidden = !precisa;
      aviso.textContent = precisa ? t('{n} ainda não assinou a Convenção de Prova Electrónica: emita-a e assine-a primeiro.', { n: c.nome }) : '';
    };
    [tipo, colab].forEach((x) => x.addEventListener('change', mostrar));
    linhas.forEach((r) => r.c.addEventListener('change', mostrar));
    mostrar();
    const erro = msgErro();
    b.append(erro);
    const go = botao(t('Emitir'), '', () => {
      const v = tipo.value;
      const corpo = { accao: 'emitir', tipo: v, id_colaborador: +colab.value || 0, observacoes: obs.value };
      if (v !== 'CONVENCAO_PROVA')
        corpo.equipamentos = linhas
          .filter((r) => r.c.checked)
          .map((r) => ({ id: r.x.id, papel: r.papel.value, acessorios: r.ace.value, estado_fisico: r.fis.value }));
      enviar(go, erro, corpo, fechar, (r) => {
        toast(t('Documento {n} emitido.', { n: r.documento.numero }), 5000);
        location.hash = '#equipamentos-documentos?doc=' + r.documento.numero;
      });
    });
    ft.append(botao(t('Cancelar'), 'ghost', fechar), go);
  });
}

// ───────────────────────────── Documentos ─────────────────────────────

function desenharDocumentos(pg) {
  const est = garantirDocs();
  garantirLista();
  if (est !== 'ok') return estadoPedido(pg, est, docs.erro, 'Não foi possível ler os documentos');
  const p = docs.dados.pode || {};
  let D = docs.dados.documentos || [];
  if (filtroAtivo('equipamentos-documentos', 'rota')) D = D.filter((x) => FV.rota.includes(String(x.rota)));
  const conta = (e) => D.filter((x) => x.estado === e).length;
  pg.append(
    tiles([
      { l: t('Documentos'), v: n0(D.length) },
      { l: t('Por assinar'), v: n0(conta('A_ASSINAR')), tone: conta('A_ASSINAR') ? 'warn' : null },
      { l: t('Assinados'), v: n0(conta('ASSINADO')) },
      { l: t('Anulados'), v: n0(conta('ANULADO')) },
    ]),
  );
  const acc = el('div', 'atv-accoes');
  if (p.movimentar) acc.append(botao(t('Emitir documento'), '', () => dialogoEmitir({})));
  acc.append(ligacao(t('Equipamentos'), '#equipamentos', 'btn ghost'));
  pg.append(acc);
  const nomeEst = (e) => t((ESTADOS_DOC[e] || [e])[0]);
  pg.append(
    tabela({
      id: 'equipamentos-documentos',
      titulo: t('Documentos dos equipamentos'),
      nota: t('Termos de entrega, autos e participações, com o estado da assinatura.'),
      rows: D.map((x) => ({ ...x, titulo_txt: t(DOCS[x.tipo] || x.tipo), equip_txt: (x.equipamentos || []).join(', ') })),
      sort: 'emissao',
      cols: [
        { key: 'numero', label: t('Número'), p: 0, nowrap: true, render: (v) => ligacao(v, '#equipamentos-documentos?doc=' + v, 'lnk mono') },
        { key: 'titulo_txt', label: t('Documento'), wrap: true, p: 1 },
        { key: 'colaborador', label: t('Colaborador'), wrap: true, p: 2 },
        { key: 'equip_txt', label: t('Equipamentos'), wrap: true, p: 3 },
        { key: 'emissao', label: t('Emissão'), type: 'date', get: (r) => String(r.emissao || '').slice(0, 10), p: 2 },
        {
          key: 'estado',
          label: t('Estado'),
          p: 0,
          get: (r) => nomeEst(r.estado),
          render: (v, r) => pill(nomeEst(r ? r.estado : v), (ESTADOS_DOC[r ? r.estado : v] || [])[1] || 'p-mut'),
          xl: (r) => nomeEst(r.estado),
        },
      ],
    }),
  );
  if (!D.length) pg.append(el('div', 'glass empty', t('Ainda não há documentos.')));
}

/** Quem assina a seguir: 1 = colaborador, 2 = responsável (o auto de abate só tem o responsável). */
function proximaOrdem(doc) {
  const feitas = (doc.assinaturas || []).map((s) => s.ordem);
  if (doc.documento.id_colaborador == null) return feitas.includes(2) ? 0 : 2;
  if (!feitas.includes(1)) return 1;
  return feitas.includes(2) ? 0 : 2;
}

function desenharDocumento(pg, num) {
  const x = documentos[num] || {};
  const est = garantirDoc(num);
  if (est !== 'ok') {
    const s = caixa(null);
    s.append(ligacao('‹ ' + t('Documentos'), '#equipamentos-documentos', 'lnk'));
    pg.append(s);
    return estadoPedido(pg, est, x.erro, 'Não foi possível abrir o documento');
  }
  const d = x.dados,
    doc = d.documento,
    p = d.pode || {};
  const cab = el('section', 'glass sec fixo atv-cab');
  cab.append(ligacao('‹ ' + t('Documentos'), '#equipamentos-documentos', 'lnk'));
  cab.append(el('div', 'atv-sobre mono', doc.numero), el('h2', null, t(DOCS[doc.tipo] || doc.tipo)));
  const pills = el('div', 'atv-pills');
  pills.append(pill(t(ESTADOS_DOC[doc.estado][0]), ESTADOS_DOC[doc.estado][1]));
  pills.append(pill(t('Emitido em {d}', { d: dataHora(doc.emissao) }), 'p-mut'));
  cab.append(pills);
  pg.append(cab);

  const info = caixa(doc.tipo === 'CONVENCAO_PROVA' ? t('Partes') : t('Equipamentos'));
  (d.equipamentos || []).forEach((e) => {
    const l = el('div', 'atv-doc');
    l.append(
      ligacao(e.id, '#equipamentos?id=' + e.id, 'lnk mono'),
      el('span', null, [[e.marca, e.modelo].filter(Boolean).join(' '), e.acessorios].filter(Boolean).join(' + ')),
      el('span', 'note', t({ ENTREGUE: 'Entregue', DEVOLVIDO: 'Devolvido', ABRANGIDO: 'Abrangido' }[e.papel] || e.papel)),
    );
    info.append(l);
  });
  if (doc.colaborador) info.append(el('p', 'note', t('Colaborador') + ': ' + doc.colaborador));
  info.append(el('p', 'note', t('Emitido por') + ': ' + (doc.emitido_por || '—')));
  const bpdf = botao(doc.estado === 'ASSINADO' ? t('Descarregar o PDF assinado') : t('Ler o documento completo (PDF)'), 'ghost', () =>
    apiDescarregar('equipamentos/pdf?numero=' + encodeURIComponent(doc.numero), doc.numero + '.pdf').catch((e) => toast(t(e.message), 6000)),
  );
  info.append(bpdf);
  pg.append(info);

  const ord = doc.estado === 'A_ASSINAR' ? proximaOrdem(d) : 0;
  const eColab = SESSAO && doc.colaborador_utilizador && String(SESSAO.login || '').toLowerCase() === String(doc.colaborador_utilizador).toLowerCase();
  if (ord && (p.movimentar || (ord === 1 && eColab))) pg.append(areaAssinar(d, ord, eColab));
  else if (doc.estado === 'A_ASSINAR') pg.append(el('div', 'glass empty', t('A aguardar a assinatura de quem tem permissão para assinar este documento.')));

  const ass = caixa(t('Assinaturas'));
  if (!(d.assinaturas || []).length) ass.append(el('p', 'note', t('Ainda sem assinaturas.')));
  const METODOS = { PRESENCIAL: 'Presencial, na presença de {q}', CONTA_PORTAL: 'Com a conta do portal' };
  (d.assinaturas || []).forEach((s) => {
    const l = el('div', 'atv-ass');
    l.append(el('b', null, s.ordem + '. ' + s.nome + ' · ' + t(s.qualidade)));
    l.append(el('span', 'note', [t(METODOS[s.metodo] || s.metodo, { q: s.testemunha || '' }), dataHora(s.data), 'IP ' + s.ip].join(' · ')));
    ass.append(l);
  });
  if (doc.hash_original) {
    const h = el('p', 'note atv-hash');
    h.append(el('span', null, t('Impressão digital SHA-256 do original') + ': '), el('code', null, doc.hash_original));
    ass.append(h);
  }
  if (doc.hash_final) {
    const h = el('p', 'note atv-hash');
    h.append(el('span', null, t('Impressão digital SHA-256 do assinado') + ': '), el('code', null, doc.hash_final));
    ass.append(h);
  }
  const acc = el('div', 'atv-accoes');
  acc.append(
    botao(t('Verificar a integridade'), 'ghost sm', () =>
      postJson('equipamentos', { accao: 'verificar', numero: doc.numero }).then((r) => {
        if (!r.ok) return toast(erroDe(r), 6000);
        const ok = r.original_integro && r.assinado_integro !== false;
        toast(ok ? t('Integridade confirmada: o documento não foi alterado.') : t('Atenção: o ficheiro não corresponde à impressão digital guardada.'), 8000);
      }),
    ),
  );
  if (p.movimentar && doc.estado === 'A_ASSINAR' && !(d.assinaturas || []).length) acc.append(botao(t('Anular'), 'ghost sm', () => dialogoAnular(doc)));
  ass.append(acc);
  pg.append(ass);
}

function dialogoAnular(doc) {
  janela(t('Anular documento'), doc.numero + ' · ' + t('O documento fica registado como anulado, com o motivo.'), (b, ft, fechar) => {
    const m = areaTexto();
    m.required = true;
    b.append(campo(t('Motivo da anulação'), m));
    const erro = msgErro();
    b.append(erro);
    const go = botao(t('Anular'), '', () => enviar(go, erro, { accao: 'anular', numero: doc.numero, motivo: m.value }, fechar));
    ft.append(botao(t('Cancelar'), 'ghost', fechar), go);
  });
}

/** Área de assinatura: quem assina, desenho no ecrã, «Li e aceito» e envio. */
function areaAssinar(d, ordem, eColab) {
  const doc = d.documento;
  const chave = doc.numero + '|' + ordem;
  if (emCurso.chave === chave && emCurso.el) return emCurso.el;
  const s = el('section', 'glass sec fixo atv-caixa atv-assinar');
  const quem = SESSAO ? SESSAO.nome || SESSAO.login : '';
  const total = doc.id_colaborador == null ? 1 : 2;
  s.append(el('h3', 'atv-rot', t('Assinatura {a} de {b}', { a: total === 1 ? 1 : ordem, b: total })));
  if (ordem === 1) {
    s.append(el('div', 'atv-nome', doc.colaborador));
    s.append(
      el(
        'p',
        'note',
        eColab ? t('Assina com a sua conta do portal.') : t('Assinatura presencial do colaborador, na presença de: {q} (sessão iniciada).', { q: quem }),
      ),
    );
  } else {
    s.append(el('div', 'atv-nome', quem));
    s.append(el('p', 'note', t('Responsável do Grupo Naval, com a sua conta do portal.')));
  }
  const tela = el('canvas', 'atv-tela');
  tela.setAttribute('aria-label', t('Área de assinatura'));
  s.append(tela);
  const barra = el('div', 'atv-barra');
  barra.append(el('span', 'note', t('Desenhe com o dedo')));
  const aceito = entrada('checkbox');
  const lAceito = el('label', 'atv-check atv-aceito');
  lAceito.append(aceito, el('span', null, t('Li e aceito o documento «{d}».', { d: t(DOCS[doc.tipo] || doc.tipo) })));
  const erro = msgErro();
  const go = botao(t('Assinar documento'), 'atv-largo', null);
  let pad = null;
  const estadoBotao = () => {
    go.disabled = !pad || pad.vazia() || !aceito.checked;
  };
  const limpar = botao(t('Limpar'), 'ghost sm', () => pad && pad.limpar());
  barra.append(limpar);
  s.append(barra, lAceito, erro, go);
  s.append(el('p', 'note', t('Fica registado: quem assina, quem presencia, data e hora, IP, navegador e a impressão digital SHA-256 do documento.')));
  aceito.addEventListener('change', estadoBotao);
  requestAnimationFrame(() => {
    pad = new AreaAssinatura(tela, { aoMudar: estadoBotao });
    estadoBotao();
  });
  go.addEventListener('click', () => {
    if (!pad || pad.vazia() || !aceito.checked) return;
    go.disabled = true;
    erro.hidden = true;
    postJson('equipamentos', {
      accao: 'assinar',
      numero: doc.numero,
      aceito: true,
      imagem_png: pad.imagem('image/png'),
      imagem_jpeg: pad.imagem('image/jpeg'),
      tracos: pad.tracos(),
    }).then((r) => {
      if (!r || !r.ok) {
        erro.textContent = erroDe(r);
        erro.hidden = false;
        estadoBotao();
        return;
      }
      pad.destruir();
      toast(
        r.documento && r.documento.estado === 'ASSINADO' ? t('Documento assinado e concluído.') : t('Assinatura registada. Falta a assinatura seguinte.'),
        6000,
      );
      renovar();
    });
  });
  estadoBotao();
  emCurso = { chave, el: s };
  return s;
}

// ───────────────────────────── Registo das páginas ─────────────────────────────

R.equipamentos = (pg) => {
  fichaId = paramHash('id').toUpperCase();
  if (fichaId && !/^[A-Z]{3}-[A-Z]{3}-\d{4}$/.test(fichaId)) fichaId = '';
  if (fichaId) desenharFicha(pg, fichaId);
  else desenharLista(pg);
};
R.equipamentos.estadoURL = () => (fichaId ? 'id=' + encodeURIComponent(fichaId) : '');

R['equipamentos-documentos'] = (pg) => {
  docNum = paramHash('doc').toUpperCase();
  if (docNum && !/^[A-Z]{3}-\d{4}-\d{6}$/.test(docNum)) docNum = '';
  if (docNum) desenharDocumento(pg, docNum);
  else desenharDocumentos(pg);
};
R['equipamentos-documentos'].estadoURL = () => (docNum ? 'doc=' + encodeURIComponent(docNum) : '');
