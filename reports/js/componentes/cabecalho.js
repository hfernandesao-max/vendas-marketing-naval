// Acções principais da página no cabeçalho, à direita do título (modelo único de página, como o Stripe, o Linear e o
// SAP Fiori: título e acções › filtros › indicadores › separadores › lista › detalhe). O cabeçalho limpa-se a cada
// desenho da página; cada página põe aqui só os botões que a pessoa pode usar.
export function accoesPagina(...nos) {
  const c = document.getElementById('titulo-acc');
  if (c) c.append(...nos.filter(Boolean));
}
export function limparAccoesPagina() {
  const c = document.getElementById('titulo-acc');
  if (c) c.replaceChildren();
}
