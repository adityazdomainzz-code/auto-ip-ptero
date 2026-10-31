#!/usr/bin/env bash
# auto-ip-ptero installer - Cloudflare DNS for every new Pterodactyl server
#   bash <(curl -fsSL https://raw.githubusercontent.com/adityazdomainzz-code/auto-ip-ptero/main/install.sh)
#   bash install.sh /path/to/panel        (default: /var/www/pterodactyl)
# Safe to run again (after a panel update it puts the sidebar link back).
set -uo pipefail

REPO="adityazdomainzz-code/auto-ip-ptero"
BRANCH="${AUTOIP_BRANCH:-main}"
PANEL="${1:-/var/www/pterodactyl}"
G='\033[0;32m'; Y='\033[1;33m'; R='\033[0;31m'; C='\033[0;36m'; N='\033[0m'
ok()   { echo -e "${G}  ✓ $*${N}"; }
warn() { echo -e "${Y}  ! $*${N}"; }
die()  { echo -e "${R}  ✗ $*${N}"; exit 1; }
step() { echo -e "\n${C}[$1/6] $2${N}"; }

echo -e "${C}=== auto-ip-ptero: Cloudflare DNS for Pterodactyl ===${N}  panel: $PANEL"

step 1 "Checking"
[ "$(id -u)" -eq 0 ] || die "Run as root (sudo bash install.sh)"
[ -f "$PANEL/artisan" ] && [ -f "$PANEL/config/app.php" ] || die "Pterodactyl panel not found in $PANEL (pass the folder: bash install.sh /path/to/panel)"
LAYOUT="$PANEL/resources/views/layouts/admin.blade.php"
[ -f "$LAYOUT" ] || die "Admin layout not found: $LAYOUT"
command -v php >/dev/null || die "php not found"
php -m 2>/dev/null | grep -qi '^curl$' || warn "php curl extension not found - install php-curl (apt install php8.3-curl)"
WEBUSER="www-data"
id nginx >/dev/null 2>&1 && ! id www-data >/dev/null 2>&1 && WEBUSER="nginx"
OWNER="$(stat -c '%U' "$PANEL/artisan" 2>/dev/null || echo "$WEBUSER")"
[ "$OWNER" = "root" ] && OWNER="$WEBUSER"
ok "panel found (files owned by $OWNER)"

step 2 "Getting the files"
SRC=""
HERE="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd || true)"
if [ -n "$HERE" ] && [ -d "$HERE/files/app" ]; then
  SRC="$HERE/files"; ok "using local files ($SRC)"
else
  TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
  command -v curl >/dev/null || die "curl not found (apt install -y curl)"
  curl -fsSL "https://codeload.github.com/$REPO/tar.gz/refs/heads/$BRANCH" -o "$TMP/src.tgz" || die "download failed from github.com/$REPO"
  tar -xzf "$TMP/src.tgz" -C "$TMP" || die "could not unpack the download"
  SRC="$(find "$TMP" -maxdepth 2 -type d -name files | head -1)"
  [ -d "$SRC/app" ] || die "files/ folder missing in the download"
  ok "downloaded github.com/$REPO ($BRANCH)"
fi

step 3 "Copying files into the panel"
BK="$PANEL/.autoip-backup"; mkdir -p "$BK"
[ -f "$BK/app.php.orig" ] || cp "$PANEL/config/app.php" "$BK/app.php.orig"
[ -f "$BK/admin.blade.php.orig" ] || cp "$LAYOUT" "$BK/admin.blade.php.orig"
cp -r "$SRC/." "$PANEL/" || die "copy failed"
ok "files copied (originals backed up in $BK)"

step 4 "Registering the extension"
if grep -q 'AutoIpServiceProvider' "$PANEL/config/app.php"; then
  ok "service provider already registered"
else
  sed -i 's#^\(\s*\)Pterodactyl\\Providers\\AppServiceProvider::class,#&\n\1Pterodactyl\\Providers\\AutoIpServiceProvider::class,#' "$PANEL/config/app.php"
  grep -q 'AutoIpServiceProvider' "$PANEL/config/app.php" || die "could not add the provider to config/app.php"
  ok "service provider registered"
fi

if grep -q 'admin.autoip' "$LAYOUT"; then
  ok "sidebar link already there"
else
  perl -0pi -e '
    my $link = qq{\n                        {{-- auto-ip-ptero --}}\n                        <li class="{{ ! starts_with(Route::currentRouteName(), \x27admin.autoip\x27) ?: \x27active\x27 }}">\n                            <a href="{{ route(\x27admin.autoip.index\x27) }}">\n                                <i class="fa fa-cloud"></i> <span>Cloudflare DNS</span>\n                            </a>\n                        </li>\n                        {{-- /auto-ip-ptero --}}};
    s{(route\(\x27admin\.nests\x27\).*?</li>)}{$1$link}s;
  ' "$LAYOUT"
  if grep -q 'admin.autoip' "$LAYOUT"; then
    ok "sidebar link added under Nests"
  else
    warn "could not find the Nests menu item - open the page directly: https://<panel>/admin/cloudflare-dns"
  fi
fi

step 5 "Database + cache"
cd "$PANEL" || die "cannot enter $PANEL"
chown -R "$OWNER:$OWNER" "$PANEL"
if [ -z "${AUTOIP_SKIP_ARTISAN:-}" ]; then
  sudo -u "$OWNER" php artisan migrate --force --path=database/migrations/2026_10_31_000000_create_autoip_dns_records_table.php 2>&1 | tail -2 || die "migration failed"
  sudo -u "$OWNER" php artisan optimize:clear >/dev/null 2>&1
  sudo -u "$OWNER" php artisan queue:restart >/dev/null 2>&1 || true
  ok "table created, cache cleared"
else
  warn "artisan skipped (AUTOIP_SKIP_ARTISAN)"
fi

step 6 "Restarting PHP"
for s in $(systemctl list-units --type=service --no-legend 'php*-fpm*' 2>/dev/null | awk '{print $1}'); do systemctl restart "$s" && ok "$s restarted"; done
chown -R "$OWNER:$OWNER" "$PANEL"

echo -e "\n${G}Done!${N} Admin panel -> ${C}Cloudflare DNS${N} (under Nests):"
echo "  1. paste a Cloudflare API token (template: Edit zone DNS) -> Connect"
echo "  2. pick your domain, set 'Public IP for DNS' if the node IP is private -> Save"
echo "  New servers now get <server-name>.<domain>. Uninstall: bash uninstall.sh"
