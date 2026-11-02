// Catálogo das páginas do portal, por área de negócio, e nomes dos recursos da matriz de acessos.
import { t } from '../i18n/i18n.js';

export const PAGES = [
  { id: 'inicio', grupo: null, icon: 'home', nome: 'Início' },
  // Gestão: análise e inteligência artificial (alertas, previsão, assistente, painel da sala comercial)
  { id: 'alertas', grupo: 'Gestão', icon: 'sino', nome: 'Alertas' },
  { id: 'previsao', grupo: 'Gestão', icon: 'grow', nome: 'Previsão de vendas' },
  { id: 'assistente', grupo: 'Gestão', icon: 'ia', nome: 'Assistente IA' },
  { id: 'painel-sala', grupo: 'Gestão', icon: 'tv', nome: 'Painel da sala comercial' },
  { id: 'vendas-promotor', grupo: 'Vendas', icon: 'chart', nome: 'Vendas por promotor' },
  { id: 'vendas-dia', grupo: 'Vendas', icon: 'route', nome: 'Vendas por dia' },
  { id: 'vendas-cliente', grupo: 'Vendas', icon: 'tag', nome: 'Vendas por cliente' },
  { id: 'vendas-artigo', grupo: 'Vendas', icon: 'box', nome: 'Vendas por artigo' },
  { id: 'vendas-marca', grupo: 'Vendas', icon: 'tag', nome: 'Vendas por promotor e marca' },
  { id: 'ranking', grupo: 'Vendas', icon: 'trophy', nome: 'Classificação' },
  { id: 'documentos', grupo: 'Vendas', icon: 'file', nome: 'Documentos' },
  { id: 'pagamentos', grupo: 'Recebimentos', icon: 'card', nome: 'Cobranças' },
  { id: 'pendentes', grupo: 'Recebimentos', icon: 'clock', nome: 'Facturas pendentes' },
  { id: 'clientes', grupo: 'Clientes', icon: 'phone', nome: 'Carteira de clientes' },
  { id: 'cadastros', grupo: 'Clientes', icon: 'users', nome: 'Cadastros · visão geral' },
  { id: 'cadastros-analise', grupo: 'Clientes', icon: 'grow', nome: 'Análise de cadastros' },
  { id: 'cadastros-clientes', grupo: 'Clientes', icon: 'file', nome: 'Clientes registados' },
  { id: 'pedidos-clientes', grupo: 'Clientes', icon: 'clip', nome: 'Pedidos dos clientes' },
  { id: 'clientes-qr', grupo: 'Clientes', icon: 'tag', nome: 'Cartazes QR dos clientes' },
  { id: 'plano-visitas', grupo: 'Terreno', icon: 'scale', nome: 'Plano de visitas' },
  { id: 'auditoria', grupo: 'Terreno', icon: 'shield', nome: 'Histórico de auditoria' },
  { id: 'sincronizacao', grupo: 'Terreno', icon: 'phone', nome: 'Sincronização dos telemóveis' },
  { id: 'plano-cargas', grupo: 'Logística', icon: 'clip', nome: 'Plano de cargas' },
  { id: 'registo-cargas', grupo: 'Logística', icon: 'box', nome: 'Registo de cargas' },
  { id: 'stock-carrinha', grupo: 'Logística', icon: 'truck', nome: 'Stock por promotor / carrinha' },
  { id: 'stock-armazem', grupo: 'Logística', icon: 'ware', nome: 'Stock por armazém' },
  // Smartphones e impressoras do terreno: ficha, eventos, documentos e assinatura no ecrã
  { id: 'equipamentos', grupo: 'Equipamentos', icon: 'phone', nome: 'Equipamentos' },
  { id: 'equipamentos-documentos', grupo: 'Equipamentos', icon: 'file', nome: 'Documentos dos equipamentos' },
  // Interfaces com outros sistemas (separadas da análise; segregação de funções e controlo de interfaces)
  { id: 'sap-exportar', grupo: 'Integrações', icon: 'upload', nome: 'Vendas para o SAP' },
  { id: 'agt', grupo: 'Integrações', icon: 'shield', nome: 'Comunicação AGT' },
  { id: 'acessos', grupo: 'Administração', icon: 'lock', nome: 'Acessos' },
  { id: 'actualizacoes', grupo: 'Administração', icon: 'upload', nome: 'Actualizações' },
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
  'equipamentos-movimentar': 'Equipamentos · eventos, documentos e assinaturas',
  'equipamentos-administrar': 'Equipamentos · registar equipamentos, colaboradores e abate',
  'painel-gestao': 'Início: gráficos de gestão (tendência, comparação por rota, ABC, distribuição numérica, carteira)',
  'resumo-diario': 'Resumo diário por correio electrónico (07:00)',
  'app-gestao': 'App de gestão (telemóvel)',
  nif: 'Ver o NIF dos clientes',
  acessos: 'Página Acessos',
  actualizacoes: 'Actualizações do portal (aprovação; só o super administrador)',
  'admin-utilizadores': 'Utilizadores e perfis',
  'admin-config': 'Configuração e ligações',
  registos: 'Registos de acesso e exportações',
};
export const nomeRecurso = (id) => t(NOMES_RECURSO[id] || (PAGES.find((p) => p.id === id) || {}).nome || id);
