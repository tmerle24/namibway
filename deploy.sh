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

echo "═══ 1/15 Maintenance-Mode AN ═══"
php artisan down --retry=15 || true

if [ "$FIRST_INSTALL" = false ]; then
   echo "═══ 2/15 Git Pull ($BRANCH) ═══"
   git fetch origin "$BRANCH"
   git reset --hard "origin/$BRANCH"
else
   echo "═══ 2/15 Erstinstallation — kein Pull nötig (frisch geklont) ═══"
fi

echo "═══ 3/15 Composer ═══"
# Immer installieren, nicht nur bei geändertem composer.lock: ein Diff gegen den
# letzten HEAD geht davon aus, dass der letzte Deploy-Lauf vollständig durchlief.
# Ist er das nicht (z.B. Abbruch nach dem Pull, aber vor/während composer install),
# hat sich composer.lock bereits "bewegt" und der Diff sieht keine Änderung mehr —
# das install wird dann dauerhaft übersprungen, obwohl vendor/ nie aktualisiert wurde.
# composer install ist bei unveränderten Dependencies ohnehin schnell (paar Sekunden).
composer install --no-dev --optimize-autoloader

echo "═══ 4/15 Release-Version snapshotten (Admin-Header + Release-Notes-Seite) ═══"
php artisan release:snapshot

echo "═══ 5/15 Storage-Rechte + View-Cache früh aufbauen ═══"
# Während der Wartung (php artisan down läuft seit Schritt 1 bis fast zum Schluss)
# kompiliert PHP-FPM (als www-data) neue Blade-Views live bei jedem Request, die noch
# nicht im Cache liegen — z.B. die neue errors/503.blade.php. touch() verlangt aber
# Eigentümerschaft der Datei (Schreibrecht über die Gruppe reicht nicht). Deshalb
# MÜSSEN die kompilierten Views www-data gehören: BladeCompiler::compile() ruft
# touch($compiled, mtime) auf, sobald eine Quell-View neuer ist als ihr Kompilat bei
# identischem Inhalt — z.B. weil scribe:generate (Schritt 10) die Doku-View bei jedem
# Deploy neu schreibt. Lief view:cache als Deploy-User (bzw. hat der chown -R in
# Schritt 13 die Kompilate wieder dem Deploy-User zugeschlagen), schlug dieses touch()
# als www-data dauerhaft mit EPERM fehl — jeder Render der betroffenen View war ein
# 500er, bis jemand manuell eingriff (Incident 2026-08-09, siehe CLAUDE.md).
# Fix: view:clear/view:cache als www-data ausführen, und storage/framework/views
# bleibt www-data-owned (Schritt 13 nimmt das Verzeichnis vom globalen chown aus).
sudo chown -R "$(whoami):www-data" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
sudo find "$APP_DIR/storage" -type d -exec chmod 775 {} \;
sudo find "$APP_DIR/storage" -type f -exec chmod 664 {} \;
sudo find "$APP_DIR/bootstrap/cache" -type d -exec chmod 775 {} \;
sudo find "$APP_DIR/bootstrap/cache" -type f -exec chmod 664 {} \;
sudo chown -R www-data:www-data "$APP_DIR/storage/framework/views"
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan view:cache

if [ "$SKIP_NPM" = false ]; then
    echo "═══ 6/15 Caches leeren vor dem Build (Wayfinder braucht die aktuellen Routen, nicht den alten Cache) ═══"
    php artisan config:clear
    php artisan route:clear

    echo "═══ 7/15 npm install + build ═══"
    # Gleiches Argument wie bei composer oben: immer installieren, nicht per Diff überspringen.
    npm ci
    export NODE_OPTIONS="--max-old-space-size=3072"
    npm run build
else
    echo "═══ 6-7/15 npm build übersprungen (--no-npm) ═══"
fi

if [ "$SKIP_MIGRATE" = false ]; then
    echo "═══ 8/15 Migrationen ═══"
    php artisan migrate --force
