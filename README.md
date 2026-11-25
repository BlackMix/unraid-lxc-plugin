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
* **Static IPv4** — `address/prefix` plus optional gateway. A prefix is required;
  entering `192.168.1.50` is normalised to `192.168.1.50/24` (`/24` is the
  netmask — LXC has no separate netmask key).
* **DNS** — up to three servers, applied only with **static IPv4** (or disabled).
  With DHCP the container uses whatever DNS the DHCP server advertises.
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

**Guest network configuration.** Saving a static (or disabled) IPv4 address also
writes the matching configuration inside the container rootfs so the guest's own
network stack does not overwrite the address on boot: `ifupdown` stanzas become
`inet manual`, `systemd-networkd` gets a `00-lxcv2-<nic>.network`, `netplan` gets
`99-lxcv2.yaml`, and NetworkManager is told to leave those interfaces alone. DNS
is written via `systemd-resolved` when available and/or a managed block in
`/etc/resolv.conf` (symlinks are never followed). **Restart the container** after
changing network settings. Custom DNS is not applied with DHCP — the DHCP
server's DNS is used instead.

This guest configuration is applied only to `dir:`, `btrfs:` and `zfs:` rootfs
paths contained in the LXC data path. LVM/overlay and ZFS datasets mounted
outside that path keep the default LXC behaviour (the guest init may overwrite
a static address). Copying or restoring a container also copies its static IP
and DNS — check for conflicts before starting it. Changing the global default
interface under Settings does not reapply guest configuration; re-save the
container's network afterwards.

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

Publish `lxc-<version>.txz` and its `.md5` in the `master` branch under
`packages/` (the `.plg` fetches them from `raw.githubusercontent.com/.../master/packages/`).

## Publishing the LXC runtime binaries on your GitHub

The `.plg` also downloads the compiled LXC runtime (`LXC-<version>-<idx>.txz`).
The source of those binaries is controlled by `LXC_BINARY_SOURCE` in
`/boot/config/plugins/lxc/plugin.cfg`:

| Value | Behaviour |
|---|---|
| `auto` (default) | Tries a release of `BlackMix/unraid-lxc-plugin`; if none exists, silently falls back to the upstream repository (message goes to syslog only) |
| `fork` | Uses **only** your release. The install stops with a clear error if the release or the assets are missing |

To go fully self-hosted:

1. Build the runtime with `source/LXC-build-2.sh` for each Unraid build index
   (produces `LXC-<lxc-version>-1.txz` and `LXC-<lxc-version>-2.txz` plus `.md5` files).
2. Create a GitHub release in `BlackMix/unraid-lxc-plugin` with the **tag set to
   the LXC version** (e.g. `6.0.0`) and upload all four files.
3. Add this line to `/boot/config/plugins/lxc/plugin.cfg`:
   ```
   LXC_BINARY_SOURCE=fork
   ```
4. Verify with `./release-check.sh` before reinstalling the plugin.

The next plugin install will then download everything from your GitHub and no
fallback message will appear anywhere.

## Proxmox-compatible backups (experimental)

The **Backups** page has a second section, independent of the legacy backup
service, that creates archives in the `vzdump` format used by Proxmox VE:

```
<backup_path>/proxmox/<container>/vzdump-lxc-<VMID>-<timestamp>.tar.zst
```

(with `.tar.gz` as a fallback when `zstd` is unavailable). The archive contains
`./etc/vzdump/pct.conf` plus the container rootfs at the top level. A
**Download** button generates a short-lived signed link (5 minutes) for each
backup; **Delete** asks for confirmation.

- While the backup runs, a dialog shows a **progress bar with percentage and
  estimated remaining time**, updated once per second (archiving → compressing).
- The container is **stopped** for the duration of the backup and restarted
  afterwards, even if the backup fails.
- The VMID can be set globally under Settings → Backup (`LXC_PROXMOX_VMID`,
  default `100`) and overridden per backup on the Backups page.
- Retention (`LXC_BACKUP_KEEP`) is applied only to `vzdump-*` files in the
  separate `proxmox/` directory; the legacy `.tar.xz` backups are untouched.
- `pct.conf` is generated from the LXC config: cores, memory/swap (converted to
  MiB), hostname, ostype, onboot, description, and `netN` entries with veth
  bridge, VLAN `tag`, hwaddr, static/DHCP IPv4, static/auto IPv6 and
  `link_down=1`. IPv4/IPv6 addresses without a prefix are normalised to `/24`
  and `/64` respectively. The `rootfs` line is a **placeholder**:
  `local-lvm:vm-<VMID>-disk-0,size=<size>G`.
- **macvlan and `veth.mode=router` containers are refused** — those topologies
  cannot be represented faithfully in `pct.conf`.

Restore on Proxmox with:

```sh
pct restore <VMID> <file> --rootfs <storage>:<size>
```

**This is experimental and has NOT been validated with a real `pct restore`.**
Before relying on it, verify on your Proxmox version: the generated `net0`
(veth + VLAN + IPv4/IPv6), the placeholder `rootfs` override, and both
`.tar.zst` and `.tar.gz` archives. The backup files themselves are verified
locally by `tests/proxmox_backup_test.php` (`tar -tf` checks for both
`./etc/vzdump/pct.conf` and the rootfs files).

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
| `GuestNetwork.php` | in-guest network config (ifupdown/networkd/netplan/NM) |
| `ProxmoxBackup.php` | Proxmox-compatible vzdump backups |

Run the backend tests with:

```bash
php tests/backend_test.php
```

## License

MIT — see [LICENSE](LICENSE). Original plugin by ich777, dashboard tile by
s3ppo.
