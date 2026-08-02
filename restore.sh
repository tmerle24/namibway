#!/bin/bash
# restore.sh — Stellt NamibWay nach einem Totalausfall auf einem neuen Server wieder her.
#
# Gegenstück zu deploy.sh: deploy.sh bringt den Code auf einen Server, setzt aber ein
# bereits befülltes .env voraus. Genau das (.env mit allen Secrets + der DB-Dump) liegt
# nirgends in Git — nur verschlüsselt im nächtlichen Backup auf R2 (config/backup.php,
# Schedule in routes/console.php). Dieses Skript holt beides zurück und übergibt dann
# an deploy.sh für den Rest (Composer, npm, Migrationen, Caches).
#
# Was dieses Skript NICHT automatisiert (siehe DEPLOYMENT.md "Disaster Recovery"):
#   - PostgreSQL/Redis-Installation, nginx-Vhost + SSL-Zertifikat, Supervisor-Config
#     für Horizon. Das sind Infrastruktur-Entscheidungen (DNS, Zertifikate), die man
#     nicht blind aus einem alten Server-Snapshot replizieren sollte — DEPLOYMENT.md
#     hat die Schritte dafür bereits dokumentiert.
#
# Voraussetzungen auf der Maschine, die dieses Skript ausführt (im Regelfall der neue
# Server selbst, per SSH):
#   - aws-cli   (S3-kompatibler Zugriff auf den R2-Backup-Bucket)
#   - 7z        (p7zip-full — zum Entschlüsseln des AES-256-verschlüsselten Backup-Zips;
#                das normale `unzip` beherrscht kein AES-256)
#   - psql-Client, falls die DB direkt aus diesem Skript restauriert werden soll
#
# Verwendung:
#   bash restore.sh
#
# Fragt alle Zugangsdaten interaktiv ab (nichts wird als Kommandozeilen-Argument
# übergeben, damit nichts in der Shell-History landet). Wer automatisieren will, kann
# die unten abgefragten Variablen vorher exportieren (R2_ACCESS_KEY, R2_SECRET_KEY,
# R2_ENDPOINT, R2_BACKUP_BUCKET, BACKUP_ARCHIVE_PASSWORD, APP_DIR, DB_HOST, DB_PORT,
# DB_DATABASE, DB_USERNAME, DB_PASSWORD) — das Skript fragt dann nicht erneut nach.
#
set -e

APP_NAME_IN_BACKUP="NamibWay"   # muss zu APP_NAME in der geretteten .env passen (config/backup.php: backup.name)
APP_DIR="${APP_DIR:-/var/www/namibway}"
WORK_DIR="$(mktemp -d)"

prompt_if_empty() {
    # $1 = Variablenname, $2 = Prompt-Text, $3 = "silent" für Passwort-Eingabe ohne Echo
    local var_name="$1" prompt_text="$2" silent="$3"
    if [ -z "${!var_name}" ]; then
        if [ "$silent" = "silent" ]; then
            read -r -s -p "$prompt_text: " value
            echo ""
        else
            read -r -p "$prompt_text: " value
        fi
        export "$var_name"="$value"
    fi
}

echo "═══ NamibWay Disaster Recovery ═══"
echo "Lädt das letzte verschlüsselte Backup aus R2, stellt .env + DB wieder her."
echo ""

for bin in aws 7z; do
    command -v "$bin" >/dev/null 2>&1 || { echo "❌ '$bin' fehlt — bitte installieren, siehe Kommentar am Skriptanfang."; exit 1; }
done

# ── 1/6 Zugangsdaten abfragen ───────────────────────────────────────
prompt_if_empty R2_ACCESS_KEY        "R2 Backup Access Key ID"
prompt_if_empty R2_SECRET_KEY        "R2 Backup Secret Access Key" silent
prompt_if_empty R2_ENDPOINT          "R2 Endpoint (z.B. https://<account-id>.r2.cloudflarestorage.com)"
prompt_if_empty R2_BACKUP_BUCKET     "R2 Backup-Bucket-Name (z.B. namibway-backups)"
prompt_if_empty BACKUP_ARCHIVE_PASSWORD "Backup-Archiv-Passwort (BACKUP_ARCHIVE_PASSWORD)" silent

export AWS_ACCESS_KEY_ID="$R2_ACCESS_KEY"
export AWS_SECRET_ACCESS_KEY="$R2_SECRET_KEY"

# ── 2/6 Neuestes Backup finden + herunterladen ──────────────────────
echo ""
echo "═══ 2/6 Suche neuestes Backup unter s3://$R2_BACKUP_BUCKET/$APP_NAME_IN_BACKUP/ ═══"
LATEST_KEY=$(aws s3api list-objects-v2 \
    --endpoint-url "$R2_ENDPOINT" \
    --bucket "$R2_BACKUP_BUCKET" \
    --prefix "${APP_NAME_IN_BACKUP}/" \
    --query 'reverse(sort_by(Contents, &LastModified))[0].Key' \
    --output text)

if [ -z "$LATEST_KEY" ] || [ "$LATEST_KEY" = "None" ]; then
    echo "❌ Kein Backup unter diesem Prefix gefunden. Bucket/Prefix/Zugangsdaten prüfen."
    exit 1
fi
echo "  → Neuestes Backup: $LATEST_KEY"

aws s3 cp "s3://$R2_BACKUP_BUCKET/$LATEST_KEY" "$WORK_DIR/backup.zip" --endpoint-url "$R2_ENDPOINT"

