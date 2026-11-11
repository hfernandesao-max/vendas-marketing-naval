#!/usr/bin/env bash
# Prepara o apt dos servidores de teste do GitHub (Ubuntu) para instalar pacotes mesmo quando o espelho do Azure
# (azure.archive.ubuntu.com) não responde, como em 11-11-2026 («Temporary failure resolving»).
#
#   1. tenta o espelho por omissão do GitHub;
#   2. se falhar, passa para os espelhos oficiais do Ubuntu, um de cada vez, até um responder;
#   3. um índice do Ubuntu por descarregar conta como falha (por omissão o apt só avisa e depois falha a instalar).
#
# Uso: sudo -E bash .github/scripts/apt-preparar.sh [pacotes...]   (sem pacotes só deixa o apt pronto)
set -uo pipefail
export DEBIAN_FRONTEND=noninteractive

FONTES=/etc/apt/sources.list.d/ubuntu.sources
ESPELHOS=(
  "http://archive.ubuntu.com/ubuntu/"
  "http://mirrors.edge.kernel.org/ubuntu/"
  "http://mirror.math.princeton.edu/pub/ubuntu/"
)

# Falha se não actualizou ou se ficou por descarregar algum índice do Ubuntu (as outras fontes do servidor de testes,
# como a da Microsoft, não contam: não dependem do espelho e não devem fazer trocar de espelho).
actualizar() {
  local saida rc
  saida=$(timeout 180 apt-get update -q -o Acquire::Retries=3 -o Acquire::http::Timeout=20 2>&1)
  rc=$?
  echo "$saida" | tail -n 5
  [ "$rc" -eq 0 ] || return 1
  ! echo "$saida" | grep -qE '^(W|E): Failed to fetch [^ ]*(ubuntu\.com|apt-mirrors\.txt|mirrors\.edge\.kernel\.org|mirror\.math\.princeton\.edu)'
}

instalar() {
  [ "$#" -eq 0 ] && return 0
  timeout 300 apt-get install -y -q --no-install-recommends -o Acquire::Retries=3 "$@"
}

usar_espelho() {
  local url="$1"
  echo "::notice::apt: a usar o espelho $url"
  if [ -f "$FONTES" ]; then
    # Ubuntu 24.04 e seguintes: formato deb822; segurança também pelo espelho (tem os mesmos pacotes)
    sed -i -E "s#^URIs:.*#URIs: ${url}#" "$FONTES"
  fi
  if [ -f /etc/apt/sources.list ]; then
    sed -i -E "s#(mirror\+file:[^ ]+|https?://[^ ]*ubuntu\.com/ubuntu/?)#${url}#g" /etc/apt/sources.list
  fi
}

if actualizar && instalar "$@"; then
  exit 0
fi
echo "::warning::apt: o espelho por omissão falhou; a tentar os espelhos oficiais do Ubuntu"
for url in "${ESPELHOS[@]}"; do
  usar_espelho "$url"
  if actualizar && instalar "$@"; then
    exit 0
  fi
  sleep 5
done
echo "::error::apt: nenhum espelho do Ubuntu respondeu"
exit 1
