// Inteligência de negócio › Criar relatório: explorador de relatórios sobre a camada semântica do portal
// (relatorios/catalogo.js). A pessoa escolhe a área, as linhas, a coluna, as medidas e os filtros; o portal monta a
// tabela cruzada e o gráfico, desce ao detalhe e exporta para Excel ou PDF. Os relatórios guardam só a definição:
// quem os abre vê sempre os dados com as suas permissões e rotas. Servidor: nucleo/api/v1/relatorios.php.
import { apiFetch, apiJson } from '../api.js';
import { draw } from '../app.js';
import { cabecalho, entregar, estiloCabeca, estiloTabela, lerEmblema, novoDoc, rodapes, tp } from '../componentes/contagem-pdf.js';
import { cartaoGrafico, grafBarras, grafColunas } from '../componentes/graficos.js';
import { abrir as abrirLig, abrirPeriodo } from '../componentes/ligacoes.js';
import { botao, campo, confirmarAccao, entrada, janela, msgErro } from '../componentes/janelas.js';
import { carregarExcelJS, NAVY2, nomeFolha } from '../excel.js';
import { dl, kz, n0, n1 } from '../formatos.js';
import { t, tv } from '../i18n/i18n.js';
import { atual, podeExportar, SESSAO } from '../perfil.js';
import { hoje, st } from '../periodo.js';
import { completarCampos, dominio, dominiosVisiveis } from '../relatorios/catalogo.js';
import { executar, GRAOS, OUTROS, partes, VAZIO, valoresDim } from '../relatorios/motor.js';
import { postJson } from '../servidor.js';
import { el, toast } from '../util.js';
import { R } from './registo.js';

const NOVO = () => ({ dominio: '', linhas: [], coluna: null, medidas: [], filtros: {}, ordem: 'valor_desc', limite: 0, grafico: 'barras', periodo: 'actual' });
const GRAO_NOME = { dia: 'Dia', semana: 'Semana', mes: 'Mês', trimestre: 'Trimestre', ano: 'Ano', dia_semana: 'Dia da semana' };
const DIA_SEMANA = { 1: 'Segunda-feira', 2: 'Terça-feira', 3: 'Quarta-feira', 4: 'Quinta-feira', 5: 'Sexta-feira', 6: 'Sábado', 7: 'Domingo' };

let LISTA = null; // {relatorios, pode, eu}
let aLerLista = false;
let rel = { id: null, versao: null, nome: '', descricao: '', visibilidade: 'pessoal', dono: null, certificado: false };
let def = NOVO();
let dados = { chave: '', linhas: null, erro: null, aLer: false, dom: null };
let detalhe = null; // grupo aberto no detalhe

const fmtMedida = (m, v) => (v == null ? '—' : m.fmt === 'kz' ? kz(v) : m.fmt === 'q' ? n1(v) : n0(v));
const dimNome = (dom, d) => {
  const { campo: c, grao } = partes(d);
  const f = dom.campos[c];
  if (!f) return d;
  const nome = f.auto ? f.nome : t(f.nome);
  return grao ? nome + ' · ' + t(GRAO_NOME[grao]) : nome;
};
/** Texto de um valor de dimensão (datas em dd-mm-aaaa, dia da semana por extenso). */
const valTxt = (dom, d, v) => {
  if (v === VAZIO) return t('(sem valor)');
  if (v === OUTROS) return t('(outros)');
  const { campo: c, grao } = partes(d);
  const f = dom.campos[c];
  if (f && f.tipo === 'data') {
    if (!grao || grao === 'dia') return dl(v);
    if (grao === 'mes') return v.slice(5, 7) + '-' + v.slice(0, 4);
    if (grao === 'dia_semana') return t(DIA_SEMANA[v] || v);
  }
  return tv(v);
};
const medidaNome = (m) => (m.auto ? m.nome : t(m.nome));

function lerLista() {
  if (aLerLista) return;
  aLerLista = true;
  apiJson('relatorios').then((d) => {
    aLerLista = false;
    LISTA = d && d.ok ? d : { relatorios: [], pode: {}, falhou: true };
    if (atual === 'relatorios') draw();
  });
}

/** Lê os dados da área (com o período da barra) e completa os campos com as colunas dos dados. */
function lerDados(dom) {
  const chave = dom.id + '|' + (dom.periodo ? st.de + '|' + st.ate : '');
  if (dados.chave === chave && (dados.linhas || dados.aLer || dados.erro)) return;
  dados = { chave, linhas: null, erro: null, aLer: true, dom: null };
  dom
    .carregar()
    .then((linhas) => {
      if (dados.chave !== chave) return;
      dados = { chave, linhas: Array.isArray(linhas) ? linhas : [], erro: null, aLer: false, dom: completarCampos(dom, Array.isArray(linhas) ? linhas : []) };
    })
    .catch((e) => {
      if (dados.chave !== chave) return;
      dados = { chave, linhas: null, erro: (e && e.message) || t('Não foi possível ler os dados.'), aLer: false, dom: null };
    })
    .finally(() => {
      if (atual === 'relatorios') draw();
    });
}