# ── 3/6 Entschlüsseln + entpacken ───────────────────────────────────
echo "═══ 3/6 Entpacke (AES-256-verschlüsselt) ═══"
7z x -p"$BACKUP_ARCHIVE_PASSWORD" -o"$WORK_DIR/extracted" "$WORK_DIR/backup.zip" >/dev/null

ENV_FILE=$(find "$WORK_DIR/extracted" -type f -name ".env" | head -n1)
DB_DUMP=$(find "$WORK_DIR/extracted" -type d -name "db-dumps" -exec find {} -type f \; | head -n1)

if [ -z "$ENV_FILE" ]; then
    echo "❌ Keine .env im Backup gefunden — Backup ist älter als der Wechsel auf 'backup:run' ohne --only-db?"
    exit 1
fi
if [ -z "$DB_DUMP" ]; then
    echo "❌ Kein DB-Dump im Backup gefunden."
    exit 1
fi
echo "  → .env gefunden: $ENV_FILE"
echo "  → DB-Dump gefunden: $DB_DUMP"

# ── 4/6 .env an Ort und Stelle bringen ──────────────────────────────
echo "═══ 4/6 .env nach $APP_DIR/.env ═══"
mkdir -p "$APP_DIR"
if [ -f "$APP_DIR/.env" ]; then
    echo "  ⚠ $APP_DIR/.env existiert bereits — wird nach .env.bak-restore gesichert."
    cp "$APP_DIR/.env" "$APP_DIR/.env.bak-restore"
fi
cp "$ENV_FILE" "$APP_DIR/.env"
echo "  → Geschrieben. APP_URL/DB_HOST ggf. anpassen, falls sich Hostname/Netzwerk auf dem neuen Server ändern."

# ── 5/6 Datenbank restaurieren ───────────────────────────────────────
echo "═══ 5/6 Datenbank restaurieren ═══"
# Aus der geretteten .env vorbelegen, damit man nicht alles doppelt eintippen muss.
DEFAULT_DB_HOST=$(grep -m1 '^DB_HOST=' "$ENV_FILE" | cut -d= -f2-)
DEFAULT_DB_PORT=$(grep -m1 '^DB_PORT=' "$ENV_FILE" | cut -d= -f2-)
DEFAULT_DB_DATABASE=$(grep -m1 '^DB_DATABASE=' "$ENV_FILE" | cut -d= -f2-)
DEFAULT_DB_USERNAME=$(grep -m1 '^DB_USERNAME=' "$ENV_FILE" | cut -d= -f2-)

prompt_if_empty DB_HOST     "DB Host [$DEFAULT_DB_HOST]"
DB_HOST="${DB_HOST:-$DEFAULT_DB_HOST}"
prompt_if_empty DB_PORT     "DB Port [$DEFAULT_DB_PORT]"
DB_PORT="${DB_PORT:-$DEFAULT_DB_PORT}"
prompt_if_empty DB_DATABASE "DB Name [$DEFAULT_DB_DATABASE]"
DB_DATABASE="${DB_DATABASE:-$DEFAULT_DB_DATABASE}"
prompt_if_empty DB_USERNAME "DB User [$DEFAULT_DB_USERNAME]"
DB_USERNAME="${DB_USERNAME:-$DEFAULT_DB_USERNAME}"
prompt_if_empty DB_PASSWORD "DB Passwort" silent

echo "  Voraussetzung: PostgreSQL läuft bereits und Datenbank/User '$DB_DATABASE'/'$DB_USERNAME'"
echo "  existieren schon (siehe DEPLOYMENT.md Schritt 'PostgreSQL + Redis installieren' /"
echo "  'Datenbank + Benutzer anlegen', falls noch nicht geschehen)."
read -r -p "  Jetzt restaurieren? [y/N] " CONFIRM_RESTORE
if [ "$CONFIRM_RESTORE" = "y" ] || [ "$CONFIRM_RESTORE" = "Y" ]; then
    case "$DB_DUMP" in
        *.gz)  GUNZIP_CMD="gunzip -c" ;;
        *)     GUNZIP_CMD="cat" ;;
    esac
    PGPASSWORD="$DB_PASSWORD" bash -c "$GUNZIP_CMD '$DB_DUMP' | psql -h '$DB_HOST' -p '$DB_PORT' -U '$DB_USERNAME' -d '$DB_DATABASE'"
    echo "  → DB restauriert."
else
    echo "  → Übersprungen. Dump liegt weiterhin unter: $DB_DUMP"
fi

# ── 6/6 Aufräumen + nächste Schritte ────────────────────────────────
rm -rf "$WORK_DIR"

echo ""
echo "✅ .env + DB sind wiederhergestellt."
echo ""
echo "Nächste Schritte:"
echo "  1. Falls auf diesem Server noch nicht geschehen: PostgreSQL/Redis installieren,"
echo "     nginx-Vhost + SSL, Supervisor-Config für Horizon — siehe DEPLOYMENT.md."
echo "  2. bash deploy.sh   (klont main falls nötig, Composer, npm-Build, Migrationen,"
echo "     Caches, Supervisor-Neustart — findet jetzt ein befülltes .env vor)."
echo "  3. APP_URL / DB_HOST in $APP_DIR/.env prüfen, falls sich Hostname oder DB-Standort"
echo "     gegenüber dem alten Server geändert haben, dann: php artisan config:cache"
