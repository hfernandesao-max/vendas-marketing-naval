# Como contribuir

1. `npm ci` e, uma vez, `npx playwright install chromium`.
2. Antes de cada alteração, leia [docs/ARQUITECTURA.md](docs/ARQUITECTURA.md): onde fica cada coisa e as regras das camadas.
3. Escreva em português de Portugal (grafia antiga, como no resto do portal); datas dd-mm-aaaa; cores azuis Naval.
4. Código novo:
   - front-end em `reports/js/` (um módulo por responsabilidade, sem compilação); textos visíveis passam por `t()` e têm tradução em `i18n/en.js`;
   - pedidos ao servidor com `postJson` (`servidor.js`); bibliotecas externas em `reports/vendor/`, carregadas com `carregarScript`;
   - PHP compatível com 7.1; autorização sempre no servidor (`naval_exigir`, `naval_pode`).
5. Antes de publicar: `npm run lint`, `npm run format:check` e `npm test` têm de passar (o GitHub verifica o mesmo).
6. Mensagens de commit curtas, no presente, a dizer o que muda para quem usa o portal.