/** Abre um relatório guardado (pelo endereço #relatorios/REL-…). */
function abrirGuardado(id) {
  const r = LISTA && (LISTA.relatorios || []).find((x) => x.id === id);
  if (!r) return false;
  rel = { id: r.id, versao: r.versao, nome: r.nome, descricao: r.descricao || '', visibilidade: r.visibilidade, dono: r.dono, certificado: !!r.certificado, r };
  def = { ...NOVO(), ...JSON.parse(JSON.stringify(r.definicao)), filtros: { ...(r.definicao.filtros || {}) } };
  detalhe = null;
  return true;
}

function novo(dominioId = '') {
  rel = { id: null, versao: null, nome: '', descricao: '', visibilidade: 'pessoal', dono: null, certificado: false };
  def = { ...NOVO(), dominio: dominioId };
  detalhe = null;
}

/** Definição inicial útil de uma área: a primeira medida e a primeira dimensão de texto (ou a data por mês). */
function sugestao(dom) {
  const cs = Object.values(dom.campos || {});
  const dim = cs.find((c) => c.id === 'promotor') || cs.find((c) => c.tipo === 'texto') || cs.find((c) => c.tipo === 'data');
  return {
    ...NOVO(),
    dominio: dom.id,
    medidas: dom.medidas.length ? [dom.medidas[0].id] : [],
    linhas: dim ? [dim.tipo === 'data' ? dim.id + ':mes' : dim.id] : [],
  };
}

R.relatorios = (pg) => {
  if (!LISTA) {
    pg.append(el('div', 'glass empty', t('A preparar o explorador…')));
    lerLista();
    return;
  }
  const pedido = new URLSearchParams(location.hash.split('?')[1] || '').get('r') || '';
  if (/^REL-[a-f0-9]{10}$/.test(pedido) && rel.id !== pedido && !abrirGuardado(pedido))
    toast(t('Este relatório já não existe ou não está partilhado consigo.'), 6000);
  const doms = dominiosVisiveis();
  if (!doms.length) {
    pg.append(el('div', 'glass empty', t('O seu perfil não tem acesso a nenhuma área de dados para relatórios.')));
    return;
  }
  const grelha = el('div', 'rel-grelha');
  grelha.append(painelGuardados(), el('div', 'rel-principal'));
  pg.append(grelha);
  const principal = grelha.lastChild;
  principal.append(painelDefinicao(doms));
  const dom = dominio(def.dominio);
  if (!dom || !doms.includes(dom)) {
    principal.append(el('div', 'glass empty', t('Escolha a área de dados para começar.')));
    return;
  }
  lerDados(dom);
  if (dados.aLer || (!dados.linhas && !dados.erro)) {
    principal.append(el('div', 'glass empty', t('A ler os dados de {a}…', { a: t(dom.nome) })));
    return;
  }
  if (dados.erro) {
    principal.append(el('div', 'glass empty', t(dados.erro)));
    return;
  }
  principal.append(resultado(dados.dom));
};

/* ------------------------------------------------------------------ relatórios guardados */
function painelGuardados() {
  const box = el('aside', 'glass rel-lista');
  box.append(el('h2', null, t('Relatórios')));
  box.append(
    botao(t('Novo relatório'), 'sm', () => {
      novo();
      draw();
    }),
  );
  const rs = LISTA.relatorios || [];
  const grupos = [
    [t('Certificados'), rs.filter((r) => r.certificado)],
    [t('Os meus'), rs.filter((r) => !r.certificado && SESSAO && r.dono.toLowerCase() === String(SESSAO.login || '').toLowerCase())],
    [t('Partilhados comigo'), rs.filter((r) => !r.certificado && !(SESSAO && r.dono.toLowerCase() === String(SESSAO.login || '').toLowerCase()))],
  ];
  grupos.forEach(([nome, l]) => {
    if (!l.length) return;
    box.append(el('h3', null, nome));
    const ul = el('ul');
    l.forEach((r) => {
      const li = el('li', r.id === rel.id ? 'on' : null);
      const a = el('a', null, r.nome);
      a.href = '#relatorios?r=' + r.id;
      a.addEventListener('click', (e) => {
        e.preventDefault();
        history.replaceState(null, '', '#relatorios?r=' + r.id);
        abrirGuardado(r.id);
        draw();
      });
      li.append(a);
      if (r.certificado) li.append(el('span', 'rel-selo', t('certificado')));
      const dom = dominio(r.definicao && r.definicao.dominio);
      li.append(el('small', null, (dom ? t(dom.nome) : '') + (r.dono_nome ? ' · ' + r.dono_nome : '')));
      ul.append(li);
    });
    box.append(ul);
  });
  if (!rs.length) box.append(el('p', 'nota', t('Ainda não há relatórios guardados. Crie um e guarde-o para o abrir depois ou partilhar.')));
  return box;
}

/* ------------------------------------------------------------------ definição */
function seleccao(rotulo, opcoes, valor, onChange, vazio) {
  const s = el('select');
  if (vazio != null) {
    const o = el('option', null, vazio);
    o.value = '';
    s.append(o);
  }
  opcoes.forEach(([v, txt, grupo]) => {
    let alvo = s;
    if (grupo) {
      alvo = [...s.children].find((g) => g.tagName === 'OPTGROUP' && g.label === grupo);
      if (!alvo) {
        alvo = el('optgroup');
        alvo.label = grupo;
        s.append(alvo);
      }
    }
    const o = el('option', null, txt);
    o.value = v;
    if (v === valor) o.selected = true;
    alvo.append(o);
  });
  s.setAttribute('aria-label', rotulo);
  s.addEventListener('change', () => onChange(s.value));
  return campo(rotulo, s);
}

