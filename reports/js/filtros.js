// Barra de filtros comum: estado, aplicação aos dados de cada página, endereço partilhável e controlos.
import { draw } from './app.js';
import { nomeRota, pendenteDe, promotorRota } from './calculos.js';
import { botao, entrada } from './componentes/janelas.js';
import { t } from './i18n/i18n.js';
import { SESSAO } from './perfil.js';
import { $, DB, el, svg } from './util.js';

// Cada página mostra só os filtros que fazem sentido para os seus dados; os valores mantêm-se ao mudar de página,
// ficam guardados no navegador e no endereço (uma ligação partilhada abre com os mesmos filtros).
const FILTROS_PAGINA = {
  inicio: ['rota'],
  'vendas-promotor': ['rota', 'tipo', 'meio', 'estado'],
  'vendas-dia': ['rota', 'cliente', 'tipo', 'meio', 'estado'],
  'vendas-cliente': ['rota', 'cliente', 'tipo', 'meio', 'estado'],
  'vendas-artigo': ['rota', 'cliente', 'artigo', 'tipo'],
  'vendas-marca': ['rota'],
  ranking: ['rota', 'tipo'],
  documentos: ['rota', 'cliente', 'tipo', 'meio', 'estado'],
  pagamentos: ['rota', 'cliente', 'tipo', 'meio'],
  pendentes: ['rota', 'cliente', 'tipo'],
  'sap-exportar': ['rota', 'tipo'],
  clientes: ['rota', 'cliente', 'tipocli', 'estadocli'],
  cadastros: ['rota'],
  'cadastros-analise': ['rota'],
  'cadastros-clientes': ['rota', 'cliente', 'municipio', 'tipocli'],
  'plano-visitas': ['rota', 'cliente'],
  auditoria: ['rota', 'cliente', 'tipo'],
  'stock-carrinha': ['rota'],
  'stock-armazem': ['rota'],
  'plano-cargas': [],
  acessos: [],
  '*': ['rota', 'cliente', 'tipo', 'meio', 'estado', 'artigo', 'municipio', 'tipocli', 'estadocli'],
};
const FV0 = { rota: [], cliente: '', tipo: '', meio: '', estado: '', artigo: '', municipio: '', tipocli: '', estadocli: '' };
export const FV = JSON.parse(JSON.stringify(FV0));
try {
  const x = JSON.parse(localStorage.getItem('vm-filtros') || '{}');
  Object.keys(FV0).forEach((k) => {
    if (x[k] != null) FV[k] = x[k];
  });
} catch (e) {}
if (!Array.isArray(FV.rota)) FV.rota = [];
const guardarFiltros = () => {
  try {
    localStorage.setItem('vm-filtros', JSON.stringify(FV));
  } catch (e) {}
};
export const normTxt = (s) =>
  String(s == null ? '' : s)
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .trim();
const filtrosDe = (pagina) => FILTROS_PAGINA[pagina] || [];
export const filtroAtivo = (pagina, k) => filtrosDe(pagina).includes(k) && (k === 'rota' ? FV.rota.length > 0 : !!FV[k]);
const contarFiltros = (pagina) => filtrosDe(pagina).filter((k) => filtroAtivo(pagina, k)).length;
// endereço: #pagina?rota=1001,1002&cliente=...
export const filtrosURL = (pagina) =>
  filtrosDe(pagina)
    .filter((k) => filtroAtivo(pagina, k))
    .map((k) => k + '=' + encodeURIComponent(k === 'rota' ? FV.rota.join(',') : FV[k]).replace(/%2C/g, ','))
    .join('&');
export function lerFiltrosURL(qs) {
  const p = new URLSearchParams(qs);
  Object.keys(FV0).forEach((k) => {
    if (p.has(k))
      FV[k] =
        k === 'rota'
          ? p
              .get(k)
              .split(',')
              .map((x) => x.trim())
              .filter(Boolean)
          : p.get(k);
  });
  guardarFiltros();
}
// Os dados de cada página (DB.vendas, DB.artigos, ...) passam a ser a vista filtrada; os dados completos ficam em DADOS_COMPLETOS
const DADOS_COMPLETOS = {},
  VISTA = {};
