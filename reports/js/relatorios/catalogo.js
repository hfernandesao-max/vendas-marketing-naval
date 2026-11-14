// Camada semântica do explorador de relatórios: as áreas do portal, com as dimensões e as medidas de cada uma e as
// regras da Naval já aplicadas (as mesmas funções dos ecrãs oficiais: vendas sem IVA, notas de crédito, cobranças,
// substituições de facturas, clientes pelo NIF). Cada área:
//   - só aparece a quem pode ver a página de origem (matriz de acessos) e lê os dados pelas APIs do portal, com as
//     rotas de quem pede (o servidor aplica o âmbito);
//   - diz de onde vêm os dados (fonte) e se seguem o período da barra.
import { apiJson } from '../api.js';
import { eNC, isCF, liquidoDe, pagosDe, pendenteDe, recebidoDe, tipoDoc } from '../calculos.js';
import { dadosCompletos } from '../filtros.js';
import { fetchStock, garantirArtigos } from '../fontes.js';
import { t } from '../i18n/i18n.js';
import { podeVer } from '../perfil.js';
import { st } from '../periodo.js';
import { DB } from '../util.js';

const sim = (v) => (v ? t('Sim') : t('Não'));
/** Campo do catálogo: [id, nome, tipo (texto|data|kz|n|q), valor?] */
const campo = (id, nome, tipo = 'texto', valor = null) => [id, { id, nome, tipo, valor }];
const campos = (lista) => Object.fromEntries(lista.filter(Boolean));
const soma = (id, nome, campoOuValor, fmt = 'kz') =>
  typeof campoOuValor === 'function' ? { id, nome, agg: 'soma', valor: campoOuValor, fmt } : { id, nome, agg: 'soma', campo: campoOuValor, fmt };
const contar = (id, nome, quando = null) => ({ id, nome, agg: 'contagem', quando, fmt: 'n' });
const distintos = (id, nome, campoOuValor) =>
  typeof campoOuValor === 'function'
    ? { id, nome, agg: 'distintos', valor: campoOuValor, fmt: 'n' }
    : { id, nome, agg: 'distintos', campo: campoOuValor, fmt: 'n' };
const razao = (id, nome, num, den, fmt = 'kz', escala = 1) => ({ id, nome, agg: 'razao', num, den, fmt, escala });
const media = (id, nome, c, fmt = 'kz') => ({ id, nome, agg: 'media', campo: c, fmt });

const periodoPinguim = () => ({ de: st.de, ate: st.ate });
const vendasEfectivas = () => (dadosCompletos().vendas || []).filter((v) => !v.substituicao);
const chaveCliente = (v) => (isCF(v.nif) ? v.cliente : v.nif);
const dimsRota = [
  campo('rota', 'Rota'),
  campo('promotor', 'Promotor', 'texto', (l) => l.promotor || (DB.mapRota && (DB.mapRota[l.rota] || {}).promotor) || l.rota),
];