/** Opções de dimensão de uma área (datas com cada grão). */
function opcoesDims(dom) {
  const out = [];
  Object.values(dom.campos).forEach((c) => {
    const nome = c.auto ? c.nome : t(c.nome);
    if (c.tipo === 'data') GRAOS.forEach((g) => out.push([c.id + ':' + g, nome + ' · ' + t(GRAO_NOME[g])]));
    else out.push([c.id, nome]);
  });
  return out;
}

function painelDefinicao(doms) {
  const sec = el('section', 'glass sec rel-def');
  const h = el('div', 'sec-h');
  const hl = el('div');
  hl.append(el('h2', null, rel.id ? rel.nome : t('Criar relatório')));
  hl.append(
    el(
      'p',
      null,
      rel.id
        ? t('Relatório guardado') +
            (rel.certificado ? ' · ' + t('certificado') : '') +
            (rel.r && rel.r.dono_nome ? ' · ' + t('criado por {n}', { n: rel.r.dono_nome }) : '')
        : t('Escolha a área, o que quer ver nas linhas e as medidas. Os números seguem as mesmas regras dos ecrãs do portal.'),
    ),
  );
  h.append(hl, accoesRelatorio());
  sec.append(h);
  const f = el('div', 'rel-campos');
  const gNome = { Comercial: t('Comercial'), Operações: t('Operações'), Finanças: t('Finanças'), Análise: t('Análise'), Sistema: t('Sistema') };
  f.append(
    seleccao(
      t('Área de dados'),
      doms.map((d) => [d.id, t(d.nome), gNome[d.grupo] || d.grupo]),
      def.dominio,
      (v) => {
        const d = dominio(v);
        def = d ? sugestao(d) : NOVO();
        detalhe = null;
        draw();
      },
      t('Escolha…'),
    ),
  );
  const dom = dados.dom && dados.dom.id === def.dominio ? dados.dom : dominio(def.dominio);
  if (dom && dom.campos) {
    const dims = opcoesDims(dom);
    [0, 1, 2].forEach((i) => {
      if (i > def.linhas.length) return;
      f.append(
        seleccao(
          i === 0 ? t('Linhas') : t('Depois por'),
          dims,
          def.linhas[i] || '',
          (v) => {
            const l = def.linhas.slice(0, i);
            if (v) l.push(v);
            def.linhas = l.concat(v ? def.linhas.slice(i + 1).filter((x) => x !== v) : []);
            detalhe = null;
            draw();
          },
          i === 0 ? t('(total geral)') : t('(nenhum)'),
        ),
      );
    });
    f.append(
      seleccao(
        t('Colunas'),
        dims,
        def.coluna || '',
        (v) => {
          def.coluna = v || null;
          draw();
        },
        t('(nenhuma)'),
      ),
    );
    f.append(
      seleccao(
        t('Ordenar'),
        [
          ['valor_desc', t('Maior valor primeiro')],
          ['valor_asc', t('Menor valor primeiro')],
          ['nome', t('Por nome ou data')],
        ],
        def.ordem,
        (v) => {
          def.ordem = v;
          draw();
        },
      ),
    );
    f.append(
      seleccao(
        t('Mostrar'),
        [
          ['0', t('Todas as linhas')],
          ['10', t('Os 10 primeiros')],
          ['20', t('Os 20 primeiros')],
          ['50', t('Os 50 primeiros')],
        ],
        String(def.limite || 0),
        (v) => {
          def.limite = +v;
          draw();
        },
      ),
    );
    f.append(
      seleccao(
        t('Gráfico'),
        [
          ['barras', t('Barras')],
          ['colunas', t('Colunas')],
          ['nenhum', t('Sem gráfico')],
        ],
        def.grafico,
        (v) => {
          def.grafico = v;
          draw();
        },
      ),
    );
    sec.append(f);
    // medidas (até 8)
    const ms = el('fieldset', 'rel-medidas');
    ms.append(el('legend', null, t('Medidas')));
    dom.medidas.forEach((m) => {
      const l = el('label', 'rel-chk');
      const c = entrada('checkbox');
      c.checked = def.medidas.includes(m.id);
      c.addEventListener('change', () => {
        def.medidas = c.checked ? [...def.medidas, m.id].slice(0, 8) : def.medidas.filter((x) => x !== m.id);
        draw();
      });
      l.append(c, el('span', null, medidaNome(m)));
      ms.append(l);
    });
    sec.append(ms);
    if (dados.linhas && dados.dom) sec.append(painelFiltros(dados.dom));
    const info = el('p', 'nota rel-fonte');
    const d0 = dominio(def.dominio);
    info.textContent =
      t('Fonte: {f}.', { f: t(d0.fonte) }) +
      ' ' +
      (d0.periodo ? t('Período da barra: {inicio} a {fim}.', { inicio: dl(st.de), fim: dl(st.ate) }) : t('Situação actual (não depende do período).')) +
      ' ' +
      t(d0.desc);
    sec.append(info);
    if (rel.id) sec.append(subscricao());
  } else sec.append(f);
  return sec;
}

