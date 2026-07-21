#!/bin/bash
# deploy_namibia_portal.sh — Universelles Deploy-Skript für den Namibia Travel & Life Portal
# Basiert auf dem bewährten Wishlist-Deploy-Skript, angepasst für dieses Projekt.
#
# Erkennt automatisch ob APP_DIR bereits ein Git-Repo ist:
#   - NEIN -> klont main-Branch frisch (Erstinstallation)
#   - JA   -> normales Update (git pull, etc.)
#
# Verwendung:
#   bash deploy_namibia_portal.sh                — volles Update/Deploy
#   bash deploy_namibia_portal.sh --no-npm        — ohne npm-Build
#   bash deploy_namibia_portal.sh --no-migrate    — ohne Migrationen
#
set -e

# ── Projekt-spezifische Werte — hier anpassen ───────────────────────
APP_DIR="/var/www/namibia-portal"
REPO_URL="git@github.com:tmerle24/namibway.git"
BRANCH="main"
QUEUE_WORKER_NAME="namibia-portal-worker"             # Supervisor-Programmname

SKIP_NPM=false
SKIP_MIGRATE=false
for arg in "$@"; do
    case $arg in
        --no-npm)     SKIP_NPM=true ;;
        --no-migrate) SKIP_MIGRATE=true ;;
    esac
done

# ── Erstinstallation: Repo klonen falls noch nicht vorhanden ────────
if [ ! -d "$APP_DIR/.git" ]; then
    echo "═══ Kein Git-Repo gefunden — klone $BRANCH nach $APP_DIR ═══"
    sudo mkdir -p "$APP_DIR"
    sudo chown "$(whoami):$(whoami)" "$APP_DIR"
    git clone --branch "$BRANCH" "$REPO_URL" "$APP_DIR"

    cd "$APP_DIR"

    if [ ! -f .env ]; then
        echo "═══ .env aus Vorlage erstellen — MUSS danach manuell befüllt werden! ═══"
        cp .env.example .env
        php artisan key:generate
        echo ""
        echo "⚠  STOPP: .env jetzt mit echten Werten befüllen (DB, MAIL, R2, STRIPE, ANTHROPIC_API_KEY),"
        echo "   dann dieses Skript erneut ausführen."
        exit 0
    fi

    FIRST_INSTALL=true
else
    cd "$APP_DIR"
    FIRST_INSTALL=false
fi

# ── Ab hier: normaler Update-/Deploy-Flow ───────────────────────────

echo "═══ 1/9 Maintenance-Mode AN ═══"
php artisan down --retry=15 || true

if [ "$FIRST_INSTALL" = false ]; then
   echo "═══ 2/9 Git Pull ($BRANCH) ═══"
   OLD_COMMIT=$(git rev-parse HEAD)
   git fetch origin "$BRANCH"
   git reset --hard "origin/$BRANCH"
   NEW_COMMIT=$(git rev-parse HEAD)
else
   echo "═══ 2/9 Erstinstallation — kein Pull nötig (frisch geklont) ═══"
fi

echo "═══ 3/9 Composer ═══"
if [ "$FIRST_INSTALL" = true ] || git diff HEAD@{1} HEAD --name-only 2>/dev/null | grep -q "composer.lock"; then
    composer install --no-dev --optimize-autoloader
else
    echo "  → composer.lock unverändert, übersprungen"
fi

if [ "$SKIP_NPM" = false ]; then
    echo "═══ 4/9 npm install + build ═══"
    if [ "$FIRST_INSTALL" = true ] || git diff HEAD@{1} HEAD --name-only 2>/dev/null | grep -qE "package-lock\.json|package\.json"; then
        npm ci
    fi
    export NODE_OPTIONS="--max-old-space-size=3072"
    npm run build
else
    echo "═══ 4/9 npm build übersprungen (--no-npm) ═══"
fi

if [ "$SKIP_MIGRATE" = false ]; then
    echo "═══ 5/9 Migrationen ═══"
    php artisan migrate --force
else
    echo "═══ 5/9 Migrationen übersprungen (--no-migrate) ═══"
fi

echo "═══ 6/9 Storage-Link ═══"
php artisan storage:link 2>/dev/null || true

echo "═══ 7/9 Caches neu aufbauen ═══"
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:clear
php artisan view:cache
php artisan event:cache

echo "═══ 8/9 Verzeichnis-Rechte ═══"
sudo chown -R "$(whoami):www-data" "$APP_DIR"
sudo find "$APP_DIR/storage" -type d -exec chmod 775 {} \;
sudo find "$APP_DIR/storage" -type f -exec chmod 664 {} \;
sudo find "$APP_DIR/bootstrap/cache" -type d -exec chmod 775 {} \;
sudo find "$APP_DIR/bootstrap/cache" -type f -exec chmod 664 {} \;

# ── Optional: separater Node-Service (z.B. ein eigener AI/Scraping-Worker) ──
# Analog zum Scraper-Service bei Wishlist — nur relevant, falls für den
# Namibia-Portal später ein eigenständiger Node-Prozess neben Laravel läuft
# (z.B. ein Sync-Job für Partner-Channel-Manager). Aktuell nicht Teil des MVP.
echo "═══ 8b/9 Optionaler Node-Service (Interfaces/Partner-Sync) ═══"
if [ -d "$APP_DIR/interfaces-service" ]; then
    cd "$APP_DIR/interfaces-service"
    if [ "$FIRST_INSTALL" = true ] || git diff HEAD@{1} HEAD --name-only 2>/dev/null | grep -q "interfaces-service/package.json"; then
        npm install
    fi
    pm2 restart namibia-portal-interfaces 2>/dev/null || pm2 start server.js --name namibia-portal-interfaces --max-memory-restart 500M
    pm2 save
    cd "$APP_DIR"
else
    echo "  → interfaces-service/ nicht gefunden, übersprungen (noch nicht Teil des MVP)"
fi

echo "═══ 9/9 Queue-Worker (Horizon) neu starten + Maintenance-Mode AUS ═══"
sudo supervisorctl restart "${QUEUE_WORKER_NAME}:*" 2>/dev/null || echo "  → Supervisor-Worker '$QUEUE_WORKER_NAME' noch nicht eingerichtet, übersprungen"
php artisan up

echo ""
if [ "$FIRST_INSTALL" = true ]; then
    echo "✅ Erstinstallation abgeschlossen."
    echo "   Nächste Schritte: Nginx-Vhost + SSL einrichten, Supervisor für Horizon konfigurieren"
    echo "   (php artisan horizon als Daemon), R2-Bucket + Anthropic-API-Key in .env prüfen,"
    echo "   ersten Admin-User per 'php artisan tinker' anlegen (Filament-Zugang)."
else
    echo "✅ Deploy fertig."
fi
