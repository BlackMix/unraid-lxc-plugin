#!/bin/bash
# LXC v2 - Proxmox-compatible backup worker.
# Args: <container> <vmid> <job> <target_dir> <rootfs> <staging> <pct_conf> <compression> <threads>
NAME="$1"; VMID="$2"; JOB="$3"; TARGET="$4"; ROOTFS="$5"; STAGING="$6"; PCT="$7"; COMP="$8"; THREADS="$9"
STATUS_DIR="/usr/local/emhttp/plugins/lxc/status"
STATUS="$STATUS_DIR/lxc-proxmox-${JOB}.json"
STAMP=$(date -u +%Y_%m_%d-%H_%M_%S)
BASE="vzdump-lxc-${VMID}-${STAMP}"
FINAL="${TARGET}/${BASE}.tar.${COMP}"
PART="${FINAL}.part"
TARPART="${TARGET}/${BASE}.tar"
LOG="${TARGET}/${BASE}.tar.log"

mkdir -p "$STATUS_DIR" "$TARGET"
status() {
  printf '{"stage":"%s","percent":%s,"started":%s,"total":0,"done":0,"updated":%s%s}\n' \
    "$1" "$2" "$(date +%s)" "$(date +%s)" "${4:+,${4}}" > "$STATUS"
}
fail() {
  local msg
  msg=$(printf '%s' "$1" | tr '"' "'")
  printf '{"stage":"error","percent":100,"started":%s,"total":0,"done":0,"updated":%s,"error":"%s"}\n' \
    "$(date +%s)" "$(date +%s)" "$msg" > "$STATUS"
  rm -f "$PART" "$TARPART"
  exit 1
}

status preparing 0
BASEARGS=""
command -v tar >/dev/null 2>&1 || fail "tar not found"
TAROPTS="--one-file-system"
if tar --numeric-owner --version >/dev/null 2>&1; then TAROPTS="$TAROPTS --numeric-owner"; fi
if tar --xattrs --version >/dev/null 2>&1; then TAROPTS="$TAROPTS --xattrs"; fi
if tar --acls --version >/dev/null 2>&1; then TAROPTS="$TAROPTS --acls"; fi

status copying 5
# pct.conf first (vzdump expects ./etc/vzdump/pct.conf at the beginning)
tar -C "$STAGING" $TAROPTS -cf "$TARPART" ./etc/vzdump/pct.conf 2> "$LOG" || fail "tar pct.conf: $(tail -3 "$LOG" | tr '\n' ' ')"
cd "$ROOTFS" || fail "cannot cd rootfs"
tar $TAROPTS --exclude=./snaps --exclude=./tmp/?* --exclude=./var/run/?*pid -rf "$TARPART" . 2>> "$LOG" || fail "tar rootfs: $(tail -3 "$LOG" | tr '\n' ' ')"

status compressing 90
if [ "$COMP" = "zst" ]; then
  zstd -q -T"$THREADS" -o "$PART" "$TARPART" >> "$LOG" 2>&1 || fail "zstd: $(tail -3 "$LOG" | tr '\n' ' ')"
else
  LEVEL=$(printf '%s' "$THREADS" | tr -cd '0-9'); [ -z "$LEVEL" ] && LEVEL=6
  [ "$LEVEL" -lt 1 ] && LEVEL=1; [ "$LEVEL" -gt 9 ] && LEVEL=9
  gzip -"$LEVEL" -c "$TARPART" > "$PART" || fail "gzip failed"
fi
rm -f "$TARPART" "$LOG"
[ -s "$PART" ] || fail "compressed file is empty"
mv "$PART" "$FINAL" || fail "cannot install final backup"

printf '{"stage":"done","percent":100,"started":%s,"total":0,"done":0,"updated":%s,"file":"%s"}\n' \
  "$(date +%s)" "$(date +%s)" "$(basename "$FINAL")" > "$STATUS"