function painelFiltros(dom) {
  const box = el('div', 'rel-filtros');
  const activos = Object.entries(def.filtros || {}).filter(([, v]) => v && v.length);
  const cab = el('div', 'rel-filtros-h');
  cab.append(el('strong', null, t('Filtros')));
  const dims = opcoesDims(dom).filter(([d]) => !(def.filtros || {})[d]);
  const s = el('select');
  s.setAttribute('aria-label', t('Acrescentar filtro'));
  const o0 = el('option', null, t('Acrescentar filtro…'));
  o0.value = '';
  s.append(o0);
  dims.forEach(([v, txt]) => {
    const o = el('option', null, txt);
    o.value = v;
    s.append(o);
  });
  s.addEventListener('change', () => {
    if (!s.value) return;
    def.filtros = { ...def.filtros, [s.value]: [] };
    draw();
  });
  cab.append(s);
  box.append(cab);
  Object.keys(def.filtros || {}).forEach((d) => {
    const det = el('details', 'rel-filtro');
    det.open = !(def.filtros[d] || []).length;
    const sel = def.filtros[d] || [];
    const sm = el('summary', null, dimNome(dom, d) + ': ' + (sel.length ? sel.map((v) => valTxt(dom, d, v)).join(', ') : t('escolha os valores')));
    det.append(sm);
    const vals = valoresDim(dados.linhas, d, dom.campos, 300);
    const lista = el('div', 'rel-filtro-vals');
    vals.forEach((v) => {
      const l = el('label', 'rel-chk');
      const c = entrada('checkbox');
      c.checked = sel.includes(v);
      c.addEventListener('change', () => {
        const atual2 = new Set(def.filtros[d] || []);
        if (c.checked) atual2.add(v);
        else atual2.delete(v);
        def.filtros = { ...def.filtros, [d]: [...atual2] };
        draw();
      });
      l.append(c, el('span', null, valTxt(dom, d, v)));
      lista.append(l);
    });
    det.append(lista);
    det.append(
      botao(t('Retirar filtro'), 'ghost sm', () => {
        const f = { ...def.filtros };
        delete f[d];
        def.filtros = f;
        draw();
      }),
    );
    box.append(det);
  });
  if (!activos.length && !Object.keys(def.filtros || {}).length) box.append(el('p', 'nota', t('Sem filtros: todos os dados da área a que tem acesso.')));
  return box;
}

/** «Receber por correio»: aviso com a ligação ao relatório (os números ficam no portal). */
function subscricao() {
  const atualF = (rel.r && rel.r.minha_subscricao) || 'nenhuma';
  const box = el('div', 'rel-subs');
  box.append(
    seleccao(
      t('Receber por correio'),
      [
        ['nenhuma', t('Não receber')],
        ['diaria', t('Todos os dias (07:30)')],
        ['semanal', t('Às segundas-feiras (07:30)')],
        ['mensal', t('No dia 1 de cada mês (07:30)')],
      ],
      atualF,
      (v) =>
        postJson('relatorios', { accao: 'subscrever', id: rel.id, frequencia: v }).then((d) => {
          if (!d || !d.ok) {
            toast(t((d && d.erro) || 'Não foi possível concluir a acção.'), 6000);
            draw();
            return;
          }
          LISTA = { ...LISTA, relatorios: d.relatorios, pode: d.pode };
          abrirGuardado(rel.id);
          toast(
            v === 'nenhuma' ? t('Deixou de receber este relatório por correio.') : t('Vai receber um aviso por correio com a ligação para este relatório.'),
            4000,
          );
          draw();
        }),
    ),
  );
  box.append(el('p', 'nota', t('O correio leva só o aviso e a ligação: por segurança, os números vêem-se no portal, com as suas permissões.')));
  if (rel.r && rel.r.subscritores)
    box.append(
      el(
        'p',
        'nota',
        rel.r.subscritores === 1
          ? t('1 pessoa recebe este relatório por correio.')
          : t('{n} pessoas recebem este relatório por correio.', { n: n0(rel.r.subscritores) }),
      ),
    );
  return box;
}

