#!/bin/bash
# LXC v2 - Proxmox-compatible backup worker (all work done here, no PHP).
# Args: <container> <vmid> <job> <target_dir> <rootfs> <staging> <pct_conf> <compression> <threads>
NAME="$1"; VMID="$2"; JOB="$3"; TARGET="$4"; ROOTFS="$5"; STAGING="$6"; PCT="$7"; COMP="$8"; THREADS="$9"
STATUS_DIR="/usr/local/emhttp/plugins/lxc/status"
STATUS="$STATUS_DIR/lxc-proxmox-${JOB}.json"
WORKLOG="/tmp/lxc-proxmox-${JOB}.log"
STAMP=$(date -u +%Y_%m_%d-%H_%M_%S)
BASE="vzdump-lxc-${VMID}-${STAMP}"
FINAL="${TARGET}/${BASE}.tar.${COMP}"
PART="${FINAL}.part"
TARPART="${TARGET}/${BASE}.tar"
LOG="${TARGET}/${BASE}.tar.log"
mkdir -p "$STATUS_DIR" "$TARGET"
echo "worker started $(date) name=$NAME vmid=$VMID job=$JOB rootfs=$ROOTFS comp=$COMP threads=$THREADS" > "$WORKLOG"

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
echo "rootfs size: $(du -sh "$ROOTFS" 2>/dev/null)" >> "$WORKLOG"

# Snapshot the rootfs into a staging directory with rsync (one filesystem,
# skips mounts, devices and temporary files). This avoids tar stalling on the
# live 23G rootfs.
SNAP="${STAGING}/rootfs"
mkdir -p "$SNAP"
status copying 5
if command -v rsync >/dev/null 2>&1; then
  echo "using rsync $(rsync --version 2>/dev/null | head -1)" >> "$WORKLOG"
  rsync -a --one-file-system --exclude='snaps/' --exclude='/tmp/*' --exclude='/var/run/*' \
    --exclude='/dev/*' "$ROOTFS/" "$SNAP/" >> "$WORKLOG" 2>&1 || fail "rsync snapshot failed"
else
  echo "rsync missing, falling back to cp -a" >> "$WORKLOG"
  cp -a --one-file-system "$ROOTFS/." "$SNAP/" >> "$WORKLOG" 2>&1 || fail "cp snapshot failed"
fi
echo "snapshot done $(date), size=$(du -sh "$SNAP" 2>/dev/null)" >> "$WORKLOG"

# Add pct.conf inside the snapshot at etc/vzdump/pct.conf.
mkdir -p "$SNAP/etc/vzdump"
cp "$PCT" "$SNAP/etc/vzdump/pct.conf" || fail "cannot copy pct.conf"

status compressing 80
CORES=$(nproc 2>/dev/null || echo 1)
# Fixed 18 threads: leaves headroom on the 48-core Xeon for UnRAID itself.
ZTHREADS=18
# tar with stdin to allow zstd streaming, no -r append.
if [ "$COMP" = "zst" ] && command -v zstd >/dev/null 2>&1; then
  echo "zstd threads=$ZTHREADS fixed (cores=$CORES, configured=$THREADS ignored)" >> "$WORKLOG"
  ( cd "$SNAP" && tar --numeric-owner --xattrs --acls -cf - . 2> "$LOG" ) \
    | zstd -q -T"$ZTHREADS" -o "$PART" || fail "tar|zstd failed: $(grep -v 'Error is not recoverable' "$LOG" | head -5 | tr '\n' ' ')"
else
  LEVEL=$(printf '%s' "$THREADS" | tr -cd '0-9'); [ -z "$LEVEL" ] && LEVEL=6
  [ "$LEVEL" -lt 1 ] && LEVEL=1; [ "$LEVEL" -gt 9 ] && LEVEL=9
  ( cd "$SNAP" && tar --numeric-owner --xattrs --acls -cf - . 2> "$LOG" ) \
    | gzip -"$LEVEL" -c > "$PART" || fail "tar|gzip failed: $(grep -v 'Error is not recoverable' "$LOG" | head -5 | tr '\n' ' ')"
fi
[ -s "$PART" ] || fail "compressed file is empty"
mv "$PART" "$FINAL" || fail "cannot install final backup"
echo "backup complete $(date), file=$FINAL size=$(wc -c < "$FINAL")" >> "$WORKLOG"
printf '{"stage":"done","percent":100,"started":%s,"total":0,"done":0,"updated":%s,"file":"%s","log":"%s"}\n' \
  "$(date +%s)" "$(date +%s)" "$(basename "$FINAL")" "$WORKLOG" > "$STATUS"
