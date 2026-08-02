# Webmail (Roundcube) — Setup

Roundcube-Installation für `webmail.namibway.com`, verbunden per IMAP/SMTP mit der bestehenden
geteilten Mailbox (dieselbe, die `namibway:fetch-partner-emails` per POP3 pollt, siehe
`app/Services/Messaging/PartnerEmailFetcher.php`). Dient als vollwertiger Mail-Client für
Mitarbeiter, um mit Partnern, Kunden und Unternehmen zu korrespondieren — im Admin-Panel unter
"Messaging → Emails" verlinkt (`app/Providers/Filament/AdminPanelProvider.php`), öffnet in
einem neuen Tab.

POP3-Fetcher und Roundcube laufen unabhängig nebeneinander und stören sich nicht: POP3 löscht
nie etwas vom Server (siehe Docblock von `PartnerEmailFetcher`), Roundcube liest per IMAP
denselben Posteingang parallel.

## Voraussetzungen

- DNS: `webmail.namibway.com` als A- oder CNAME-Eintrag auf die Server-IP (beim jeweiligen
  DNS-Provider, z.B. Cloudflare).
- IMAP- und SMTP-Zugangsdaten des bestehenden Mailproviders — meist derselbe Host wie
  `POP3_HOST` in der Produktions-`.env`, ggf. mit eigenem IMAP-Port (Standard: 993 SSL) und
  SMTP-Port (587 STARTTLS oder 465 SSL). Beim Provider nachfragen, falls unklar.
- nginx + Certbot bereits vorhanden (wie für `namibway.com`, siehe `DEPLOYMENT.md`).

## 1. Installation

**Variante A — Distro-Paket (einfacher, evtl. ältere Version):**

```bash
sudo apt update
sudo apt install roundcube roundcube-pgsql
```

Der `dbconfig-common`-Dialog während der Installation fragt nach einer DB — Roundcube braucht
eine eigene kleine Datenbank für Adressbuch/Einstellungen, unabhängig von `namibway`. Entweder
dort automatisch anlegen lassen (PostgreSQL ist bereits installiert, siehe `DEPLOYMENT.md`)
oder manuell:

```bash
sudo -u postgres psql -c "CREATE DATABASE roundcube;"
sudo -u postgres psql -c "CREATE USER roundcube WITH ENCRYPTED PASSWORD 'ein-sicheres-passwort';"
sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE roundcube TO roundcube;"
sudo -u postgres psql -d roundcube -c "GRANT ALL ON SCHEMA public TO roundcube;"
```

**Variante B — aktuelles Release von roundcube.net (falls das Distro-Paket zu alt ist):**

```bash
cd /var/www
sudo curl -LO https://github.com/roundcube/roundcubemail/releases/latest/download/roundcubemail-<version>-complete.tar.gz
sudo tar xzf roundcubemail-<version>-complete.tar.gz
sudo mv roundcubemail-<version> webmail
sudo chown -R www-data:www-data webmail
cd webmail
php bin/install.php   # interaktiver DB-/Konfig-Assistent
```

## 2. nginx-vhost

`/etc/nginx/sites-available/webmail.namibway.com`, nach demselben Muster wie der bestehende
`namibway.com`-vhost (PHP-FPM-Handoff — welche `php*-fpm`-Version, siehe laufende Units mit
`systemctl list-units --type=service | grep php`):

```nginx
server {
    listen 80;
    server_name webmail.namibway.com;
    root /var/lib/roundcube/public_html;   # bzw. /var/www/webmail/public_html bei Variante B

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;   # an tatsächlich genutzte Version anpassen
    }

    location ~ ^/(README|SECURITY|CHANGELOG|composer\.json)$ {
        deny all;
    }
}
```

Danach TLS wie gewohnt:

```bash
sudo ln -s /etc/nginx/sites-available/webmail.namibway.com /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d webmail.namibway.com
```

## 3. Roundcube mit der Mailbox verbinden

In `config.inc.php` (Distro-Paket: `/etc/roundcube/config.inc.php`; Variante B:
`/var/www/webmail/config/config.inc.php`):

```php
$config['default_host'] = 'ssl://<imap-host>';   // Port 993, oder tls://... auf Port 143
$config['default_port'] = 993;

$config['smtp_server'] = 'tls://<smtp-host>';    // Port 587, oder ssl://... auf Port 465
$config['smtp_port'] = 587;
$config['smtp_user'] = '%u';                      // übernimmt den Login-Benutzernamen
$config['smtp_pass'] = '%p';

$config['product_name'] = 'NamibWay Webmail';
```

Da es eine **geteilte Mailbox** ist (kein Multi-User-Setup), am Login-Screen einfach mit dem
`POP3_USERNAME`/`POP3_PASSWORD`-Paar (bzw. dessen IMAP-Äquivalent) anmelden — Roundcube fragt
Login/Passwort interaktiv ab, es muss nichts davon fest im Code stehen.

## Test

```bash
curl -sI https://webmail.namibway.com | head -1   # 200 erwartet
```

Danach im Browser unter `https://webmail.namibway.com` mit den Mailbox-Zugangsdaten einloggen
und prüfen, dass Posteingang + Versand funktionieren.

## Troubleshooting

**502 Bad Gateway:** falscher PHP-FPM-Socket-Pfad in der nginx-Config — mit
`systemctl list-units --type=service | grep php` die tatsächlich laufende Version prüfen.

**TLS-Fehler beim IMAP/SMTP-Connect:** Port und Schema (`ssl://` vs. `tls://`) müssen zum
Provider passen — 993/`ssl://` (implizites TLS) ist nicht dasselbe wie 143/`tls://`
(STARTTLS), ebenso 465 vs. 587 bei SMTP.

**Login schlägt fehl, Zugangsdaten sind aber korrekt:** IMAP könnte beim Provider separat vom
POP3-Zugang aktiviert/erlaubt werden müssen — beim Hoster nachfragen, falls POP3 funktioniert,
IMAP aber nicht.
