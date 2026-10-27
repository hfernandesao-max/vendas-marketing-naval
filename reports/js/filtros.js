// Barra de filtros comum: estado, aplicação aos dados de cada página, endereço partilhável e controlos.
import { draw } from './app.js';
import { pendenteDe, promotorRota } from './calculos.js';
import { escolha, etiquetas, fecharAberto, pesquisa } from './componentes/barra-filtros.js';
import { t } from './i18n/i18n.js';
import { SESSAO } from './perfil.js';
import { $, DB, el } from './util.js';

// Cada página mostra só os filtros que fazem sentido para os seus dados; os valores mantêm-se ao mudar de página,
// ficam guardados no navegador e no endereço (uma ligação partilhada abre com os mesmos filtros).
const FILTROS_PAGINA = {
  inicio: ['rota'],
  sincronizacao: ['rota'],
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
  'registo-cargas': ['rota'],
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
/** Dados completos do período (sem os filtros das páginas), já com o âmbito do perfil aplicado no servidor. */
export const dadosCompletos = () => {
  const out = {};
  ['vendas', 'artigos', 'cargas', 'clientes', 'cad'].forEach((k) => {
    out[k] = DB[k] === VISTA[k] && k in DADOS_COMPLETOS ? DADOS_COMPLETOS[k] : DB[k];
  });
  return out;
};
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
// Tipos de documento: FR e FT
function tiposPresentes() {
  return [
    ['FR', t('Factura-recibo')],
    ['FT', t('Factura')],
  ];
}
// Descrição dos filtros activos (capa do Excel, nota das páginas)
export function descreverFiltros(pagina) {
  const NOMES = { tipo: { FR: 'Factura-recibo', FT: 'Factura' }, estado: { pago: 'Pagos', pendente: 'Pendentes' } };
  return filtrosDe(pagina)
    .filter((k) => filtroAtivo(pagina, k))
    .map((k) => {
      if (k === 'rota') return (FV.rota.length > 1 ? t('Rotas') : t('Rota')) + ': ' + FV.rota.join(', ');
      if (k === 'meio') return t('Meio de cobrança') + ': ' + (FV.meio === '-' ? t('Sem meio indicado') : nomeMeio(FV.meio));
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
// Barra de filtros (design system em componentes/barra-filtros.js): pesquisas, escolhas e etiquetas
export const filtroAberto = (aplica) => {
  if (fecharAberto && aplica !== false) fecharAberto();
};
const ICONE_FILTRO = { rota: 'route', tipo: 'file', meio: 'card', estado: 'clock', municipio: 'ware', tipocli: 'users', estadocli: 'shield' };
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
  const linha = el('div', 'fb-linha');
  const etiq = [];
  // Escolha única (valor em FV[k], '' = todos)
  const unica = (k, rotulo, ops) => {
    if (FV[k] && !ops.some(([v]) => v === FV[k])) ops = [...ops, [FV[k], FV[k]]];
    linha.append(
      escolha({
        titulo: rotulo,
        icone: ICONE_FILTRO[k],
        opcoes: ops.map(([v, txt]) => ({ id: v, titulo: txt })),
        selecionados: FV[k] ? [FV[k]] : [],
        multipla: false,
        aoMudar: (sel) => {
          FV[k] = sel[0] || '';
          aplicar();
        },
      }),
    );
    if (FV[k]) {
      const o = ops.find(([v]) => v === FV[k]);
      etiq.push({
        txt: rotulo + ': ' + (o ? o[1] : FV[k]),
        remover: () => {
          FV[k] = '';
          aplicar();
        },
      });
    }
  };
  const texto = (k, rotulo, sugestoes) => {
    linha.append(
      pesquisa({
        rotulo,
        valor: FV[k],
        sugestoes,
        aoMudar: (v) => {
          FV[k] = v;
          aplicar();
        },
      }),
    );
    if (FV[k])
      etiq.push({
        txt: '🔍 «' + FV[k] + '»',
        remover: () => {
          FV[k] = '';
          aplicar();
        },
      });
  };
  const rotasDisp = () => {
    const minhas = SESSAO && Array.isArray(SESSAO.rotas) ? SESSAO.rotas.map(String) : null;
    let rs = opcoes([...Object.keys(DB.mapRota || {}), ...(C.vendas || []).map((x) => x.rota), ...(C.clientes || []).map((x) => x.rota)]);
    if (minhas) rs = rs.filter((r) => minhas.includes(r));
    return rs;
  };
  // Pesquisas primeiro (ocupam o espaço livre), depois as escolhas, como nas páginas de stock
  lista
    .filter((k) => k === 'cliente' || k === 'artigo')
    .forEach((k) => {
      if (k === 'cliente')
        texto('cliente', t('Cliente (nome ou NIF)'), opcoes([...(C.vendas || []).map((x) => x.cliente), ...(C.clientes || []).map((x) => x.nome)]));
      else texto('artigo', t('Artigo (nome ou código)'), opcoes((C.artigos || []).map((x) => x.artigo)));
    });
  lista.forEach((k) => {
    if (k === 'rota') {
      const rs = rotasDisp();
      if (rs.length < 2 && !FV.rota.length) return;
      linha.append(
        escolha({
          titulo: t('Rotas'),
          icone: ICONE_FILTRO.rota,
          opcoes: rs.map((r) => ({ id: r, titulo: promotorRota(r), subtitulo: t('Rota') + ' ' + r })),
          selecionados: FV.rota.slice(),
          multipla: true,
          procurar: t('Procurar rota ou promotor…'),
          aoMudar: (sel) => {
            FV.rota = rs.filter((r) => sel.includes(r));
            aplicar();
          },
        }),
      );
      FV.rota.forEach((r) =>
        etiq.push({
          txt: promotorRota(r),
          remover: () => {
            FV.rota = FV.rota.filter((x) => x !== r);
            aplicar();
          },
        }),
      );
    } else if (k === 'tipo') unica('tipo', t('Tipo de documento'), tiposPresentes(C.vendas));
    else if (k === 'meio') {
      const m = new Map();
      (C.vendas || []).forEach((v) => (v.pagamentos || []).forEach((x) => m.set(x.codigo, x.meio)));
      unica('meio', t('Meio de cobrança'), [
        ...[...m.entries()].sort((a, b) => String(a[1]).localeCompare(String(b[1]), 'pt')).map(([c, n]) => [c, t(n)]),
        ['-', t('Sem meio indicado')],
      ]);
    } else if (k === 'estado')
      unica('estado', t('Estado'), [
        ['pago', t('Pagos')],
        ['pendente', t('Pendentes')],
      ]);
    else if (k === 'municipio') {
      const ms = opcoes(((C.cad && C.cad.cadastros) || []).map((x) => x.municipio));
      if (ms.length)
        unica(
          'municipio',
          t('Município'),
          ms.map((x) => [x, x]),
        );
    } else if (k === 'tipocli') {
      const ts = opcoes([...(C.clientes || []).map((x) => x.tipo), ...((C.cad && C.cad.cadastros) || []).map((x) => x.tipo)]);
      if (ts.length)
        unica(
          'tipocli',
          t('Tipo de cliente'),
          ts.map((x) => [x, t(x)]),
        );
    } else if (k === 'estadocli') {
      const es = opcoes((C.clientes || []).map((x) => x.estado));
      if (es.length)
        unica(
          'estadocli',
          t('Estado do cliente'),
          es.map((x) => [x, t(x)]),
        );
    }
  });
  box.append(
    linha,
    etiquetas(etiq, () => {
      filtrosDe(pagina).forEach((k) => {
        FV[k] = JSON.parse(JSON.stringify(FV0[k]));
      });
      aplicar();
    }),
  );
  box.hidden = !linha.childNodes.length;
}
