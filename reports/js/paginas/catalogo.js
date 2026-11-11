// Catálogo das páginas do portal, por secção e área de negócio, e nomes dos recursos da matriz de acessos.
import { t } from '../i18n/i18n.js';

// Organização do menu (ISO 9241-110, adequação à tarefa; padrão SAP Fiori / Microsoft Dynamics): secções pela cadeia
// de valor (Comercial → Operações → Análise) e, separadas em baixo, as funções de sistema. Cada página tem um ícone só
// seu (um ícone, um significado). Os Alertas não ficam no menu: estão no sino da barra superior (convenção
// internacional do centro de notificações).
export const SECCOES = ['Comercial', 'Operações', 'Análise', 'Sistema'];
export const SECCAO_GRUPO = {
  Vendas: 'Comercial',
  Clientes: 'Comercial',
  Cobranças: 'Comercial',
  Terreno: 'Operações',
  Logística: 'Operações',
  'Inteligência de negócio': 'Análise',
  Equipamentos: 'Sistema',
  Integrações: 'Sistema',
  Administração: 'Sistema',
};
export const PAGES = [
  { id: 'inicio', grupo: null, icon: 'home', nome: 'Início' },
  { id: 'alertas', grupo: null, icon: 'sino', nome: 'Alertas', menu: false },
  // Comercial
  { id: 'vendas-promotor', grupo: 'Vendas', icon: 'user', nome: 'Vendas por promotor' },
  { id: 'vendas-dia', grupo: 'Vendas', icon: 'cal', nome: 'Vendas por dia' },
  { id: 'vendas-cliente', grupo: 'Vendas', icon: 'loja', nome: 'Vendas por cliente' },
  { id: 'vendas-artigo', grupo: 'Vendas', icon: 'box', nome: 'Vendas por artigo' },
  { id: 'vendas-marca', grupo: 'Vendas', icon: 'tag', nome: 'Vendas por promotor e marca' },
  { id: 'ranking', grupo: 'Vendas', icon: 'trophy', nome: 'Classificação' },
  { id: 'documentos', grupo: 'Vendas', icon: 'file', nome: 'Documentos' },
  { id: 'clientes', grupo: 'Clientes', icon: 'lista', nome: 'Carteira de clientes' },
  { id: 'cadastros', grupo: 'Clientes', icon: 'kpi', nome: 'Cadastros · indicadores' },
  { id: 'cadastros-analise', grupo: 'Clientes', icon: 'pizza', nome: 'Análise de cadastros' },
  { id: 'cadastros-clientes', grupo: 'Clientes', icon: 'userplus', nome: 'Clientes registados' },
  { id: 'pedidos-clientes', grupo: 'Clientes', icon: 'carrinho', nome: 'Pedidos dos clientes' },
  { id: 'clientes-qr', grupo: 'Clientes', icon: 'qr', nome: 'Cartazes QR dos clientes' },
  { id: 'pagamentos', grupo: 'Cobranças', icon: 'card', nome: 'Cobranças' },
  { id: 'pendentes', grupo: 'Cobranças', icon: 'clock', nome: 'Facturas pendentes' },
  // Operações: trabalho de terreno (visitas, rotas e mapas) e logística das carrinhas
  { id: 'visitas-dia', grupo: 'Terreno', icon: 'check', nome: 'Visitas do dia' },
  { id: 'plano-visitas', grupo: 'Terreno', icon: 'agenda', nome: 'Plano de visitas' },
  { id: 'geo-terreno', grupo: 'Terreno', icon: 'pin', nome: 'Percurso do dia' },
  { id: 'geo-rotas', grupo: 'Terreno', icon: 'mapa', nome: 'Optimização de rotas' },
  { id: 'geo-prospeccao', grupo: 'Terreno', icon: 'radar', nome: 'Prospecção de clientes' },
  { id: 'geo-zonas', grupo: 'Terreno', icon: 'zona', nome: 'Zonas de entrega' },
  { id: 'geo-distribuidores', grupo: 'Terreno', icon: 'rede', nome: 'Distribuidores' },
  { id: 'plano-cargas', grupo: 'Logística', icon: 'clip', nome: 'Plano de cargas' },
  { id: 'painel-carrinha', grupo: 'Logística', icon: 'carrinhaok', nome: 'Painel da carrinha' },
  { id: 'conciliacao-carrinha', grupo: 'Logística', icon: 'scale', nome: 'Conciliação da carrinha' },
  { id: 'registo-cargas', grupo: 'Logística', icon: 'carga', nome: 'Registo de cargas' },
  { id: 'stock-carrinha', grupo: 'Logística', icon: 'truck', nome: 'Stock por promotor / carrinha' },
  { id: 'stock-armazem', grupo: 'Logística', icon: 'ware', nome: 'Stock por armazém' },
  // Análise: indicadores, previsão e inteligência artificial
  { id: 'previsao', grupo: 'Inteligência de negócio', icon: 'grow', nome: 'Previsão de vendas' },
  { id: 'assistente', grupo: 'Inteligência de negócio', icon: 'ia', nome: 'Assistente IA' },
  { id: 'painel-sala', grupo: 'Inteligência de negócio', icon: 'tv', nome: 'Painel da sala comercial' },
  { id: 'qualidade-dados', grupo: 'Inteligência de negócio', icon: 'alvo', nome: 'Qualidade dos dados e anomalias' },
  { id: 'valor-portal', grupo: 'Inteligência de negócio', icon: 'valor', nome: 'Valor do portal' },
  { id: 'apresentacao', grupo: 'Inteligência de negócio', icon: 'slides', nome: 'Apresentação do portal' },
  // Sistema: smartphones e impressoras, interfaces com outros sistemas e administração (segregação de funções)
  { id: 'equipamentos', grupo: 'Equipamentos', icon: 'phone', nome: 'Equipamentos' },
  { id: 'sincronizacao', grupo: 'Equipamentos', icon: 'sync', nome: 'Sincronização dos telemóveis' },
  { id: 'equipamentos-documentos', grupo: 'Equipamentos', icon: 'pasta', nome: 'Documentos dos equipamentos' },
  { id: 'sap-exportar', grupo: 'Integrações', icon: 'upload', nome: 'Vendas para o SAP' },
  { id: 'agt', grupo: 'Integrações', icon: 'shield', nome: 'Comunicação AGT' },
  { id: 'acessos', grupo: 'Administração', icon: 'lock', nome: 'Acessos' },
  { id: 'auditoria', grupo: 'Administração', icon: 'historico', nome: 'Histórico de auditoria' },
  { id: 'actualizacoes', grupo: 'Administração', icon: 'actualizar', nome: 'Actualizações' },
  { id: 'modulos', grupo: 'Administração', icon: 'pecas', nome: 'Módulos' },
  { id: 'conformidade', grupo: 'Administração', icon: 'selo', nome: 'Conformidade' },
  { id: 'suporte', grupo: 'Administração', icon: 'suporte', nome: 'Suporte' },
];
/** Secção de uma página (Comercial, Operações, Análise, Sistema) ou null (Início, Alertas). */
export const seccaoDe = (p) => (p && p.grupo ? SECCAO_GRUPO[p.grupo] || null : null);

