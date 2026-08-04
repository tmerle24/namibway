#!/bin/bash
# restore-listing-city-ids.sh — Repariert NUR listings.city_id aus einem älteren Backup.
#
# Hintergrund: `namibway:backfill-listing-cities` hatte einen Bug — ein "Windhoek"-
# Treffer im Freitext-Adressfeld (viele abgelegene Betriebe geben Windhoek als Post-/
# Rechnungsadresse an, unabhängig vom echten Standort) hat bestehende, meist
# region-korrekte city_id-Werte überschrieben. Das Script ist gefixt (siehe
# app/Console/Commands/BackfillListingCities.php), aber ein erneuter Lauf repariert
# bereits falsch gesetzte Zeilen NICHT — die stehen ja schon auf "Windhoek" und gelten
# damit als "bereits korrekt".
#
# Dieses Script holt sich das letzte Backup aus R2, das VOR dem fehlerhaften Lauf lag,
# spielt es in eine SCRATCH-Datenbank ein (nicht die Live-DB!), und kopiert daraus
# ausschließlich die Spalte listings.city_id in die Live-DB zurück — anhand der
# Listing-ID, per UPDATE ... FROM. Nichts anderes wird angefasst: keine anderen Spalten,
# keine anderen Tabellen, keine Listings, die es im Backup noch nicht gab.
#
# Voraussetzungen (wie restore.sh): aws-cli, 7z (p7zip-full), psql/createdb/dropdb.
# Läuft am einfachsten direkt auf dem Produktionsserver per SSH (dort schon vorhanden).
#
# Verwendung:
#   bash restore-listing-city-ids.sh              # zeigt nur, was sich ändern würde
#   bash restore-listing-city-ids.sh --apply       # schreibt die Änderungen wirklich
#
# Zugangsdaten werden interaktiv abgefragt (wie restore.sh) oder vorab exportiert:
#   R2_ACCESS_KEY, R2_SECRET_KEY, R2_ENDPOINT, R2_BACKUP_BUCKET,
#   BACKUP_ARCHIVE_PASSWORD, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
#
set -e

# aws-cli v2.13+ defaults to sending extra request-checksum headers on `s3
# cp`/`sync`/`mv` that Cloudflare R2's S3-compatible API rejects with a plain
# 400 on the HeadObject preflight — `aws s3api` calls (list-objects-v2 below)
# are unaffected since they don't send these headers, which is why the listing
# step can succeed while the download step 400s. Confirmed live 2026-08-04;
# REQUEST alone wasn't enough to unblock a retry the next day (fresh SSH
# session — or R2 also rejecting the response-checksum validation aws-cli
# does on the way back), so both directions are disabled here.
export AWS_REQUEST_CHECKSUM_CALCULATION=when_required
export AWS_RESPONSE_CHECKSUM_VALIDATION=when_required

# R2 only accepts its own jurisdiction hints as a "region" (wnam/enam/weur/
# eeur/apac/oc/auto) — aws-cli otherwise falls back to whatever
# AWS_DEFAULT_REGION or ~/.aws/config on the machine happens to have (e.g. a
# real AWS region set up for something unrelated, like SES), which R2 then
# rejects outright. "auto" is R2's own catch-all and always valid. An env var
# here overrides both an ambient AWS_DEFAULT_REGION and ~/.aws/config.
export AWS_DEFAULT_REGION=auto

APP_NAME_IN_BACKUP="NamibWay"
APPLY=false
[ "$1" = "--apply" ] && APPLY=true

WORK_DIR="$(mktemp -d)"
SCRATCH_DB="namibway_city_id_restore_scratch"

