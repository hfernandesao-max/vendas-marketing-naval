// Perfil do utilizador (sessão) e permissões da matriz de acessos.
import { pendenteDe, vendasR } from './calculos.js';
import { PAGES } from './paginas/catalogo.js';
import { DB } from './util.js';

// SESSAO vem de data/sessao.php. Sem servidor PHP (teste local) fica null e tudo é visível;
// no servidor os dados são sempre filtrados pelos serviços PHP, seja o que for que o ecrã mostre.
export let SESSAO = null;
const nivelDe = (id) => (SESSAO ? (SESSAO.permissoes || {})[id] || '' : 'A');
export const podeVer = (id) => nivelDe(id) !== '';
export const podeExportar = (id) => ['E', 'Er', 'G', 'A'].includes(nivelDe(id));
export const paginasVisiveis = () => PAGES.filter((p) => podeVer(p.id));
export let atual = 'inicio';
export const paginaAtual = () => PAGES.find((x) => x.id === atual) || paginasVisiveis()[0] || PAGES[0];
export function contarAlertas() {
  if (!DB.vendas || !DB.clientes) return { pagamentos: 0, clientes: 0 };
  const V = vendasR();
  const np = V.filter((x) => pendenteDe(x) > 0).length;
  return { pagamentos: np, pendentes: np, clientes: DB.clientes.filter((x) => x.estado !== 'Ativo').length };
}

// Alteração do estado partilhado: só este módulo altera estas variáveis; os outros lêem-nas.
export function definirAtual(valor) {
  atual = valor;
}
export function definirSessao(valor) {
  SESSAO = valor;
}