/* ------------------------------------------------------------------ guardar, partilhar, certificar */
function accoesRelatorio() {
  const acc = el('div', 'sec-tools');
  if (!def.dominio || !def.medidas.length) return acc;
  const meu = !rel.id || (SESSAO && String(rel.dono || '').toLowerCase() === String(SESSAO.login || '').toLowerCase());
  acc.append(botao(rel.id && meu ? t('Guardar alterações') : t('Guardar'), 'sm', () => guardar(rel.id && meu ? rel.id : null)));
  if (rel.id) acc.append(botao(t('Guardar como novo'), 'ghost sm', () => guardar(null)));
  if (rel.id && LISTA.pode && LISTA.pode.certificar)
    acc.append(
      botao(rel.certificado ? t('Retirar certificação') : t('Certificar'), 'ghost sm', () =>
        accaoServidor(
          rel.certificado ? 'retirar_certificacao' : 'certificar',
          rel.id,
          rel.certificado ? t('Certificação retirada.') : t('Relatório certificado.'),
        ),
      ),
    );
  if (rel.id && meu)
    acc.append(
      botao(t('Apagar'), 'ghost sm', () =>
        confirmarAccao(
          t('Apagar o relatório'),
          t('O relatório «{n}» deixa de existir para todos. Os dados não são afectados.', { n: rel.nome }),
          t('Apagar'),
          () =>
            postJson('relatorios', { accao: 'apagar', id: rel.id }).then((d) => {
              if (d && d.ok) {
                LISTA = { ...LISTA, relatorios: d.relatorios, pode: d.pode };
                novo();
                toast(t('Relatório apagado.'), 3000);
                draw();
              }
              return d || {};
            }),
        ),
      ),
    );
  return acc;
}

function accaoServidor(accao, id, ok) {
  return postJson('relatorios', { accao, id }).then((d) => {
    if (!d || !d.ok) {
      toast(t((d && d.erro) || 'Não foi possível concluir a acção.'), 6000);
      return;
    }
    LISTA = { ...LISTA, relatorios: d.relatorios, pode: d.pode };
    if (d.relatorio && d.relatorio.id === rel.id) abrirGuardado(d.relatorio.id);
    toast(ok, 3000);
    draw();
  });
}

function guardar(id) {
  janela(
    id ? t('Guardar alterações') : t('Guardar relatório'),
    t('Guarda-se só a definição. Quem o abrir vê os dados com as suas próprias permissões e rotas.'),
    (b, ft, fechar) => {
      const nome = entrada('text', id ? rel.nome : '', { maxLength: 80, required: true });
      const desc = el('textarea');
      desc.maxLength = 300;
      desc.rows = 2;
      desc.value = id ? rel.descricao : '';
      const vis = el('select');
      [
        ['pessoal', t('Só para mim')],
        ['partilhado', t('Partilhado com todos os que usam relatórios')],
      ].forEach(([v, txt]) => {
        const o = el('option', null, txt);
        o.value = v;
        if (v === (id ? rel.visibilidade : 'pessoal')) o.selected = true;
        vis.append(o);
      });
      if (!(LISTA.pode && LISTA.pode.partilhar)) vis.disabled = true;
      const erro = msgErro();
      b.append(campo(t('Nome'), nome), campo(t('Descrição (opcional)'), desc), campo(t('Quem o vê'), vis), erro);
      const go = botao(t('Guardar'), '', () => {
        go.disabled = true;
        postJson('relatorios', {
          accao: 'guardar',
          id: id || undefined,
          versao: id ? rel.versao : undefined,
          nome: nome.value.trim(),
          descricao: desc.value.trim(),
          visibilidade: vis.value,
          definicao: def,
        }).then((d) => {
          go.disabled = false;
          if (!d || !d.ok) {
            erro.textContent = t((d && d.erro) || 'Não foi possível guardar.');
            erro.hidden = false;
            return;
          }
          LISTA = { ...LISTA, relatorios: d.relatorios, pode: d.pode };
          history.replaceState(null, '', '#relatorios?r=' + d.relatorio.id);
          abrirGuardado(d.relatorio.id);
          fechar();
          toast(t('Relatório guardado.'), 3000);
          draw();
        });
      });
      go.id = 'rel-guardar-ok';
      ft.append(botao(t('Cancelar'), 'ghost', fechar), go);
      setTimeout(() => nome.focus(), 30);
    },
  );
}

/**
 * Detalhe de uma linha do gráfico (o mesmo dos outros gráficos do portal): rota, cliente, artigo ou documento abrem a
 * sua ficha; um dia abre a página com esse dia. Só com uma dimensão nas linhas; senão, null (sem detalhe).
 */
function abrirDaDimensao(R0, temporal) {
  if (R0.dims.length !== 1) return null;
  const { campo: c, grao } = partes(R0.dims[0]);
  const valor = (i) => String(R0.grupos[i].chaves[0] ?? '');
  if (c === 'rota') return (i) => abrirLig('rota', { rota: valor(i) });
  if (c === 'cliente_codigo') return (i) => abrirLig('cliente', { codigo: valor(i) });
  if (c === 'cliente') return (i) => abrirLig('cliente', { nome: valor(i) });
  if (c === 'documento') return (i) => abrirLig('documento', { documento: valor(i) });
  if (c === 'codigo' || c === 'artigo_codigo') return (i) => abrirLig('artigo', { codigo: valor(i) });
  if (c === 'artigo') return (i) => abrirLig('artigo', { artigo: valor(i) });
  if (temporal && !grao && /^\d{4}-\d{2}-\d{2}$/.test(valor(0))) return (i) => abrirPeriodo(valor(i), valor(i), atual);
  return null;
}

