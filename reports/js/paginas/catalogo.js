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
  Finanças: 'Comercial',
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
  { id: 'vendas-promotor-artigo', grupo: 'Vendas', icon: 'grelha', nome: 'Vendas por promotor e artigo' },
  // fora do menu até o Pinguim BO trazer a marca dos artigos (a página explica o que falta)
  { id: 'vendas-marca', grupo: 'Vendas', icon: 'tag', nome: 'Vendas por promotor e marca', menu: false },
  // a Classificação entrou em «Vendas por promotor» e «Vendas por cliente» (posição, quota, classe ABC)
  { id: 'ranking', grupo: 'Vendas', icon: 'trophy', nome: 'Classificação', menu: false },
  { id: 'documentos', grupo: 'Vendas', icon: 'file', nome: 'Documentos de venda' },
  { id: 'relatorio-vendas', grupo: 'Vendas', icon: 'relatorio', nome: 'Consolidado Diário de Vendas' },
  { id: 'clientes', grupo: 'Clientes', icon: 'lista', nome: 'Carteira de clientes' },
  { id: 'cadastros', grupo: 'Clientes', icon: 'kpi', nome: 'Cadastros · indicadores' },
  { id: 'cadastros-analise', grupo: 'Clientes', icon: 'pizza', nome: 'Análise de cadastros' },
  { id: 'cadastros-clientes', grupo: 'Clientes', icon: 'userplus', nome: 'Clientes registados' },
  { id: 'pedidos-clientes', grupo: 'Clientes', icon: 'carrinho', nome: 'Pedidos dos clientes' },
  { id: 'clientes-qr', grupo: 'Clientes', icon: 'qr', nome: 'Cartazes QR dos clientes' },
  { id: 'pagamentos', grupo: 'Finanças', icon: 'card', nome: 'Cobranças' },
  { id: 'pendentes', grupo: 'Finanças', icon: 'clock', nome: 'Contas a receber (antiguidade)' },
  { id: 'caixa-marketing', grupo: 'Finanças', icon: 'caixa', nome: 'Fecho de caixa' },
  { id: 'multicaixa-tpa', grupo: 'Finanças', icon: 'tpa', nome: 'Multicaixa por TPA' },
  // Operações: trabalho de terreno e logística das carrinhas. O Terreno divide-se em subgrupos pela tarefa
  // (ISO 9241-110): o dia (o que se usa todas as manhãs), o planeamento, o território e clientes, e a análise.
  // Os subgrupos são só rótulos dentro da área (sem um terceiro nível que abre e fecha).
  { id: 'visitas-dia', grupo: 'Terreno', sub: 'Operação do dia', icon: 'check', nome: 'Visitas do dia' },
  { id: 'geo-terreno', grupo: 'Terreno', sub: 'Operação do dia', icon: 'pin', nome: 'Percurso do dia' },
  { id: 'ocorrencias', grupo: 'Terreno', sub: 'Operação do dia', icon: 'aviso', nome: 'Ocorrências' },
  { id: 'plano-visitas', grupo: 'Terreno', sub: 'Planeamento', icon: 'agenda', nome: 'Plano de visitas' },
  { id: 'geo-rotas', grupo: 'Terreno', sub: 'Planeamento', icon: 'mapa', nome: 'Optimização de rotas' },
  { id: 'rotas-propostas', grupo: 'Terreno', sub: 'Planeamento', icon: 'route', nome: 'Rotas propostas' },
  { id: 'geo-prospeccao', grupo: 'Terreno', sub: 'Território e clientes', icon: 'radar', nome: 'Prospecção de clientes' },
  { id: 'geo-zonas', grupo: 'Terreno', sub: 'Território e clientes', icon: 'zona', nome: 'Zonas de entrega' },
  { id: 'geo-distribuidores', grupo: 'Terreno', sub: 'Território e clientes', icon: 'rede', nome: 'Distribuidores' },
  { id: 'conversao-visitas', grupo: 'Terreno', sub: 'Análise', icon: 'funil', nome: 'Conversão das visitas' },
  // Logística pela ordem do processo: plano → requisição → carga → stock → conciliação
  { id: 'plano-cargas', grupo: 'Logística', icon: 'clip', nome: 'Cargas planeadas' },
  { id: 'requisicoes-carrinha', grupo: 'Logística', icon: 'requisicao', nome: 'Requisições ao armazém' },
  { id: 'cargas-po', grupo: 'Logística', icon: 'conferir', nome: 'Cargas (PO)' },
  { id: 'registo-cargas', grupo: 'Logística', icon: 'carga', nome: 'Cargas realizadas' },
  { id: 'stock-carrinha', grupo: 'Logística', icon: 'truck', nome: 'Stock por promotor / carrinha' },
  { id: 'stock-armazem', grupo: 'Logística', icon: 'ware', nome: 'Stock por armazém' },
  { id: 'conciliacao-carrinha', grupo: 'Logística', icon: 'scale', nome: 'Conciliação da carrinha' },
  { id: 'painel-carrinha', grupo: 'Logística', icon: 'carrinhaok', nome: 'Painel da carrinha' },
  // Análise: indicadores, previsão e inteligência artificial
  { id: 'relatorios', grupo: 'Inteligência de negócio', icon: 'explorar', nome: 'Criar relatório' },
  { id: 'previsao', grupo: 'Inteligência de negócio', icon: 'grow', nome: 'Previsão de vendas' },
  { id: 'assistente', grupo: 'Inteligência de negócio', icon: 'ia', nome: 'Assistente IA' },
  { id: 'painel-sala', grupo: 'Inteligência de negócio', icon: 'tv', nome: 'Painel da sala comercial' },
  { id: 'qualidade-dados', grupo: 'Inteligência de negócio', icon: 'alvo', nome: 'Qualidade dos dados e anomalias' },
  { id: 'perdas', grupo: 'Inteligência de negócio', icon: 'perdas', nome: 'Perdas e tempo parado' },
  // antes «Histórico de auditoria» em Administração: são controlos às vendas (duplicados, pendentes, cobranças sem
  // meio, visitas não feitas); o histórico de quem fez o quê está em Acessos › Registos de actividade
  { id: 'auditoria', grupo: 'Inteligência de negócio', icon: 'historico', nome: 'Controlo interno das vendas' },
  // Sistema: smartphones e impressoras, interfaces com outros sistemas e administração (segregação de funções)
  { id: 'equipamentos', grupo: 'Equipamentos', icon: 'phone', nome: 'Equipamentos' },
  { id: 'equipamentos-documentos', grupo: 'Equipamentos', icon: 'pasta', nome: 'Documentos dos equipamentos' },
  { id: 'sap-exportar', grupo: 'Integrações', icon: 'upload', nome: 'Vendas para o SAP' },
  { id: 'agt', grupo: 'Integrações', icon: 'shield', nome: 'Comunicação AGT' },
  { id: 'sincronizacao', grupo: 'Integrações', icon: 'sync', nome: 'Sincronização dos telemóveis' },
  { id: 'acessos', grupo: 'Administração', icon: 'lock', nome: 'Acessos' },
  { id: 'actualizacoes', grupo: 'Administração', icon: 'actualizar', nome: 'Actualizações' },
  { id: 'modulos', grupo: 'Administração', icon: 'pecas', nome: 'Módulos' },
  { id: 'conformidade', grupo: 'Administração', icon: 'selo', nome: 'Conformidade' },
  { id: 'suporte', grupo: 'Administração', icon: 'suporte', nome: 'Suporte' },
  { id: 'calendario', grupo: 'Administração', icon: 'calendario', nome: 'Calendário de trabalho' },
  // sobre o próprio portal (não são análise do negócio)
  { id: 'valor-portal', grupo: 'Administração', icon: 'valor', nome: 'Valor do portal' },
  { id: 'apresentacao', grupo: 'Administração', icon: 'slides', nome: 'Apresentação do portal' },
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
  'rotas-propostas': 'GEO · rotas propostas: aprovar uma rota nova (G) e atribuir-lhe os clientes da zona',
  ocorrencias: 'GEO · ocorrências do terreno: ver e tratar (G; Vr: as das suas rotas)',
  'geo-terreno': 'GEO · terreno do dia: percurso, prova de visita e tempos (localização do OLAP)',
  'fecho-dia': 'Fecho do dia por rota por correio electrónico (20:00)',
  'conciliacao-coordenacao': 'Conciliação da carrinha · aprovação do coordenador (1.º nível)',
  'cargas-po': 'Cargas (PO) · conferência no cais, validação e ordens ao Pinguim BO (ver)',
  'requisicoes-aprovacao': 'Requisições (PO) · aprovar a PO para a carga no cais (G: o coordenador, pela função «Aprovar as PO para a carga»)',
  'validacao-carga': 'Cargas (PO) · validar a carga conferida e tratar as ordens da Guia de Remessa ao Pinguim BO (G)',
  'conciliacao-fecho': 'Conciliação da carrinha · aprovação final do responsável (2.º nível, fecha)',
  'caixa-aprovacao': 'Fecho de caixa · aprovação do superior de quem o lança (1.º nível)',
  'caixa-aprovacao-final': 'Fecho de caixa · aprovação final do responsável máximo (2.º nível)',
  'equipamentos-movimentar': 'Equipamentos · eventos, documentos e assinaturas',
  'equipamentos-administrar': 'Equipamentos · registar equipamentos, colaboradores e abate',
  'painel-gestao': 'Início: gráficos de gestão (tendência, comparação por rota, ABC, distribuição numérica, carteira)',
  'resumo-diario': 'Resumo diário por correio electrónico (07:00)',
  'caixa-entrega': 'Entrega do dinheiro pelo vendedor (app Naval Operações): notas e moedas que entrega à tesouraria (G: o vendedor, nas suas rotas)',
  perdas: 'Perdas e tempo parado por rota (Vr: só as suas rotas)',
  calendario: 'Calendário de trabalho (V: ver; G: definir folgas da empresa e dias de trabalho excepcionais)',
  'pendentes-escalamento': 'Pendentes atrasados: recebe o escalamento (10:00 e 16:30), com quem os tem para fazer',
  'app-gestao': 'App de gestão (telemóvel)',
  nif: 'Ver o NIF dos clientes',
  acessos: 'Página Acessos',
  actualizacoes: 'Actualizações do portal (aprovação; só o super administrador)',
  modulos: 'Módulos do portal (ligar, piloto, desligar; só o super administrador)',
  conformidade: 'Conformidade (prova dos controlos ISO; confirmar a revisão de acessos só o super administrador)',
  suporte: 'Suporte e incidentes (V: os seus pedidos; G: gerir todos)',
  'valor-portal': 'Valor do portal (V: ver o cálculo; G: alterar os parâmetros)',
  relatorios: 'Criar relatório (V: criar e guardar os seus; G: também partilhar com todos)',
  'relatorios-certificar': 'Relatórios · certificar (selo «certificado»; outra pessoa que não o autor)',
  'modo-apresentacao': 'Modo apresentação (projector ou LCD: app no ecrã do computador; sessão até 60 min sem actividade)',
  'admin-utilizadores': 'Utilizadores e perfis',
  'admin-config': 'Configuração e ligações',
  registos: 'Registos de acesso e exportações',
};
export const nomeRecurso = (id) => t(NOMES_RECURSO[id] || (PAGES.find((p) => p.id === id) || {}).nome || id);
