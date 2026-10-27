# Segurança — Portal RTM / GeoMobilidade Naval

Guia de segurança da plataforma (auditoria e segunda validação de 27-10-2026): o que a aplicação garante, como está
configurada, como é verificada automaticamente e o que tem de ser feito no servidor. Normas de referência: OWASP ASVS
4.0.3 (nível 2), OWASP Top 10 (2021), NIST SP 800-63B (palavras-passe e autenticação) e NIST SSDF SP 800-218
(desenvolvimento seguro).

## 1. O que a aplicação garante

| Área                    | Medida                                                                                                                                                                                                                                                                                                                                                                |
| ----------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Configuração e segredos | Um só leitor do `.env` (`nucleo/config.php`); sem valores de ligação predefinidos no código; chaves obrigatórias validadas (`naval_exigir_config`), com resposta só com os **nomes** das chaves em falta. Chave do Google Maps no `.env` (`GOOGLE_MAPS_API_KEY`).                                                                                                     |
| Ficheiros públicos      | `.htaccess` recusa `.env*`, `.git`, ficheiros começados por ponto, CSV/Excel/SQL/cópias/registos, documentação, configurações, `nucleo/`, `ferramentas/`, `tests/`, `docs/`, `node_modules/`. Verificado num Apache real: `tests/apache/verificar.sh`.                                                                                                                |
| Autenticação            | bcrypt (`password_hash`); bloqueio da conta à 3.ª falha (desbloqueio pelo SADM); limite por IP (20 falhas em 15 min, `LOGIN_MAX_FALHAS_IP`); resposta igual e em tempo igual para contas inexistentes; dois passos TOTP (RFC 6238) sem reutilização de códigos, **obrigatórios** nos perfis marcados na matriz (a pessoa configura-os na entrada, `MFA_OBRIGATORIA`). |
| Palavras-passe          | NIST SP 800-63B / ASVS 2.1: mínimo 12 caracteres, frases aceites, sem regras de composição; recusa palavras-passe comuns ou divulgadas (`nucleo/seguranca/senhas-comuns.txt`) e o nome da conta, do email ou da empresa; temporárias expiram em 24 h; cada pessoa muda a sua em `geo/senha.php` (pede a actual).                                                      |
| Sessões                 | Cookie próprio (`NAVALSESS`; `__Host-NAVALSESS` em HTTPS) `HttpOnly`, `SameSite=Lax` e `Secure` em HTTPS; `use_strict_mode`, `use_only_cookies`; novo identificador e novo código CSRF na entrada; 30 min de inactividade e 10 h no máximo; mudar ou repor a palavra-passe ou os dois passos termina as outras sessões da conta.                                      |
| Concorrência            | Leituras e gravações das contas, bloqueios e limites em exclusivo (`naval_exclusivo`, flock), sem alterações perdidas entre pedidos simultâneos. Lista de contas ilegível → ninguém entra (falha segura).                                                                                                                                                             |
| CSRF                    | Código anti-falsificação em todos os pedidos de alteração (incluindo o registo das exportações), no formulário de entrada e na saída (`logout.php`, com um código próprio que não revela o principal).                                                                                                                                                                |
| Autorização             | Matriz de acessos por perfil e recurso, verificada no servidor em cada serviço (`naval_exigir`), âmbito por rota e NIF.                                                                                                                                                                                                                                               |
| Base de dados           | Só `SELECT` (guarda no código que recusa outras instruções) e consultas preparadas (PDO); ligação cifrada ao SQL Server (`DB_ENCRYPT=1`; `DB_CONFIAR_CERTIFICADO=0` com certificado válido).                                                                                                                                                                          |
| Dados privados          | Pasta privada com `.htaccess` «Require all denied» criado automaticamente (protecção mesmo que fique na pasta pública); diagnóstico do SADM indica se está dentro da pasta pública. Registos sem o texto escrito em contas inexistentes.                                                                                                                              |
| Erros                   | Sem detalhes técnicos nas respostas (mensagens do PHP, SQL, endereços internos) excepto para SADM/TI ou com `APP_DEBUG=1`; os detalhes ficam no log do PHP.                                                                                                                                                                                                           |
| Pedidos                 | Datas validadas e período máximo de 400 dias; XML do Pinguim BO sem acesso à rede (`LIBXML_NONET`); TLS do Pinguim BO verificado por omissão.                                                                                                                                                                                                                         |
| Cabeçalhos              | `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, HSTS (em HTTPS), CSP (`frame-ancestors`, `base-uri`, `form-action`, `object-src` aplicadas; origens em modo de relatório até `CSP_APLICAR=1`), sem `X-Powered-By`.                                                                                                              |
| Front-end               | Portal sem `innerHTML` com dados; GEO com escape dos nomes de clientes, rotas e zonas e CSV exportados sem fórmulas (CSV injection); bibliotecas do GEO locais e com versão fixa (`geo/vendor/`).                                                                                                                                                                     |
| Email                   | Destinatários limitados aos domínios autorizados (`EMAIL_DOMINIOS_PERMITIDOS`), recusas registadas.                                                                                                                                                                                                                                                                   |

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
10. Depois do HTTPS: `EXIGIR_HTTPS=1`. Atrás de um proxy inverso: `PROXY_CONFIAVEIS` com o IP do proxy.
11. Antes de publicar: as contas SADM, TI e DIR (perfis com dois passos obrigatórios) passam a configurar os dois passos
    na entrada seguinte — avisar as pessoas para terem uma aplicação de autenticação no telemóvel.

## 5. Riscos aceites ou por decidir

- Bloqueio permanente à 3.ª falha (decisão da gestão): quem souber o nome ou o email de uma conta pode bloqueá-la; o
  limite por IP reduz o risco. Alternativa ASVS: bloqueio temporário progressivo.
- Aprovações duplas dos SADM registadas por nome (não por duas contas autenticadas).
- Ficheiros Excel do SAP e do plano de cargas gerados no navegador e aceites pelo servidor sem comparar o conteúdo.
- CSP com `'unsafe-inline'` (GEO com scripts na página).
- Telefones e matrículas dos motoristas visíveis a quem vê o plano de cargas.

## 3. Verificação automática (DevSecOps)

| Quando                                 | O quê                                                                                                                                                                          |
| -------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Cada envio e pedido de integração      | `Testes` (sintaxe PHP 7.1–8.3, unitários, Playwright, PHPStan, regras do Apache) e `Segurança` (gitleaks, npm audit, OSV-Scanner, Semgrep OWASP, CodeQL, OWASP ZAP passivo).   |
| Todas as segundas-feiras (06:00–06:45) | As mesmas verificações sobre o código e as bases de vulnerabilidades actualizadas; se alguma falhar, abre um _issue_ «Segurança».                                              |
| Todas as segundas-feiras (Dependabot)  | Pedidos de integração com as versões corrigidas das dependências (npm, GitHub Actions, Composer). Correcções e versões menores integradas sozinhas quando tudo passa.          |
| Sempre (GitHub)                        | _Secret scanning_ e _push protection_: recusa envios com chaves ou palavras-passe conhecidas.                                                                                  |
| Todas as semanas (tarefa agendada)     | Revisão de segurança assistida: corre as verificações, corrige o que for seguro num ramo próprio e abre um pedido de integração para aprovação humana (nunca altera o `main`). |

Resultados: separador **Security** do repositório (_Code scanning_, _Dependabot_, _Secret scanning_) e **Actions**.
Regra: nenhuma correcção automática de código entra no `main` sem passar os testes; as de código passam por revisão.

## 4. Verificar à mão

```bash
php tests/unidade/correr.php          # inclui CSRF/TOTP/limite por IP/período/configuração
npx playwright test --project=servidor  # tests/servidor/seguranca.spec.js
bash tests/apache/verificar.sh        # regras do Apache (Linux com apache2 + libapache2-mod-php)
```

No servidor, depois de publicar: `https://<portal>/.env`, `/.git/config`, `/README.md`, `/nucleo/config.php` e
`/geo/apache_vhost.conf` têm de responder **403**.
