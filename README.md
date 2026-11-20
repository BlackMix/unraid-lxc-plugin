# LXC v2 for Unraid

Run [LXC](https://linuxcontainers.org/) system containers on Unraid with a full
webGui editor: networking, GPU/USB passthrough, SSH provisioning and resource
limits — without touching the config file by hand.

This is a fork of the original Unraid LXC plugin, rebranded as **LXC v2** and
maintained at <https://github.com/BlackMix/unraid-lxc-plugin>.

## Installation

1. Unraid **6.10.0 or newer**.
2. Install from **Plugins → Install Plugin** using the URL:
   ```
   https://raw.githubusercontent.com/BlackMix/unraid-lxc-plugin/master/lxc.plg
   ```
3. Go to **Settings → LXC v2**, pick a storage path and set *Enable LXC* to *Yes*.

> The plugin keeps the internal id `lxc` so it upgrades an existing installation
> in place. The configuration lives in `/boot/config/plugins/lxc/` and the
> webGui files in `/usr/local/emhttp/plugins/lxc/`.

## Features

### Container list

Every row has inline action icons: **terminal**, **start/stop**, **restart** and
**edit**. The dropdown with all the remaining actions (snapshot, backup, copy,
freeze, kill, destroy) is still available.

### Container editor

Open it with the pencil icon. It has five tabs:

| Tab | What you can do |
|---|---|
| **General** | Description, WebUI URL, autostart, start order, support/donate links |
| **Network** | Add and edit interfaces (see below) |
| **Devices** | GPU, USB and misc device passthrough |
| **SSH** | Root password, root login policy, extra user, install OpenSSH |
| **Advanced** | Raw `config` editor with automatic `config.bak` |

### Network

* **DHCP** — no address is written, the container fetches a lease.
* **Static IPv4** — `address/prefix` plus optional gateway.
* **IPv6** — disabled, `auto` (SLAAC) or static with gateway.
* **VLAN** — for `veth` interfaces the tag is written as
  `lxc.net.N.veth.vlan.id`; for `macvlan` the plugin attaches to
  `<link>.<vlan>`.
* **Multiple interfaces** — add as many NICs as you need; each one gets its own
  `lxc.net.N.*` block.
* **veth (bridged)** for `br0`, `br1`, … or **macvlan** for `eth0`/`bond0`/`vhost0`.

The plugin owns the `lxc.net.*` block and rewrites it atomically, so editing the
same container twice does not duplicate lines. Legacy (unmarked) `lxc.net.*`
lines are migrated into the managed block on the first save.

### GPU passthrough

The plugin enumerates `/sys/class/drm` (Intel/AMD iGPU and discrete cards) and
the NVIDIA nodes, groups the DRM nodes by PCI address and lists them by name.
Selecting a GPU writes the `lxc.mount.entry` lines for its nodes plus the
matching `lxc.cgroup2.devices.allow` rules — no manual major/minor lookup.

### USB passthrough

Selecting a USB device writes:

* a stable udev symlink `/dev/lxcv2-usb/<vendor>_<product>`;
* a bind mount of the current `/dev/bus/usb/<bus>/<dev>`;
* `lxc.cgroup2.devices.allow = c 189:* rwm`.

Because the bus/device numbers change on every re-plug or reboot, the plugin
re-syncs the container configuration before it starts, and a udev hook
(`lxc-device-sync`) does the same when a device is added.

### Extra devices

`/dev/kvm`, `/dev/fuse`, `/dev/net/tun`, `/dev/vhost-net`, `/dev/snd`,
`/dev/input`, `/dev/video0`, `/dev/dvb`, USB serial (`ttyUSB*`, `ttyACM*`),
I2C and GPIO — only the ones present on the host are selectable.

### SSH

* Set the **root password**.
* Choose **PermitRootLogin**: `prohibit-password`, `yes`, `no`,
  `forced-commands-only`.
* Toggle **PasswordAuthentication**.
* Create an **additional user** with an optional sudo/wheel membership.
* Optionally **install OpenSSH server** using the container's package manager.

Passwords are passed to `chpasswd` over **stdin** — they never appear on the
command line, in the config file or in the syslog. When the container is
stopped the changes are staged and sshd is enabled for the next boot.

### Resources

Memory limit, swap limit, CPU pinning (`0-3`, `0,2,4`) and CPU weight, written
as `lxc.cgroup2.*` keys so they remain visible in the raw editor.

## Safety

* Every container name coming from the browser is validated against
  `^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$` before it reaches a shell command.
* All new shell calls use argument arrays instead of string concatenation.
* Configuration writes are atomic (`config.tmp` → `rename`) and keep a
  `config.bak` of the previous version.
* The plugin never writes a password to disk or to the syslog.

## Building the plugin package

The `.plg` downloads a Slackware package built from `source/`. On an Unraid box:

```bash
cd source && ./makepkg-unraid.sh
```

Or from any Linux machine with `tar`:

```bash
./build.sh          # builds packages/lxc-<version>.txz and updates the md5 in lxc.plg
```

Publish `lxc-<version>.txz` and its `.md5` in a GitHub release of
`BlackMix/unraid-lxc-plugin`. The LXC runtime binaries (`LXC-<lxc-version>-<idx>.txz`)
are downloaded from the same release; when the fork has no release yet the
installer falls back to the upstream repository so the plugin still installs.

## Development

The backend helpers are dependency-free PHP classes under
`source/usr/local/emhttp/plugins/lxc/include/`:

| File | Purpose |
|---|---|
| `Paths.php` | plugin paths, container name validation, safe process runner |
| `ContainerConfig.php` | atomic config edits with managed blocks |
| `Network.php` | NIC model, host link discovery, net block (de)serialisation |
| `Devices.php` | GPU/USB/misc discovery and passthrough rules |
| `Ssh.php` | in-container SSH provisioning |
| `Resources.php` | memory/CPU limits |

Run the backend tests with:

```bash
php tests/backend_test.php
```

## License

MIT — see [LICENSE](LICENSE). Original plugin by ich777, dashboard tile by
s3ppo.
