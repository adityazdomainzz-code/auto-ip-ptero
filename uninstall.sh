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

sed -i '/AutoIpServiceProvider::class,/d' config/app.php
perl -0pi -e 's{\n\s*\{\{-- auto-ip-ptero --\}\}.*?\{\{-- /auto-ip-ptero --\}\}}{}s' resources/views/layouts/admin.blade.php
if [ "$DROP" = 1 ]; then
  sudo -u "$OWNER" php artisan migrate:rollback --force --path=database/migrations/2026_10_31_000000_create_autoip_dns_records_table.php || true
fi
rm -f app/Providers/AutoIpServiceProvider.php app/Http/Controllers/Admin/AutoIpController.php
rm -rf app/Services/AutoIp resources/views/admin/auto-ip
[ "$DROP" = 1 ] && rm -f database/migrations/2026_10_31_000000_create_autoip_dns_records_table.php
sudo -u "$OWNER" php artisan optimize:clear >/dev/null 2>&1
for s in $(systemctl list-units --type=service --no-legend 'php*-fpm*' 2>/dev/null | awk '{print $1}'); do systemctl restart "$s"; done
echo "auto-ip-ptero removed. (Cloudflare records were left as they are.)"
