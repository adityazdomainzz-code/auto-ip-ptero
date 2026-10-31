#!/usr/bin/env bash
# auto-ip-ptero uninstaller
#   bash <(curl -fsSL https://raw.githubusercontent.com/adityazdomainzz-code/auto-ip-ptero/main/uninstall.sh)
#   add --drop-table to also delete the saved server -> address list
# DNS records already in Cloudflare are NOT deleted.
set -uo pipefail
PANEL="/var/www/pterodactyl"; DROP=0
for a in "$@"; do case "$a" in --drop-table) DROP=1 ;; *) PANEL="$a" ;; esac; done
[ "$(id -u)" -eq 0 ] || { echo "Run as root"; exit 1; }
[ -f "$PANEL/artisan" ] || { echo "Panel not found in $PANEL"; exit 1; }
OWNER="$(stat -c '%U' "$PANEL/artisan")"; [ "$OWNER" = "root" ] && OWNER="www-data"
cd "$PANEL" || exit 1

# show the real IPs again in the panel
sudo -u "$OWNER" php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  if (Illuminate\Support\Facades\Schema::hasColumn("autoip_dns_records","prev_alias")) foreach (Illuminate\Support\Facades\DB::table("autoip_dns_records")->get() as $r) {
    $a = Illuminate\Support\Facades\DB::table("servers")->where("id",$r->server_id)->value("allocation_id");
    if ($a) Illuminate\Support\Facades\DB::table("allocations")->where("id",$a)->where("ip_alias",$r->hostname)->update(["ip_alias"=>($r->prev_alias ?? "") !== "" ? $r->prev_alias : null]);
  }' 2>/dev/null && echo "IP aliases restored"
sed -i '/AutoIpServiceProvider::class,/d' config/app.php
perl -0pi -e 's{\n\s*\{\{-- auto-ip-ptero --\}\}.*?\{\{-- /auto-ip-ptero --\}\}}{}s' resources/views/layouts/admin.blade.php
if [ "$DROP" = 1 ]; then
  sudo -u "$OWNER" php artisan migrate:rollback --force --path=database/migrations/2026_10_31_000001_add_prev_alias_to_autoip_dns_records.php --path=database/migrations/2026_10_31_000000_create_autoip_dns_records_table.php || true
fi
rm -f app/Providers/AutoIpServiceProvider.php app/Http/Controllers/Admin/AutoIpController.php
rm -rf app/Services/AutoIp resources/views/admin/auto-ip
[ "$DROP" = 1 ] && rm -f database/migrations/2026_10_31_00000[01]_*autoip*.php
sudo -u "$OWNER" php artisan optimize:clear >/dev/null 2>&1
for s in $(systemctl list-units --type=service --no-legend 'php*-fpm*' 2>/dev/null | awk '{print $1}'); do systemctl restart "$s"; done
echo "auto-ip-ptero removed. (Cloudflare records were left as they are.)"