// Nomes das funções da matriz de acessos que não são páginas
export const NOMES_RECURSO = {
  'pagina-principal': 'Página principal e indicador das fontes',
  'excel-completo': 'Excel completo (todas as páginas)',
  'exportar-dados': 'Exportar dados (todos os dados de gestão em Excel)',
  'estrutura-dados': 'Dicionário técnico da base de dados (Excel)',
  'geo-rotas': 'GEO · percursos optimizados automaticamente',
  'geo-rotas-descarregar': 'GEO · Excel do percurso optimizado',
  'geo-zonas': 'GEO · delimitação de zonas (ver)',
  'geo-zonas-gerir': 'GEO · desenhar, editar, importar e exportar zonas',
  'geo-distribuidores': 'GEO · gestão de distribuidores',
  'geo-prospeccao': 'GEO · prospecção: territórios das rotas e potenciais clientes',
  'geo-terreno': 'GEO · terreno do dia: percurso, prova de visita e tempos (localização do OLAP)',
  'fecho-dia': 'Fecho do dia por rota por correio electrónico (20:00)',
  'equipamentos-movimentar': 'Equipamentos · eventos, documentos e assinaturas',
  'equipamentos-administrar': 'Equipamentos · registar equipamentos, colaboradores e abate',
  'painel-gestao': 'Início: gráficos de gestão (tendência, comparação por rota, ABC, distribuição numérica, carteira)',
  'resumo-diario': 'Resumo diário por correio electrónico (07:00)',
  'app-gestao': 'App de gestão (telemóvel)',
  nif: 'Ver o NIF dos clientes',
  acessos: 'Página Acessos',
  actualizacoes: 'Actualizações do portal (aprovação; só o super administrador)',
  modulos: 'Módulos do portal (ligar, piloto, desligar; só o super administrador)',
  conformidade: 'Conformidade (prova dos controlos ISO; confirmar a revisão de acessos só o super administrador)',
  suporte: 'Suporte e incidentes (V: os seus pedidos; G: gerir todos)',
  'valor-portal': 'Valor do portal (V: ver o cálculo; G: alterar os parâmetros)',
  'admin-utilizadores': 'Utilizadores e perfis',
  'admin-config': 'Configuração e ligações',
  registos: 'Registos de acesso e exportações',
};
export const nomeRecurso = (id) => t(NOMES_RECURSO[id] || (PAGES.find((p) => p.id === id) || {}).nome || id);
