# Segurança — Portal RTM / GeoMobilidade Naval

Guia de segurança da plataforma (auditoria de 27-10-2026): o que a aplicação garante, como está configurada e o que
tem de ser feito no servidor. Modelo de referência: OWASP ASVS 4.0 (nível 2) e OWASP Top 10 (2021).

## 1. O que a aplicação garante

| Área                    | Medida                                                                                                                                                                                                                                                            |
| ----------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Configuração e segredos | Um só leitor do `.env` (`nucleo/config.php`); sem valores de ligação predefinidos no código; chaves obrigatórias validadas (`naval_exigir_config`), com resposta só com os **nomes** das chaves em falta. Chave do Google Maps no `.env` (`GOOGLE_MAPS_API_KEY`). |
| Ficheiros públicos      | `.htaccess` recusa `.env*`, `.git`, ficheiros começados por ponto, CSV/Excel/SQL/cópias/registos, documentação, configurações, `nucleo/`, `ferramentas/`, `tests/`, `docs/`, `node_modules/`. Verificado num Apache real: `tests/apache/verificar.sh`.            |
| Autenticação            | bcrypt (`password_hash`); bloqueio da conta à 3.ª falha (desbloqueio pelo SADM); limite por IP (20 falhas em 15 min, `LOGIN_MAX_FALHAS_IP`); resposta igual e em tempo igual para contas inexistentes; dois passos TOTP (RFC 6238) sem reutilização de códigos.   |
| Sessões                 | Cookie `HttpOnly`, `SameSite=Lax` e `Secure` em HTTPS; `use_strict_mode`, `use_only_cookies`; novo identificador e novo código CSRF na entrada; 30 min de inactividade e 10 h no máximo.                                                                          |
| CSRF                    | Código anti-falsificação em todos os pedidos de alteração, no formulário de entrada e na saída (`logout.php`).                                                                                                                                                    |
| Autorização             | Matriz de acessos por perfil e recurso, verificada no servidor em cada serviço (`naval_exigir`), âmbito por rota e NIF.                                                                                                                                           |
| Base de dados           | Só `SELECT` (guarda no código que recusa outras instruções) e consultas preparadas (PDO).                                                                                                                                                                         |
| Erros                   | Sem detalhes técnicos nas respostas (mensagens do PHP, SQL, endereços internos) excepto para SADM/TI ou com `APP_DEBUG=1`; os detalhes ficam no log do PHP.                                                                                                       |
| Pedidos                 | Datas validadas e período máximo de 400 dias; XML do Pinguim BO sem acesso à rede (`LIBXML_NONET`); TLS do Pinguim BO verificado por omissão.                                                                                                                     |
| Cabeçalhos              | `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, HSTS (em HTTPS), CSP (`frame-ancestors`, `base-uri`, `form-action`, `object-src` aplicadas; origens em modo de relatório até `CSP_APLICAR=1`), sem `X-Powered-By`.          |
| Front-end               | Portal sem `innerHTML` com dados; GEO com escape dos nomes de clientes, rotas e zonas; bibliotecas do GEO locais e com versão fixa (`geo/vendor/`).                                                                                                               |

## 2. Instruções para o servidor (Windows, Apache 2.4)

1. **Rodar credenciais expostas** (o repositório no GitHub é público e o histórico guarda-as):
   - palavra-passe da conta da exportação REST do Pinguim BO (esteve num `.env` versionado em 24-10-2026);
   - chaves do Google Maps (estiveram no código e em `geo/api.txt`): criar uma chave nova, restringida a
     _HTTP referrers_ = endereço do portal e só à _Maps JavaScript API_; apagar as antigas;
   - palavras-passe das contas da lista antiga (`geo/auth/config_users.php` tem os hashes no repositório público).
2. **`.env`**: acrescentar `PINGUIM_URL`, `DB_HOST`, `DB_NAME` e `GOOGLE_MAPS_API_KEY` (deixaram de ter valor por
   omissão no código). Permissões NTFS: leitura só para a conta do serviço do Apache e para os administradores.
3. **HTTPS**: activar `mod_ssl` com um certificado válido para `geo.naval.co.ao` e redireccionar HTTP → HTTPS (exemplo
   em `geo/apache_vhost.conf`). Com HTTPS, o cookie da sessão passa a `Secure` e é enviado o HSTS.
4. **Apache** (`httpd.conf`): `ServerTokens Prod`, `ServerSignature Off`, `TraceEnable Off`; `mod_headers` activo;
   `AllowOverride All` na pasta do site (para os `.htaccess`).
5. **PHP** (`php.ini` de produção): `display_errors=Off`, `log_errors=On`, `expose_php=Off`,
   `session.use_strict_mode=1`, `session.cookie_httponly=1`, `allow_url_include=Off`; PHP suportado (8.1 ou superior
   recomendado; 7.x já não recebe correcções de segurança).
6. **Base de dados**: utilizador próprio com `db_datareader` (e `VIEW DEFINITION` para o dicionário técnico); nunca `sa`.
7. **Pastas**: `dados-privados/` fora da pasta pública, escrita só pela conta do Apache; retirar da pasta pública os
   ficheiros de dados (`*.csv`) e cópias de segurança.
8. **Repositório**: tornar o repositório do GitHub **privado** (tem dados de clientes em `reports/data/*.json` e
   hashes de palavras-passe) e, depois de rodar as credenciais, limpar o histórico (`git filter-repo`) se a política
   da empresa o exigir.
9. Depois de uma semana sem violações da CSP na consola do navegador, `CSP_APLICAR=1` no `.env`.

## 3. Verificar

```bash
php tests/unidade/correr.php          # inclui CSRF/TOTP/limite por IP/período/configuração
npx playwright test --project=servidor  # tests/servidor/seguranca.spec.js
bash tests/apache/verificar.sh        # regras do Apache (Linux com apache2 + libapache2-mod-php)
```

No servidor, depois de publicar: `https://<portal>/.env`, `/.git/config`, `/README.md`, `/nucleo/config.php` e
`/geo/apache_vhost.conf` têm de responder **403**.
