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
- Mailprovider ist OVH — IMAP `imap.mail.ovh.net:993` (SSL/TLS), SMTP `smtp.mail.ovh.net:465`
  (SSL/TLS), Login = volle E-Mail-Adresse + deren Passwort (identisch mit dem, was hinter
  `POP3_USERNAME`/`POP3_PASSWORD` in der Produktions-`.env` steckt). Siehe Schritt 3.
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

Bewusst nur ein reiner Port-80-vhost — **kein** eigener `listen 443`-Block und keine manuelle
Weiterleitung nötig. Das übernimmt Certbot im nächsten Schritt automatisch:

```bash
sudo ln -s /etc/nginx/sites-available/webmail.namibway.com /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d webmail.namibway.com
```

Das `--nginx`-Plugin bearbeitet die vhost-Datei danach selbst: es ergänzt einen
`listen 443 ssl`-Block mit den Zertifikatspfaden und richtet eine Weiterleitung von Port 80 auf
443 ein. Mit `sudo cat /etc/nginx/sites-available/webmail.namibway.com` danach kurz prüfen, ob
beides drinsteht — falls die interaktive Certbot-Frage nach der Weiterleitung übersprungen
wurde (ältere Certbot-Versionen fragen das explizit ab), lässt sich das mit
`sudo certbot install --nginx -d webmail.namibway.com` bzw. `sudo certbot enhance --nginx --redirect` nachholen.

## 3. Roundcube mit der Mailbox verbinden

Mailprovider ist OVH — Server laut [OVH-Doku](https://docs.ovhcloud.com/fr/guides/web-cloud/email-and-collaborative-solutions/mx-plan/how-to-configure-outlook-2016):
IMAP `imap.mail.ovh.net:993` (SSL/TLS), SMTP `smtp.mail.ovh.net:465` (SSL/TLS). Alternativ
`ssl0.ovh.net` für beides, falls die spezifischen Hostnamen mal nicht auflösen.

In `config.inc.php` (Distro-Paket: `/etc/roundcube/config.inc.php`; Variante B:
`/var/www/webmail/config/config.inc.php`) — Achtung: die Config-Keys heißen seit Roundcube 1.5
`imap_host`/`smtp_host`, nicht mehr `default_host`/`smtp_server`/`smtp_port` (ältere
Anleitungen im Netz nutzen noch die alten Namen):

```php
// Port + Schema stecken direkt im Host-String, kein separater *_port-Key mehr.
// Das ssl://-Präfix ist Pflicht: 993/465 sind implizites TLS — der Server
// erwartet den TLS-Handshake sofort nach dem Connect. Ohne das Präfix
// verbindet Roundcube im Klartext, der Server antwortet nie darauf, und der
// Login hängt bis zum nginx-Timeout (siehe Troubleshooting unten — genau
// dieser Fehler ist uns beim ersten Setup passiert).
$config['imap_host'] = ['ssl://imap.mail.ovh.net:993'];

$config['smtp_host'] = 'ssl://smtp.mail.ovh.net:465';
$config['smtp_user'] = '%u';   // übernimmt den Login-Benutzernamen
$config['smtp_pass'] = '%p';   // übernimmt das Login-Passwort

$config['product_name'] = 'NamibWay Webmail';
```

Da es eine **geteilte Mailbox** ist (kein Multi-User-Setup), am Login-Screen einfach mit dem
`POP3_USERNAME`/`POP3_PASSWORD`-Paar (identisch mit dem IMAP/SMTP-Login bei OVH) anmelden —
Roundcube fragt Login/Passwort interaktiv ab, es muss nichts davon fest im Code stehen.

## Test

```bash
curl -sI https://webmail.namibway.com | head -1   # 200 erwartet
```

Danach im Browser unter `https://webmail.namibway.com` mit den Mailbox-Zugangsdaten einloggen
und prüfen, dass Posteingang + Versand funktionieren.

## Troubleshooting

**502 Bad Gateway sofort:** falscher PHP-FPM-Socket-Pfad in der nginx-Config — mit
`systemctl list-units --type=service | grep php` die tatsächlich laufende Version prüfen.

**502 Bad Gateway erst nach dem Login-Klick, nginx-Log zeigt "upstream timed out ... while
reading response header":** `imap_host`/`smtp_host` fehlt das `ssl://`-Präfix. 993/465 sind
implizites TLS — der Server erwartet den TLS-Handshake direkt nach dem Connect; ohne `ssl://`
verbindet Roundcube im Klartext, der Server antwortet nicht, und PHP-FPM hängt bis zum
nginx-`fastcgi_read_timeout` (Standard 60s). Diagnose: `sudo -u www-data php -r
'$s=@stream_socket_client("ssl://imap.mail.ovh.net:993",$e,$s2,5); var_dump($s!==false);'`
— läuft das sofort (`bool(true)`), aber der echte Login trotzdem in einen Timeout, ist
`ssl://` in der Config das Erste, was zu prüfen ist. Nach dem Fix `sudo systemctl reload
php8.3-fpm` nicht vergessen (OPcache hält sonst den alten Config-Stand fest).

**TLS-Fehler beim IMAP/SMTP-Connect trotz `ssl://`-Präfix:** falls der Provider stattdessen
STARTTLS erwartet (z.B. bei einem anderen Provider als OVH), braucht `imap_host`/`smtp_host`
`tls://` statt `ssl://` und einen anderen Port (typischerweise 143 bzw. 587).

**Login schlägt fehl, Zugangsdaten sind aber korrekt:** IMAP könnte beim Provider separat vom
POP3-Zugang aktiviert/erlaubt werden müssen — bei OVH sollte das über dasselbe Postfach-Konto
laufen, im Zweifel im OVH-Kundencenter prüfen, ob IMAP für diese Adresse freigeschaltet ist.
