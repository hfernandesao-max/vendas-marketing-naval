// Trabalho por gravar (prática de protecção de formulários «dirty form» e gravação automática de rascunhos):
//   - enquanto há dados escritos e ainda não gravados, a actualização automática do portal não redesenha a página
//     (os números que se estão a escrever não se perdem) e a app não recarrega com uma versão nova;
//   - ao fechar ou recarregar o separador, o navegador pede confirmação;
//   - cada formulário guarda o rascunho neste navegador (até gravar), e repõe-no se a página voltar a abrir.
const porGravar = new Set();

export const haPorGravar = () => porGravar.size > 0;
/** Ao desenhar uma página, os formulários voltam a dizer se têm trabalho por gravar (os de outras páginas saem). */
export const limparPorGravar = () => porGravar.clear();
export function marcarPorGravar(chave, sim) {
  if (sim) porGravar.add(chave);
  else porGravar.delete(chave);
}

const PREFIXO = 'vm-rascunho-';
/** Rascunho guardado (ou null). O armazenamento pode não existir (janela privada): o formulário funciona sem ele. */
export function lerRascunho(chave) {
  try {
    const x = JSON.parse(localStorage.getItem(PREFIXO + chave) || 'null');
    return x && x.v ? x : null;
  } catch (e) {
    return null;
  }
}
export function guardarRascunho(chave, valores) {
  const vazio = !Object.values(valores).some((v) => v !== '' && v != null);
  marcarPorGravar(chave, !vazio);
  try {
    if (vazio) localStorage.removeItem(PREFIXO + chave);
    else localStorage.setItem(PREFIXO + chave, JSON.stringify({ v: valores, em: Date.now() }));
  } catch (e) {
    /* sem armazenamento: fica só na memória da página */
  }
}
export function apagarRascunho(chave) {
  marcarPorGravar(chave, false);
  try {
    localStorage.removeItem(PREFIXO + chave);
  } catch (e) {
    /* nada a apagar */
  }
}
/** Rascunhos com mais de 7 dias deixam de interessar: apagam-se ao abrir o portal. */
function limparAntigos() {
  try {
    Object.keys(localStorage)
      .filter((k) => k.startsWith(PREFIXO))
      .forEach((k) => {
        const x = JSON.parse(localStorage.getItem(k) || 'null');
        if (!x || !x.em || Date.now() - x.em > 7 * 86400e3) localStorage.removeItem(k);
      });
  } catch (e) {
    /* sem armazenamento */
  }
}
limparAntigos();

window.addEventListener('beforeunload', (e) => {
  if (!haPorGravar()) return;
  e.preventDefault();
  e.returnValue = '';
});
