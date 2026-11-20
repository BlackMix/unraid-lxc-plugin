#!/bin/bash
#
# Envia os commits locais para o GitHub usando o token lido de .env.
# O token nunca aparece na linha de comando, no .git/config nem nos logs.
#
# Uso:
#   ./git.sh              push do branch atual para origin
#   ./git.sh --dry-run    simula o push sem alterar o remoto
#
set -euo pipefail

cd "$(dirname "$0")"

ENV_FILE=".env"
DRY_RUN=0

case "${1:-}" in
  --dry-run|-n) DRY_RUN=1 ;;
  --help|-h)
    echo "Uso: $0 [--dry-run]"
    exit 0
    ;;
  "") ;;
  *)
    echo "Opção desconhecida: $1" >&2
    exit 2
    ;;
esac

fail() {
  echo "ERRO: $*" >&2
  exit 1
}

[ -f "$ENV_FILE" ] || fail "Arquivo $ENV_FILE não encontrado. Copie .env.example para .env."

# Nunca aceitar um .env que esteja versionado.
if git ls-files --error-unmatch "$ENV_FILE" >/dev/null 2>&1; then
  fail "$ENV_FILE está versionado! Remova-o do índice: git rm --cached $ENV_FILE"
fi

# Leitura controlada: não damos source no .env, para não executar comandos
# arbitrários escondidos nele.
TOKEN="$(sed -n 's/^[[:space:]]*GITHUB_TOKEN[[:space:]]*=[[:space:]]*//p' "$ENV_FILE" | tail -1)"
OWNER="$(sed -n 's/^[[:space:]]*GITHUB_USER[[:space:]]*=[[:space:]]*//p' "$ENV_FILE" | tail -1)"

for var in TOKEN OWNER; do
  value="${!var}"
  value="${value%\"}"; value="${value#\"}"
  value="${value%\'}"; value="${value#\'}"
  value="$(printf '%s' "$value" | tr -d '\r\n')"
  printf -v "$var" '%s' "$value"
done

[ -n "$TOKEN" ] || fail "GITHUB_TOKEN vazio em $ENV_FILE."
case "$TOKEN" in
  SEU_TOKEN_AQUI|YOUR_TOKEN_HERE|changeme|xxx)
    fail "Substitua o placeholder SEU_TOKEN_AQUI em $ENV_FILE pelo seu PAT do GitHub."
    ;;
esac

git rev-parse --is-inside-work-tree >/dev/null 2>&1 || fail "Esta pasta não é um repositório Git."

if [ -n "$(git status --porcelain)" ]; then
  echo "ERRO: existem alterações não commitadas. Faça o commit antes de enviar." >&2
  git status --short >&2
  exit 1
fi

BRANCH="$(git rev-parse --abbrev-ref HEAD)"
[ "$BRANCH" != "HEAD" ] || fail "Você está em HEAD desanexado."

if ! git remote get-url origin >/dev/null 2>&1; then
  OWNER="${OWNER:-BlackMix}"
  git remote add origin "https://github.com/${OWNER}/unraid-lxc-plugin.git"
fi

echo "Branch:  $BRANCH"
echo "Remoto:  $(git remote get-url origin)"
echo "Token:   configurado (não exibido)"
[ "$DRY_RUN" -eq 1 ] && echo "Modo:    simulação (--dry-run)"

# Credencial temporária: o helper é um comando git só desta invocação e lê o
# token de uma variável de ambiente, sem gravá-lo em arquivo algum.
export LXC_GIT_TOKEN="$TOKEN"
trap 'unset LXC_GIT_TOKEN' EXIT
CRED_HELPER='!f() { echo username=x-access-token; echo password=$LXC_GIT_TOKEN; }; f'

ARGS=()
[ "$DRY_RUN" -eq 1 ] && ARGS+=(--dry-run)

git -c credential.helper="$CRED_HELPER" push "${ARGS[@]}" origin "HEAD:refs/heads/$BRANCH"

if [ "$DRY_RUN" -eq 0 ]; then
  git branch --set-upstream-to="origin/$BRANCH" "$BRANCH" >/dev/null 2>&1 || true
  echo "Push concluído."
fi