else
    echo "═══ 8/15 Migrationen übersprungen (--no-migrate) ═══"
fi

echo "═══ 9/15 Storage-Link ═══"
php artisan storage:link 2>/dev/null || true

echo "═══ 10/15 API-Doku generieren (Scribe) ═══"
php artisan scribe:generate --force

echo "═══ 11/15 Caches neu aufbauen ═══"
php artisan config:cache
php artisan route:cache
php artisan event:cache

echo "═══ 12/15 Laravel-Scheduler-Cron sicherstellen (Backups u.a. laufen darüber) ═══"
CRON_LINE="* * * * * cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1"
(crontab -l 2>/dev/null | grep -qF "$APP_DIR" && echo "  → Cron-Eintrag bereits vorhanden") || \
    (crontab -l 2>/dev/null; echo "$CRON_LINE") | crontab -

echo "═══ 13/15 Verzeichnis-Rechte, Horizon neu starten, Maintenance-Mode AUS ═══"
sudo chown -R "$(whoami):www-data" "$APP_DIR"
sudo find "$APP_DIR/storage" -type d -exec chmod 775 {} \;
sudo find "$APP_DIR/storage" -type f -exec chmod 664 {} \;
sudo find "$APP_DIR/bootstrap/cache" -type d -exec chmod 775 {} \;
sudo find "$APP_DIR/bootstrap/cache" -type f -exec chmod 664 {} \;
# Kompilierte Views zurück an www-data — der globale chown oben hat sie gerade dem
# Deploy-User zugeschlagen, womit das touch() des BladeCompilers als www-data mit
# EPERM fehlschlagen würde (500er auf jeder betroffenen View, siehe Schritt 5).
sudo chown -R www-data:www-data "$APP_DIR/storage/framework/views"

sudo supervisorctl restart "${QUEUE_WORKER_NAME}:*" 2>/dev/null || echo "  → Supervisor-Worker '$QUEUE_WORKER_NAME' noch nicht eingerichtet, übersprungen"

echo "═══ 14/15 PHP-FPM neu laden (OPcache) ═══"
# Ohne das hier laufen die bestehenden FPM-Worker mit dem Bytecode-Stand von vor
# dem Deploy weiter, falls opcache.validate_timestamps=0 gesetzt ist (übliche
# Prod-Härtung) — ein Code-Fix landet dann im Repo und im Composer-Autoloader,
# aber nie im tatsächlich ausgeführten Prozess, bis FPM neu lädt. Traf uns am
# 2026-08-02: ein bestätigt korrekter Fix (Save-Button-Bindung ans Formular)
# blieb nach dem Deploy wirkungslos, bis genau das hier fehlte.
#
# Alle laufenden php*-fpm-Units neu laden, nicht nur die erste gefundene: auf
# diesem Server laufen php8.3-fpm UND php8.4-fpm gleichzeitig (vermutlich ein
# Rest einer PHP-Versions-Migration) — welche davon nginx tatsächlich anspricht,
# entscheidet sich in der nginx-Config, nicht hier, und ein Reload der falschen
# Version lässt den Bug scheinbar unverändert weiterbestehen.
PHP_FPM_SERVICES=$(systemctl list-units --type=service --state=running --no-legend 2>/dev/null | awk '{print $1}' | grep -E '^php[0-9.]*-fpm\.service$')
if [ -n "$PHP_FPM_SERVICES" ]; then
    for service in $PHP_FPM_SERVICES; do
        sudo systemctl reload "$service" && echo "  → $service neu geladen" || echo "  → Neuladen von $service fehlgeschlagen — bitte manuell prüfen"
    done
else
    echo "  → Kein laufender php*-fpm-Service gefunden — übersprungen (bitte manuell prüfen, falls Code-Änderungen nicht ankommen)"
fi

php artisan up

echo "═══ 15/15 Health-Check ═══"
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
