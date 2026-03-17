# Spickzettel – AS26 Agenda-App

Schnellreferenz für häufige Aufgaben.

---

## 1. Admin-Zugang

Den Admin-Modus aktivierst du, indem du `?admin=<SECRET>` an die URL hängst.  
Das Secret ist in `config/.env` unter `ADMIN_SECRET` hinterlegt.

| Umgebung | URL |
|---|---|
| **Dev** | `https://dev.as26.cool-camp.site/?admin=as26-review-2026` |
| **Live** | `https://agenda.adventuresouthside.com/?admin=as26-review-2026` |

Funktioniert auf jeder Seite, z. B.:
- `https://dev.as26.cool-camp.site/programm?admin=as26-review-2026`

Das Secret wird per JavaScript in `sessionStorage` gespeichert und sofort aus der URL entfernt.  
Ein neues Tab erfordert erneute Eingabe.

Admin Dashboard: https://dev.as26.cool-camp.site/admin/analytics.html (Login mit Admin-Secret)

Secret manuell eingeben im Formular
?admin=as26-review-2026 als URL-Parameter (wie überall sonst)
Automatisch wenn schon auf einer anderen Seite mit ?admin=... angemeldet (sessionStorage)




---

## 2. QR-Codes erzeugen

Beide Skripte liegen in `public/` und werden auf der **Konsole** ausgeführt:

```bash
# Ins Projektverzeichnis wechseln
cd /var/www/dev.as26.cool-camp.site

# 1) QR-Code-PNGs generieren (pro Workshop eine Datei in public/qr/)
php public/qr-generate.php

# 2) QR-URLs + Live-URLs in die Notion-DB zurückschreiben
php public/qr-upload.php
```

### Was passiert?

| Skript | Beschreibung |
|---|---|
| `qr-generate.php` | Liest alle Workshop-IDs aus Notion, erzeugt pro Workshop eine PNG-Datei unter `public/qr/<id>.png`. Basis-URL: `https://agenda.adventuresouthside.com/w/<id>` |
| `qr-upload.php` | Schreibt die Property **Feedback-QR** (externer File-Link) und **Live-URL** in jede Notion-Seite zurück. |

---

## 3. JSON-Daten regenerieren (Notion → API)

Die statischen JSON-Dateien unter `public/api/` werden aus Notion generiert.

### Konsole (manuell)

```bash
cd /var/www/dev.as26.cool-camp.site

# Alle drei auf einmal (mit Lock-Schutz):
php cli/generate-json.php

# Nur einzeln:
php scripts/generate-workshops-json.php
php scripts/generate-aussteller-json.php
php scripts/generate-experten-json.php
```

### Cron (automatisch, stündlich)

```cron
0 * * * * cd /var/www/as26.cool-camp.site && php cli/generate-json.php >> /var/log/as26-json.log 2>&1
```

### Erzeugte Dateien

| Generator | Zieldatei |
|---|---|
| `generate-workshops-json.php` | `public/api/workshops.json` |
| `generate-aussteller-json.php` | `public/api/aussteller.json` |
| `generate-experten-json.php` | `public/api/experten.json` |

> **Hinweis:** `cli/generate-json.php` nutzt eine Lock-Datei (`storage/generate-json.lock`), um parallele Läufe zu verhindern. Falls ein Lauf hängt, die Lock-Datei manuell löschen:
> ```bash
> rm storage/generate-json.lock
> ```

---

## 4. Wichtige URLs (Dev)

| Seite | URL |
|---|---|
| Startseite | `https://dev.as26.cool-camp.site/` |
| Programm | `https://dev.as26.cool-camp.site/programm` |
| Aussteller | `https://dev.as26.cool-camp.site/aussteller` |
| Experten | `https://dev.as26.cool-camp.site/experten` |
| Workshop (Beispiel) | `https://dev.as26.cool-camp.site/w/<32-hex-id>` |
| Workshop-Feedback | `https://dev.as26.cool-camp.site/w/<id>/feedback` |
| Workshop-Q&A | `https://dev.as26.cool-camp.site/w/<id>/qa` |
| Workshop-Wall | `https://dev.as26.cool-camp.site/w/<id>/wall` |
| FAQ | `https://dev.as26.cool-camp.site/faq` |
| Impressum | `https://dev.as26.cool-camp.site/impressum` |

---

## 5. Notion-Datenbanken

Übersicht der genutzten DBs (IDs aus `config/.env`):

| Zweck | ENV-Variable |
|---|---|
| Workshops | `NOTION_WORKSHOP_DB` |
| Aussteller | `NOTION_AUSSTELLER_DB` |
| Referenten/Experten | `NOTION_REFERENTEN_DB` |
| Feedback | `NOTION_FEEDBACK_DB` |
| Q&A-Fragen | `NOTION_QA_DB` |
| Reviews | `NOTION_REVIEW_DB` |
| E-Mail-Adressen | `NOTION_EMAIL_DB` |

---

## 6. Webhooks (n8n)

| Webhook | ENV-Variable |
|---|---|
| Feedback | `N8N_FEEDBACK_WEBHOOK` |
| Q&A | `N8N_QA_WEBHOOK` |
| Upvote | `N8N_UPVOTE_WEBHOOK` |

---

## 7. Release-Management

Jede abgeschlossene Aufgabe (Feature, Bugfix, Refactoring) **muss** mit einem Release dokumentiert werden. Ohne diesen Schritt ist die Aufgabe nicht abgeschlossen.

### Datenbank & CLI

| Was | Pfad |
|---|---|
| Release-DB | `storage/releases/releases.sqlite` (SQLite, gitignored, pro Umgebung separat) |
| CLI-Tool | `php cli/release.php` |

### Befehle

```bash
cd /var/www/dev.as26.cool-camp.site

# DB initialisieren (einmalig pro Umgebung)
php cli/release.php init

# Aktuelle Version anzeigen
php cli/release.php current

# Changelog anzeigen (optional mit Limit)
php cli/release.php log
php cli/release.php log --limit=10

# Neues Release eintragen
php cli/release.php release 1.2.3 "Kurze Beschreibung der Änderung"
```

### Semantic Versioning

| Stufe | Wann | Beispiel |
|---|---|---|
| **PATCH** | Bugfixes, kleine Korrekturen | `1.0.0` → `1.0.1` |
| **MINOR** | Neue Features, Erweiterungen | `1.0.1` → `1.1.0` |
| **MAJOR** | Breaking Changes | `1.1.0` → `2.0.0` |

### Ablauf: Neues Release erzeugen

1. Code-Änderungen fertigstellen und testen
2. Git-Commit erstellen
3. Release eintragen:
   ```bash
   php cli/release.php release <X.Y.Z> "Beschreibung"
   ```
   → Aktualisiert automatisch `APP_VERSION` in `config/.env`
4. Erneut committen (Version-Bump) und pushen

### Deployment auf Produktion

```bash
cd /var/www/as26.cool-camp.site && git pull
php cli/release.php init          # falls DB noch nicht existiert
php cli/release.php release <X.Y.Z> "Beschreibung"   # gleiche Version wie auf Dev
```