prompt_if_empty() {
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

cleanup() {
    rm -rf "$WORK_DIR"
    if [ -n "$DB_HOST" ] && [ -n "$DB_PASSWORD" ]; then
        PGPASSWORD="$DB_PASSWORD" dropdb -h "$DB_HOST" -p "${DB_PORT:-5432}" -U "$DB_USERNAME" --if-exists "$SCRATCH_DB" 2>/dev/null || true
        # Real (non-TEMP) table on the LIVE db — a psql TEMP table only lives for one
        # connection, but this script queries it across several separate `psql -c`
        # calls, so it has to be a real table, explicitly dropped here every time
        # (success, failure, or Ctrl-C) so nothing scratch-y is ever left behind.
        if [ -n "$DB_DATABASE" ]; then
            PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "${DB_PORT:-5432}" -U "$DB_USERNAME" -d "$DB_DATABASE" \
                -c "DROP TABLE IF EXISTS _listing_city_id_restore;" >/dev/null 2>&1 || true
        fi
    fi
}
trap cleanup EXIT

echo "═══ NamibWay: listings.city_id aus Backup wiederherstellen ═══"
if [ "$APPLY" = false ]; then
    echo "(Trockenlauf — zeigt nur die Anzahl betroffener Zeilen. Mit --apply wirklich schreiben.)"
fi
echo ""

for bin in aws 7z psql createdb dropdb; do
    command -v "$bin" >/dev/null 2>&1 || { echo "❌ '$bin' fehlt."; exit 1; }
done

# ── 1/5 Zugangsdaten + Backup-Auswahl ───────────────────────────────
prompt_if_empty R2_ACCESS_KEY           "R2 Backup Access Key ID"
prompt_if_empty R2_SECRET_KEY           "R2 Backup Secret Access Key" silent
prompt_if_empty R2_ENDPOINT             "R2 Endpoint (z.B. https://<account-id>.r2.cloudflarestorage.com)"
prompt_if_empty R2_BACKUP_BUCKET        "R2 Backup-Bucket-Name (z.B. namibway-backups)"
prompt_if_empty BACKUP_ARCHIVE_PASSWORD "Backup-Archiv-Passwort" silent

export AWS_ACCESS_KEY_ID="$R2_ACCESS_KEY"
export AWS_SECRET_ACCESS_KEY="$R2_SECRET_KEY"

echo ""
echo "═══ 2/5 Verfügbare Backups (neueste zuerst) ═══"
aws s3api list-objects-v2 \
    --endpoint-url "$R2_ENDPOINT" \
    --bucket "$R2_BACKUP_BUCKET" \
    --prefix "${APP_NAME_IN_BACKUP}/" \
    --query 'reverse(sort_by(Contents, &LastModified))[].{Key:Key,Modified:LastModified}' \
    --output table

echo ""
echo "Wähle das letzte Backup, dessen Zeitstempel VOR deinem SSH-Lauf von"
echo "'namibway:backfill-listing-cities' liegt."
prompt_if_empty BACKUP_KEY "Vollständiger Key des zu verwendenden Backups (z.B. ${APP_NAME_IN_BACKUP}/2026-08-03-...zip)"

aws s3 cp "s3://$R2_BACKUP_BUCKET/$BACKUP_KEY" "$WORK_DIR/backup.zip" --endpoint-url "$R2_ENDPOINT"

# ── 3/5 Entschlüsseln + DB-Dump in Scratch-DB laden ─────────────────
echo ""
echo "═══ 3/5 Entpacke + lade in Scratch-Datenbank '$SCRATCH_DB' ═══"
7z x -p"$BACKUP_ARCHIVE_PASSWORD" -o"$WORK_DIR/extracted" "$WORK_DIR/backup.zip" >/dev/null

DB_DUMP=$(find "$WORK_DIR/extracted" -type d -name "db-dumps" -exec find {} -type f \; | head -n1)
if [ -z "$DB_DUMP" ]; then
    echo "❌ Kein DB-Dump im gewählten Backup gefunden."
    exit 1
fi
echo "  → DB-Dump: $DB_DUMP"

ENV_FILE=$(find "$WORK_DIR/extracted" -type f -name ".env" | head -n1)
DEFAULT_DB_HOST=$(grep -m1 '^DB_HOST=' "$ENV_FILE" 2>/dev/null | cut -d= -f2-)
DEFAULT_DB_PORT=$(grep -m1 '^DB_PORT=' "$ENV_FILE" 2>/dev/null | cut -d= -f2-)
DEFAULT_DB_USERNAME=$(grep -m1 '^DB_USERNAME=' "$ENV_FILE" 2>/dev/null | cut -d= -f2-)

echo ""
echo "Zugangsdaten für die LIVE-Produktions-DB (dieselbe, die repariert werden soll):"
prompt_if_empty DB_HOST     "DB Host [$DEFAULT_DB_HOST]"
DB_HOST="${DB_HOST:-$DEFAULT_DB_HOST}"
prompt_if_empty DB_PORT     "DB Port [$DEFAULT_DB_PORT]"
DB_PORT="${DB_PORT:-$DEFAULT_DB_PORT}"
prompt_if_empty DB_DATABASE "Live DB Name"
prompt_if_empty DB_USERNAME "DB User [$DEFAULT_DB_USERNAME]"
DB_USERNAME="${DB_USERNAME:-$DEFAULT_DB_USERNAME}"
prompt_if_empty DB_PASSWORD "DB Passwort" silent

PGPASSWORD="$DB_PASSWORD" dropdb -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" --if-exists "$SCRATCH_DB"
PGPASSWORD="$DB_PASSWORD" createdb -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" "$SCRATCH_DB"

case "$DB_DUMP" in
    *.gz) GUNZIP_CMD="gunzip -c" ;;
    *)    GUNZIP_CMD="cat" ;;
