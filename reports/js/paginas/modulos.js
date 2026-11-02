// Carregamento das páginas a pedido (divisão do código por rota, prática habitual das aplicações web): o arranque
// traz só o núcleo do portal; o módulo de cada página descarrega-se quando a página se abre pela primeira vez
// (import dinâmico). O import map de reports/index.php dá a cada módulo a sua versão (?v=…), por isso os módulos
// carregados a pedido também ficam guardados no navegador até mudarem.
import { R } from './registo.js';

// Endereços fixos (literais): o navegador e as ferramentas de análise vêem cada módulo; nada vem do endereço
const MODULOS = {
  inicio: () => import('./inicio.js'),
  vendas: () => import('./vendas.js'),
  recebimentos: () => import('./recebimentos.js'),
  clientes: () => import('./clientes.js'),
  terreno: () => import('./terreno.js'),
  sincronizacao: () => import('./sincronizacao.js'),
  'plano-cargas': () => import('./plano-cargas.js'),
  cargas: () => import('./cargas.js'),
  logistica: () => import('./logistica.js'),
  equipamentos: () => import('./equipamentos.js'),
  sap: () => import('./sap.js'),
  agt: () => import('./agt.js'),
  acessos: () => import('./acessos.js'),
};

/** Módulo de cada página do catálogo (paginas/catalogo.js). */
export const MODULO_DA_PAGINA = {
  inicio: 'inicio',
  'vendas-promotor': 'vendas',
  'vendas-dia': 'vendas',
  'vendas-cliente': 'vendas',
  'vendas-artigo': 'vendas',
  'vendas-marca': 'vendas',
  ranking: 'vendas',
  documentos: 'vendas',
  pagamentos: 'recebimentos',
  pendentes: 'recebimentos',
  clientes: 'clientes',
  cadastros: 'clientes',
  'cadastros-analise': 'clientes',
  'cadastros-clientes': 'clientes',
  'plano-visitas': 'terreno',
  auditoria: 'terreno',
  sincronizacao: 'sincronizacao',
  'plano-cargas': 'plano-cargas',
  'registo-cargas': 'cargas',
  'stock-carrinha': 'logistica',
  'stock-armazem': 'logistica',
  equipamentos: 'equipamentos',
  'equipamentos-documentos': 'equipamentos',
  'sap-exportar': 'sap',
  agt: 'agt',
  acessos: 'acessos',
};

const pedidos = new Map();

/** A página já tem a função de desenho registada? */
export const paginaPronta = (id) => typeof R[id] === 'function';

/**
 * Carrega o módulo da página (uma só vez; pedidos repetidos partilham o mesmo carregamento). Se falhar (ex.: sem
 * rede), o pedido seguinte tenta de novo.
 */
export function carregarPagina(id) {
  if (paginaPronta(id)) return Promise.resolve();
  const m = MODULO_DA_PAGINA[id];
  if (!m || !MODULOS[m]) return Promise.reject(new Error('Página desconhecida: ' + id));
  if (!pedidos.has(m))
    pedidos.set(
      m,
      MODULOS[m]().catch((e) => {
        pedidos.delete(m);
        throw e;
      }),
    );
  return pedidos.get(m);
}

/** Carrega várias páginas em paralelo (ex.: antes do relatório consolidado, que desenha todas). */
export const carregarPaginas = (ids) => Promise.all([...new Set(ids)].map(carregarPagina));