export const DOMINIOS = [
  {
    id: 'vendas',
    grupo: 'Comercial',
    nome: 'Vendas (documentos)',
    desc: 'Documentos de venda e notas de crédito do período, como nas páginas de vendas (substituições de facturas à parte).',
    pagina: ['vendas-dia', 'vendas-promotor', 'vendas-cliente'],
    fonte: 'Pinguim BO',
    periodo: true,
    carregar: async () => vendasEfectivas(),
    campos: campos([
      campo('data', 'Data', 'data'),
      ...dimsRota,
      campo('tipo', 'Tipo de documento', 'texto', (v) => t(v.tipo || tipoDoc(v))),
      campo('cliente', 'Cliente'),
      campo('cliente_codigo', 'Código do cliente'),
      campo('documento', 'Documento'),
      campo('nota_credito', 'Nota de crédito', 'texto', (v) => sim(eNC(v))),
      campo('liquidado', 'Liquidado', 'texto', (v) => sim(v.liquidado)),
      campo('meio', 'Meio de cobrança', 'texto', (v) => [...new Set(pagosDe(v).map((p) => t(p.meio || p.codigo)))].sort()),
      campo('hora', 'Hora', 'texto', (v) => String(v.hora || '').slice(0, 2) + 'h'),
    ]),
    medidas: [
      soma('vendas_sem_iva', 'Vendas sem IVA', liquidoDe),
      soma('vendas_com_iva', 'Vendas com IVA', 'total'),
      soma('notas_credito', 'Notas de crédito', (v) => (eNC(v) ? -(+v.total || 0) : 0)),
      contar('documentos', 'Documentos de venda', (v) => !eNC(v)),
      distintos('clientes', 'Clientes com compras', (v) => (eNC(v) ? null : chaveCliente(v))),
      razao(
        'valor_medio',
        'Valor médio por documento (sem IVA)',
        (v) => (eNC(v) ? 0 : liquidoDe(v)),
        (v) => (eNC(v) ? 0 : 1),
      ),
      soma('cobrado', 'Cobrado', recebidoDe),
      soma('por_receber', 'Por receber', pendenteDe),
    ],
  },
  {
    id: 'artigos',
    grupo: 'Comercial',
    nome: 'Vendas por artigo (linhas)',
    desc: 'Linhas de artigo dos documentos de venda do período.',
    pagina: ['vendas-artigo', 'vendas-promotor-artigo'],
    fonte: 'Pinguim BO',
    periodo: true,
    carregar: async () => {
      await garantirArtigos();
      const docs = new Set(vendasEfectivas().map((v) => v.documento));
      return (dadosCompletos().artigos || []).filter((a) => docs.has(a.documento));
    },
    campos: campos([
      campo('data', 'Data', 'data'),
      ...dimsRota,
      campo('codigo', 'Código do artigo'),
      campo('artigo', 'Artigo'),
      campo('unidade', 'Unidade'),
      campo('documento', 'Documento'),
      campo('nota_credito', 'Nota de crédito', 'texto', (a) => sim(a.nc)),
    ]),
    medidas: [
      soma('valor_sem_iva', 'Vendas sem IVA', 'valor_sem_iva'),
      soma('total', 'Vendas com IVA', 'total'),
      soma('quantidade', 'Quantidade', 'quantidade', 'q'),
      soma('desconto', 'Desconto', 'desconto'),
      soma('iva', 'IVA', 'iva'),
      distintos('documentos', 'Documentos', 'documento'),
      razao(
        'preco_medio',
        'Preço médio sem IVA',
        (a) => +a.valor_sem_iva || 0,
        (a) => +a.quantidade || 0,
      ),
      contar('linhas', 'Linhas'),
    ],
  },
  {
    id: 'cobrancas',
    grupo: 'Comercial',
    nome: 'Cobranças',
    desc: 'Valores cobrados por documento e meio de cobrança.',
    pagina: ['pagamentos'],
    fonte: 'Pinguim BO',
    periodo: true,
    carregar: async () =>
      vendasEfectivas()
        .filter((v) => !eNC(v))
        .flatMap((v) => pagosDe(v).map((p) => ({ data: v.data, rota: v.rota, promotor: v.promotor, documento: v.documento, cliente: v.cliente, ...p }))),
    campos: campos([
      campo('data', 'Data', 'data'),
      ...dimsRota,
      campo('meio', 'Meio de cobrança', 'texto', (p) => t(p.meio || p.codigo)),
      campo('cliente', 'Cliente'),
      campo('documento', 'Documento'),
    ]),
    medidas: [soma('valor', 'Valor cobrado', 'valor'), distintos('documentos', 'Documentos', 'documento'), contar('cobrancas', 'Cobranças')],
  },
  {
    id: 'carteira',
    grupo: 'Comercial',
    nome: 'Carteira de clientes',
    desc: 'Clientes da carteira por rota e dia de visita (situação actual).',
    pagina: ['clientes'],
    fonte: 'Pinguim BO e cadastros RTM',
    periodo: false,
    carregar: async () => dadosCompletos().clientes || [],
    campos: campos([
      ...dimsRota,
      campo('nome', 'Cliente'),
      campo('codigo', 'Código do cliente'),
      campo('tipo', 'Tipo de cliente'),
      campo('dia', 'Dia de visita'),
      campo('estado', 'Estado'),
      campo('municipio', 'Município'),
      campo('gps', 'Com GPS', 'texto', (c) => (c.tem_gps == null ? '' : sim(c.tem_gps))),
    ]),
    medidas: [contar('clientes', 'Clientes'), contar('com_gps', 'Clientes com GPS', (c) => !!c.tem_gps)],
  },
  {
    id: 'cadastros',
    grupo: 'Comercial',
    nome: 'Cadastros de clientes',
    desc: 'Novos clientes registados pelos promotores (cadastros RTM).',
    pagina: ['cadastros', 'cadastros-clientes'],
    fonte: 'Cadastros RTM',
    periodo: false,
    carregar: async () => (dadosCompletos().cad && dadosCompletos().cad.cadastros) || [],
    campos: campos([
      campo('data', 'Data do registo', 'data', (c) => c.data || c.data_registo || c.criado || ''),
      ...dimsRota,
      campo('municipio', 'Município'),
      campo('tipo', 'Tipo de cliente'),
      campo('estado', 'Estado'),
    ]),
    medidas: [contar('cadastros', 'Cadastros')],
  },
  {
    id: 'cargas',
    grupo: 'Operações',
    nome: 'Plano de cargas',
    desc: 'Carga de cada carrinha por dia (plano de cargas).',
    pagina: ['plano-cargas', 'registo-cargas'],
    fonte: 'Plano de cargas',
    periodo: true,
    carregar: async () => (dadosCompletos().cargas || []).filter((c) => c.data >= st.de && c.data <= st.ate),
    campos: campos([
      campo('data', 'Data', 'data'),
      ...dimsRota,
      campo('motorista', 'Motorista'),
      campo('matricula', 'Matrícula'),
      campo('transportadora', 'Transportadora'),
    ]),
    medidas: [
      soma('carga', 'Total (cx)', 'carga', 'n'),
      soma('fraldas', 'Fraldas (cx)', 'carga_fraldas', 'n'),
      soma('sabao', 'Sabão (cx)', 'carga_sabao', 'n'),
      soma('sabonete', 'Sabonete (cx)', 'carga_sabonete', 'n'),
      contar('cargas', 'Cargas'),
    ],
  },
  {
    id: 'stock',
    grupo: 'Operações',
    nome: 'Stock (carrinhas e armazéns)',
    desc: 'Posição de stock no último dia do período, por carrinha e armazém.',
    pagina: ['stock-carrinha', 'stock-armazem'],
    fonte: 'Pinguim BO',
    periodo: true,
    carregar: async () => {
      const S = await fetchStock(st.ate);
      return [
        ...((podeVer('stock-carrinha') && S.linhas_carrinhas) || []).map((l) => ({ ...l, tipo_local: t('Carrinha'), local: l.carrinha || l.armazem })),
        ...((podeVer('stock-armazem') && S.linhas_armazens) || []).map((l) => ({ ...l, tipo_local: t('Armazém'), local: l.armazem })),
      ].filter((l) => +l.quantidade);
    },
    campos: campos([
      campo('tipo_local', 'Carrinha ou armazém'),
      campo('local', 'Local'),
      ...dimsRota,
      campo('codigo', 'Código do artigo'),
      campo('artigo', 'Artigo', 'texto', (l) => l.artigo || l.produto),
    ]),
    medidas: [soma('quantidade', 'Quantidade', 'quantidade', 'q'), distintos('artigos', 'Artigos', 'codigo')],
  },
  {
    id: 'requisicoes',
    grupo: 'Operações',
    nome: 'Requisições ao armazém',
    desc: 'Requisições de reposição das carrinhas do período, por estado.',
    pagina: ['requisicoes-carrinha'],
    fonte: 'Portal RTM',
    periodo: true,
    carregar: async () => {
      const d = await apiJson('requisicoes?de=' + st.de + '&ate=' + st.ate);
      return (d && d.requisicoes) || [];
    },
    campos: campos([
      campo('data', 'Data', 'data'),
      ...dimsRota,
      campo('estado', 'Estado', 'texto', (r) =>
        t({ rascunho: 'Rascunho', emitida: 'Emitida', anulada: 'Anulada', carregada: 'Carregada' }[r.estado] || r.estado),
      ),
      campo('origem', 'Origem', 'texto', (r) => t((r.origem && r.origem.tipo) === 'conciliacao' ? 'Automática (conciliação)' : 'Manual')),
      campo('criado_por', 'Criada por'),
    ]),
    medidas: [contar('requisicoes', 'Requisições'), soma('quantidade', 'Quantidade', 'quantidade', 'q'), soma('linhas', 'Linhas', 'n_linhas', 'n')],
  },
  {
    id: 'caixa',
    grupo: 'Finanças',
    nome: 'Fecho de caixa (dias)',
    desc: 'Resumo diário do fecho de caixa: saldo inicial, entregas, Multicaixa, depósitos, contagem e diferença.',
    pagina: ['caixa-marketing'],
    fonte: 'Portal RTM',
    periodo: true,
    carregar: async () => {
      const d = await apiJson('relatorios?fonte=caixa&de=' + st.de + '&ate=' + st.ate);
      return (d && d.dias) || [];
    },
    campos: campos([
      campo('dia', 'Dia', 'data'),
      campo('estado', 'Estado', 'texto', (c) =>
        t({ aberta: 'Aberto', fechada: 'Validado', verificada: 'Aprovado no 1.º nível', aprovada: 'Aprovado' }[c.estado] || c.estado),
      ),
      campo('fechada_por', 'Validado por'),
      campo('aprovada_por', 'Aprovado por'),
    ]),
    medidas: [
      soma('entregue', 'Dinheiro entregue', 'entregue'),
      soma('multicaixa', 'Multicaixa', 'multicaixa'),
      soma('depositos', 'Depósitos no banco', 'depositos'),
      soma('diferenca', 'Diferença da contagem', 'diferenca'),
      media('saldo_teorico', 'Saldo final teórico (média)', 'saldo_teorico'),
      contar('dias', 'Dias'),
      contar('dias_com_diferenca', 'Dias com diferença', (c) => Math.abs(+c.diferenca || 0) >= 0.01),
    ],
  },
  {
    id: 'caixa_movimentos',
    grupo: 'Finanças',
    nome: 'Fecho de caixa (movimentos)',
    desc: 'Entregas por promotor, talões do TPA e depósitos no banco, linha a linha.',
    pagina: ['caixa-marketing'],
    fonte: 'Portal RTM',
    periodo: true,
    carregar: async () => {
      const d = await apiJson('relatorios?fonte=caixa&de=' + st.de + '&ate=' + st.ate);
      return (d && d.movimentos) || [];
    },
    campos: campos([
      campo('dia', 'Dia', 'data'),
      campo('tipo', 'Movimento', 'texto', (m) =>
        t({ entrega: 'Dinheiro entregue', multicaixa: 'Multicaixa', deposito: 'Depósito no banco' }[m.tipo] || m.tipo),
      ),
      ...dimsRota,
      campo('empresa', 'Empresa'),
      campo('banco', 'Banco'),
    ]),
    medidas: [soma('valor', 'Valor', 'valor'), contar('movimentos', 'Movimentos')],
  },
  {
    id: 'conciliacao',
    grupo: 'Operações',
    nome: 'Conciliação das carrinhas',
    desc: 'Carregado, vendido e contado por rota e dia, com as diferenças e o seu valor (rotas contadas).',
    pagina: ['conciliacao-carrinha'],
    fonte: 'Portal RTM',
    periodo: true,
    carregar: async () => {
      const d = await apiJson('relatorios?fonte=conciliacao&de=' + st.de + '&ate=' + st.ate);
      return (d && d.rotas) || [];
    },
    campos: campos([
      campo('dia', 'Dia', 'data'),
      ...dimsRota,
      campo('estado', 'Estado', 'texto', (r) =>
        t(
          { por_contar: 'Por contar', por_validar: 'Por validar', por_aprovar: 'Por aprovar', por_fechar: 'Por fechar', aprovada: 'Aprovada' }[r.estado] ||
            r.estado,
        ),
      ),
      campo('contado_por', 'Contado por'),
    ]),
    medidas: [
      soma('falta_kz', 'Faltas (Kz)', 'falta_kz'),
      soma('sobra_kz', 'Sobras (Kz)', 'sobra_kz'),
      soma('diferencas', 'Artigos com diferença', 'diferencas', 'n'),
      soma('vendido', 'Quantidade vendida', 'vendido', 'q'),
      soma('carregado', 'Quantidade carregada', 'carregado', 'q'),
      contar('conciliacoes', 'Conciliações'),
    ],
  },
  {
    id: 'conciliacao_artigos',
    grupo: 'Operações',
    nome: 'Conciliação das carrinhas (artigos)',
    desc: 'Cada artigo da conciliação: inicial, carregado, vendido, devolvido, esperado, contado e diferença.',
    pagina: ['conciliacao-carrinha'],
    fonte: 'Portal RTM',
    periodo: true,
    carregar: async () => {
      const d = await apiJson('relatorios?fonte=conciliacao&de=' + st.de + '&ate=' + st.ate);
      return (d && d.artigos) || [];
    },
    campos: campos([campo('dia', 'Dia', 'data'), ...dimsRota, campo('codigo', 'Código do artigo'), campo('artigo', 'Artigo')]),
    medidas: [
      soma('diferenca', 'Diferença (quantidade)', 'diferenca', 'q'),
      soma('valor_diferenca', 'Diferença (Kz)', 'valor_diferenca'),
      soma('vendido', 'Vendido', 'vendido', 'q'),
      soma('carregado', 'Carregado', 'carregado', 'q'),
      soma('contado', 'Contado', 'contado', 'q'),
    ],
  },
  {
    id: 'pedidos',
    grupo: 'Comercial',
    nome: 'Pedidos dos clientes',
    desc: 'Pedidos feitos pelos clientes pelo código QR (pendentes e decididos nos últimos 30 dias).',
    pagina: ['pedidos-clientes'],
    fonte: 'Portal RTM',
    periodo: false,
    carregar: async () => {
      const d = await apiJson('pedidos-clientes');
      return (d && d.pedidos) || [];
    },
    campos: campos([campo('criado', 'Data do pedido', 'data'), ...dimsRota, campo('tipo', 'Tipo de pedido'), campo('estado', 'Estado')]),
    medidas: [contar('pedidos', 'Pedidos'), contar('pendentes', 'Pendentes', (p) => p.estado === 'pendente')],
  },
  {
    id: 'alertas',
    grupo: 'Análise',
    nome: 'Alertas',
    desc: 'Alertas de anomalias do último dia útil, com o tratamento (assumido, resolvido, silenciado).',
    pagina: ['alertas'],
    fonte: 'Portal RTM',
    periodo: false,
    carregar: async () => {
      const d = await apiJson('alertas');
      return (d && d.alertas) || [];
    },
    campos: campos([
      campo('gravidade', 'Gravidade', 'texto', (a) => t({ critico: 'Crítico', aviso: 'Aviso', info: 'Informação' }[a.gravidade] || a.gravidade)),
      campo('tipo', 'Tipo de alerta'),
      ...dimsRota,
      campo('tratamento', 'Tratamento', 'texto', (a) => t(((a.tratamento && a.tratamento.estado) || 'novo').replace(/^./, (c) => c.toUpperCase()))),
    ]),
    medidas: [contar('alertas', 'Alertas'), contar('activos', 'Alertas activos', (a) => a.activo !== false)],
  },
  {
    id: 'equipamentos',
    grupo: 'Sistema',
    nome: 'Equipamentos',
    desc: 'Smartphones e impressoras do terreno, por tipo, estado e colaborador.',
    pagina: ['equipamentos'],
    fonte: 'Base de dados dos equipamentos',
    periodo: false,
    carregar: async () => {
      const d = await apiJson('equipamentos');
      return (d && d.equipamentos) || [];
    },
    campos: null, // campos lidos dos próprios dados (as colunas da ficha do equipamento)
    medidas: [contar('equipamentos', 'Equipamentos')],
  },
  {
    id: 'suporte',
    grupo: 'Sistema',
    nome: 'Pedidos de suporte',
    desc: 'Pedidos de suporte e incidentes (os seus; quem gere vê todos).',
    pagina: ['suporte'],
    fonte: 'Portal RTM',
    periodo: false,
    carregar: async () => {
      const d = await apiJson('suporte');
      return (d && d.pedidos) || [];
    },
    campos: campos([
      campo('criado_em', 'Data de abertura', 'data'),
      campo('tipo', 'Tipo'),
      campo('prioridade', 'Prioridade'),
      campo('estado', 'Estado'),
      campo('fora_prazo', 'Fora do prazo', 'texto', (p) => sim(p.fora_prazo)),
    ]),
    medidas: [
      contar('pedidos', 'Pedidos'),
      contar('fora_prazo', 'Fora do prazo', (p) => !!p.fora_prazo),
      media('satisfacao', 'Satisfação (média)', 'satisfacao', 'n'),
    ],
  },
];

