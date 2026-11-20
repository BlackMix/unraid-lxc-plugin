#!/bin/bash
AUTOSTART_DELAY="$(cat /boot/config/plugins/lxc/plugin.cfg | grep -n "^AUTOSTART_DELAY=" | cut -d '=' -f2 | sed 's/\"//g')"

logger "LXC: Waiting ${AUTOSTART_DELAY}s for autostart from container(s) in background"
sleep ${AUTOSTART_DELAY:=10}s 2>/dev/null

# LXC v2: refresh GPU/USB passthrough rules before the containers boot
if [ -x /usr/local/emhttp/plugins/lxc/lxc_scripts/lxc-device-sync ]; then
  /usr/local/emhttp/plugins/lxc/lxc_scripts/lxc-device-sync >/dev/null 2>&1
fi

logger "LXC: Executing autostart from container(s)"
lxc-autostart
