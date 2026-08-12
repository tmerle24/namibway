#!/bin/bash
# backup.sh — Tägliches Datenbank-Backup nach Cloudflare R2
#
# Einrichtung auf dem Server:
#   1. Skript ausführbar machen: chmod +x /var/www/namibway/backup.sh
#   2. Cron einrichten (als root oder www-data):
#      echo "0 3 * * * www-data /var/www/namibway/backup.sh >> /var/log/namibway-backup.log 2>&1" \
#        > /etc/cron.d/namibway-backup
#
# Backup-Ziel: namibway-backups/namibway/db/YYYY-MM-DD.sql.gz
# Aufbewahrung: 30 Tage
#
set -euo pipefail

APP_DIR="/var/www/namibway"
ENV_FILE="$APP_DIR/.env"
PREFIX="namibway/db"
RETAIN_DAYS=30
DATE=$(date +%Y-%m-%d)
TMPFILE=$(mktemp /tmp/namibway_backup_XXXXXX.sql.gz)

env_get() {
    grep -m1 "^${1}=" "$ENV_FILE" | cut -d= -f2- | tr -d '"' | tr -d "'"
}

DB_HOST=$(env_get DB_HOST);     DB_HOST=${DB_HOST:-127.0.0.1}
DB_PORT=$(env_get DB_PORT);     DB_PORT=${DB_PORT:-5432}
DB_DATABASE=$(env_get DB_DATABASE)
DB_USERNAME=$(env_get DB_USERNAME)
DB_PASSWORD=$(env_get DB_PASSWORD)

# Backup-Bucket: eigener Key wenn gesetzt, sonst Haupt-R2-Key wiederverwenden
BUCKET=$(env_get CLOUDFLARE_R2_BACKUP_BUCKET); BUCKET=${BUCKET:-namibway-backups}
R2_KEY=$(env_get CLOUDFLARE_R2_BACKUP_ACCESS_KEY_ID)
R2_KEY=${R2_KEY:-$(env_get CLOUDFLARE_R2_ACCESS_KEY_ID)}
R2_SECRET=$(env_get CLOUDFLARE_R2_BACKUP_SECRET_ACCESS_KEY)
R2_SECRET=${R2_SECRET:-$(env_get CLOUDFLARE_R2_SECRET_ACCESS_KEY)}
R2_ENDPOINT=$(env_get CLOUDFLARE_R2_ENDPOINT)

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starte Backup: $DB_DATABASE → s3://$BUCKET/$PREFIX/$DATE.sql.gz"

PGPASSWORD="$DB_PASSWORD" pg_dump \
    -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" "$DB_DATABASE" \
    | gzip > "$TMPFILE"

DUMP_SIZE=$(du -sh "$TMPFILE" | cut -f1)
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Dump erstellt: $DUMP_SIZE"

AWS_ACCESS_KEY_ID="$R2_KEY" \
AWS_SECRET_ACCESS_KEY="$R2_SECRET" \
aws s3 cp "$TMPFILE" \
    "s3://$BUCKET/$PREFIX/$DATE.sql.gz" \
    --endpoint-url "$R2_ENDPOINT" \
    --region auto \
    --no-progress

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Upload abgeschlossen"

rm -f "$TMPFILE"

CUTOFF=$(date -d "$RETAIN_DAYS days ago" +%Y-%m-%d 2>/dev/null \
    || date -v-${RETAIN_DAYS}d +%Y-%m-%d)

AWS_ACCESS_KEY_ID="$R2_KEY" \
AWS_SECRET_ACCESS_KEY="$R2_SECRET" \
aws s3 ls "s3://$BUCKET/$PREFIX/" \
    --endpoint-url "$R2_ENDPOINT" --region auto \
    | awk '{print $4}' \
    | grep -E '^[0-9]{4}-[0-9]{2}-[0-9]{2}\.sql\.gz$' \
    | while read -r KEY; do
        FILE_DATE="${KEY%.sql.gz}"
        if [[ "$FILE_DATE" < "$CUTOFF" ]]; then
            AWS_ACCESS_KEY_ID="$R2_KEY" \
            AWS_SECRET_ACCESS_KEY="$R2_SECRET" \
            aws s3 rm "s3://$BUCKET/$PREFIX/$KEY" \
                --endpoint-url "$R2_ENDPOINT" --region auto
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Altes Backup gelöscht: $KEY"
        fi
    done

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Backup erfolgreich abgeschlossen"