/** Nome legível de uma chave dos dados (campos lidos dos próprios dados). */
const nomeChave = (k) =>
  String(k)
    .replace(/([a-z])([A-Z])/g, '$1 $2')
    .replace(/_/g, ' ')
    .replace(/^./, (c) => c.toUpperCase());

/**
 * Áreas sem catálogo próprio: campos tirados das colunas simples dos dados (texto vira dimensão; números
 * ficam disponíveis como medidas de soma). Os campos do catálogo mantêm o nome, o tipo e as regras.
 */
export function completarCampos(dom, linhas) {
  // só as áreas sem catálogo próprio (ex.: Equipamentos, cuja ficha vem da sua base de dados) ganham campos dos dados:
  // nas outras, as medidas e as dimensões são só as do catálogo, com as regras da Naval
  if (dom.campos) return dom;
  const base = {};
  const extra = [];
  const amostra = linhas.slice(0, 200);
  const chaves = new Set();
  amostra.forEach((l) => Object.keys(l).forEach((k) => chaves.add(k)));
  chaves.forEach((k) => {
    if (base[k] || /^(chave|id|historico|linhas|pagamentos|substituicao|pagamentos_origem|impressoes|origem|tratamento|nif)$/i.test(k)) return;
    const vs = amostra.map((l) => l[k]).filter((v) => v != null && v !== '');
    if (!vs.length || vs.some((v) => typeof v === 'object')) return;
    if (vs.every((v) => typeof v === 'number')) {
      extra.push(soma('soma_' + k.toLowerCase().replace(/[^a-z0-9_]/g, '_'), nomeChave(k), (l) => (typeof l[k] === 'number' ? l[k] : null), 'n'));
      return;
    }
    const data = vs.every((v) => /^\d{4}-\d{2}-\d{2}/.test(String(v)));
    base[k] = { id: k, nome: nomeChave(k), tipo: data ? 'data' : 'texto', auto: true };
  });
  const ids = new Set(dom.medidas.map((m) => m.id));
  return { ...dom, campos: base, medidas: [...dom.medidas, ...extra.filter((m) => !ids.has(m.id))] };
}

/** Áreas que esta pessoa pode usar (vê pelo menos uma página de origem). */
export const dominiosVisiveis = () => DOMINIOS.filter((d) => d.pagina.some(podeVer));
export const dominio = (id) => DOMINIOS.find((d) => d.id === id) || null;
export { periodoPinguim };
