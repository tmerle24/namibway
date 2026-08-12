#!/bin/bash
# restore.sh — Datenbank-Wiederherstellung aus Cloudflare R2
#
# Verwendung:
#   bash restore.sh                  — stellt das neueste Backup wieder her
#   bash restore.sh 2026-08-10       — stellt ein bestimmtes Datum wieder her
#   bash restore.sh --list           — listet verfügbare Backups
#
set -euo pipefail

APP_DIR="/var/www/namibway"
ENV_FILE="$APP_DIR/.env"
PREFIX="namibway/db"

env_get() {
    grep -m1 "^${1}=" "$ENV_FILE" | cut -d= -f2- | tr -d '"' | tr -d "'"
}

DB_HOST=$(env_get DB_HOST);     DB_HOST=${DB_HOST:-127.0.0.1}
DB_PORT=$(env_get DB_PORT);     DB_PORT=${DB_PORT:-5432}
DB_DATABASE=$(env_get DB_DATABASE)
DB_USERNAME=$(env_get DB_USERNAME)
DB_PASSWORD=$(env_get DB_PASSWORD)

BUCKET=$(env_get CLOUDFLARE_R2_BACKUP_BUCKET); BUCKET=${BUCKET:-namibway-backups}
R2_KEY=$(env_get CLOUDFLARE_R2_BACKUP_ACCESS_KEY_ID)
R2_KEY=${R2_KEY:-$(env_get CLOUDFLARE_R2_ACCESS_KEY_ID)}
R2_SECRET=$(env_get CLOUDFLARE_R2_BACKUP_SECRET_ACCESS_KEY)
R2_SECRET=${R2_SECRET:-$(env_get CLOUDFLARE_R2_SECRET_ACCESS_KEY)}
R2_ENDPOINT=$(env_get CLOUDFLARE_R2_ENDPOINT)

s3() {
    AWS_ACCESS_KEY_ID="$R2_KEY" \
    AWS_SECRET_ACCESS_KEY="$R2_SECRET" \
    aws s3 "$@" --endpoint-url "$R2_ENDPOINT" --region auto
}

if [[ "${1:-}" == "--list" ]]; then
    echo "Verfügbare Backups:"
    s3 ls "s3://$BUCKET/$PREFIX/" \
        | awk '{print $1, $2, $3, $4}' \
        | grep -E '[0-9]{4}-[0-9]{2}-[0-9]{2}\.sql\.gz$' \
        | sort -r
    exit 0
fi

if [[ -n "${1:-}" ]]; then
    TARGET_DATE="$1"
    KEY="$TARGET_DATE.sql.gz"
else
    KEY=$(s3 ls "s3://$BUCKET/$PREFIX/" \
        | awk '{print $4}' \
        | grep -E '^[0-9]{4}-[0-9]{2}-[0-9]{2}\.sql\.gz$' \
        | sort -r | head -1)
    TARGET_DATE="${KEY%.sql.gz}"
fi

if [[ -z "$KEY" ]]; then
    echo "FEHLER: Kein Backup gefunden." >&2
    exit 1
fi

echo ""
echo "╔══════════════════════════════════════════════════════════╗"
echo "║               NAMIBWAY — DATENBANK-RESTORE               ║"
echo "╠══════════════════════════════════════════════════════════╣"
echo "║  Backup:    $KEY"
echo "║  Datenbank: $DB_DATABASE auf $DB_HOST:$DB_PORT"
echo "║                                                          ║"
echo "║  ACHTUNG: Die bestehende Datenbank wird VOLLSTÄNDIG      ║"
echo "║  überschrieben. Diese Aktion kann nicht rückgängig       ║"
echo "║  gemacht werden.                                         ║"
echo "╚══════════════════════════════════════════════════════════╝"
echo ""
read -rp "Jetzt wiederherstellen? Tippe 'ja' zum Bestätigen: " CONFIRM
if [[ "$CONFIRM" != "ja" ]]; then
    echo "Abgebrochen."
    exit 0
fi

TMPFILE=$(mktemp /tmp/namibway_restore_XXXXXX.sql.gz)
trap 'rm -f "$TMPFILE"' EXIT

echo ""
echo "[1/4] Lade Backup herunter: $KEY"
s3 cp "s3://$BUCKET/$PREFIX/$KEY" "$TMPFILE"
echo "      $(du -sh "$TMPFILE" | cut -f1) heruntergeladen"

echo "[2/4] Wartungsmodus aktivieren"
php "$APP_DIR/artisan" down --retry=5 2>/dev/null || true

echo "[3/4] Datenbank wiederherstellen"
export PGPASSWORD="$DB_PASSWORD"

psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" postgres <<SQL
SELECT pg_terminate_backend(pid)
FROM pg_stat_activity
WHERE datname = '$DB_DATABASE' AND pid <> pg_backend_pid();

DROP DATABASE IF EXISTS "$DB_DATABASE";
CREATE DATABASE "$DB_DATABASE" OWNER "$DB_USERNAME";
SQL

gunzip -c "$TMPFILE" | psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" "$DB_DATABASE"
echo "      Datenbank wiederhergestellt."

echo "[4/4] App-Cache leeren und Worker neu starten"
php "$APP_DIR/artisan" optimize:clear
php "$APP_DIR/artisan" migrate --force
supervisorctl restart namibway-horizon:* 2>/dev/null || true
php "$APP_DIR/artisan" up

echo ""
echo "✓ Wiederherstellung abgeschlossen (Backup: $TARGET_DATE)"
echo "  Bitte die App kurz durchtesten bevor du sie wieder freigibst."
