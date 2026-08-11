# NamibWay – Setup & Deployment

Diese Doku beschreibt, wie man NamibWay lokal zum Laufen bringt und auf einem Produktionsserver installiert.

## Inhalt

- [Voraussetzungen](#voraussetzungen)
- [Lokale Entwicklung](#lokale-entwicklung)
- [Produktions-Deployment](#produktions-deployment)
- [Buchungs-Subdomain](#buchungs-subdomain)
- [Bild-Thumbnails](#bild-thumbnails)
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

## Disaster Recovery — Server-Totalausfall

Nightly Backup (`config/backup.php`, Schedule in `routes/console.php`) sichert **DB-Dump +
`.env`** verschlüsselt (AES-256, `BACKUP_ARCHIVE_PASSWORD`) nach R2 (`r2-backups`-Disk,
non-public Bucket). Der Rest ist bereits anderswo durabel: Code in GitHub (`main`), Medien
in Cloudflare R2 (`r2`-Disk). `.env` ist damit die einzige Sache, die *nur* auf dem Server
existiert — ohne sie im Backup wäre ein Server-Verlust selbst mit DB-Dump in der Hand nicht
restaurierbar (alle Secrets: DB-Passwort, `ANTHROPIC_API_KEY`, R2-/OAuth-Credentials,
`APP_KEY`).

**Nicht** über das Backup abgedeckt (bewusst — Infrastruktur-Entscheidungen, keine Daten):
PostgreSQL/Redis-Installation, nginx-Vhost + SSL-Zertifikat, Supervisor-Config für Horizon.
Die Schritte dafür stehen oben unter "Produktions-Deployment" 1–2 und 7.

### Restore auf neuem Server

```bash
# 1. PostgreSQL + Redis installieren, DB + User anlegen — siehe oben Schritte 1–2
# 2. .env + DB aus dem letzten Backup zurückholen:
scp restore.sh user@neuer-server:~/
ssh user@neuer-server
bash restore.sh
# fragt R2-Zugangsdaten, Backup-Passwort und DB-Zugangsdaten interaktiv ab
# (Werte vorab exportieren, um die Abfrage zu überspringen — siehe Kommentar im Skript)

# 3. Code + Dependencies + Migrationen (findet jetzt ein befülltes .env vor):
bash deploy.sh

# 4. nginx-Vhost + SSL, Supervisor-Config für Horizon — siehe oben Schritte 7 + DNS/SSL
```

`restore.sh` sichert ein eventuell schon vorhandenes `.env` vor dem Überschreiben nach
`.env.bak-restore`. `APP_URL`/`DB_HOST` in der wiederhergestellten `.env` prüfen, falls sich
Hostname oder DB-Standort gegenüber dem alten Server geändert haben.

---

## Buchungs-Subdomain

Das Partner-Panel — das Buchungssystem, das Lodges selbst bedienen — kann unter einer
eigenen Adresse laufen (`booking.namibway.com`), statt wie ein Unterverzeichnis der
Reise-Website auszusehen. Das ist ein Verkaufsargument, kein Detail: das Panel wird als
eigenes Produkt angeboten.

**Ohne gesetzte Variable ändert sich nichts.** `BOOKING_PANEL_DOMAIN` leer heißt: Panel
antwortet wie bisher unter `/partner` auf dem Host, der die App ausliefert. Lokale
Entwicklung und CI brauchen also keinen Hosts-Eintrag und keine Konfiguration.

### Voraussetzungen auf dem Server (vor dem Setzen der Variable)

1. **DNS-Record bei OVH**, nicht bei Cloudflare. Die DNS von namibway.com liegt bei OVH —
   genau das hat am 2026-08-09 `cdn.namibway.com` zerlegt, als eine Cloudflare-Adresse
   konfiguriert wurde, die es nie gab. Erst den A-Record anlegen, dann die Variable setzen.
2. **Zertifikat**, das den Host abdeckt (`certbot --nginx -d booking.namibway.com`).
3. **nginx-Server-Block** für den Host, der auf dasselbe `public/`-Verzeichnis und denselben
   PHP-FPM-Socket zeigt wie namibway.com. Es ist dieselbe Anwendung, nicht eine zweite
   Installation.

### Der nginx-vhost

`/etc/nginx/sites-available/booking.namibway.com`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name booking.namibway.com;

    # Dasselbe Verzeichnis wie namibway.com. Kein zweites Deployment, kein
    # zweites git-Repo — eine Installation, zwei Adressen.
    root /var/www/namibway/public;

    index index.php;
    charset utf-8;

    # Muss zum Hauptvhost passen: im Panel werden Zimmerfotos hochgeladen.
    client_max_body_size 32M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    # .env, .git & Co. — nur .well-known bleibt erreichbar, das braucht Certbot.
    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

**Den `fastcgi_pass` nicht raten.** Auf diesem Server laufen `php8.3-fpm` und `php8.4-fpm`
gleichzeitig (siehe Deploy-Zwischenfall 2026-08-02), und der Panel-vhost muss auf denselben
Socket zeigen wie der Hauptvhost, sonst läuft dieselbe Anwendung auf zwei PHP-Versionen:

```bash
grep fastcgi_pass /etc/nginx/sites-available/namibway.com
```

Bewusst nur ein reiner Port-80-Block — **kein** eigener `listen 443`-Teil und keine manuelle
Weiterleitung. Beides ergänzt Certbot im nächsten Schritt selbst.

### Aktivieren und Zertifikat

Reihenfolge ist wichtig: Certbot prüft die Domain über den laufenden vhost, der A-Record muss
also stehen (ist er) und nginx den Host bereits kennen.

```bash
sudo ln -s /etc/nginx/sites-available/booking.namibway.com /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

# Erreichbarkeit vor dem Zertifikat prüfen — ein 301/200 hier, kein Timeout:
curl -I http://booking.namibway.com

sudo certbot --nginx -d booking.namibway.com
```

Certbot schreibt danach den `listen 443 ssl`-Block mit den Zertifikatspfaden in dieselbe Datei
und richtet die Weiterleitung von 80 auf 443 ein. Kurz prüfen:

```bash
sudo cat /etc/nginx/sites-available/booking.namibway.com | grep -E "listen|ssl_certificate|return 301"
```

Fehlt die Weiterleitung (ältere Certbot-Versionen fragen sie interaktiv ab):
`sudo certbot install --nginx -d booking.namibway.com`.

Die Erneuerung läuft über den Certbot-Timer mit, der für namibway.com schon existiert —
nichts einzurichten. Kontrolle: `sudo certbot renew --dry-run`.

### Erst danach die Variable setzen

```bash
sudo -u www-data php /var/www/namibway/artisan down   # optional, dauert Sekunden
# .env bearbeiten (siehe unten), dann:
cd /var/www/namibway && php artisan config:cache && sudo systemctl reload php8.4-fpm
sudo -u www-data php /var/www/namibway/artisan up
```

**`SESSION_DOMAIN` zu ändern meldet alle angemeldeten Nutzer einmalig ab** — die bestehenden
Cookies gelten für den alten Wert und werden nicht mehr gelesen. Einmaliger Effekt, aber
besser abends als vormittags.

### `.env`

```
BOOKING_PANEL_DOMAIN=booking.namibway.com

# Muss mit einem führenden Punkt gesetzt werden, sonst gilt das Session-Cookie nur für
# genau einen der beiden Hosts und ein Login auf namibway.com trägt nicht nach
# booking.namibway.com (und umgekehrt).
SESSION_DOMAIN=.namibway.com
```

`APP_URL` bleibt `https://namibway.com`. Die Weiterleitung von `/partner` auf den neuen
Host wird für genau diesen Host registriert.

### Was danach passiert

- `booking.namibway.com/` → Weiterleitung auf `/partner`
- `booking.namibway.com/partner/...` → das Panel
- `namibway.com/partner/...` → Weiterleitung auf denselben Pfad unter
  `booking.namibway.com`, damit bereits verschickte Links und Lesezeichen weiter
  funktionieren
- `namibway.com/partner/inquiries/{id}/confirm|cancel` → **bleibt, wo es ist.** Eine
  URL-Signatur deckt den Host mit ab; eine Weiterleitung würde genau die Signatur
  ungültig machen, die den Link autorisiert. Diese Ausnahme steht als Muster in
  `routes/partner.php` und nicht in der Reihenfolge der Routen.

Nach der Änderung `php artisan config:cache` bzw. `deploy.sh` laufen lassen und **einen
echten Login auf der Subdomain testen** — eine Routenliste beweist nichts über Cookies.

---

## Customer websites (`*.websites.namibway.com`)

> Written in English, the project language (`CLAUDE.md` → Language). The rest of this
> file predates that rule.

Customer websites are served by the same installation, resolved by host — see
`config/sites.php` and `App\Http\Middleware\ResolveSiteHost`. Nothing happens until
`SITES_HOST_SUFFIX` is set; without it a site simply has no host and is reviewed at
`/_sites/{slug}`, which is also how local development and CI run.

### Step 1 — the certificate, and why this one is different

The booking subdomain was issued with `certbot --nginx`, which proves ownership by
answering an HTTP request on that exact host. **That cannot work here.** A wildcard
covers hosts that do not exist yet, so there is nothing to answer on; Let's Encrypt
only issues a wildcard against a DNS-01 challenge, which means writing a TXT record
into the zone. namibway.com's DNS is at OVH, so that needs OVH's API.

Create an OVH API token at <https://eu.api.ovh.com/createToken/> with these rights —
nothing wider, this credential can edit the whole zone:

```
GET     /domain/zone/*
POST    /domain/zone/*
PUT     /domain/zone/*
DELETE  /domain/zone/*
```

Then, on the server:

```bash
sudo apt install -y python3-certbot-dns-ovh

sudo install -d -m 700 /root/.secrets
sudo tee /root/.secrets/ovh.ini >/dev/null <<'INI'
dns_ovh_endpoint = ovh-eu
dns_ovh_application_key = APPLICATION_KEY
dns_ovh_application_secret = APPLICATION_SECRET
dns_ovh_consumer_key = CONSUMER_KEY
INI
sudo chmod 600 /root/.secrets/ovh.ini

sudo certbot certonly \
  --dns-ovh --dns-ovh-credentials /root/.secrets/ovh.ini \
  --dns-ovh-propagation-seconds 60 \
  -d '*.websites.namibway.com'
```

The quotes around the `-d` argument are not optional: an unquoted `*` is expanded by
the shell against the current directory.

Note the lineage name certbot prints — it is the name without the asterisk, so the
paths below are `/etc/letsencrypt/live/websites.namibway.com/`. Confirm rather than
assume:

```bash
sudo certbot certificates | grep -A3 websites
```

Renewal runs on the existing certbot timer and re-reads the credentials file on its
own — nothing to schedule. Check it once: `sudo certbot renew --dry-run`.

### Step 2 — the vhost

Same directory as the main site: one installation, many addresses — exactly like the
booking subdomain above. Certbot does **not** write the TLS block for a certificate it
issued with `certonly`, so unlike the booking vhost this file carries it itself.

```bash
sudo tee /etc/nginx/sites-available/websites.namibway.com >/dev/null <<'NGINX'
server {
    listen 80;
    listen [::]:80;
    server_name *.websites.namibway.com;

    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name *.websites.namibway.com;

    ssl_certificate     /etc/letsencrypt/live/websites.namibway.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/websites.namibway.com/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

    root /var/www/namibway/public;
    index index.php;
    charset utf-8;

    # A customer's photographs, not a lodge's room list — but the same bucket
    # and the same uploader, so keep this in step with the main vhost.
    client_max_body_size 32M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }

    # Deliberately NO `location = /robots.txt` here, unlike the other vhosts.
    # Customer sites answer robots.txt from PHP, because a draft has to say
    # Disallow and a published site has to name its sitemap — the static file in
    # public/ says neither, and it would win.

    error_page 404 /index.php;

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX

sudo ln -s /etc/nginx/sites-available/websites.namibway.com /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

**`fastcgi_pass`: do not guess it.** This server runs both `php8.3-fpm` and
`php8.4-fpm` — see the warning under the booking subdomain and copy whichever socket
the main vhost actually uses:

```bash
grep fastcgi_pass /etc/nginx/sites-available/namibway.com
```

### Step 3 — check it before switching the application on

The host resolves and TLS is valid before any site exists. A 404 from Laravel here is
the correct answer — it means the request reached PHP and no site claims that host:

```bash
curl -I https://anything.websites.namibway.com
```

A TLS error at this point means the certificate or the paths are wrong. A connection
refused or a timeout means DNS or the vhost.

### Step 4 — then set the variable

```bash
cd /var/www/namibway
sudo -u www-data nano .env          # add the line below
php artisan config:cache
sudo systemctl reload php8.4-fpm
```

```
SITES_HOST_SUFFIX=websites.namibway.com
```

No `SESSION_DOMAIN` change, unlike the booking subdomain: customer sites carry no
session at all, so there is nothing to share and nobody gets logged out.

Sites created afterwards get `{slug}.websites.namibway.com` as their host. A site
created before that has a null host and keeps working at `/_sites/{slug}`; giving it
an address is an `UPDATE` on one column, not a regeneration:

```bash
php artisan tinker --execute="App\Models\Site::where('slug','x')->update(['host'=>'x.websites.namibway.com']);"
```

### Step 5 — end to end

```bash
php artisan sites:generate <listing-slug>     # prints the address and the draft token
```

Open the printed URL. A draft answers only with its `?preview=` token, on its own host
as much as on `/_sites/{slug}` — that is deliberate, a draft is research about somebody's
business and not publication under their name.

---

## Bild-Thumbnails

Die App speichert von jedem Foto **nur das Original** — Google-Places-Fotos kommen mit
1200px herein (`GooglePlacesPhotoFinder`), Filament-Uploads werden clientseitig auf 2000px
gedeckelt (`AppServiceProvider`), gescrapte Heros sind fremde URLs. Ein 44px großes
Tages-Thumbnail im Reiseplan würde ohne Weiteres das volle Original laden.

### Was läuft (ohne Konfiguration)

Die App verkleinert selbst. `/thumbs/{breite}/{key}` holt das Original aus R2, skaliert es
einmal mit GD, legt die WebP-Fassung im selben Bucket unter `thumbs/` ab und verweist
dorthin (`ThumbnailController`). Ab dem zweiten Aufruf wird nur noch verwiesen.

- **Nichts einzustellen.** Kein DNS, kein Cloudflare-Konto, keine `.env`-Variable.
- **Kein Backfill.** Es entsteht, was tatsächlich angeschaut wird.
- **Die Bilddaten laufen nicht über den Server.** Die Antwort ist eine Weiterleitung nach
  R2; PHP schickt einen Satz, nicht die Megabytes.
- **Derivate sind ein Cache, keine Daten.** Der Ordner `thumbs/` im Bucket darf jederzeit
  gelöscht werden — er entsteht neu. Genau so wendet man auch eine geänderte Breitenleiter
  oder Qualität an; eine Migration gibt es nicht. Aus demselben Grund ist es unkritisch,
  wenn `photos:audit-r2` diese Dateien als „verwaist" zählen würde (die Standard-Präfixe
  umfassen `thumbs/` nicht).
- Abschaltbar mit `MEDIA_THUMBNAILS_ENABLED=false` — dann werden wieder Originale
  ausgeliefert, nur eben langsam.

### Optional: Cloudflare statt selbst rechnen

Dasselbe Original kann auch von **Cloudflare Image Transformations** in der gewünschten
Breite ausgeliefert werden (`/cdn-cgi/image/<optionen>/<pfad>`). Vorteil gegenüber dem
Eigenbau: die Rechenarbeit passiert nicht auf eurem Server, und `format=auto` liefert
zusätzlich AVIF statt nur WebP. Das ist eine Optimierung, keine Voraussetzung — ist es
aktiv, hat es Vorrang, sonst greift der Eigenbau oben.

**Das funktioniert nicht auf der Standard-Bucket-URL.** `https://pub-<hash>.r2.dev` liegt
nicht auf der namibway.com-Zone und kennt kein `/cdn-cgi/image/`. Zum Einschalten:

0. **Voraussetzung, die aktuell NICHT erfüllt ist:** Die DNS-Zone `namibway.com` muss bei
   Cloudflare liegen — eine R2 Custom Domain kann nur an eine Zone im eigenen
   Cloudflare-Account gehängt werden. Stand 2026-08-09 zeigt `namibway.com` direkt auf die
   OVH-IP (kein Cloudflare-Proxy, kein `cf-ray`-Header), d.h. erst Nameserver zu Cloudflare
   umziehen, dann weiter. Prüfen: `dig +short cdn.namibway.com` muss etwas zurückgeben,
   bevor Schritt 3 auch nur angefasst wird.
1. Im Cloudflare-Dashboard eine **Custom Domain** an den Media-Bucket hängen
   (R2 → Bucket `namibway` → Settings → Public access → Custom Domain), z.B. `cdn.namibway.com`.
   Der Bucket für Backups (`namibway-backups`) bekommt **keine** Custom Domain.
2. Für die Zone `namibway.com` **Transformations** aktivieren
   (Images → Transformations → Zone auf `on`). Kostet pro *einzigartiger* Transformation —
   deshalb rastet `config/media.php` angefragte Breiten auf eine kurze Leiter
   (64/128/256/400/800/1600), statt jede CSS-Pixelbreite durchzureichen.
3. In der `.env` auf dem Server:
   ```bash
   CLOUDFLARE_R2_URL=https://cdn.namibway.com   # statt der pub-<hash>.r2.dev-URL
   MEDIA_TRANSFORMS_ENABLED=true
   ```
4. `bash deploy_namibway.sh` (oder mindestens `php artisan config:cache`).

> `MEDIA_TRANSFORMS_ENABLED=true` allein reicht nicht und schadet auch nicht:
> Solange `CLOUDFLARE_R2_URL` auf `pub-<hash>.r2.dev` (oder den S3-Endpunkt)
> zeigt, wird die Einstellung **ignoriert**. Diese Hostnamen liegen nicht auf
> einer Cloudflare-Zone, `/cdn-cgi/image/` gibt es dort nicht — am 2026-08-09
> hat genau das jedes Listing-Foto in einen 404 verwandelt, der dann auf ein
> Ersatzbild zurückfiel. Erst die Custom Domain aus Schritt 1 macht den
> Schalter wirksam.

**Prüfen — am besten schon vor Schritt 3:**

```bash
php artisan namibway:check-media-transforms
```

Das Command zieht echte Katalogfotos einmal im Original und einmal über
`/cdn-cgi/image/` und vergleicht Status, Content-Type und Bytes. Es probiert die
transformierte URL **auch dann**, wenn `MEDIA_TRANSFORMS_ENABLED` noch `false` ist — so
lässt sich die Cloudflare-Seite bestätigen, bevor Produktion sich darauf verlässt.
Exit-Code ≠ 0 heißt: es greift nicht. Zwei Fälle, die es explizit auseinanderhält —
404 auf der Variante (Custom Domain oder Transformations fehlen) und 200 in
Originalgröße (Cloudflare reicht durch, statt zu skalieren).

Zusätzlich meldet es Bilder, die als absolute URL auf einem **alten** Media-Origin
liegen: Google-Places-Fotos speichern zum Download-Zeitpunkt die volle Bucket-URL
(`GooglePlacesPhotoFinder`), also tragen bestehende Zeilen weiter die
`pub-<hash>.r2.dev`-Adresse, wenn `CLOUDFLARE_R2_URL` auf die Custom Domain wechselt.
Solche Bilder werden weiter unverkleinert ausgeliefert.

**Solange das aus ist, funktioniert alles weiter** — jede URL wird unverändert durchgereicht,
die Bilder sind nur so groß wie das Original. Einzige Ausnahme: Unsplash-Platzhalter werden
über deren eigene Query-Parameter verkleinert, das läuft ohne Cloudflare und ohne Kosten.

Wenn nach Schritt 4 Bilder verschwinden, ist fast immer Schritt 1 oder 2 unvollständig
(Custom Domain fehlt, oder Transformations für die Zone nicht aktiv). `MEDIA_TRANSFORMS_ENABLED=false`
+ `php artisan config:cache` stellt den alten Zustand sofort wieder her.

---

## Nützliche Befehle

```bash
bash deploy_namibway.sh                # volles Update/Deploy
bash deploy_namibway.sh --no-npm       # Deploy ohne npm-Build
bash deploy_namibway.sh --no-migrate   # Deploy ohne Migrationen
php artisan make:filament-user         # neuen Admin-User anlegen
php artisan namibway:check-media-transforms   # Bild-Thumbnails: greift Cloudflare?
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
