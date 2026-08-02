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

echo "═══ 1/12 Maintenance-Mode AN ═══"
php artisan down --retry=15 || true

if [ "$FIRST_INSTALL" = false ]; then
   echo "═══ 2/12 Git Pull ($BRANCH) ═══"
   git fetch origin "$BRANCH"
   git reset --hard "origin/$BRANCH"
else
   echo "═══ 2/12 Erstinstallation — kein Pull nötig (frisch geklont) ═══"
fi

echo "═══ 3/12 Composer ═══"
# Immer installieren, nicht nur bei geändertem composer.lock: ein Diff gegen den
# letzten HEAD geht davon aus, dass der letzte Deploy-Lauf vollständig durchlief.
# Ist er das nicht (z.B. Abbruch nach dem Pull, aber vor/während composer install),
# hat sich composer.lock bereits "bewegt" und der Diff sieht keine Änderung mehr —
# das install wird dann dauerhaft übersprungen, obwohl vendor/ nie aktualisiert wurde.
# composer install ist bei unveränderten Dependencies ohnehin schnell (paar Sekunden).
composer install --no-dev --optimize-autoloader

if [ "$SKIP_NPM" = false ]; then
    echo "═══ 4/12 Caches leeren vor dem Build (Wayfinder braucht die aktuellen Routen, nicht den alten Cache) ═══"
    php artisan config:clear
    php artisan route:clear

    echo "═══ 5/12 npm install + build ═══"
    # Gleiches Argument wie bei composer oben: immer installieren, nicht per Diff überspringen.
    npm ci
    export NODE_OPTIONS="--max-old-space-size=3072"
    npm run build
else
    echo "═══ 4-5/12 npm build übersprungen (--no-npm) ═══"
fi

if [ "$SKIP_MIGRATE" = false ]; then
    echo "═══ 6/12 Migrationen ═══"
    php artisan migrate --force
else
    echo "═══ 6/12 Migrationen übersprungen (--no-migrate) ═══"
fi

echo "═══ 7/12 Storage-Link ═══"
php artisan storage:link 2>/dev/null || true

echo "═══ 8/12 API-Doku generieren (Scribe) ═══"
php artisan scribe:generate --force

echo "═══ 9/12 Caches neu aufbauen ═══"
php artisan config:cache
php artisan route:cache
php artisan view:clear
php artisan view:cache
php artisan event:cache

echo "═══ 10/12 Laravel-Scheduler-Cron sicherstellen (Backups u.a. laufen darüber) ═══"
CRON_LINE="* * * * * cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1"
(crontab -l 2>/dev/null | grep -qF "$APP_DIR" && echo "  → Cron-Eintrag bereits vorhanden") || \
    (crontab -l 2>/dev/null; echo "$CRON_LINE") | crontab -

echo "═══ 11/12 Verzeichnis-Rechte, Horizon neu starten, Maintenance-Mode AUS ═══"
sudo chown -R "$(whoami):www-data" "$APP_DIR"
sudo find "$APP_DIR/storage" -type d -exec chmod 775 {} \;
sudo find "$APP_DIR/storage" -type f -exec chmod 664 {} \;
sudo find "$APP_DIR/bootstrap/cache" -type d -exec chmod 775 {} \;
sudo find "$APP_DIR/bootstrap/cache" -type f -exec chmod 664 {} \;

sudo supervisorctl restart "${QUEUE_WORKER_NAME}:*" 2>/dev/null || echo "  → Supervisor-Worker '$QUEUE_WORKER_NAME' noch nicht eingerichtet, übersprungen"
php artisan up

echo "═══ 12/12 Health-Check ═══"
HEALTH_URL="https://www.namibway.com/up"
HEALTH_OK=false
for i in 1 2 3 4 5; do
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "$HEALTH_URL" || echo "000")
    if [ "$HTTP_CODE" = "200" ]; then
        HEALTH_OK=true
        break
    fi
    echo "  → Versuch $i/5: HTTP $HTTP_CODE, warte 3s..."
    sleep 3
done

if [ "$HEALTH_OK" = false ]; then
    echo ""
    echo "❌ Health-Check fehlgeschlagen: $HEALTH_URL antwortet nicht mit 200 (zuletzt: HTTP $HTTP_CODE)."
    echo "   Deploy ist technisch durchgelaufen, aber die Seite scheint kaputt zu sein — bitte prüfen:"
    echo "   tail -100 $APP_DIR/storage/logs/laravel.log"
    exit 1
fi
echo "  → $HEALTH_URL antwortet mit 200"

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
