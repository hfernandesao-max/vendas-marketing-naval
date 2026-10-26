# Como contribuir

1. `npm ci` e, uma vez, `npx playwright install chromium`.
2. Antes de cada alteração, leia [docs/ARQUITECTURA.md](docs/ARQUITECTURA.md): onde fica cada coisa e as regras das camadas.
3. Escreva em português de Portugal (grafia antiga, como no resto do portal); datas dd-mm-aaaa; cores azuis Naval.
4. Código novo:
   - front-end em `reports/js/` (um módulo por responsabilidade, sem compilação); textos visíveis passam por `t()` e têm tradução em `i18n/en.js`;
   - pedidos ao servidor com `postJson` (`servidor.js`); bibliotecas externas em `reports/vendor/`, carregadas com `carregarScript`;
   - PHP compatível com 7.1; regras de negócio em `nucleo/servicos/` (sem HTTP, erros com `NavalErro`), controladores finos em `reports/data/` que começam por `require nucleo/api.php`; configuração só com `naval_config()`; autorização sempre no servidor (`naval_exigir`, `naval_pode`);
   - cada regra nova do back-end leva um teste em `tests/unidade/` e, se mudar um serviço JSON, em `tests/servidor/`.
5. Antes de publicar: `npm run lint`, `npm run format:check`, `npm run test:unidade` e `npm test` têm de passar; com o composer, também `composer analisar` (o GitHub verifica tudo, incluindo o PHP 7.1).
6. Mensagens de commit curtas, no presente, a dizer o que muda para quem usa o portal.