/* ------------------------------------------------------------------ resultado */
function resultado(dom) {
  const box = el('div', 'rel-resultado');
  if (!def.medidas.length) {
    box.append(el('div', 'glass empty', t('Escolha pelo menos uma medida.')));
    return box;
  }
  const R0 = executar(dados.linhas, dom, def);
  if (!R0.linhas.length) {
    box.append(el('div', 'glass empty', t('Sem dados para esta escolha no período.')));
    return box;
  }
  const ms = R0.medidas;
  const m0 = ms[0];
  // gráfico (uma dimensão nas linhas; datas em colunas, o resto em barras ordenadas)
  if (def.grafico !== 'nenhum' && R0.dims.length >= 1 && R0.grupos.length > 1) {
    const temporal = dom.campos[partes(R0.dims[0]).campo].tipo === 'data';
    const itens = R0.grupos.slice(0, temporal ? 60 : 25).map((g) => {
      const rot = g.chaves.map((v, i) => valTxt(dom, R0.dims[i], v)).join(' · ');
      return { rot, titulo: rot, valor: +g.valores[m0.id] || 0, linhas: [[fmtMedida(m0, g.valores[m0.id]), medidaNome(m0)]] };
    });
    const colunas = def.grafico === 'colunas' || (temporal && def.grafico !== 'barras');
    box.append(
      cartaoGrafico({
        titulo: t('{m} por {d}', { m: medidaNome(m0), d: R0.dims.map((d) => dimNome(dom, d).toLowerCase()).join(' · ') }),
        sub: t('{n} linhas · total {v}', { n: n0(R0.grupos.length), v: fmtMedida(m0, R0.total[m0.id]) }),
        desenhar: (w) => (colunas ? grafColunas(w, { itens, fmt: (v) => fmtMedida(m0, v) }) : grafBarras(w, { itens, fmt: (v) => fmtMedida(m0, v) })),
        colunas: [
          [t('Linha'), 0],
          [medidaNome(m0), 1],
        ],
        linhas: itens.map((x) => [x.rot, fmtMedida(m0, x.valor)]),
        abrirLinha: abrirDaDimensao(R0, temporal),
      }),
    );
  }
  box.append(tabelaCruzada(dom, R0));
  if (detalhe) box.append(tabelaDetalhe(dom, R0));
  return box;
}

/** Cabeçalhos e linhas da tabela (para o ecrã, o Excel e o PDF). */
function grelhaDe(dom, R0) {
  const ms = R0.medidas;
  const cab = R0.dims.map((d) => dimNome(dom, d));
  if (!R0.dims.length) cab.push(t('Total'));
  const colunas = [];
  if (R0.coluna)
    R0.colunas.forEach((c) => ms.forEach((m) => colunas.push({ c, m, nome: valTxt(dom, R0.coluna, c) + (ms.length > 1 ? ' · ' + medidaNome(m) : '') })));
  ms.forEach((m) => colunas.push({ c: null, m, nome: (R0.coluna ? t('Total') + ' · ' : '') + medidaNome(m) }));
  const linhas = R0.grupos.map((g) => ({
    g,
    rot: R0.dims.length ? g.chaves.map((v, i) => valTxt(dom, R0.dims[i], v)) : [t('Total')],
    vals: colunas.map((k) => (k.c == null ? g.valores[k.m.id] : g.porColuna[k.c] ? g.porColuna[k.c][k.m.id] : null)),
  }));
  const total = colunas.map((k) => (k.c == null ? R0.total[k.m.id] : R0.totalColunas[k.c] ? R0.totalColunas[k.c][k.m.id] : null));
  return { cab, colunas, linhas, total };
}

function tabelaCruzada(dom, R0) {
  const G = grelhaDe(dom, R0);
  const sec = el('section', 'glass sec rel-tabela');
  sec.id = 's-rel-tabela';
  const h = el('div', 'sec-h');
  const hl = el('div');
  hl.append(el('h2', null, rel.id ? rel.nome : t('Resultado')));
  hl.append(el('p', null, descricaoDef(dom, R0) + ' · ' + t('Clique numa linha para ver o detalhe.')));
  const tools = el('div', 'sec-tools');
  const exp = paginaExportavel(dom);
  if (exp) {
    tools.append(botao('Excel', 'sm', () => exportarXlsx(dom, R0, G, exp)));
    tools.append(botao('PDF', 'sm', () => exportarPdf(dom, R0, G, exp)));
  }
  h.append(hl, tools);
  sec.append(h);
  const tw = el('div', 'tw');
  const tb = el('table', 'rel-pivot');
  const thead = el('thead');
  const trh = el('tr');
  G.cab.forEach((c) => trh.append(el('th', null, c)));
  G.colunas.forEach((k) => trh.append(el('th', 'n', k.nome)));
  thead.append(trh);
  const tbody = el('tbody');
  G.linhas.forEach((l) => {
    const tr = el('tr', detalhe && detalhe === JSON.stringify(l.g.chaves) ? 'on' : null);
    tr.tabIndex = 0;
    tr.setAttribute('role', 'button');
    tr.setAttribute('aria-label', t('Ver o detalhe de {l}', { l: l.rot.join(' · ') }));
    l.rot.forEach((v) => tr.append(el('td', null, v)));
    l.vals.forEach((v, i) => tr.append(el('td', 'n', fmtMedida(G.colunas[i].m, v))));
    const abrir = () => {
      detalhe = JSON.stringify(l.g.chaves);
      draw();
      setTimeout(() => {
        const d = document.getElementById('s-rel-detalhe');
        if (d) d.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }, 50);
    };
    tr.addEventListener('click', abrir);
    tr.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        abrir();
      }
    });
    tbody.append(tr);
  });
  const tfoot = el('tfoot');
  if (R0.dims.length) {
    const trt = el('tr');
    trt.append(el('th', null, t('Total')));
    for (let i = 1; i < G.cab.length; i++) trt.append(el('th'));
    G.total.forEach((v, i) => trt.append(el('th', 'n', v == null ? '' : fmtMedida(G.colunas[i].m, v))));
    tfoot.append(trt);
  }
  tb.append(thead, tbody, tfoot);
  tw.append(tb);
  sec.append(tw);
  if (def.limite && R0.grupos.length >= def.limite)
    sec.append(el('p', 'nota', t('A mostrar os {n} primeiros; o total inclui todas as linhas.', { n: def.limite })));
  return sec;
}

