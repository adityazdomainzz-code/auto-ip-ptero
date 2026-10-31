#!/usr/bin/env bash
# MC Manager (plugin manager + player manager) for Pterodactyl - Blueprint extension
#   bash <(curl -fsSL https://raw.githubusercontent.com/adityazdomainzz-code/auto-ip-ptero/main/pm.sh)
#   bash pm.sh --remove            uninstall
# Needs Blueprint (https://blueprint.zip). Safe to run again to update.
set -uo pipefail

REPO="adityazdomainzz-code/auto-ip-ptero"
BRANCH="${AUTOIP_BRANCH:-main}"
PANEL="/var/www/pterodactyl"
REMOVE=0
for a in "$@"; do case "$a" in --remove|--uninstall) REMOVE=1 ;; *) PANEL="$a" ;; esac; done
G='\033[0;32m'; Y='\033[1;33m'; R='\033[0;31m'; C='\033[0;36m'; N='\033[0m'
ok()   { echo -e "${G}  ✓ $*${N}"; }
die()  { echo -e "${R}  ✗ $*${N}"; exit 1; }

echo -e "${C}=== MC Manager: plugins + players for Pterodactyl ===${N}  panel: $PANEL"
[ "$(id -u)" -eq 0 ] || die "Run as root"
[ -f "$PANEL/artisan" ] || die "Pterodactyl not found in $PANEL (bash pm.sh /path/to/panel)"
command -v blueprint >/dev/null || [ -f "$PANEL/blueprint.sh" ] || die "Blueprint is not installed - install it first (https://blueprint.zip)"
OWNER="$(stat -c '%U' "$PANEL/artisan")"; [ "$OWNER" = "root" ] && OWNER="www-data"
cd "$PANEL" || exit 1

if [ "$REMOVE" = 1 ]; then
  blueprint -remove mcmanager || die "remove failed"
  chown -R "$OWNER:$OWNER" "$PANEL"
  ok "MC Manager removed"; exit 0
fi

command -v zip >/dev/null || apt-get install -y zip >/dev/null 2>&1 || die "zip is missing (apt install -y zip)"
SRC=""
HERE="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" 2>/dev/null && pwd || true)"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
if [ -n "$HERE" ] && [ -f "$HERE/pm/conf.yml" ]; then
  SRC="$HERE/pm"; ok "using local files"
else
  curl -fsSL "https://codeload.github.com/$REPO/tar.gz/refs/heads/$BRANCH" -o "$TMP/src.tgz" || die "download failed"
  tar -xzf "$TMP/src.tgz" -C "$TMP" || die "unpack failed"
  SRC="$(find "$TMP" -maxdepth 2 -type d -name pm | head -1)"
  [ -f "$SRC/conf.yml" ] || die "pm/ folder missing in the repo"
  ok "downloaded github.com/$REPO"
fi

( cd "$SRC" && zip -qr "$TMP/mcmanager.blueprint" . ) || die "could not build the extension file"
cp "$TMP/mcmanager.blueprint" "$PANEL/mcmanager.blueprint"
ok "extension file ready - installing with Blueprint (panel rebuild takes 5-10 minutes, do not press Ctrl+C)"

if blueprint -i mcmanager; then
  rm -f "$PANEL/mcmanager.blueprint"
  chown -R "$OWNER:$OWNER" "$PANEL"
  sudo -u "$OWNER" php artisan optimize:clear >/dev/null 2>&1
  echo -e "\n${G}Done!${N} Open any Minecraft server -> new ${C}Plugins${N} and ${C}Players${N} tabs."
else
  chown -R "$OWNER:$OWNER" "$PANEL"
  sudo -u "$OWNER" php artisan up >/dev/null 2>&1
  die "Blueprint install failed - see the output above"
fi
