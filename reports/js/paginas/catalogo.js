// Catálogo das páginas do portal, por área de negócio, e nomes dos recursos da matriz de acessos.
import { t } from '../i18n/i18n.js';

export const PAGES = [
  { id: 'inicio', grupo: null, icon: 'home', nome: 'Início' },
  { id: 'vendas-promotor', grupo: 'Vendas', icon: 'chart', nome: 'Vendas por promotor' },
  { id: 'vendas-dia', grupo: 'Vendas', icon: 'route', nome: 'Vendas por dia' },
  { id: 'vendas-cliente', grupo: 'Vendas', icon: 'tag', nome: 'Vendas por cliente' },
  { id: 'vendas-artigo', grupo: 'Vendas', icon: 'box', nome: 'Vendas por artigo' },
  { id: 'vendas-marca', grupo: 'Vendas', icon: 'tag', nome: 'Vendas por promotor e marca' },
  { id: 'ranking', grupo: 'Vendas', icon: 'trophy', nome: 'Ranking' },
  { id: 'documentos', grupo: 'Vendas', icon: 'file', nome: 'Documentos' },
  { id: 'pagamentos', grupo: 'Recebimentos', icon: 'card', nome: 'Cobranças' },
  { id: 'pendentes', grupo: 'Recebimentos', icon: 'clock', nome: 'Facturas pendentes' },
  { id: 'sap-exportar', grupo: 'Recebimentos', icon: 'upload', nome: 'Ficheiro para o SAP' },
  { id: 'clientes', grupo: 'Clientes', icon: 'phone', nome: 'Carteira de clientes' },
  { id: 'cadastros', grupo: 'Clientes', icon: 'users', nome: 'Cadastros · visão geral' },
  { id: 'cadastros-analise', grupo: 'Clientes', icon: 'grow', nome: 'Análise de cadastros' },
  { id: 'cadastros-clientes', grupo: 'Clientes', icon: 'file', nome: 'Clientes registados' },
  { id: 'plano-visitas', grupo: 'Terreno', icon: 'scale', nome: 'Plano de visitas' },
  { id: 'auditoria', grupo: 'Terreno', icon: 'shield', nome: 'Histórico de auditoria' },
  { id: 'sincronizacao', grupo: 'Terreno', icon: 'phone', nome: 'Sincronização dos smartphones' },
  { id: 'plano-cargas', grupo: 'Logística', icon: 'clip', nome: 'Plano de cargas' },
  { id: 'registo-cargas', grupo: 'Logística', icon: 'box', nome: 'Registo de cargas' },
  { id: 'stock-carrinha', grupo: 'Logística', icon: 'truck', nome: 'Stock por promotor / carrinha' },
  { id: 'stock-armazem', grupo: 'Logística', icon: 'ware', nome: 'Stock por armazém' },
  { id: 'acessos', grupo: 'Administração', icon: 'lock', nome: 'Acessos' },
];

// Nomes das funções da matriz de acessos que não são páginas
export const NOMES_RECURSO = {
  'pagina-principal': 'Página principal e indicador das fontes',
  'excel-completo': 'Excel completo (todas as páginas)',
  'exportar-dados': 'Exportar dados (todos os dados de gestão em Excel)',
  'estrutura-dados': 'Dicionário técnico da base de dados (Excel)',
  'geo-rotas': 'GEO · optimização de rotas',
  'geo-rotas-importar': 'GEO · importar CSV de clientes',
  'geo-rotas-descarregar': 'GEO · descarregar rota optimizada',
  'geo-zonas': 'GEO · delimitação de zonas (ver)',
  'geo-zonas-gerir': 'GEO · desenhar, editar, importar e exportar zonas',
  'geo-distribuidores': 'GEO · gestão de distribuidores',
  nif: 'Ver o NIF dos clientes',
  acessos: 'Página Acessos',
  'admin-utilizadores': 'Utilizadores e perfis',
  'admin-config': 'Configuração e ligações',
  registos: 'Registos de acesso e exportações',
};
export const nomeRecurso = (id) => t(NOMES_RECURSO[id] || (PAGES.find((p) => p.id === id) || {}).nome || id);
