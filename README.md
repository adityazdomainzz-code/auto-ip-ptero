# auto-ip-ptero

Cloudflare DNS for every new Pterodactyl server.

Create a server called **Survival SMP** and it automatically gets **`survival-smp.yourdomain.com`**,
pointing to the server's primary allocation (IP alias first, then IP) or to the public IP you set.
Minecraft (Java) servers also get an SRV record, so players can join with just the name, no port.

- Name already used, in the panel or in your Cloudflare DNS: the server is **not created**, and the panel shows *"Server name already exists"*.
- Server renamed: the address moves to the new name.
- Server deleted: its DNS records are deleted.
- Records are DNS only (grey cloud), because game traffic cannot pass the Cloudflare proxy.
- Private IPs (10.x, 192.168.x, 172.16-31.x, 100.64/10) are never published. Set **Public IP for DNS** instead.

Tested on Pterodactyl **v1.15.x** (Laravel 12, PHP 8.2/8.3).

## Install

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/adityazdomainzz-code/auto-ip-ptero/main/install.sh)
```

The panel folder defaults to `/var/www/pterodactyl`. Pass a different one as the first argument: `bash install.sh /path/to/panel`.

Then open **Admin → Cloudflare DNS** (in the sidebar under *Nests*):

1. Create a Cloudflare API token: *My Profile → API Tokens → Create Token → template **Edit zone DNS*** → Zone Resources: your domain. Paste it and click **Connect**.
2. Pick the domain. Fill **Public IP for DNS** if your node IP is private (NAT). Tick **Enable**, then **Save**.
3. Optional: **Create for N existing servers** gives addresses to servers made before the install.

After a Pterodactyl update, run the installer again. Updates can remove the sidebar link; the installer is safe to run multiple times.

## Uninstall

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/adityazdomainzz-code/auto-ip-ptero/main/uninstall.sh)
# add --drop-table to also delete the saved server -> address list
```

DNS records already created in Cloudflare are left untouched.

## What it changes in the panel

| File | Change |
|---|---|
| `app/Providers/AutoIpServiceProvider.php` | new: hooks server create / rename / delete, registers the admin routes |
| `app/Services/AutoIp/*` | new: Cloudflare API and record storage |
| `app/Http/Controllers/Admin/AutoIpController.php` | new: admin page |
| `resources/views/admin/auto-ip/index.blade.php` | new: admin page view |
| `database/migrations/2026_10_31_000000_create_autoip_dns_records_table.php` | new table `autoip_dns_records` |
| `config/app.php` | one line: registers the service provider |
| `resources/views/layouts/admin.blade.php` | sidebar link after *Nests* (between `auto-ip-ptero` markers) |

The installer saves the originals of the two edited files to `.autoip-backup/` in the panel folder.