function tabelaDetalhe(dom, R0) {
  const g = R0.grupos.find((x) => JSON.stringify(x.chaves) === detalhe);
  if (!g) return el('div');
  const linhas = g.linhas.slice(0, 500);
  const cs = Object.values(dom.campos).slice(0, 12);
  const sec = el('section', 'glass sec');
  sec.id = 's-rel-detalhe';
  const h = el('div', 'sec-h');
  const hl = el('div');
  hl.append(el('h2', null, t('Detalhe') + ': ' + (R0.dims.length ? g.chaves.map((v, i) => valTxt(dom, R0.dims[i], v)).join(' · ') : t('Total'))));
  hl.append(el('p', null, t('{n} registos', { n: n0(g.linhas.length) }) + (g.linhas.length > 500 ? ' · ' + t('a mostrar os primeiros 500') : '')));
  h.append(
    hl,
    botao(t('Fechar o detalhe'), 'ghost sm', () => {
      detalhe = null;
      draw();
    }),
  );
  sec.append(h);
  const tw = el('div', 'tw');
  const tb = el('table');
  const trh = el('tr');
  cs.forEach((c) => trh.append(el('th', null, c.auto ? c.nome : t(c.nome))));
  const thead = el('thead');
  thead.append(trh);
  const tbody = el('tbody');
  linhas.forEach((l) => {
    const tr = el('tr');
    cs.forEach((c) => {
      const v = c.valor ? c.valor(l) : l[c.id];
      tr.append(el('td', null, v == null || v === '' ? '' : c.tipo === 'data' ? dl(String(v).slice(0, 10)) : Array.isArray(v) ? v.join(', ') : tv(String(v))));
    });
    tbody.append(tr);
  });
  tb.append(thead, tbody);
  tw.append(tb);
  sec.append(tw);
  return sec;
}

function descricaoDef(dom, R0) {
  const partesTxt = [t(dom.nome)];
  if (dom.periodo) partesTxt.push(dl(st.de) + ' – ' + dl(st.ate));
  const fs = Object.entries(def.filtros || {}).filter(([, v]) => v && v.length);
  if (fs.length) partesTxt.push(t('Filtros') + ': ' + fs.map(([d, v]) => dimNome(dom, d) + ' = ' + v.map((x) => valTxt(dom, d, x)).join(', ')).join('; '));
  partesTxt.push(t('{n} registos', { n: n0(R0.linhas.length) }));
  return partesTxt.join(' · ');
}

/** Página de origem que esta pessoa pode exportar (a exportação regista-se e o servidor confirma). */
const paginaExportavel = (dom) => dom.pagina.find(podeExportar) || null;

async function autorizar(pagina, nome, linhas) {
  try {
    const r = await apiFetch('exportacoes', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      cache: 'no-store',
      body: JSON.stringify({
        pagina,
        completo: false,
        tabela: 'Relatório: ' + nome,
        linhas,
        periodo: dl(st.de) + ' – ' + dl(st.ate),
        csrf: SESSAO && SESSAO.csrf,
      }),
    });
    const d = await r.json().catch(() => null);
    if (!d) return !SESSAO;
    if (!d.ok) toast(t(d.erro || 'O seu perfil não permite exportar esta informação.'), 7000);
    return !!d.ok;
  } catch (e) {
    return !SESSAO;
  }
}

