#!/bin/bash
# LXC v2 - Proxmox-compatible backup worker.
# Args: <container> <vmid> <job> <target_dir> <rootfs> <staging> <pct_conf> <compression> <threads>
NAME="$1"; VMID="$2"; JOB="$3"; TARGET="$4"; ROOTFS="$5"; STAGING="$6"; PCT="$7"; COMP="$8"; THREADS="$9"
STATUS_DIR="/usr/local/emhttp/plugins/lxc/status"
STATUS="$STATUS_DIR/lxc-proxmox-${JOB}.json"
WORKLOG="/tmp/lxc-proxmox-${JOB}.log"
STAMP=$(date -u +%Y_%m_%d-%H_%M_%S)
echo "worker started $(date) name=$NAME vmid=$VMID job=$JOB rootfs=$ROOTFS comp=$COMP threads=$THREADS" > "$WORKLOG"
BASE="vzdump-lxc-${VMID}-${STAMP}"
FINAL="${TARGET}/${BASE}.tar.${COMP}"
PART="${FINAL}.part"
TARPART="${TARGET}/${BASE}.tar"
LOG="${TARGET}/${BASE}.tar.log"

mkdir -p "$STATUS_DIR" "$TARGET"
status() {
  printf '{"stage":"%s","percent":%s,"started":%s,"total":0,"done":0,"updated":%s,"log":"%s"}\n' \
    "$1" "$2" "$(date +%s)" "$(date +%s)" "$WORKLOG" > "$STATUS"
  echo "status $1 $2 $(date)" >> "$WORKLOG"
}
fail() {
  local msg
  msg=$(printf '%s' "$1" | tr '"' "'" | tr '\n' ' ')
  printf '{"stage":"error","percent":100,"started":%s,"total":0,"done":0,"updated":%s,"error":"%s","log":"%s"}\n' \
    "$(date +%s)" "$(date +%s)" "$msg" "$WORKLOG" > "$STATUS"
  echo "FAIL $msg $(date)" >> "$WORKLOG"
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
echo "rootfs size: $(du -sh "$ROOTFS" 2>/dev/null)" >> "$WORKLOG"
# ONE tar command with two -C entries: pct.conf first (vzdump format),
# then the rootfs. No -r/append (the Unraid tar rejects it).
tar $TAROPTS --exclude=./snaps --exclude=./tmp/?* --exclude=./var/run/?*pid \
  -cf "$TARPART" -C "$STAGING" ./etc/vzdump/pct.conf -C "$ROOTFS" . \
  2> "$LOG" || fail "tar: $(grep -v 'Error is not recoverable' "$LOG" | head -5 | tr '\n' ' ')"
echo "tar done $(date), bytes=$(wc -c < "$TARPART" 2>/dev/null)" >> "$WORKLOG"

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

echo "backup complete $(date), file=$FINAL size=$(wc -c < "$FINAL")" >> "$WORKLOG"
printf '{"stage":"done","percent":100,"started":%s,"total":0,"done":0,"updated":%s,"file":"%s","log":"%s"}\n' \
  "$(date +%s)" "$(date +%s)" "$(basename "$FINAL")" "$WORKLOG" > "$STATUS"
