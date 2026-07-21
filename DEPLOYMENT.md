# NamibWay – Setup & Deployment

Diese Doku beschreibt, wie man NamibWay lokal zum Laufen bringt und auf einem Produktionsserver installiert.

## Inhalt

- [Voraussetzungen](#voraussetzungen)
- [Lokale Entwicklung](#lokale-entwicklung)
- [Produktions-Deployment](#produktions-deployment)
- [Nützliche Befehle](#nützliche-befehle)
- [Troubleshooting](#troubleshooting)

---

## Voraussetzungen

| Tool | Version | Zweck |
|---|---|---|
| PHP | ≥ 8.3 | Laravel Backend |
| Composer | ≥ 2.x | PHP-Abhängigkeiten |
| Node.js | ≥ 18 | Frontend-Build (Vite) |
| npm | ≥ 9 | JS-Abhängigkeiten |
| PostgreSQL | ≥ 15 | Datenbank |
| Redis | ≥ 6 | Queue-Backend (Horizon) |

Optional für Produktion:
- **Docker** — lokal für Postgres + Redis (siehe `docker-compose.yml`), auf dem Server nicht nötig falls beide direkt installiert sind
- **nginx** — Webserver vor Laravel
- **Supervisor** oder systemd — für den Horizon-Prozess (Queue-Worker)

---

## Lokale Entwicklung

### 1. Projekt klonen & Abhängigkeiten installieren

```bash
git clone git@github.com:tmerle24/namibway.git
cd namibway

composer install
npm install
```

### 2. Umgebungsvariablen einrichten

```bash
cp .env.example .env
php artisan key:generate
```

### 3. Postgres + Redis lokal starten (Docker)

```bash
docker-compose up -d
```

Das startet einen Postgres-Container mit den Zugangsdaten aus `.env` (`namibway` / `namibway`) sowie einen Redis-Container auf Port 6379 (Queue-Backend für Horizon).

### 4. Migrieren + seeden

```bash
php artisan migrate
php artisan db:seed   # optional, legt Demo-Listings + Test-User an
```

### 5. Filament-Admin-Zugang anlegen

```bash
php artisan make:filament-user
```

`make:filament-user` legt nur den Login an — Panel-Zugriff wird separat über `users.is_admin`
gesteuert (`App\Models\User::canAccessPanel()`, siehe auch Horizons `viewHorizon`-Gate).
Danach also:

```bash
php artisan tinker --execute="App\Models\User::where('email', 'DEINE-EMAIL')->update(['is_admin' => true]);"
```

### 6. Alles starten

```bash
composer run dev
```

Das startet parallel: Laravel-Server, Vite-Dev-Server, Queue-Listener, Log-Tail. Für Horizon
statt des einfachen Queue-Listeners separat `php artisan horizon` laufen lassen.

App läuft dann unter **http://localhost:8000**, Admin unter **http://localhost:8000/admin**,
Horizon-Dashboard unter **http://localhost:8000/horizon** (lokal ohne Login-Zwang, siehe
`app/Providers/HorizonServiceProvider.php`).

---

## Produktions-Deployment

Nginx, DNS und SSL-Zertifikat werden hier als bereits eingerichtet vorausgesetzt.

### 1. PostgreSQL + Redis installieren (falls noch nicht vorhanden)

```bash
sudo apt update
sudo apt install postgresql postgresql-contrib redis-server php-redis
sudo systemctl enable --now postgresql
sudo systemctl enable --now redis-server
redis-cli ping   # sollte PONG zurückgeben
```

### 2. Datenbank + Benutzer anlegen

```bash
sudo -u postgres psql
```

Im `psql`-Prompt:

```sql
CREATE DATABASE namibway;
CREATE USER namibway WITH ENCRYPTED PASSWORD 'ein-sicheres-passwort';
GRANT ALL PRIVILEGES ON DATABASE namibway TO namibway;
ALTER DATABASE namibway OWNER TO namibway;
\q
```

Ab PostgreSQL 15 muss der Benutzer zusätzlich Rechte auf das `public`-Schema bekommen:

```bash
sudo -u postgres psql -d namibway -c "GRANT ALL ON SCHEMA public TO namibway;"
```

Verbindung testen:

```bash
psql -h 127.0.0.1 -U namibway -d namibway
```

### 3. Erstinstallation über das Deploy-Skript

```bash
scp deploy_namibway.sh user@server:~/
ssh user@server
bash deploy_namibway.sh
```

Beim ersten Lauf ist `/var/www/namibway` noch kein Git-Repo — das Skript klont dann automatisch, legt `.env` aus `.env.example` an und **bricht danach bewusst ab**, damit `.env` befüllt werden kann.

### 4. `.env` befüllen

```bash
cd /var/www/namibway
nano .env
```

Wichtige Produktions-Werte:

```env
APP_NAME=NamibWay
APP_ENV=production
APP_DEBUG=false
APP_URL=https://namibway.com

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=namibway
DB_USERNAME=namibway
DB_PASSWORD=ein-sicheres-passwort

SESSION_DRIVER=database
QUEUE_CONNECTION=redis
CACHE_STORE=database

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=smtp
MAIL_HOST=...

ANTHROPIC_API_KEY=sk-ant-...

AWS_ACCESS_KEY_ID=...        # Cloudflare R2
AWS_SECRET_ACCESS_KEY=...
AWS_BUCKET=...
AWS_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
AWS_USE_PATH_STYLE_ENDPOINT=true

# Social Login — Client-ID/Secret aus der jeweiligen Developer Console
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"

FACEBOOK_CLIENT_ID=...
FACEBOOK_CLIENT_SECRET=...
FACEBOOK_REDIRECT_URI="${APP_URL}/auth/facebook/callback"
```

> Google: OAuth-Client unter [Google Cloud Console](https://console.cloud.google.com/apis/credentials)
> anlegen, Redirect-URI exakt wie oben eintragen.
> Facebook: App unter [Meta for Developers](https://developers.facebook.com/apps) anlegen,
> Produkt „Facebook Login" hinzufügen, gleiche Redirect-URI eintragen.

### 5. Deploy-Skript erneut ausführen

```bash
bash deploy_namibway.sh
```

Führt jetzt Composer, npm-Build, Migrationen, Caching und Rechte-Setzen aus.

### 6. Ersten Admin-User anlegen (Filament-Zugang)

```bash
cd /var/www/namibway
php artisan make:filament-user
```

`make:filament-user` legt nur den Login an — Panel- **und** Horizon-Zugriff hängen zusätzlich
an `users.is_admin` (siehe `App\Models\User::canAccessPanel()` und
`app/Providers/HorizonServiceProvider.php`). Danach also:

```bash
php artisan tinker --execute="App\Models\User::where('email', 'DEINE-EMAIL')->update(['is_admin' => true]);"
```

Ohne diesen Schritt bekommt man beim Login "These credentials do not match our records." —
das Passwort ist korrekt, nur `is_admin` fehlt noch.

### 7. Horizon dauerhaft laufen lassen (Supervisor)

`/etc/supervisor/conf.d/namibway-horizon.conf`:

```ini
[program:namibway-horizon]
process_name=%(program_name)s
command=php /var/www/namibway/artisan horizon
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/namibway/storage/logs/horizon.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start namibway-horizon:*
```

Dashboard danach unter `https://www.namibway.com/horizon` (nur für Nutzer mit `is_admin = true`).

`deploy_namibway.sh` startet den Supervisor-Prozess `namibway-horizon` bei jedem Deploy automatisch neu
(`QUEUE_WORKER_NAME` im Skript-Kopf) — passt zum obigen Supervisor-Programmnamen.

### 8. Cronjob für den Laravel Scheduler

```bash
crontab -e
```

```cron
* * * * * cd /var/www/namibway && php artisan schedule:run >> /dev/null 2>&1
```

### 9. Rechte prüfen (macht das Deploy-Skript bereits automatisch)

```bash
sudo chown -R $(whoami):www-data /var/www/namibway
sudo chmod -R 775 /var/www/namibway/storage /var/www/namibway/bootstrap/cache
```

---

## Nützliche Befehle

```bash
bash deploy_namibway.sh                # volles Update/Deploy
bash deploy_namibway.sh --no-npm       # Deploy ohne npm-Build
bash deploy_namibway.sh --no-migrate   # Deploy ohne Migrationen
php artisan make:filament-user         # neuen Admin-User anlegen
php artisan migrate:status             # Migrationsstatus prüfen
docker-compose up -d                   # lokal: Postgres + Redis starten
php artisan horizon                    # lokal: Horizon im Vordergrund starten
```

---

## Troubleshooting

**Postgres-Verbindung schlägt fehl (`SQLSTATE[08006]`):**
```bash
sudo systemctl status postgresql
psql -h 127.0.0.1 -U namibway -d namibway   # manuell testen
```
Prüfen, ob in `pg_hba.conf` `md5`/`scram-sha-256` für lokale Verbindungen erlaubt ist (nicht `peer`).

**Migrations schlagen fehl:**
```bash
php artisan migrate:status
php artisan migrate --force
```

**Filament-Admin lädt keine Assets / 419-Fehler:**
```bash
php artisan config:clear
php artisan view:clear
php artisan filament:optimize-clear
```

**Horizon verarbeitet keine Jobs:**
```bash
sudo supervisorctl status namibway-horizon:*
tail -f /var/www/namibway/storage/logs/horizon.log
redis-cli ping                          # Redis erreichbar?
php artisan horizon:status              # sollte "active" sein
```

**"These credentials do not match our records." beim Admin-Login (Passwort ist aber richtig):**
`is_admin` fehlt für diesen User — siehe Schritt 6 oben (`canAccessPanel()` verweigert sonst den Zugriff).
```bash
php artisan tinker --execute="App\Models\User::where('email', 'DEINE-EMAIL')->update(['is_admin' => true]);"
```
