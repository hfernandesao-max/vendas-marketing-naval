// Contadores do menu que vêm do servidor (o que pede acção no Terreno): rotas propostas pendentes de aprovação e
// ocorrências abertas hoje. Um pedido leve (v1/terreno/contagens) na entrada e de 5 em 5 minutos, só para quem vê
// alguma destas páginas; ao chegar, o menu volta a desenhar-se com os números.
import { apiJson } from './api.js';
import { SESSAO, podeVer } from './perfil.js';

/** {página: número} */
export const CONTAGENS = {};
const PAGINAS = ['rotas-propostas', 'ocorrencias'];
const INTERVALO = 5 * 60000;
let aLer = false;
let lidoEm = 0;

/** Pede os números quando já passaram 5 minutos (ou nunca se pediram); depois chama $depois. */
export function lerContagens(depois) {
  if (!SESSAO || aLer || Date.now() - lidoEm < INTERVALO || !PAGINAS.some(podeVer)) return;
  aLer = true;
  apiJson('terreno/contagens').then((d) => {
    aLer = false;
    lidoEm = Date.now();
    if (!d || !d.ok || !d.contagens) return;
    let mudou = false;
    PAGINAS.forEach((p) => {
      const n = +d.contagens[p] || 0;
      if (CONTAGENS[p] !== n) mudou = true;
      CONTAGENS[p] = n;
    });
    if (mudou && depois) depois();
  });
}

/** Depois de decidir uma proposta ou tratar uma ocorrência: o número certo sem esperar 5 minutos. */
export function actualizarContagem(pagina, n) {
  CONTAGENS[pagina] = Math.max(0, +n || 0);
}
