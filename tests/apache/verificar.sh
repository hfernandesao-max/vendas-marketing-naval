#!/usr/bin/env bash
# Verifica as regras do Apache (.htaccess) num Apache 2.4 real com mod_php: ficheiros e pastas internos
# recusados (403), páginas e recursos públicos servidos (200) e cabeçalhos de segurança.
# Uso (Linux, com apache2 e libapache2-mod-php instalados):  bash tests/apache/verificar.sh [porta]
# Não toca na configuração do Apache do sistema: usa um ficheiro de configuração temporário.
set -u
RAIZ="$(cd "$(dirname "$0")/../.." && pwd)"
PORTA="${1:-8830}"
TMP="$(mktemp -d)"
MODS=/usr/lib/apache2/modules
PHPMOD="$(ls $MODS/libphp*.so 2>/dev/null | head -1)"
[ -n "$PHPMOD" ] || { echo "mod_php não encontrado em $MODS"; exit 2; }
# .env de teste (o mesmo dos testes do servidor), lido pelo PHP através de NAVAL_ENV
php "$RAIZ/tests/servidor/preparar.php" >/dev/null
chmod -R a+rX "$RAIZ" 2>/dev/null; chmod -R a+rwX "$RAIZ/tests/servidor/ambiente" 2>/dev/null
d="$RAIZ"; while [ "$d" != "/" ]; do chmod o+x "$d" 2>/dev/null; d="$(dirname "$d")"; done
# ficheiro de dados de teste na raiz (como os CSV de clientes do projecto original)
echo "codigo;nome" > "$RAIZ/teste-apache.csv"
trap 'rm -f "$RAIZ/teste-apache.csv"; [ -f "$TMP/httpd.pid" ] && kill "$(cat "$TMP/httpd.pid")" 2>/dev/null; rm -rf "$TMP"' EXIT

cat > "$TMP/httpd.conf" <<CONF
ServerRoot /etc/apache2
ServerName 127.0.0.1
Listen 127.0.0.1:$PORTA
PidFile $TMP/httpd.pid
ErrorLog $TMP/erro.log
LoadModule mpm_prefork_module $MODS/mod_mpm_prefork.so
LoadModule authz_core_module $MODS/mod_authz_core.so
LoadModule mime_module $MODS/mod_mime.so
LoadModule dir_module $MODS/mod_dir.so
LoadModule headers_module $MODS/mod_headers.so
LoadModule rewrite_module $MODS/mod_rewrite.so
LoadModule env_module $MODS/mod_env.so
LoadModule php_module $PHPMOD
TypesConfig /etc/mime.types
User www-data
Group www-data
DocumentRoot "$RAIZ"
SetEnv NAVAL_ENV "$RAIZ/tests/servidor/ambiente/.env"
<FilesMatch "\.php$">
    SetHandler application/x-httpd-php
</FilesMatch>
<Directory />
    AllowOverride None
    Require all denied
</Directory>
<Directory "$RAIZ">
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
CONF
apache2 -f "$TMP/httpd.conf" -t || exit 2
apache2 -f "$TMP/httpd.conf" -k start || { cat "$TMP/erro.log"; exit 2; }
sleep 1

FALHAS=0
URL="http://127.0.0.1:$PORTA"
estado() { curl -s -o /dev/null -w '%{http_code}' "$URL$1"; }
espera() {
  local e="$1"; shift
  for c in "$@"; do
    local r; r="$(estado "$c")"
    if [ "$r" = "$e" ]; then echo "  ok    $e $c"; else echo "  FALHA $c: esperado $e, obtido $r"; FALHAS=$((FALHAS+1)); fi
  done
}
echo "Recusados (403):"
espera 403 /.env /.env.exemplo /.gitignore /.htaccess /.git/config /.git/HEAD /.github/workflows/ci.yml \
  /README.md /CHANGELOG.md /composer.json /package.json /package-lock.json /phpstan.neon.dist /playwright.config.js /eslint.config.js \
  /teste-apache.csv /index.html /reports/index.html \
  /nucleo/config.php /nucleo/servicos/bd.php /ferramentas/utilizadores.php /tests/servidor/preparar.php /tests/servidor/ambiente/.env \
  /docs/ARQUITECTURA.md /node_modules/ /geo/apache_vhost.conf /geo/config.php /geo/auth/config_users.php \
  /reports/data/vendas.json /reports/data/rotas.json /reports/modelos/
echo "Servidos:"
espera 200 /geo/login.php /geo/vendor/lucide-1.62.0.min.js /geo/vendor/papaparse-5.3.0.min.js /reports/js/app.js /geo/style.css
espera 302 /index.php /reports/
echo "Cabeçalhos:"
H="$(curl -s -D - -o /dev/null "$URL/reports/js/app.js")"
for h in "X-Content-Type-Options: nosniff" "X-Frame-Options: SAMEORIGIN" "Referrer-Policy: strict-origin-when-cross-origin" "Permissions-Policy:"; do
  if echo "$H" | grep -qi "^$h"; then echo "  ok    $h (estático)"; else echo "  FALHA cabeçalho em falta no estático: $h"; FALHAS=$((FALHAS+1)); fi
done
H="$(curl -s -D - -o /dev/null "$URL/geo/login.php")"
for h in "Content-Security-Policy: frame-ancestors" "X-Content-Type-Options: nosniff" "Set-Cookie: PHPSESSID=.*HttpOnly"; do
  if echo "$H" | grep -qiE "^$h"; then echo "  ok    $h (PHP)"; else echo "  FALHA cabeçalho em falta no PHP: $h"; FALHAS=$((FALHAS+1)); fi
done
if echo "$H" | grep -qi "^X-Powered-By"; then echo "  FALHA X-Powered-By presente"; FALHAS=$((FALHAS+1)); else echo "  ok    sem X-Powered-By"; fi
echo
[ "$FALHAS" = 0 ] && echo "Apache: todas as verificações passaram." || { echo "Apache: $FALHAS falhas"; tail -20 "$TMP/erro.log"; }
exit $((FALHAS > 0))
