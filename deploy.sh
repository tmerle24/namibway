#!/bin/bash
# deploy_namibway.sh — Universelles Deploy-Skript für NamibWay
# Basiert auf dem bewährten Wishlist-Deploy-Skript, angepasst für dieses Projekt.
#
# Erkennt automatisch ob APP_DIR bereits ein Git-Repo ist:
#   - NEIN -> klont main-Branch frisch (Erstinstallation)
#   - JA   -> normales Update (git pull, etc.)
#
# Verwendung:
#   bash deploy_namibway.sh                — volles Update/Deploy
#   bash deploy_namibway.sh --no-npm       — ohne npm-Build
#   bash deploy_namibway.sh --no-migrate   — ohne Migrationen
#
set -e

# ── Projekt-spezifische Werte — hier anpassen ───────────────────────
APP_DIR="/var/www/namibway"
REPO_URL="git@github.com:tmerle24/namibway.git"
BRANCH="main"
QUEUE_WORKER_NAME="namibway-horizon"                  # Supervisor-Programmname (php artisan horizon)

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
        echo "⚠  STOPP: .env jetzt mit echten Werten befüllen (DB, MAIL, R2, ANTHROPIC_API_KEY),"
        echo "   dann dieses Skript erneut ausführen."
        exit 0
    fi

    FIRST_INSTALL=true
else
    cd "$APP_DIR"
    FIRST_INSTALL=false
fi

# ── Ab hier: normaler Update-/Deploy-Flow ───────────────────────────

echo "═══ 1/11 Maintenance-Mode AN ═══"
php artisan down --retry=15 || true

if [ "$FIRST_INSTALL" = false ]; then
   echo "═══ 2/11 Git Pull ($BRANCH) ═══"
   git fetch origin "$BRANCH"
   git reset --hard "origin/$BRANCH"
else
   echo "═══ 2/11 Erstinstallation — kein Pull nötig (frisch geklont) ═══"
fi

echo "═══ 3/11 Composer ═══"
# Immer installieren, nicht nur bei geändertem composer.lock: ein Diff gegen den
# letzten HEAD geht davon aus, dass der letzte Deploy-Lauf vollständig durchlief.
# Ist er das nicht (z.B. Abbruch nach dem Pull, aber vor/während composer install),
# hat sich composer.lock bereits "bewegt" und der Diff sieht keine Änderung mehr —
# das install wird dann dauerhaft übersprungen, obwohl vendor/ nie aktualisiert wurde.
# composer install ist bei unveränderten Dependencies ohnehin schnell (paar Sekunden).
composer install --no-dev --optimize-autoloader

if [ "$SKIP_NPM" = false ]; then
    echo "═══ 4/11 Caches leeren vor dem Build (Wayfinder braucht die aktuellen Routen, nicht den alten Cache) ═══"
    php artisan config:clear
    php artisan route:clear

    echo "═══ 5/11 npm install + build ═══"
    # Gleiches Argument wie bei composer oben: immer installieren, nicht per Diff überspringen.
    npm ci
    export NODE_OPTIONS="--max-old-space-size=3072"
    npm run build
else
    echo "═══ 4-5/11 npm build übersprungen (--no-npm) ═══"
fi

if [ "$SKIP_MIGRATE" = false ]; then
    echo "═══ 6/11 Migrationen ═══"
    php artisan migrate --force
else
    echo "═══ 6/11 Migrationen übersprungen (--no-migrate) ═══"
fi

echo "═══ 7/11 Storage-Link ═══"
php artisan storage:link 2>/dev/null || true

echo "═══ 8/11 API-Doku generieren (Scribe) ═══"
php artisan scribe:generate --force

echo "═══ 9/11 Caches neu aufbauen ═══"
php artisan config:cache
php artisan route:cache
php artisan view:clear
php artisan view:cache
php artisan event:cache

echo "═══ 10/11 Laravel-Scheduler-Cron sicherstellen (Backups u.a. laufen darüber) ═══"
CRON_LINE="* * * * * cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1"
(crontab -l 2>/dev/null | grep -qF "$APP_DIR" && echo "  → Cron-Eintrag bereits vorhanden") || \
    (crontab -l 2>/dev/null; echo "$CRON_LINE") | crontab -

echo "═══ 11/11 Verzeichnis-Rechte, Horizon neu starten, Maintenance-Mode AUS ═══"
sudo chown -R "$(whoami):www-data" "$APP_DIR"
sudo find "$APP_DIR/storage" -type d -exec chmod 775 {} \;
sudo find "$APP_DIR/storage" -type f -exec chmod 664 {} \;
sudo find "$APP_DIR/bootstrap/cache" -type d -exec chmod 775 {} \;
sudo find "$APP_DIR/bootstrap/cache" -type f -exec chmod 664 {} \;

sudo supervisorctl restart "${QUEUE_WORKER_NAME}:*" 2>/dev/null || echo "  → Supervisor-Worker '$QUEUE_WORKER_NAME' noch nicht eingerichtet, übersprungen"
php artisan up

echo ""
if [ "$FIRST_INSTALL" = true ]; then
    echo "✅ Erstinstallation abgeschlossen."
    echo "   Nächste Schritte: Redis installieren, Supervisor für Horizon konfigurieren,"
    echo "   R2-Bucket + Anthropic-API-Key in .env prüfen,"
    echo "   ersten Admin-User anlegen (danach is_admin=true setzen, siehe DEPLOYMENT.md):"
    echo "   php artisan make:filament-user"
else
    echo "✅ Deploy fertig."
fi