esac
PGPASSWORD="$DB_PASSWORD" bash -c "$GUNZIP_CMD '$DB_DUMP' | psql -q -h '$DB_HOST' -p '$DB_PORT' -U '$DB_USERNAME' -d '$SCRATCH_DB'" >/dev/null
echo "  → Backup-Dump vollständig in '$SCRATCH_DB' geladen (nur zum Auslesen, wird am Ende gelöscht)."

# ── 4/5 city_id-Mapping extrahieren und in die Live-DB kopieren ────
echo ""
echo "═══ 4/5 city_id aus dem Backup extrahieren ═══"
PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$SCRATCH_DB" \
    -c "\copy (SELECT id, city_id FROM listings) TO '$WORK_DIR/city_ids.csv' WITH CSV" >/dev/null
echo "  → $(wc -l < "$WORK_DIR/city_ids.csv") Listings im Backup gefunden."

PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$DB_DATABASE" <<SQL
DROP TABLE IF EXISTS _listing_city_id_restore;
CREATE TABLE _listing_city_id_restore (id bigint, city_id bigint);
\copy _listing_city_id_restore FROM '$WORK_DIR/city_ids.csv' WITH CSV
SQL

echo ""
echo "═══ 5/5 Vergleich Live vs. Backup ═══"
DIFF_COUNT=$(PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$DB_DATABASE" -tAc "
    SELECT count(*) FROM listings l
    JOIN _listing_city_id_restore b ON b.id = l.id
    WHERE l.city_id IS DISTINCT FROM b.city_id;
")
echo "  → $DIFF_COUNT Listing(s) unterscheiden sich zwischen Live und Backup."

PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$DB_DATABASE" -c "
    SELECT l.id, l.name, l.city_id AS live_city_id, b.city_id AS backup_city_id
    FROM listings l
    JOIN _listing_city_id_restore b ON b.id = l.id
    WHERE l.city_id IS DISTINCT FROM b.city_id
    ORDER BY l.id
    LIMIT 30;
"

if [ "$APPLY" = true ]; then
    PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$DB_DATABASE" -c "
        UPDATE listings l SET city_id = b.city_id
        FROM _listing_city_id_restore b
        WHERE l.id = b.id AND l.city_id IS DISTINCT FROM b.city_id;
    "
    echo ""
    echo "✅ $DIFF_COUNT Listing(s) auf ihre city_id aus dem Backup zurückgesetzt."
else
    echo ""
    echo "Nichts geschrieben (Trockenlauf). Zum Anwenden erneut mit --apply aufrufen."
fi