export function prepararDados(pagina) {
  ['vendas', 'artigos', 'cargas', 'clientes', 'cad'].forEach((k) => {
    if (DB[k] !== VISTA[k]) DADOS_COMPLETOS[k] = DB[k];
  });
  const C = DADOS_COMPLETOS,
    at = (k) => filtroAtivo(pagina, k);
  const rs = at('rota') ? new Set(FV.rota.map(String)) : null;
  const qc = at('cliente') ? normTxt(FV.cliente) : '',
    qa = at('artigo') ? normTxt(FV.artigo) : '';
  const tp = at('tipo') ? FV.tipo : '',
    me = at('meio') ? FV.meio : '',
    es = at('estado') ? FV.estado : '';
  const mu = at('municipio') ? FV.municipio : '',
    tc = at('tipocli') ? FV.tipocli : '',
    ec = at('estadocli') ? FV.estadocli : '';
  const okRota = (x) => !rs || rs.has(String(x.rota));
  const docsArtigo = qa && C.artigos ? new Set(C.artigos.filter((a) => normTxt(a.codigo + ' ' + a.artigo).includes(qa)).map((a) => a.documento)) : null;
  const okVenda = (v) =>
    okRota(v) &&
    (!qc || normTxt([v.cliente, v.nif, v.cliente_codigo].join(' ')).includes(qc)) &&
    (!tp || (v.tipo === 'Factura' ? 'FT' : 'FR') === tp) &&
    (!me || (me === '-' ? !(v.pagamentos || []).length : (v.pagamentos || []).some((x) => x.codigo === me))) &&
    (!es || (es === 'pendente') === pendenteDe(v) > 0.005) &&
    (!docsArtigo || docsArtigo.has(v.documento));
  VISTA.vendas = Array.isArray(C.vendas) ? C.vendas.filter(okVenda) : C.vendas;
  const docsOk = new Set((VISTA.vendas || []).map((v) => v.documento));
  VISTA.artigos = Array.isArray(C.artigos)
    ? C.artigos.filter((a) => docsOk.has(a.documento) && (!qa || normTxt(a.codigo + ' ' + a.artigo).includes(qa)))
    : C.artigos;
  VISTA.cargas = Array.isArray(C.cargas) ? C.cargas.filter(okRota) : C.cargas;
  const okCli = (c) => okRota(c) && (!qc || normTxt([c.nome, c.nif, c.codigo].join(' ')).includes(qc)) && (!tc || c.tipo === tc) && (!ec || c.estado === ec);
  VISTA.clientes = Array.isArray(C.clientes) ? C.clientes.filter(okCli) : C.clientes;
  if (C.cad && Array.isArray(C.cad.cadastros)) {
    const okCad = (c) => okRota(c) && (!qc || normTxt([c.cliente, c.nif].join(' ')).includes(qc)) && (!mu || c.municipio === mu) && (!tc || c.tipo === tc);
    VISTA.cad = {
      ...C.cad,
      cadastros: C.cad.cadastros.filter(okCad),
      cadastros_diarios: (C.cad.cadastros_diarios || []).filter(okRota),
      carteira: (C.cad.carteira || []).filter(okCli),
    };
  } else VISTA.cad = C.cad;
  ['vendas', 'artigos', 'cargas', 'clientes', 'cad'].forEach((k) => {
    DB[k] = VISTA[k];
  });
}
// Descrição dos filtros activos (capa do Excel, nota das páginas)
export function descreverFiltros(pagina) {
  const NOMES = { tipo: { FR: 'Factura-recibo', FT: 'Factura' }, estado: { pago: 'Pagos', pendente: 'Pendentes' } };
  return filtrosDe(pagina)
    .filter((k) => filtroAtivo(pagina, k))
    .map((k) => {
      if (k === 'rota') return (FV.rota.length > 1 ? t('Rotas') : t('Rota')) + ': ' + FV.rota.join(', ');
      if (k === 'meio') return t('Meio de recebimento') + ': ' + (FV.meio === '-' ? t('Sem meio indicado') : nomeMeio(FV.meio));
      const rot = {
        cliente: 'Cliente',
        tipo: 'Tipo de documento',
        estado: 'Estado',
        artigo: 'Artigo',
        municipio: 'Município',
        tipocli: 'Tipo de cliente',
        estadocli: 'Estado do cliente',
      }[k];
      return t(rot) + ': ' + t((NOMES[k] || {})[FV[k]] || FV[k]);
    })
    .join(' · ');
}
const nomeMeio = (cod) => {
  for (const v of DADOS_COMPLETOS.vendas || DB.vendas || []) for (const x of v.pagamentos || []) if (x.codigo === cod) return x.meio;
  return cod;
};
// Barra de filtros
export let filtroAberto = null;
export function desenharFiltros(pagina) {
  const box = $('#filtros2'),
    lista = filtrosDe(pagina),
    C = DADOS_COMPLETOS;
  box.replaceChildren();
  box.hidden = !lista.length;
  if (!lista.length) return;
  const aplicar = () => {
    guardarFiltros();
    draw();
  };
  const opcoes = (arr) =>
    [...new Set(arr.filter((x) => x != null && String(x).trim() !== '').map(String))].sort((a, b) => a.localeCompare(b, 'pt', { numeric: true }));
  const selectF = (k, rotulo, ops) => {
    const w = el('label', 'f-ctl' + (FV[k] ? ' on' : '')),
      s = el('select');
    s.setAttribute('aria-label', rotulo);
    ops.forEach(([v, txt]) => {
      const o = el('option', null, txt);
      o.value = v;
      s.append(o);
    });
    s.value = FV[k] || '';
    if (s.value !== (FV[k] || '')) {
      const o = el('option', null, FV[k]);
      o.value = FV[k];
      s.append(o);
      s.value = FV[k];
    }
    s.addEventListener('change', () => {
      FV[k] = s.value;
      aplicar();
    });
    w.append(s);
    return w;
  };
  const textoF = (k, ph, sugestoes) => {
    const w = el('label', 'f-ctl f-txt' + (FV[k] ? ' on' : '')),
      i = entrada('search', FV[k], { placeholder: ph, autocomplete: 'off' });
    i.setAttribute('aria-label', ph);
    if (sugestoes.length) {
      const id = 'dl-f-' + k;
      const d = el('datalist');
      d.id = id;
      sugestoes.slice(0, 1500).forEach((v) => {
        const o = el('option');
        o.value = v;
        d.append(o);
      });
      w.append(d);
      i.setAttribute('list', id);
    }
    const ir = () => {
      if (i.value.trim() !== FV[k]) {
        FV[k] = i.value.trim();
        aplicar();
      }
    };
    i.addEventListener('change', ir);
    i.addEventListener('search', ir);
    w.prepend(el('span', 'f-ico'));
    w.querySelector('.f-ico').innerHTML = svg('filtro');
    w.append(i);
    return w;
  };
  const rotasDisp = () => {
    const minhas = SESSAO && Array.isArray(SESSAO.rotas) ? SESSAO.rotas.map(String) : null;
    let rs = opcoes([...Object.keys(DB.mapRota || {}), ...(C.vendas || []).map((x) => x.rota), ...(C.clientes || []).map((x) => x.rota)]);
    if (minhas) rs = rs.filter((r) => minhas.includes(r));
    return rs;
  };
  lista.forEach((k) => {
    if (k === 'rota') {
      const rs = rotasDisp();
      if (rs.length < 2 && !FV.rota.length) return;
      const w = el('div', 'f-ctl f-rota' + (FV.rota.length ? ' on' : ''));
      const b = el(
        'button',
        'f-btn',
        FV.rota.length === 0 ? t('Todas as rotas') : FV.rota.length === 1 ? promotorRota(FV.rota[0]) : t('{n} rotas', { n: FV.rota.length }),
      );
      b.type = 'button';
      b.setAttribute('aria-haspopup', 'true');
      b.setAttribute('aria-expanded', 'false');
      const pop = el('div', 'f-pop');
      pop.hidden = true;
      const sel = new Set(FV.rota);
      const procura = entrada('search', '', { placeholder: t('Procurar rota ou promotor…') });
      const lst = el('div', 'f-lst');
      const desenhar = () => {
        const q = normTxt(procura.value);
        lst.replaceChildren();
        rs.filter((r) => !q || normTxt(r + ' ' + nomeRota(r)).includes(q)).forEach((r) => {
          const l = el('label', 'f-op'),
            c = entrada('checkbox');
          c.checked = sel.has(r);
          c.addEventListener('change', () => {
            if (c.checked) sel.add(r);
            else sel.delete(r);
          });
          l.append(c, el('b', null, r), el('span', null, nomeRota(r) !== r ? nomeRota(r) : ''));
          lst.append(l);
        });
      };
      procura.addEventListener('input', desenhar);
      const rod = el('div', 'f-rod');
      rod.append(
        botao(t('Todas'), 'ghost sm', () => {
          sel.clear();
          desenhar();
        }),
        botao(t('Aplicar'), 'sm', () => fechar(true)),
      );
      pop.append(procura, lst, rod);
      const fechar = (aplica) => {
        pop.hidden = true;
        b.setAttribute('aria-expanded', 'false');
        filtroAberto = null;
        document.removeEventListener('mousedown', fora);
        document.removeEventListener('keydown', esc);
        if (aplica !== false) {
          const novo = rs.filter((r) => sel.has(r));
          if (novo.join() !== FV.rota.join()) {
            FV.rota = novo;
            aplicar();
          }
        }
      };
      const fora = (e) => {
        if (!w.contains(e.target)) fechar(true);
      };
      const esc = (e) => {
        if (e.key === 'Escape') {
          fechar(false);
          b.focus();
        }
      };
      b.addEventListener('click', () => {
        if (!pop.hidden) {
          fechar(true);
          return;
        }
        desenhar();
        pop.hidden = false;
        b.setAttribute('aria-expanded', 'true');
        filtroAberto = fechar;
        setTimeout(() => {
          document.addEventListener('mousedown', fora);
          document.addEventListener('keydown', esc);
          procura.focus();
        }, 0);
      });
      w.append(b, pop);
      box.append(w);
    } else if (k === 'cliente')
      box.append(textoF('cliente', t('Cliente (nome ou NIF)'), opcoes([...(C.vendas || []).map((x) => x.cliente), ...(C.clientes || []).map((x) => x.nome)])));
    else if (k === 'artigo') box.append(textoF('artigo', t('Artigo (nome ou código)'), opcoes((C.artigos || []).map((x) => x.artigo))));
    else if (k === 'tipo')
      box.append(
        selectF('tipo', t('Tipo de documento'), [
          ['', t('Todos os documentos')],
          ['FR', t('Factura-recibo')],
          ['FT', t('Factura')],
        ]),
      );
    else if (k === 'meio') {
      const m = new Map();
      (C.vendas || []).forEach((v) => (v.pagamentos || []).forEach((x) => m.set(x.codigo, x.meio)));
      box.append(
        selectF('meio', t('Meio de recebimento'), [
          ['', t('Todos os meios')],
          ...[...m.entries()].sort((a, b) => String(a[1]).localeCompare(String(b[1]), 'pt')).map(([c, n]) => [c, t(n)]),
          ['-', t('Sem meio indicado')],
        ]),
      );
    } else if (k === 'estado')
      box.append(
        selectF('estado', t('Estado'), [
          ['', t('Pagos e pendentes')],
          ['pago', t('Pagos')],
          ['pendente', t('Pendentes')],
        ]),
      );
    else if (k === 'municipio') {
      const ms = opcoes(((C.cad && C.cad.cadastros) || []).map((x) => x.municipio));
      if (ms.length) box.append(selectF('municipio', t('Município'), [['', t('Todos os municípios')], ...ms.map((x) => [x, x])]));
    } else if (k === 'tipocli') {
      const ts = opcoes([...(C.clientes || []).map((x) => x.tipo), ...((C.cad && C.cad.cadastros) || []).map((x) => x.tipo)]);
      if (ts.length) box.append(selectF('tipocli', t('Tipo de cliente'), [['', t('Todos os tipos de cliente')], ...ts.map((x) => [x, t(x)])]));
    } else if (k === 'estadocli') {
      const es = opcoes((C.clientes || []).map((x) => x.estado));
      if (es.length) box.append(selectF('estadocli', t('Estado do cliente'), [['', t('Todos os estados')], ...es.map((x) => [x, t(x)])]));
    }
  });
  const n = contarFiltros(pagina);
  if (n) {
    const lp = el('button', 'linkbtn f-limpar', n === 1 ? t('Limpar filtro') : t('Limpar {n} filtros', { n }));
    lp.type = 'button';
    lp.addEventListener('click', () => {
      filtrosDe(pagina).forEach((k) => {
        FV[k] = JSON.parse(JSON.stringify(FV0[k]));
      });
      aplicar();
    });
    box.append(lp);
  }
  box.hidden = !box.childNodes.length;
}