const nomeFicheiro = (ext) => (t('RTM - Relatório') + ' ' + (rel.nome || t('sem nome')) + '-' + dl(hoje())).replace(/[\\/:*?"<>|]/g, '-') + '.' + ext;

async function exportarXlsx(dom, R0, G, pagina) {
  if (!(await autorizar(pagina, rel.nome || t(dom.nome), G.linhas.length))) return;
  try {
    await carregarExcelJS();
    const wb = new ExcelJS.Workbook();
    wb.creator = 'Portal RTM · Grupo Naval';
    wb.created = new Date();
    const F = (o) => Object.assign({ name: 'Arial', size: 10 }, o || {});
    const usados = new Set();
    const ws = wb.addWorksheet(nomeFolha(t('Relatório'), usados), { views: [{ state: 'frozen', ySplit: 5 }] });
    ws.getCell('A1').value = 'Grupo Naval Angola · ' + t('Portal RTM');
    ws.getCell('A1').font = F({ size: 10, color: { argb: 'FF56657C' } });
    ws.getCell('A2').value = rel.nome || t('Relatório');
    ws.getCell('A2').font = F({ size: 16, bold: true, color: { argb: NAVY2 } });
    ws.getCell('A3').value = descricaoDef(dom, R0);
    ws.getCell('A3').font = F({ size: 9, color: { argb: 'FF56657C' } });
    ws.getCell('A4').value =
      t('Gerado em') +
      ' ' +
      dl(hoje()) +
      ' ' +
      new Date().toTimeString().slice(0, 5) +
      (SESSAO ? ' · ' + tv(SESSAO.nome) : '') +
      ' · ' +
      t('Fonte: {f}.', { f: t(dom.fonte) });
    ws.getCell('A4').font = F({ size: 9, color: { argb: 'FF56657C' } });
    ws.addTable({
      name: 'tblRelatorio',
      ref: 'A5',
      headerRow: true,
      totalsRow: false,
      style: { theme: 'TableStyleMedium2', showRowStripes: true },
      columns: [...G.cab, ...G.colunas.map((k) => k.nome)].map((n) => ({ name: String(n), filterButton: true })),
      rows: G.linhas.map((l) => [...l.rot, ...l.vals.map((v) => (v == null ? null : +v))]),
    });
    G.colunas.forEach((k, i) => {
      ws.getColumn(G.cab.length + i + 1).numFmt = k.m.fmt === 'kz' ? '#,##0.00 "Kz"' : k.m.fmt === 'q' ? '#,##0.0' : '#,##0';
      ws.getColumn(G.cab.length + i + 1).width = Math.max(14, Math.min(28, k.nome.length + 4));
    });
    G.cab.forEach((c, i) => {
      ws.getColumn(i + 1).width = 28;
    });
    const buf = await wb.xlsx.writeBuffer();
    const a = el('a');
    a.href = URL.createObjectURL(new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }));
    a.download = nomeFicheiro('xlsx');
    document.body.append(a);
    a.click();
    a.remove();
    toast(t('Excel transferido.'), 2500);
  } catch (e) {
    console.error(e);
    toast(t('Não foi possível gerar o Excel.'), 6000);
  }
}

async function exportarPdf(dom, R0, G, pagina) {
  if (!(await autorizar(pagina, rel.nome || t(dom.nome), G.linhas.length))) return;
  try {
    const largo = G.cab.length + G.colunas.length > 5;
    const [doc, img] = await Promise.all([novoDoc(largo ? 'landscape' : 'portrait'), lerEmblema()]);
    const ref = rel.id || t('Relatório');
    doc.setProperties({ title: rel.nome || t('Relatório'), subject: t(dom.nome), creator: 'Portal RTM · Grupo Naval' });
    const y = cabecalho(doc, img, rel.nome || t('Relatório'), ref, [
      [tp('Área'), t(dom.nome)],
      [tp('Período'), dom.periodo ? dl(st.de) + ' – ' + dl(st.ate) : tp('situação actual')],
      [tp('Registos'), n0(R0.linhas.length)],
      [tp('Certificado'), rel.certificado ? tp('Sim') : tp('Não')],
    ]);
    const fs = Object.entries(def.filtros || {}).filter(([, v]) => v && v.length);
    let y2 = y;
    if (fs.length) {
      doc
        .setFont('helvetica', 'normal')
        .setFontSize(8)
        .setTextColor(86, 101, 124)
        .text(
          doc.splitTextToSize(
            tp('Filtros') + ': ' + fs.map(([d, v]) => dimNome(dom, d) + ' = ' + v.map((x) => valTxt(dom, d, x)).join(', ')).join('; '),
            doc.internal.pageSize.getWidth() - 28,
          ),
          14,
          y2,
        );
      y2 += 8;
    }
    doc.autoTable({
      startY: y2,
      margin: { left: 14, right: 14, bottom: 16 },
      head: [[...G.cab, ...G.colunas.map((k) => k.nome)]],
      body: G.linhas.map((l) => [...l.rot, ...l.vals.map((v, i) => fmtMedida(G.colunas[i].m, v))]),
      foot: R0.dims.length
        ? [[t('Total'), ...G.cab.slice(1).map(() => ''), ...G.total.map((v, i) => (v == null ? '' : fmtMedida(G.colunas[i].m, v)))]]
        : undefined,
      styles: { ...estiloTabela, fontSize: 8 },
      headStyles: estiloCabeca,
      footStyles: { fillColor: [234, 240, 249], textColor: [39, 55, 119], fontStyle: 'bold' },
      theme: 'grid',
      didParseCell: (d) => {
        if (d.column.index >= G.cab.length) d.cell.styles.halign = 'right';
      },
    });
    rodapes(doc, ref);
    entregar(doc, nomeFicheiro('pdf'), false);
  } catch (e) {
    console.error(e);
    toast(t('Não foi possível gerar o PDF.'), 6000);
  }
}

// relatório aberto no endereço (#relatorios?r=REL-…): o endereço abre-se de novo, guarda-se nos favoritos e partilha-se
R.relatorios.estadoURL = () => (rel.id ? 'r=' + rel.id : '');
