#!/bin/bash
#
# Verifica se o release do fork contem os binarios do LXC que o .plg espera.
# Uso: ./release-check.sh
#
set -euo pipefail

cd "$(dirname "$0")"

OWNER="${GITHUB_USER:-BlackMix}"
REPO="unraid-lxc-plugin"
API="https://api.github.com/repos/${OWNER}/${REPO}/releases/latest"

for cmd in curl python3; do
  command -v "$cmd" >/dev/null 2>&1 || { echo "ERRO: $cmd e necessario." >&2; exit 2; }
done

echo "Repo: ${OWNER}/${REPO}"

if ! JSON="$(curl -fsSL "$API" 2>/dev/null)"; then
  echo "Nenhum release encontrado em ${OWNER}/${REPO}."
  echo "Crie um release com a tag igual a versao do LXC (ex.: 6.0.0)."
  exit 1
fi

TAG="$(printf '%s' "$JSON" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("tag_name") or "")')"
ASSETS="$(printf '%s' "$JSON" | python3 -c 'import json,sys; print("\n".join(a.get("name","") for a in json.load(sys.stdin).get("assets",[])))')"

if [ -z "$TAG" ]; then
  echo "ERRO: nao foi possivel ler a tag do release."
  exit 1
fi

echo "Tag:  ${TAG}"
echo ""
echo "Assets esperados:"

MISSING=0
for idx in 1 2; do
  for ext in txz txz.md5; do
    name="LXC-${TAG}-${idx}.${ext}"
    if printf '%s\n' "$ASSETS" | grep -Fxq "$name"; then
      echo "  ok    $name"
    else
      echo "  FALTA $name"
      MISSING=1
    fi
  done
done

echo ""
if [ "$MISSING" -eq 0 ]; then
  echo "Release completo. Defina LXC_BINARY_SOURCE=fork no plugin.cfg antes de reinstalar."
  exit 0
fi

echo "Release incompleto. O .plg continuara usando o fallback (LXC_BINARY_SOURCE=auto)."
exit 1
